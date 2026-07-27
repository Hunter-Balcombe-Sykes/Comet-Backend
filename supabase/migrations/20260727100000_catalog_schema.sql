-- Catalog schema — the compiled platform catalog's DB projection (plan §1).
-- Written ONLY by `php artisan catalog:sync` (upsert + tombstone; nothing is
-- ever TRUNCATEd or DELETEd) plus the runtime tables at the bottom, which the
-- compiler/sync never touch. The router reads the opcache artefact, never
-- these tables — they exist for FKs, analytics joins, and the dashboard API.

CREATE SCHEMA IF NOT EXISTS "catalog";

CREATE TABLE "catalog"."brands" (
    "key" text PRIMARY KEY,
    "display_name" text NOT NULL,
    "homepage" text,
    "lifecycle" text NOT NULL DEFAULT 'active'
        CHECK ("lifecycle" IN ('active', 'hidden', 'sunset', 'retired')),
    "successor_key" text REFERENCES "catalog"."brands" ("key"),
    "last_synced_digest" text NOT NULL,
    "tombstoned_at" timestamp with time zone,
    "created_at" timestamp with time zone NOT NULL DEFAULT now(),
    "updated_at" timestamp with time zone NOT NULL DEFAULT now()
);

CREATE TABLE "catalog"."surfaces" (
    "key" text PRIMARY KEY,
    "brand_key" text NOT NULL REFERENCES "catalog"."brands" ("key"),
    "display_name" text NOT NULL,
    "routing_class" text NOT NULL
        CHECK ("routing_class" IN ('social', 'content', 'events', 'shop', 'booking', 'reservations', 'ordering', 'link', 'ignore')),
    "shelf" text NOT NULL,
    "identifier_kind" text NOT NULL,
    "refresh_interval_secs" integer NOT NULL DEFAULT 0,
    -- Capability NAMES (never class names) — one column per role, per the
    -- platform-identity ruling. Absent fetch capability = link-only surface.
    "connect_capability" text,
    "connect_fetch_capability" text,
    "fetch_capability" text,
    "normalize_capability" text,
    "highlights_capability" text,
    "gate_capability" text,
    "completeness_capability" text,
    "canonical_url_template" text,
    "default_sections" jsonb NOT NULL DEFAULT '{}'::jsonb,
    "is_connectable" boolean NOT NULL DEFAULT true,
    "is_multi_account" boolean NOT NULL DEFAULT false,
    "max_accounts" integer NOT NULL DEFAULT 1,
    "supports_incremental" boolean NOT NULL DEFAULT false,
    "lifecycle" text NOT NULL DEFAULT 'active'
        CHECK ("lifecycle" IN ('active', 'hidden', 'sunset', 'retired')),
    "embed" jsonb,
    "reserved_paths" jsonb NOT NULL DEFAULT '[]'::jsonb,
    "note" text,
    "last_synced_digest" text NOT NULL,
    "tombstoned_at" timestamp with time zone,
    "created_at" timestamp with time zone NOT NULL DEFAULT now(),
    "updated_at" timestamp with time zone NOT NULL DEFAULT now()
);

CREATE INDEX "idx_catalog_surfaces_brand" ON "catalog"."surfaces" ("brand_key") WHERE "tombstoned_at" IS NULL;

CREATE TABLE "catalog"."detectors" (
    -- Deterministic 16-hex id computed from the detector's semantic fields —
    -- stable across compiles when the rule is unchanged.
    "id" text PRIMARY KEY,
    "surface_key" text REFERENCES "catalog"."surfaces" ("key"),
    "signal_key" text,
    "evidence" text NOT NULL,
    "registrable_key" text,
    "subdomain_pattern" text,
    "path_pattern" text,
    "query_requires" jsonb NOT NULL DEFAULT '[]'::jsonb,
    "fingerprint" text,
    "identifier_capture" text,
    "identifier_source" text NOT NULL DEFAULT 'none',
    "probe_capability" text,
    "strength" integer NOT NULL,
    "reject_patterns" jsonb NOT NULL DEFAULT '[]'::jsonb,
    "markets" jsonb NOT NULL DEFAULT '[]'::jsonb,
    "note" text,
    -- Compiled total-order tie-breaker; populated when the P2 rulepack lands.
    "specificity" integer,
    "last_synced_digest" text NOT NULL,
    "tombstoned_at" timestamp with time zone,
    "created_at" timestamp with time zone NOT NULL DEFAULT now(),
    "updated_at" timestamp with time zone NOT NULL DEFAULT now(),
    -- A detector claims a surface XOR names a pure signal — never both/neither.
    CONSTRAINT "detectors_surface_xor_signal" CHECK (("surface_key" IS NULL) <> ("signal_key" IS NULL))
);

CREATE INDEX "idx_catalog_detectors_registrable" ON "catalog"."detectors" ("registrable_key") WHERE "tombstoned_at" IS NULL;
CREATE INDEX "idx_catalog_detectors_surface" ON "catalog"."detectors" ("surface_key") WHERE "tombstoned_at" IS NULL;

-- Host aliases (youtu.be → youtube.com and friends) — alias host to the
-- registrable key whose detectors should evaluate.
CREATE TABLE "catalog"."host_aliases" (
    "alias_host" text PRIMARY KEY,
    "registrable_key" text NOT NULL,
    "note" text,
    "last_synced_digest" text NOT NULL,
    "tombstoned_at" timestamp with time zone,
    "created_at" timestamp with time zone NOT NULL DEFAULT now(),
    "updated_at" timestamp with time zone NOT NULL DEFAULT now()
);

-- PSL suffix overrides: multi-tenant platform suffixes (myshopify.com,
-- square.site, …) where the tenant label — not the eTLD+1 — is the identity.
CREATE TABLE "catalog"."suffix_overrides" (
    "suffix" text PRIMARY KEY,
    "note" text,
    "last_synced_digest" text NOT NULL,
    "tombstoned_at" timestamp with time zone,
    "created_at" timestamp with time zone NOT NULL DEFAULT now(),
    "updated_at" timestamp with time zone NOT NULL DEFAULT now()
);

-- Single-row sync bookkeeping: lets catalog:sync no-op instantly when the
-- compiled digest already landed.
CREATE TABLE "catalog"."sync_state" (
    "id" smallint PRIMARY KEY DEFAULT 1 CHECK ("id" = 1),
    "digest" text NOT NULL,
    "synced_at" timestamp with time zone NOT NULL DEFAULT now()
);

-- ── Runtime tables. catalog:compile / catalog:sync NEVER touch these. ────────

-- Staff kill-switch per detector. Deliberately NO FK to catalog.detectors:
-- a suspension must survive the detector being recompiled/tombstoned.
CREATE TABLE "catalog"."detector_suspensions" (
    "detector_id" text PRIMARY KEY,
    "reason" text NOT NULL,
    "set_by" text,
    "set_at" timestamp with time zone NOT NULL DEFAULT now(),
    "expires_at" timestamp with time zone NOT NULL
);

-- Rot/triage queue: domains observed with no (or failing) detector coverage.
-- Privacy: registrable key + masked path shape ONLY — never full paths or
-- query strings.
CREATE TABLE "catalog"."unmatched_domains" (
    "registrable_key" text PRIMARY KEY,
    "sample_path_shape" text,
    "hits" integer NOT NULL DEFAULT 1,
    "has_detectors" boolean NOT NULL DEFAULT false,
    "first_seen_at" timestamp with time zone NOT NULL DEFAULT now(),
    "last_seen_at" timestamp with time zone NOT NULL DEFAULT now(),
    "triaged_at" timestamp with time zone
);

-- ── RLS + grants (mirrors the baseline's app_backend pattern). ───────────────

ALTER TABLE "catalog"."brands" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "catalog"."brands" FORCE ROW LEVEL SECURITY;
ALTER TABLE "catalog"."surfaces" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "catalog"."surfaces" FORCE ROW LEVEL SECURITY;
ALTER TABLE "catalog"."detectors" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "catalog"."detectors" FORCE ROW LEVEL SECURITY;
ALTER TABLE "catalog"."host_aliases" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "catalog"."host_aliases" FORCE ROW LEVEL SECURITY;
ALTER TABLE "catalog"."suffix_overrides" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "catalog"."suffix_overrides" FORCE ROW LEVEL SECURITY;
ALTER TABLE "catalog"."sync_state" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "catalog"."sync_state" FORCE ROW LEVEL SECURITY;
ALTER TABLE "catalog"."detector_suspensions" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "catalog"."detector_suspensions" FORCE ROW LEVEL SECURITY;
ALTER TABLE "catalog"."unmatched_domains" ENABLE ROW LEVEL SECURITY;
ALTER TABLE "catalog"."unmatched_domains" FORCE ROW LEVEL SECURITY;

CREATE POLICY "catalog_brands_app_backend_all" ON "catalog"."brands" TO "app_backend" USING (true) WITH CHECK (true);
CREATE POLICY "catalog_surfaces_app_backend_all" ON "catalog"."surfaces" TO "app_backend" USING (true) WITH CHECK (true);
CREATE POLICY "catalog_detectors_app_backend_all" ON "catalog"."detectors" TO "app_backend" USING (true) WITH CHECK (true);
CREATE POLICY "catalog_host_aliases_app_backend_all" ON "catalog"."host_aliases" TO "app_backend" USING (true) WITH CHECK (true);
CREATE POLICY "catalog_suffix_overrides_app_backend_all" ON "catalog"."suffix_overrides" TO "app_backend" USING (true) WITH CHECK (true);
CREATE POLICY "catalog_sync_state_app_backend_all" ON "catalog"."sync_state" TO "app_backend" USING (true) WITH CHECK (true);
CREATE POLICY "catalog_detector_suspensions_app_backend_all" ON "catalog"."detector_suspensions" TO "app_backend" USING (true) WITH CHECK (true);
CREATE POLICY "catalog_unmatched_domains_app_backend_all" ON "catalog"."unmatched_domains" TO "app_backend" USING (true) WITH CHECK (true);

GRANT USAGE ON SCHEMA "catalog" TO "service_role";
GRANT USAGE ON SCHEMA "catalog" TO "app_backend";
GRANT ALL ON ALL TABLES IN SCHEMA "catalog" TO "service_role";
GRANT ALL ON ALL TABLES IN SCHEMA "catalog" TO "app_backend";
ALTER DEFAULT PRIVILEGES IN SCHEMA "catalog" GRANT ALL ON TABLES TO "service_role";
ALTER DEFAULT PRIVILEGES IN SCHEMA "catalog" GRANT ALL ON TABLES TO "app_backend";
