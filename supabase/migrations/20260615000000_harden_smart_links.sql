-- 20260615000000_harden_smart_links.sql
--
-- S3 — harden site.smart_links (audit SCHEMA-1/3 + DINT-4/9). The table was
-- created (20260531000000) without the hardening every other site.* tenant
-- table carries: no RLS, no DB-side defaults, no updated_at trigger, no
-- canonical-URL dedup. This brings it to parity, mirroring the
-- platform_connections hardening (20260609000000).
--
-- DDL (RLS + defaults + trigger) lives here inside a transaction; the
-- canonical-URL UNIQUE index is split into 20260615000001 per CONVENTIONS.md §1
-- (CONCURRENTLY can't run inside a transaction).
--
-- DEFERRED to follow-ups (NOT in this migration):
--   * SCHEMA-5 / DINT-5 type/platform CHECK constraints — `platform` is a
--     semi-open field ('generic' fallback + extractor-set values, see
--     SmartLinkResolver), so a CHECK risks breaking link resolution; it needs a
--     confirmed closed enum first. `type` is closed (SmartLinkTypeRegistry) but a
--     CHECK there couples every new type to a migration — decide deliberately.
--   * FORCE ROW LEVEL SECURITY — handled uniformly across tables in S5.

BEGIN;

-- SCHEMA-3: DB-side defaults so raw-SQL / admin inserts that bypass Eloquent get
-- a PK + timestamps. SET DEFAULT is catalog-only; existing rows are untouched.
ALTER TABLE site.smart_links
    ALTER COLUMN id         SET DEFAULT gen_random_uuid(),
    ALTER COLUMN created_at SET DEFAULT now(),
    ALTER COLUMN updated_at SET DEFAULT now();

-- DINT-9: keep updated_at fresh on every UPDATE. The app sets it via Eloquent,
-- but the refresh cron / raw updates should not let it stall. Reuses the shared
-- public.set_updated_at() trigger function (NEW.updated_at = now()).
CREATE OR REPLACE TRIGGER set_timestamp_smart_links
    BEFORE UPDATE ON site.smart_links
    FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- SCHEMA-1: the RLS deadbolt behind the SmartLinkPolicy keypad. app_backend (the
-- sole accessor) has BYPASSRLS, so the app path is unaffected; enabling RLS flips
-- every OTHER role to default-deny, closing the direct-Supabase-Studio / raw-query
-- read gap. Backend-only table (fronted by the cached public payload, not a direct
-- anon/authenticated read), so no anon/authenticated policy — same shape as
-- platform_connections.
ALTER TABLE site.smart_links ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS smart_links_app_backend_all ON site.smart_links;
CREATE POLICY smart_links_app_backend_all
    ON site.smart_links
    FOR ALL TO app_backend
    USING (true) WITH CHECK (true);

COMMIT;
