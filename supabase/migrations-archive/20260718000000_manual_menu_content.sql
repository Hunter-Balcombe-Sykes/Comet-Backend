-- Owner-authored (manual) menu content (2026-07-18). Two additive columns that
-- let a user edit / add / delete dishes on their menu independently of the
-- Uber Eats / DoorDash scrape, and have those edits survive every rebuild.
--
-- site.menu_items.is_manual
--   TRUE marks a dish the owner created OR detached from platform sync by
--   editing it (POST/PATCH via MenuContentController). MenuFetchJob's wholesale
--   scrape rebuild NEVER deletes an is_manual row, and a scraped dish that
--   collides (same category + normalized name) with a manual one is skipped —
--   the manual version wins. Scan enrichment (MenuScanApplier) also leaves
--   is_manual rows untouched. Defaults false so every existing scraped/scanned
--   row keeps its current behaviour.
--
-- site.menus.suppressed_items
--   The scraped dishes the owner explicitly deleted, as a JSONB list of
--   {category, name} pairs (category + item name, matched normalized). The
--   scrape rebuild consults this so a deleted scraped dish is NOT resurrected on
--   the next refresh. NULL / absent = nothing suppressed (the common case).
--
-- Down:
--   ALTER TABLE site.menu_items DROP COLUMN IF EXISTS is_manual;
--   ALTER TABLE site.menus DROP COLUMN IF EXISTS suppressed_items;

ALTER TABLE site.menu_items
    ADD COLUMN IF NOT EXISTS is_manual BOOLEAN NOT NULL DEFAULT false;

COMMENT ON COLUMN site.menu_items.is_manual IS
    'TRUE = owner-authored dish (created or edited via the dashboard). Preserved across scrape rebuilds; a colliding scraped dish is skipped in its favour.';

ALTER TABLE site.menus
    ADD COLUMN IF NOT EXISTS suppressed_items JSONB NULL;

COMMENT ON COLUMN site.menus.suppressed_items IS
    'List of {category, name} scraped dishes the owner deleted — the scrape rebuild must not resurrect them. NULL = none suppressed.';
