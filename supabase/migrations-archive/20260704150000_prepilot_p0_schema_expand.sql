-- =====================================================================
-- Pre-pilot P0 schema EXPAND — FOUND-34, FOUND-35
-- =====================================================================
-- The additive (expand) half of the 2026-07-04 foundational P0 sweep. Adds
-- columns the NEW code both reads and writes, so this MUST be applied to dev
-- Supabase BEFORE the code deploys (old code ignores the new NULLABLE columns).
--   FOUND-34: resource_kind discriminator on site.platform_connections
--             (replaces str_starts_with(resource_id, 'event-'/'link-') reads)
--   FOUND-35: handle column on site.blocks (mirrors the platform/category
--             promotion in 20260701170000 — expand-only, settings.handle
--             dual-write continues)
--
-- FOUND-37 (drop core.users.about) is the CONTRACT half — split out into
-- 20260704180000_drop_users_about.sql, applied AFTER the code deploys, because
-- the currently-deployed OLD code still writes `about` via fill(). Bundling the
-- drop here would leave NO single deploy↔apply ordering safe.
--
-- FOUND-36 (MenuSource url filter) and FOUND-49 (booking settings.platform)
-- are documented-defer — no schema change; see one-line code comments at
-- their read sites instead.
-- =====================================================================
BEGIN;

-- ── FOUND-34 ─────────────────────────────────────────────────────────
ALTER TABLE site.platform_connections
    ADD COLUMN IF NOT EXISTS resource_kind text;

UPDATE site.platform_connections
SET resource_kind = 'event'
WHERE resource_id LIKE 'event-%' AND resource_kind IS NULL;

UPDATE site.platform_connections
SET resource_kind = 'link'
WHERE resource_id LIKE 'link-%' AND resource_kind IS NULL;

-- NOT VALID then VALIDATE (not a plain validated CHECK) so adding the constraint
-- never takes a full-table ACCESS EXCLUSIVE lock for the scan — enforced by
-- guard:no-unsafe-migrations. The backfill above already guarantees conformity,
-- so the VALIDATE pass is O(rows) and lock-light.
ALTER TABLE site.platform_connections
    ADD CONSTRAINT platform_connections_resource_kind_check
    CHECK (resource_kind IS NULL OR resource_kind IN ('event', 'link')) NOT VALID;
ALTER TABLE site.platform_connections VALIDATE CONSTRAINT platform_connections_resource_kind_check;

-- ── FOUND-35 ─────────────────────────────────────────────────────────
ALTER TABLE site.blocks
    ADD COLUMN IF NOT EXISTS handle text;

UPDATE site.blocks
SET handle = NULLIF(settings->>'handle', '')
WHERE block_group = 'links' AND handle IS NULL;

COMMIT;

-- ROLLBACK:
-- BEGIN;
-- ALTER TABLE site.blocks DROP COLUMN IF EXISTS handle;
-- ALTER TABLE site.platform_connections DROP CONSTRAINT IF EXISTS platform_connections_resource_kind_check;
-- ALTER TABLE site.platform_connections DROP COLUMN IF EXISTS resource_kind;
-- COMMIT;
