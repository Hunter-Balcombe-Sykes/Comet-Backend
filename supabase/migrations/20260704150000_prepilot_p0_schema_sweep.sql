-- =====================================================================
-- Pre-pilot P0 schema sweep — FOUND-34, FOUND-35, FOUND-37
-- =====================================================================
-- Bundles three schema-shape fixes from the 2026-07-04 foundational audit:
--   FOUND-34: resource_kind discriminator on site.platform_connections
--             (replaces str_starts_with(resource_id, 'event-'/'link-') reads)
--   FOUND-35: handle column on site.blocks (mirrors the platform/category
--             promotion in 20260701170000 — expand-only, settings.handle
--             dual-write continues)
--   FOUND-37: drop core.users.about — dead JSONB column; credentials/
--             experience already live in child tables (20260701150100)
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

-- ── FOUND-37 ─────────────────────────────────────────────────────────
-- Drops users_about_is_object CHECK automatically (column-scoped constraint).
ALTER TABLE core.users DROP COLUMN IF EXISTS about;

COMMIT;

-- ROLLBACK:
-- BEGIN;
-- ALTER TABLE core.users ADD COLUMN about jsonb NOT NULL DEFAULT '{}'::jsonb;
-- ALTER TABLE core.users ADD CONSTRAINT users_about_is_object CHECK (jsonb_typeof(about) = 'object');
-- ALTER TABLE site.blocks DROP COLUMN IF EXISTS handle;
-- ALTER TABLE site.platform_connections DROP CONSTRAINT IF EXISTS platform_connections_resource_kind_check;
-- ALTER TABLE site.platform_connections DROP COLUMN IF EXISTS resource_kind;
-- COMMIT;
