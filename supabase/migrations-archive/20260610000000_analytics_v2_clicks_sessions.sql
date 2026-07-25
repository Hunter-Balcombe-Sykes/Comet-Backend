-- Analytics v2 for the skeleton-era sitepages.
--
-- The legacy click pipeline was keyed to site.blocks ("link blocks") — the old
-- link-in-bio model. Skeleton sitepages have no blocks: a click is an outbound
-- anchor identified by destination URL plus structured labels captured at the
-- anchor (platform slug, product id/title, owning section). This migration:
--
--   1. link_clicks — link_block_id becomes NULLABLE (legacy rows keep theirs);
--      adds url/platform/product/section/label plus geo+device columns so click
--      rows are self-describing without a blocks join.
--   2. site_visits — region_code (ISO-3166-2 suffix, e.g. NSW/VIC) for
--      state-level geo. Populated from the Cloudflare request context that the
--      partna-pages middleware forwards as X-Visitor-Region.
--   3. NEW analytics.site_sessions — one row per visitor session, UPSERTed by
--      the /public/analytics/ping beacon. PK is the client-minted session UUID;
--      writes use GREATEST() so at-least-once queue retries are idempotent.
--      last_seen_at powers "live now"; duration_seconds powers avg session time.

BEGIN;

-- 1. link_clicks v2 ---------------------------------------------------------

ALTER TABLE analytics.link_clicks ALTER COLUMN link_block_id DROP NOT NULL;

ALTER TABLE analytics.link_clicks
    ADD COLUMN IF NOT EXISTS url text,
    ADD COLUMN IF NOT EXISTS platform text,
    ADD COLUMN IF NOT EXISTS product_id text,
    ADD COLUMN IF NOT EXISTS product_title text,
    ADD COLUMN IF NOT EXISTS section_key text,
    ADD COLUMN IF NOT EXISTS label text,
    ADD COLUMN IF NOT EXISTS country_code text,
    ADD COLUMN IF NOT EXISTS region_code text,
    ADD COLUMN IF NOT EXISTS device_type text;

-- Partial indexes for the new platform/product groupings live in the +1 file
-- (20260610000001) — CONCURRENTLY, outside a transaction, per CONVENTIONS §1.

-- 2. site_visits region -----------------------------------------------------

ALTER TABLE analytics.site_visits
    ADD COLUMN IF NOT EXISTS region_code text;

-- 3. site_sessions ----------------------------------------------------------

CREATE TABLE IF NOT EXISTS analytics.site_sessions (
    id uuid NOT NULL,
    user_id uuid NOT NULL,
    site_id uuid NOT NULL,
    visitor_id uuid,
    started_at timestamptz NOT NULL DEFAULT now(),
    last_seen_at timestamptz NOT NULL DEFAULT now(),
    -- Cumulative visible-time reported by the client; GREATEST() on upsert.
    -- Capped at 24h so a stuck client can't manufacture absurd averages.
    duration_seconds integer NOT NULL DEFAULT 0,
    country_code text,
    region_code text,
    device_type text,
    referrer text,
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT site_sessions_pkey PRIMARY KEY (id),
    CONSTRAINT site_sessions_user_fk FOREIGN KEY (user_id) REFERENCES core.users(id) ON DELETE CASCADE,
    CONSTRAINT site_sessions_site_fk FOREIGN KEY (site_id) REFERENCES site.sites(id) ON DELETE CASCADE,
    CONSTRAINT site_sessions_duration_check CHECK (duration_seconds >= 0 AND duration_seconds <= 86400)
);

ALTER TABLE analytics.site_sessions OWNER TO postgres;

-- "live now" = COUNT(*) WHERE last_seen_at > now() - interval; range scans on
-- (user, last_seen_at) and (user, started_at) cover both dashboard reads.
CREATE INDEX IF NOT EXISTS site_sessions_user_last_seen_idx
    ON analytics.site_sessions (user_id, last_seen_at DESC);
CREATE INDEX IF NOT EXISTS site_sessions_user_started_idx
    ON analytics.site_sessions (user_id, started_at DESC);

-- RLS parity with sibling analytics tables (owner-read via dashboard role,
-- app_backend holds BYPASSRLS — baseline decision 16).
ALTER TABLE analytics.site_sessions ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS site_sessions_owner_select ON analytics.site_sessions;
CREATE POLICY site_sessions_owner_select ON analytics.site_sessions
    FOR SELECT TO authenticated
    USING (user_id = (SELECT id FROM core.users WHERE auth_user_id = auth.uid() AND deleted_at IS NULL));

-- Baseline's GRANT ... ON ALL TABLES predates this table; grant explicitly.
-- Sessions are mutable by design (ping upserts), unlike the event tables.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON analytics.site_sessions TO app_backend';
    END IF;
END $$;

GRANT USAGE ON SCHEMA analytics TO service_role;

COMMIT;
