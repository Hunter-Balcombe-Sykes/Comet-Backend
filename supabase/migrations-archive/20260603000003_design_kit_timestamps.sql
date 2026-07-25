-- =====================================================================
-- Add created_at / updated_at columns + update trigger to site.design_kits
-- =====================================================================
-- site.design_kits is the only site-schema table without standard timestamps.
-- writeDesignKit() uses raw DB::table() queries (not Eloquent), so the ORM
-- never sets updated_at automatically — a DB-level trigger is required.
--
-- Strategy:
--   1. ADD COLUMN IF NOT EXISTS for both timestamp cols (idempotent).
--   2. Backfill from the parent site.sites row so existing kits pick up a
--      meaningful timestamp rather than all landing at migration time.
--   3. Reuse the existing public.set_updated_at() trigger function (defined
--      in the baseline migration 20260526000000) — no need to define a new
--      function. The naming convention follows other site-schema triggers:
--      set_timestamp_<table_name>.
-- =====================================================================

BEGIN;

-- 1. Add timestamp columns — IF NOT EXISTS makes this re-run safe.
ALTER TABLE site.design_kits
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT now();

-- 2. Backfill timestamps from the parent site. Any kit whose site has been
--    deleted (orphan via CASCADE) simply won't match and is left with the
--    migration-time default — harmless.
UPDATE site.design_kits dk
SET created_at = s.created_at,
    updated_at = s.updated_at
FROM site.sites s
WHERE s.id = dk.site_id;

-- 3. Add a BEFORE UPDATE trigger that sets updated_at = now() on every row
--    update. DROP TRIGGER IF EXISTS + CREATE TRIGGER is the idempotent
--    pattern used across this codebase (see 20260527070000 for the same
--    pattern on trg_create_empty_design_kit).
DROP TRIGGER IF EXISTS set_timestamp_design_kits ON site.design_kits;
CREATE TRIGGER set_timestamp_design_kits
    BEFORE UPDATE ON site.design_kits
    FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

COMMIT;
