-- Routing schema (plan §2/§3): the observe → project → place → reconcile
-- pipeline's durable state. Deliberately NOT the router's read path — the
-- router reads the compiled artefact; these tables record what was observed,
-- what was decided, and what a user asked us to stop doing.

CREATE SCHEMA IF NOT EXISTS "routing";

-- ── Observations: append-only, month-partitioned, evidence pruned at 180d ────
-- One row per link the system was asked to make sense of. Never updated.
CREATE TABLE "routing"."link_observations" (
    "id" uuid NOT NULL DEFAULT gen_random_uuid(),
    "user_id" uuid,                       -- NULL for pre-account/staff builds
    "observed_at" timestamp with time zone NOT NULL DEFAULT now(),
    "source" text NOT NULL
        CHECK ("source" IN ('paste', 'website_import', 'link_in_bio', 'bio_harvest', 'google_business', 'staff', 'reproject')),
    "import_run_id" uuid,
    "raw_url" text NOT NULL,
    "canonical_url" text,
    "registrable_key" text,
    -- Bounded operational envelope (C1): the projector's evidence inputs, not
    -- content. Shape validated in code by the Evidence value object.
    "evidence" jsonb NOT NULL DEFAULT '{}'::jsonb,
    "detector_id" text,
    "surface_key" text,
    "confidence" smallint CHECK ("confidence" IS NULL OR ("confidence" BETWEEN 0 AND 100)),
    "margin" smallint,
    "verdict" text
        CHECK ("verdict" IS NULL OR "verdict" IN ('place', 'choose', 'note', 'hold', 'reject')),
    "block_reason" text,
    "catalog_digest" text,
    PRIMARY KEY ("id", "observed_at")
) PARTITION BY RANGE ("observed_at");

CREATE INDEX "idx_link_observations_user" ON "routing"."link_observations" ("user_id", "observed_at" DESC);
CREATE INDEX "idx_link_observations_registrable" ON "routing"."link_observations" ("registrable_key", "observed_at" DESC);
CREATE INDEX "idx_link_observations_detector" ON "routing"."link_observations" ("detector_id", "observed_at" DESC);

-- Seed partitions: current month + the next two. `routing:partitions` (daily)
-- keeps a 3-month runway ahead and detaches+drops beyond retention.
CREATE TABLE "routing"."link_observations_2026_07" PARTITION OF "routing"."link_observations"
    FOR VALUES FROM ('2026-07-01 00:00:00+00') TO ('2026-08-01 00:00:00+00');
CREATE TABLE "routing"."link_observations_2026_08" PARTITION OF "routing"."link_observations"
    FOR VALUES FROM ('2026-08-01 00:00:00+00') TO ('2026-09-01 00:00:00+00');
CREATE TABLE "routing"."link_observations_2026_09" PARTITION OF "routing"."link_observations"
    FOR VALUES FROM ('2026-09-01 00:00:00+00') TO ('2026-10-01 00:00:00+00');

-- ── Source intents: the desired-state reconciler's ledger ───────────────────
-- One row per (user, surface, identifier) the system wants to exist. The
-- reconciler moves rows between states; connections are the projection of
-- applied intents. 5 states, 9 block reasons (plan §2).
CREATE TABLE "routing"."source_intents" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "user_id" uuid NOT NULL REFERENCES "core"."users" ("id") ON DELETE CASCADE,
    "surface_key" text NOT NULL,
    "routing_class" text NOT NULL,
    "identifier" text NOT NULL,
    "canonical_url" text,
    "state" text NOT NULL DEFAULT 'proposed'
        CHECK ("state" IN ('proposed', 'applied', 'blocked', 'dismissed', 'superseded')),
    "block_reason" text
        CHECK ("block_reason" IS NULL OR "block_reason" IN (
            'gate', 'capability', 'conflict', 'cap_reached', 'below_threshold',
            'tombstoned', 'unservable', 'invalid_identifier', 'duplicate'
        )),
    "conflicting_connection_id" uuid,
    "connection_id" uuid,
    "confidence" smallint CHECK ("confidence" IS NULL OR ("confidence" BETWEEN 0 AND 100)),
    "origin" text NOT NULL
        CHECK ("origin" IN ('paste', 'website_import', 'link_in_bio', 'bio_harvest', 'google_business', 'staff', 'reproject')),
    "import_run_id" uuid,
    "detector_id" text,
    "catalog_digest" text,
    "first_seen_at" timestamp with time zone NOT NULL DEFAULT now(),
    "resolved_at" timestamp with time zone,
    "created_at" timestamp with time zone NOT NULL DEFAULT now(),
    "updated_at" timestamp with time zone NOT NULL DEFAULT now()
);

-- One live intent per (user, surface, identifier); dismissed/superseded rows
-- stay for provenance but leave the slot free.
CREATE UNIQUE INDEX "idx_source_intents_live"
    ON "routing"."source_intents" ("user_id", "surface_key", "identifier")
    WHERE ("state" IN ('proposed', 'applied', 'blocked'));
CREATE INDEX "idx_source_intents_inbox"
    ON "routing"."source_intents" ("user_id", "state", "first_seen_at" DESC);
-- Staff stuck-intents view feed: anything proposed/blocked for a long time.
CREATE INDEX "idx_source_intents_stuck"
    ON "routing"."source_intents" ("state", "first_seen_at")
    WHERE ("state" IN ('proposed', 'blocked'));

-- ── Item tombstones: a user's "never again", honoured across re-imports ─────
CREATE TABLE "routing"."item_tombstones" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "user_id" uuid NOT NULL REFERENCES "core"."users" ("id") ON DELETE CASCADE,
    "source_ref" text NOT NULL,           -- canonical url or surface:identifier
    "scope" text NOT NULL DEFAULT 'this_source'
        CHECK ("scope" IN ('this_source', 'any_source')),
    "reason" text,
    "created_at" timestamp with time zone NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX "idx_item_tombstones_unique"
    ON "routing"."item_tombstones" ("user_id", "source_ref", "scope");

-- ── Import runs: one-shot acquisitions (plan §3) ────────────────────────────
CREATE TABLE "routing"."import_runs" (
    "id" uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    "user_id" uuid NOT NULL REFERENCES "core"."users" ("id") ON DELETE CASCADE,
    "kind" text NOT NULL
        CHECK ("kind" IN ('website', 'link_in_bio', 'bio_harvest', 'menu_scan')),
    "source_url" text,
    "started_at" timestamp with time zone NOT NULL DEFAULT now(),
    "finished_at" timestamp with time zone,
    "outcome" text
        CHECK ("outcome" IS NULL OR "outcome" IN ('ok', 'partial', 'unavailable', 'error', 'cooldown', 'budget')),
    "observations_count" integer NOT NULL DEFAULT 0,
    "intents_count" integer NOT NULL DEFAULT 0,
    "items_count" integer NOT NULL DEFAULT 0,
    "error_class" text,
    -- Bounded operational envelope: per-stage counters/timings for the run card.
    "detail" jsonb NOT NULL DEFAULT '{}'::jsonb,
    "created_at" timestamp with time zone NOT NULL DEFAULT now()
);

CREATE INDEX "idx_import_runs_user" ON "routing"."import_runs" ("user_id", "started_at" DESC);
-- Per-user daily cooldown lookups (importers can trigger paid effects).
CREATE INDEX "idx_import_runs_cooldown" ON "routing"."import_runs" ("user_id", "kind", "started_at" DESC);

-- ── RLS + grants (mirrors the baseline's app_backend pattern) ───────────────

ALTER TABLE "routing"."link_observations" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "routing"."link_observations" FORCE ROW LEVEL SECURITY;
ALTER TABLE "routing"."source_intents" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "routing"."source_intents" FORCE ROW LEVEL SECURITY;
ALTER TABLE "routing"."item_tombstones" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "routing"."item_tombstones" FORCE ROW LEVEL SECURITY;
ALTER TABLE "routing"."import_runs" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "routing"."import_runs" FORCE ROW LEVEL SECURITY;

CREATE POLICY "link_observations_app_backend_all" ON "routing"."link_observations" TO "app_backend" USING (true) WITH CHECK (true);
CREATE POLICY "source_intents_app_backend_all" ON "routing"."source_intents" TO "app_backend" USING (true) WITH CHECK (true);
CREATE POLICY "item_tombstones_app_backend_all" ON "routing"."item_tombstones" TO "app_backend" USING (true) WITH CHECK (true);
CREATE POLICY "import_runs_app_backend_all" ON "routing"."import_runs" TO "app_backend" USING (true) WITH CHECK (true);

GRANT USAGE ON SCHEMA "routing" TO "service_role";
GRANT USAGE ON SCHEMA "routing" TO "app_backend";
GRANT ALL ON ALL TABLES IN SCHEMA "routing" TO "service_role";
GRANT ALL ON ALL TABLES IN SCHEMA "routing" TO "app_backend";
ALTER DEFAULT PRIVILEGES IN SCHEMA "routing" GRANT ALL ON TABLES TO "service_role";
ALTER DEFAULT PRIVILEGES IN SCHEMA "routing" GRANT ALL ON TABLES TO "app_backend";
