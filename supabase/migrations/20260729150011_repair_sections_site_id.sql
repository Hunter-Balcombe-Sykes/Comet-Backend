-- Pre-flight for 20260729150013/14. A section whose site_id disagrees with its
-- page's site_id would abort the composite FK's validation scan (SQLSTATE
-- 23503). site.pages is the source of truth here, not sections.site_id:
-- sections.site_id is the DENORMALISED copy (20260727150000:37), and
-- SectionController::store() derives it from the page it just resolved
-- (app/Http/Controllers/Api/Site/SectionController.php:76-80, findPage() at
-- :159 scoping by site).
--
-- Expected to match 0 rows: dev has 1 section, 0 mismatches (verified
-- 2026-07-29); prod has no site.sections table yet. Idempotent -- the
-- IS DISTINCT FROM guard means a re-run matches nothing.
--
-- No transaction: backfill outside DDL per CONVENTIONS.md §5.
--
-- ROLLBACK: NONE, and none wanted. Overwrites sections.site_id (the
--           DENORMALISED copy) from site.pages, the source of truth. The
--           prior mismatched value is not recorded, and restoring it would
--           break the composite FK 20260729150014 adds.

UPDATE "site"."sections" s
SET "site_id" = p."site_id",
    "updated_at" = now()
FROM "site"."pages" p
WHERE p."id" = s."page_id"
  AND s."site_id" IS DISTINCT FROM p."site_id";
