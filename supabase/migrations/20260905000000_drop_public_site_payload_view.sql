-- Drop site.public_site_payload.
--
-- WHY: the view backed GET /api/public/site and /api/public/site-by-slug via
-- SiteCacheService::buildPayloadFromDb(). A four-repo consumer search on
-- 2026-09-04 found no caller for either route, and both were removed from the
-- backend in the same branch. The view now has no reader.
--
-- It also carried two pieces of drift worth recording, because both die here:
--   * `gallery` and `gallery_videos` keys filtered on site_media usage
--     'gallery', retired 2026-09-02. Both had returned '[]' since, while still
--     costing two correlated subqueries per cache miss.
--   * `skeleton_id` aliased site.sites.architecture_id — a third name for a
--     column the canonical wire calls architectureId.
--
-- NOT DROPPED: site.all_site_data is a different view, still read by
-- StaffSiteController through App\Models\Views\AllSiteData. Leave it alone.
--
-- SAFE: dropping a view is catalog-only. No table, column or row is touched.
-- Nothing else in the database depends on it: the pg_depend/pg_rewrite
-- dependency query run against dev on 2026-09-04 returned ZERO rows, i.e. no
-- other view or rule is built on top of this one. (The query is not reproduced
-- here; the result is what this migration relies on.)
--
-- ROLLBACK: the recreate source is
-- supabase/migrations/20260817000000_public_site_payload_services_from_content.sql
-- — the last file that carries the view's definition — but that file is
-- stale against the live schema and will NOT replay as-is: migration
-- 20260904235904 renamed site_media.pool to usage, and Postgres rewrote this
-- view's stored definition in place rather than through a migration file, so
-- no file records the current form. Before replaying, change that file's
-- five `sm.pool::text = ...` predicates (lines 102, 124, 155, 186, 204) to
-- `sm.usage::text` — leave the two `'pool:services'` section-key literals
-- (lines 49, 302) alone, they are unrelated.

BEGIN;

DROP VIEW IF EXISTS "site"."public_site_payload";

COMMIT;
