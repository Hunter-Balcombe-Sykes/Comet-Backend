-- 20260819000210_drop_site_shop_brands.sql
--
-- The parent, after its child (20260819000200). This is the last table of the
-- shop re-home, and the one slice 7 deferred: its DROP was originally numbered
-- 20260817000900, that file was never written, and the deferral is what this
-- project exists to discharge.
--
-- Unlike site.shop_products, this table was a LIVE WRITE TARGET until the
-- re-home's Task 7. Every write path — addBrand, updateBrand, setProducts,
-- removeBrand, forget, addProduct, removeProduct, both async jobs, the
-- pre-account seeding lane — now goes to content.collections +
-- content.storefronts through ShopContentWriter. Store identity is enforced
-- there by storefronts_user_provider_ref_uq (20260819000110), which is what
-- makes upsertStore() safe as the sole writer.
--
-- pg_depend, re-checked against dev immediately before this file was applied:
-- no inbound foreign keys (shop_products, its only one, is already gone), no
-- views, no materialised views, no triggers, no function bodies. The only
-- dependents are its own index (idx_shop_brands_connection), its TOAST table
-- and its own RLS policy (shop_brands_app_backend_all).
--
-- No CASCADE, deliberately — see the sibling file. A bare DROP that FAILS on an
-- unexpected dependent is the safety property being bought here.
--
-- BACKUP: taken and proven restorable before this ran — see the checkpoint.
--
-- PRODUCTION IS OUT OF SCOPE and still carries both tables, along with the code
-- that reads them. This band is dev-only.
--
-- ROLLBACK: no in-place rollback for a DROP. Restore from the pre-DROP dump
-- (scripts/db/backup-to-r2.sh, bucket partna-db-backups).
BEGIN;

DROP TABLE IF EXISTS site.shop_brands;

COMMIT;
