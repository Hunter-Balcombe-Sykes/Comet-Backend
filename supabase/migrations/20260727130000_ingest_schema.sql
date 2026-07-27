-- Ingest schema (plan §4): the ELT pipeline's durable state.
--
-- The load-bearing idea: `record_versions` is a CONTENT-ADDRESSED log that
-- sits between fetching and interpreting. Unchanged content writes zero rows,
-- so "did anything actually change?" is answerable without diffing, and a
-- projector can be re-run over history without re-fetching anything.
--
-- Deletion is never inferred from absence alone (C5): a key is only eligible
-- once a Coverage window DOMINATES it, and then only after N consecutive
-- dominated absences, and then only if the delete-guard has not tripped.

CREATE SCHEMA IF NOT EXISTS "ingest";

-- ── sources: the fetch unit. One row per (connection × source). ─────────────
CREATE TABLE "ingest"."sources" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "user_id" uuid NOT NULL REFERENCES "core"."users" ("id") ON DELETE CASCADE,
    "connection_id" uuid REFERENCES "site"."platform_connections" ("id") ON DELETE CASCADE,
    "source_key" text NOT NULL,              -- manifest source key, e.g. 'bandcamp'
    "surface_key" text NOT NULL,             -- catalog surface this serves
    "identifier" text NOT NULL,              -- handle/url/place id
    -- Scheduling. The band comes from the stream's SourceProfile; change_rate
    -- is an EWMA over qualifying runs so a dormant source backs off on its own
    -- rather than by hand-tuned config.
    "cost_units" integer NOT NULL DEFAULT 1,
    "min_interval_secs" integer NOT NULL DEFAULT 3600,
    "max_interval_secs" integer NOT NULL DEFAULT 604800,
    "change_rate" double precision NOT NULL DEFAULT 0.5,
    "next_attempt_at" timestamp with time zone NOT NULL DEFAULT now(),
    "last_run_at" timestamp with time zone,
    "visibility" double precision NOT NULL DEFAULT 1.0,
    -- THE run lock. `ShouldBeUnique`/`Cache::lock` are deliberately NOT used
    -- for fetch jobs: one mechanism, visible in the same row as the schedule.
    "in_flight_since" timestamp with time zone,
    "in_flight_run_id" uuid,
    "health" text NOT NULL DEFAULT 'ok'
        CHECK ("health" IN ('ok', 'degraded', 'unavailable', 'shape', 'suppressed', 'dead')),
    "consecutive_failures" integer NOT NULL DEFAULT 0,
    "auto_sync" boolean NOT NULL DEFAULT true,
    "scope" text NOT NULL DEFAULT 'all' CHECK ("scope" IN ('all', 'latest_n')),
    "scope_n" integer,
    "created_at" timestamp with time zone NOT NULL DEFAULT now(),
    "updated_at" timestamp with time zone NOT NULL DEFAULT now(),
    CONSTRAINT "sources_unique_per_connection" UNIQUE ("connection_id", "source_key")
);

CREATE INDEX "idx_ingest_sources_due" ON "ingest"."sources" ("next_attempt_at")
    WHERE ("in_flight_since" IS NULL AND "auto_sync" AND "health" <> 'dead');
CREATE INDEX "idx_ingest_sources_user" ON "ingest"."sources" ("user_id");
CREATE INDEX "idx_ingest_sources_stranded" ON "ingest"."sources" ("in_flight_since")
    WHERE ("in_flight_since" IS NOT NULL);

-- ── streams: one row per (source, stream). Owns coverage/schema/health. ─────
CREATE TABLE "ingest"."streams" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "source_id" uuid NOT NULL REFERENCES "ingest"."sources" ("id") ON DELETE CASCADE,
    "stream_name" text NOT NULL,
    "cursor" jsonb,                          -- last Bookmark
    "coverage" jsonb,                        -- last Coverage window
    "observed_schema" jsonb NOT NULL DEFAULT '{}'::jsonb,
    "schema_hash" text,
    "health" text NOT NULL DEFAULT 'ok'
        CHECK ("health" IN ('ok', 'degraded', 'unavailable', 'shape', 'suppressed')),
    "consecutive_failures" integer NOT NULL DEFAULT 0,
    "suppressed_until" timestamp with time zone,
    -- Delete-guard: set when a run would have removed an implausible share of
    -- the stream. Deletion stays frozen until a human resolves the anomaly or
    -- the replacement rate clears it.
    "guard_tripped_at" timestamp with time zone,
    -- Monotonic fence: a projection write carrying an older seq is discarded,
    -- so a slow job can never overwrite a newer one's output.
    "run_seq" bigint NOT NULL DEFAULT 0,
    "created_at" timestamp with time zone NOT NULL DEFAULT now(),
    "updated_at" timestamp with time zone NOT NULL DEFAULT now(),
    CONSTRAINT "streams_unique_per_source" UNIQUE ("source_id", "stream_name")
);

CREATE INDEX "idx_ingest_streams_health" ON "ingest"."streams" ("health")
    WHERE ("health" <> 'ok');

-- ── runs: one row per fetch attempt. ────────────────────────────────────────
CREATE TABLE "ingest"."runs" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "source_id" uuid NOT NULL REFERENCES "ingest"."sources" ("id") ON DELETE CASCADE,
    "trigger" text NOT NULL
        CHECK ("trigger" IN ('schedule', 'manual', 'connect', 'backfill', 'reproject')),
    "started_at" timestamp with time zone NOT NULL DEFAULT now(),
    "finished_at" timestamp with time zone,
    "outcome" text
        CHECK ("outcome" IS NULL OR "outcome" IN ('ok', 'not_modified', 'unavailable', 'shape', 'degraded', 'budget_skipped', 'deferred', 'error')),
    "error_class" text,
    "records_seen" integer NOT NULL DEFAULT 0,
    "records_changed" integer NOT NULL DEFAULT 0,
    "records_tombstoned" integer NOT NULL DEFAULT 0,
    "effects_count" integer NOT NULL DEFAULT 0,
    "cost_claimed" integer NOT NULL DEFAULT 0,
    "detail" jsonb NOT NULL DEFAULT '{}'::jsonb,
    "created_at" timestamp with time zone NOT NULL DEFAULT now()
);

CREATE INDEX "idx_ingest_runs_source" ON "ingest"."runs" ("source_id", "started_at" DESC);
CREATE INDEX "idx_ingest_runs_outcome" ON "ingest"."runs" ("outcome", "started_at" DESC);

-- ── record_versions: THE durable artifact. Content-addressed. ───────────────
-- Hash-partitioned: this is the highest-volume table in the system and every
-- access is by stream_id.
CREATE TABLE "ingest"."record_versions" (
    "id" bigserial NOT NULL,
    "stream_id" uuid NOT NULL,
    "key" text NOT NULL,                     -- vendor-stable id within the stream
    "doc_hash" text NOT NULL,                -- sha256 over the canonicalised doc
    "doc" jsonb NOT NULL,                    -- verbatim, POST-redaction
    "first_seen_run" uuid,
    "first_seen_at" timestamp with time zone NOT NULL DEFAULT now(),
    "is_current" boolean NOT NULL DEFAULT true,
    PRIMARY KEY ("id", "stream_id")
) PARTITION BY HASH ("stream_id");

-- 8 partitions: enough to keep any one manageable at pilot scale without
-- making the planner walk a large partition set on every lookup.
CREATE TABLE "ingest"."record_versions_p0" PARTITION OF "ingest"."record_versions" FOR VALUES WITH (MODULUS 8, REMAINDER 0);
CREATE TABLE "ingest"."record_versions_p1" PARTITION OF "ingest"."record_versions" FOR VALUES WITH (MODULUS 8, REMAINDER 1);
CREATE TABLE "ingest"."record_versions_p2" PARTITION OF "ingest"."record_versions" FOR VALUES WITH (MODULUS 8, REMAINDER 2);
CREATE TABLE "ingest"."record_versions_p3" PARTITION OF "ingest"."record_versions" FOR VALUES WITH (MODULUS 8, REMAINDER 3);
CREATE TABLE "ingest"."record_versions_p4" PARTITION OF "ingest"."record_versions" FOR VALUES WITH (MODULUS 8, REMAINDER 4);
CREATE TABLE "ingest"."record_versions_p5" PARTITION OF "ingest"."record_versions" FOR VALUES WITH (MODULUS 8, REMAINDER 5);
CREATE TABLE "ingest"."record_versions_p6" PARTITION OF "ingest"."record_versions" FOR VALUES WITH (MODULUS 8, REMAINDER 6);
CREATE TABLE "ingest"."record_versions_p7" PARTITION OF "ingest"."record_versions" FOR VALUES WITH (MODULUS 8, REMAINDER 7);

-- The write is an upsert that DOES NOTHING on conflict: identical content
-- landing again is a no-op, which is what makes change detection free.
CREATE UNIQUE INDEX "idx_record_versions_content" ON "ingest"."record_versions" ("stream_id", "key", "doc_hash");
CREATE INDEX "idx_record_versions_current" ON "ingest"."record_versions" ("stream_id", "key") WHERE "is_current";

-- ── record_state: per-key presence/absence bookkeeping. ─────────────────────
CREATE TABLE "ingest"."record_state" (
    "stream_id" uuid NOT NULL REFERENCES "ingest"."streams" ("id") ON DELETE CASCADE,
    "key" text NOT NULL,
    "current_version_id" bigint,
    "last_seen_run" uuid,
    "last_seen_at" timestamp with time zone NOT NULL DEFAULT now(),
    -- Set ONLY when a Coverage window dominated this key and it was absent.
    -- Absence outside a dominating window means nothing at all.
    "absent_since" timestamp with time zone,
    "absent_runs" integer NOT NULL DEFAULT 0,
    "tombstoned_at" timestamp with time zone,
    PRIMARY KEY ("stream_id", "key")
);

CREATE INDEX "idx_record_state_live" ON "ingest"."record_state" ("stream_id")
    WHERE ("tombstoned_at" IS NULL);

-- ── effects: the charge-once ledger (C6). ──────────────────────────────────
-- Keyed by a digest of the effect REQUESTED, so a retry of the same job
-- returns the recorded result instead of paying again. `settled_at IS NULL`
-- with a claim means a process died mid-effect: that row is refused, not
-- retried blindly, until it is explicitly resolved.
CREATE TABLE "ingest"."effects" (
    "digest" text PRIMARY KEY,
    "run_id" uuid,
    "source_id" uuid,
    "kind" text NOT NULL,                    -- http | actor | api | ai
    "cost_tag" text,                         -- budget bucket that was charged
    "cost_units" integer NOT NULL DEFAULT 0,
    "claimed_at" timestamp with time zone NOT NULL DEFAULT now(),
    "settled_at" timestamp with time zone,
    "status" text NOT NULL DEFAULT 'claimed'
        CHECK ("status" IN ('claimed', 'ok', 'failed', 'refused', 'abandoned')),
    -- Response bodies live on the private ingest disk, not in Postgres.
    "body_ref" text,
    "meta" jsonb NOT NULL DEFAULT '{}'::jsonb
);

CREATE INDEX "idx_effects_run" ON "ingest"."effects" ("run_id");
CREATE INDEX "idx_effects_unsettled" ON "ingest"."effects" ("claimed_at")
    WHERE ("settled_at" IS NULL);

-- ── anomalies: things a human must look at. ────────────────────────────────
CREATE TABLE "ingest"."anomalies" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "stream_id" uuid REFERENCES "ingest"."streams" ("id") ON DELETE CASCADE,
    "source_id" uuid REFERENCES "ingest"."sources" ("id") ON DELETE CASCADE,
    "run_id" uuid,
    "kind" text NOT NULL,                    -- delete_guard | shape | drift | stranded | schema
    "severity" text NOT NULL DEFAULT 'warning' CHECK ("severity" IN ('info', 'warning', 'critical')),
    "summary" text NOT NULL,
    "detail" jsonb NOT NULL DEFAULT '{}'::jsonb,
    "detected_at" timestamp with time zone NOT NULL DEFAULT now(),
    -- The delete-guard's ONLY release valve besides a recovering replacement
    -- rate: a person deciding the deletion is real.
    "resolved_at" timestamp with time zone,
    "resolved_by" text,
    "resolution" text
);

CREATE INDEX "idx_anomalies_open" ON "ingest"."anomalies" ("kind", "detected_at" DESC)
    WHERE ("resolved_at" IS NULL);

-- ── RLS + grants (mirrors the baseline's app_backend pattern). ─────────────

ALTER TABLE "ingest"."sources" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "ingest"."sources" FORCE ROW LEVEL SECURITY;
ALTER TABLE "ingest"."streams" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "ingest"."streams" FORCE ROW LEVEL SECURITY;
ALTER TABLE "ingest"."runs" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "ingest"."runs" FORCE ROW LEVEL SECURITY;
ALTER TABLE "ingest"."record_versions" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "ingest"."record_versions" FORCE ROW LEVEL SECURITY;
ALTER TABLE "ingest"."record_state" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "ingest"."record_state" FORCE ROW LEVEL SECURITY;
ALTER TABLE "ingest"."effects" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "ingest"."effects" FORCE ROW LEVEL SECURITY;
ALTER TABLE "ingest"."anomalies" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "ingest"."anomalies" FORCE ROW LEVEL SECURITY;

CREATE POLICY "ingest_sources_app_backend_all" ON "ingest"."sources" TO "app_backend" USING (true) WITH CHECK (true);
CREATE POLICY "ingest_streams_app_backend_all" ON "ingest"."streams" TO "app_backend" USING (true) WITH CHECK (true);
CREATE POLICY "ingest_runs_app_backend_all" ON "ingest"."runs" TO "app_backend" USING (true) WITH CHECK (true);
CREATE POLICY "ingest_record_versions_app_backend_all" ON "ingest"."record_versions" TO "app_backend" USING (true) WITH CHECK (true);
CREATE POLICY "ingest_record_state_app_backend_all" ON "ingest"."record_state" TO "app_backend" USING (true) WITH CHECK (true);
CREATE POLICY "ingest_effects_app_backend_all" ON "ingest"."effects" TO "app_backend" USING (true) WITH CHECK (true);
CREATE POLICY "ingest_anomalies_app_backend_all" ON "ingest"."anomalies" TO "app_backend" USING (true) WITH CHECK (true);

GRANT USAGE ON SCHEMA "ingest" TO "service_role";
GRANT USAGE ON SCHEMA "ingest" TO "app_backend";
GRANT ALL ON ALL TABLES IN SCHEMA "ingest" TO "service_role";
GRANT ALL ON ALL TABLES IN SCHEMA "ingest" TO "app_backend";
GRANT ALL ON ALL SEQUENCES IN SCHEMA "ingest" TO "app_backend";
ALTER DEFAULT PRIVILEGES IN SCHEMA "ingest" GRANT ALL ON TABLES TO "service_role";
ALTER DEFAULT PRIVILEGES IN SCHEMA "ingest" GRANT ALL ON TABLES TO "app_backend";
ALTER DEFAULT PRIVILEGES IN SCHEMA "ingest" GRANT ALL ON SEQUENCES TO "app_backend";
