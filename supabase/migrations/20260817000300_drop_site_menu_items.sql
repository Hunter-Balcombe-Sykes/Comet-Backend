-- Slice 7 Phase 6 — legacy teardown, 3 of 4 menu tables.
--
-- site.menu_items is the dish. Its content.* twin is content.items kind
-- 'menu_item', coord manual:menu:{menu_id}:{sha1(normalised name)}
-- (MenuProjectionMapper::coordFor), written by MenuContentController's ten
-- owner verbs, MenuFetchJob::persist() and MenuScanApplier since Phase 2.
--
-- ACCEPTED LOSS, owner ruling 2026-08-17: 10 dishes on `ollies` scraped at
-- 2026-08-16 23:03:42+00 exist only here and are destroyed by this DROP. They
-- postdate the last content:backfill-menus run, and the backfiller — which read
-- this table — retires in the same change.
--
-- No IF EXISTS — fail loudly on schema drift rather than silently skipping.

BEGIN;

DROP TABLE site.menu_items;

COMMIT;
