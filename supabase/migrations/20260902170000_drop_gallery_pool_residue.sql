-- Wave 6 tail (2026-09-02): drop the two DB objects the POOL_GALLERY
-- retirement left behind.
--
-- The app-side teardown finished in d68279aa5 — SiteMedia::POOL_GALLERY is
-- deleted, GALLERY_POOLS is [POOL_CONTENT], the /gallery routes and controller
-- are gone, and migration 20260901200000 emptied the lane (dev: 0 rows in pool
-- 'gallery', verified 2026-09-02). These two objects survived because that
-- migration deliberately deferred them: "drop the CHECK value with that
-- teardown, not here". This is that teardown.
--
-- 1. core.enforce_site_gallery_max6 — the 6-image-per-site cap. Its first
--    statement is `if new.pool is distinct from 'gallery' then return new;`,
--    so it has been a no-op on every write since the backfill. Dropping the
--    trigger and its function together; nothing else calls the function.
--    tests/Postgres/GalleryMax6TriggerTest.php is deleted with its subject.
--
-- 2. site_media_pool_check — recreated without two unreachable values:
--      * 'gallery' — retired above; 0 rows.
--      * 'product' — dead since the shop re-home (site.shop_products dropped
--        2026-08-17). 0 rows EVER on dev, and no writer anywhere in app/,
--        scripts/, tests/ or database/ (grepped 2026-09-02). Removed in the
--        same pass because it is the same class of unreachable enum value.
--    Surviving values are exactly the three SiteMedia POOL_* constants.
--
-- PRE-APPLY CHECK (re-run per environment — prod's schema has diverged and is
-- NOT covered by the dev verification above):
--   SELECT DISTINCT pool FROM site.site_media;
--   -- must return only: content, design, documents
--
-- ROLLBACK: re-add the two values to the CHECK and recreate the trigger from
--           20260726000000_baseline_pilot.sql lines 196-235 + 3460. No data is
--           touched by this file, so a rollback is lossless.
--
-- The CHECK is replaced via the CONVENTIONS.md §2 two-step (NOT VALID, then
-- VALIDATE in its own transaction). site_media is small — 902 rows on dev —
-- so the one-shot form would have been imperceptible here, but the rule is
-- blanket for a reason: prod's row count is not dev's, and the guard
-- (scripts/guard-no-unsafe-migrations.php Checks 3 and 8) is what keeps the
-- exception from being argued case by case.

SET lock_timeout      = '2s';
SET statement_timeout = '30s';

DROP TRIGGER IF EXISTS "enforce_site_gallery_max6" ON "site"."site_media";
DROP FUNCTION IF EXISTS "core"."enforce_site_gallery_max6"();

ALTER TABLE "site"."site_media" DROP CONSTRAINT IF EXISTS "site_media_pool_check";

-- Step A — catalog write only; the existing-row scan is deferred.
BEGIN;
ALTER TABLE "site"."site_media"
    ADD CONSTRAINT "site_media_pool_check"
    CHECK (("pool")::text = ANY ((ARRAY['content'::character varying, 'design'::character varying, 'documents'::character varying])::text[])) NOT VALID;
COMMIT;

-- Step B — separate transaction: SHARE UPDATE EXCLUSIVE, concurrent writes keep running.
BEGIN;
ALTER TABLE "site"."site_media" VALIDATE CONSTRAINT "site_media_pool_check";
COMMIT;
