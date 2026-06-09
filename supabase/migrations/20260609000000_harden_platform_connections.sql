-- =====================================================================
-- Harden site.platform_connections — RLS + column defaults
-- =====================================================================
-- This table was reconstructed into the repo from the live dev DB
-- (see 20260602150238_create_platform_connections.sql) and never received
-- the hardening every other site.* table carries:
--   * RLS               (CONS-13) — defense-in-depth behind the policy layer
--   * id default        (CONS-29) — raw SQL / admin inserts that bypass Eloquent
--   * timestamp defaults (CONS-34) — same
--
-- All three are metadata-only catalog changes (no row scan, no table
-- rewrite), so a single transaction is safe — none of the CONVENTIONS.md
-- hot-path patterns (CONCURRENTLY / NOT VALID / backfill) apply here.

BEGIN;

-- CONS-29 + CONS-34 — DB-side defaults for inserts that bypass Eloquent.
-- SET DEFAULT is catalog-only; existing rows are untouched.
ALTER TABLE site.platform_connections
    ALTER COLUMN id         SET DEFAULT gen_random_uuid(),
    ALTER COLUMN created_at SET DEFAULT now(),
    ALTER COLUMN updated_at SET DEFAULT now();

-- CONS-13 — the deadbolt behind the IntegrationConnectionPolicy keypad.
-- app_backend (the sole accessor) already has BYPASSRLS, so the app path is
-- unaffected; enabling RLS flips every OTHER role to default-deny, closing
-- the direct-Supabase-Studio / raw-query read gap. Minimal by design: this
-- is a backend-only table, so no authenticated/anon SELECT policy is granted
-- (unlike enquiries/sites, which front direct reads).
ALTER TABLE site.platform_connections ENABLE ROW LEVEL SECURITY;

CREATE POLICY platform_connections_app_backend_all
    ON site.platform_connections
    FOR ALL TO app_backend
    USING (true) WITH CHECK (true);

COMMIT;
