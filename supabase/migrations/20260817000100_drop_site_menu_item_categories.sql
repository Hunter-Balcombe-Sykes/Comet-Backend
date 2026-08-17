-- Slice 7 Phase 6 — legacy teardown, 1 of 4 menu tables. Children first.
-- ROLLBACK: NONE. A DROP TABLE has no reverse: the pivot rows are gone with
-- the table. The pre-image is the pg_dump taken immediately before this ran,
-- recorded in the slice checkpoint; restore from there, not from a migration.
--
-- site.menu_item_categories is the dish<->category pivot. Its content.* twin is
-- content.collection_items on a `menu_category` collection, live since slice 4
-- and the only lane the dashboard, the scrape and the public pool have read
-- since the Phase 2 cutover.
--
-- The last code references died with this migration's branch: the composer's
-- legacy fallback (MenuPayloadComposer::legacyCategories), MenuBackfiller and
-- MenuItemObserver. Nothing in app/ reads or writes this table.
--
-- No IF EXISTS — fail loudly on schema drift rather than silently skipping.

BEGIN;

DROP TABLE site.menu_item_categories;

COMMIT;
