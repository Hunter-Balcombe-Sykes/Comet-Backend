-- =====================================================================
-- Menu per-platform sync state → site.menu_platform_links (FOUND-2)
-- =====================================================================
-- Replaces the six hardcoded columns on site.menus
--   (uber_eats_store_url / uber_eats_synced_at / uber_eats_status,
--    doordash_store_url / doordash_synced_at / doordash_status)
-- with one row per (menu, platform). Adding a third delivery platform is
-- now a row, not three new columns + five code edits. Each row tracks one
-- platform's scrape: the store URL targeted, when it last synced, and its
-- last per-platform status (independent of the merge outcome).
-- Default-privilege grant in the baseline (ALTER DEFAULT PRIVILEGES IN
-- SCHEMA site, baseline :2303) auto-covers this table, exactly as it did
-- for site.menu_categories / site.menu_items (added in 20260619050000
-- with no explicit GRANT). No RLS (matches the rest of the menu subsystem).
--
-- The CLI already batches a whole file into one implicit transaction, but
-- SET LOCAL only takes effect inside an explicit BEGIN/COMMIT block, and the
-- atomicity of the backfill + DROP COLUMN below shouldn't depend on
-- undocumented CLI internals (audit MIG-2): a mid-run failure between the
-- backfill and the DROP COLUMN would otherwise leave a half-applied schema.

BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '30s';

CREATE TABLE IF NOT EXISTS site.menu_platform_links (
    id         uuid PRIMARY KEY,
    menu_id    uuid NOT NULL REFERENCES site.menus (id) ON DELETE CASCADE,
    platform   text NOT NULL CHECK (platform IN ('uber-eats', 'doordash')),
    store_url  text,
    synced_at  timestamptz,
    status     text CHECK (status IN ('pending', 'ok', 'unavailable')),
    created_at timestamptz,
    updated_at timestamptz,
    UNIQUE (menu_id, platform)
);
CREATE INDEX IF NOT EXISTS idx_menu_platform_links_menu ON site.menu_platform_links (menu_id);

-- Backfill: one row per connected platform (gated on store_url present —
-- a platform with no store URL was never connected). No-op pre-beta (zero
-- rows); correct for prod-shape parity.
INSERT INTO site.menu_platform_links (id, menu_id, platform, store_url, synced_at, status, created_at, updated_at)
SELECT gen_random_uuid(), m.id, 'uber-eats', m.uber_eats_store_url, m.uber_eats_synced_at, m.uber_eats_status, now(), now()
FROM site.menus m
WHERE m.uber_eats_store_url IS NOT NULL;

INSERT INTO site.menu_platform_links (id, menu_id, platform, store_url, synced_at, status, created_at, updated_at)
SELECT gen_random_uuid(), m.id, 'doordash', m.doordash_store_url, m.doordash_synced_at, m.doordash_status, now(), now()
FROM site.menus m
WHERE m.doordash_store_url IS NOT NULL;

ALTER TABLE site.menus
    DROP COLUMN IF EXISTS uber_eats_store_url,
    DROP COLUMN IF EXISTS uber_eats_synced_at,
    DROP COLUMN IF EXISTS uber_eats_status,
    DROP COLUMN IF EXISTS doordash_store_url,
    DROP COLUMN IF EXISTS doordash_synced_at,
    DROP COLUMN IF EXISTS doordash_status;

COMMIT;

-- ROLLBACK:
-- ALTER TABLE site.menus
--     ADD COLUMN IF NOT EXISTS uber_eats_store_url  text,
--     ADD COLUMN IF NOT EXISTS uber_eats_synced_at  timestamptz,
--     ADD COLUMN IF NOT EXISTS uber_eats_status     text
--         CHECK (uber_eats_status IN ('pending', 'ok', 'unavailable')),
--     ADD COLUMN IF NOT EXISTS doordash_store_url   text,
--     ADD COLUMN IF NOT EXISTS doordash_synced_at   timestamptz,
--     ADD COLUMN IF NOT EXISTS doordash_status      text
--         CHECK (doordash_status IN ('pending', 'ok', 'unavailable'));
-- UPDATE site.menus m SET
--     uber_eats_store_url = ue.store_url, uber_eats_synced_at = ue.synced_at, uber_eats_status = ue.status
--     FROM site.menu_platform_links ue WHERE ue.menu_id = m.id AND ue.platform = 'uber-eats';
-- UPDATE site.menus m SET
--     doordash_store_url = dd.store_url, doordash_synced_at = dd.synced_at, doordash_status = dd.status
--     FROM site.menu_platform_links dd WHERE dd.menu_id = m.id AND dd.platform = 'doordash';
-- DROP TABLE IF EXISTS site.menu_platform_links;
