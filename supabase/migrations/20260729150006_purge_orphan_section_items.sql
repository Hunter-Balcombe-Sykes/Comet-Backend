-- Pre-flight for 20260729150007/8. An orphan curation row would abort the FK's
-- validation scan (SQLSTATE 23503) and take the whole db push with it. Same
-- shape and same reasoning as 20260729120000_purge_orphan_ingest_rows.sql.
--
-- Expected to match 0 rows: dev site.section_items = 0 rows / 0 orphans
-- (verified 2026-07-29); prod has no site.section_items table yet. Left in
-- regardless -- it is the only thing standing between a stray orphan and a
-- failed production migration. Idempotent: re-running matches nothing.
--
-- Deleting these rows IS correct, not merely expedient: a pin or exclude
-- naming an item that does not exist can never be applied by DocumentBuilder
-- (app/Site/Documents/DocumentBuilder.php:158 joins through content.items),
-- so it is unreachable state, not user data.
--
-- No transaction: backfill/DML outside DDL per CONVENTIONS.md §5.

DELETE FROM "site"."section_items" si
WHERE NOT EXISTS (
    SELECT 1 FROM "content"."items" i WHERE i."id" = si."item_id"
);
