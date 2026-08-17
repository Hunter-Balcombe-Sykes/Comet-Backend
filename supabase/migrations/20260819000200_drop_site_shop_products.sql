-- 20260819000200_drop_site_shop_products.sql
--
-- The child goes first: site.shop_products.brand_id REFERENCES
-- site.shop_brands(id) ON DELETE CASCADE, so dropping the parent first would
-- either take this table with it (silently, via CASCADE) or fail. Explicit
-- ordering across two files makes the dependency visible in the migration
-- history rather than implicit in a CASCADE.
--
-- Nothing has WRITTEN this table since slice 5a; the shop re-home removed the
-- last reads (Task 2) and the last explicit child deletes with them. Its data
-- lives in content.items + content.collection_items, reached through
-- ShopContentWriter::syncStore().
--
-- pg_depend, re-checked against dev immediately before this file was applied:
-- no inbound foreign keys, no views, no materialised views, no triggers, and
-- no function bodies referencing it. The only dependents are its own index
-- (idx_shop_products_brand), its TOAST table, and its own RLS policy
-- (shop_products_app_backend_all) — all of which drop with it. So no CASCADE
-- is needed and none is used: a bare DROP that would FAIL on an unexpected
-- dependent is the point. CASCADE here would silently take something with it.
--
-- BACKUP: taken and proven restorable before this ran — see the checkpoint.
--
-- ROLLBACK: there is no in-place rollback for a DROP. Restore from the
-- pre-DROP dump (scripts/db/backup-to-r2.sh, bucket partna-db-backups) into a
-- scratch schema and copy the rows back. Production is NOT affected by this
-- migration band and still carries both tables.
BEGIN;

DROP TABLE IF EXISTS site.shop_products;

COMMIT;
