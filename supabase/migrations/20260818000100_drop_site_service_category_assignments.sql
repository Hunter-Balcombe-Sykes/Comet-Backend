-- Services cutover — legacy teardown, 1 of 3. Child first: it holds FKs to
-- BOTH siblings, so dropping either parent ahead of it would fail.
--
-- ROLLBACK: NONE. A DROP TABLE has no reverse. Pre-image: the pg_dump taken
-- 2026-08-17 14:56 UTC+10 (services-teardown-202608171456.dump, sha256
-- 26b3318bfd7fad14c7a3606b9c8c3e101dbdd05d8997f354b81a128ddb65bae7), restored
-- into a scratch PG17 database and count-matched against live EXACTLY —
-- services 79 / service_categories 16 / service_category_assignments 61. The
-- R2 copy of that dump was DEFERRED by owner ruling 2026-08-17: the upload
-- path exists (scripts/db/backup-to-r2.sh, wrangler OAuth) but encrypting
-- needs BACKUP_PASSPHRASE, which lives only as a GitHub Actions secret. The
-- dump is on one laptop, exactly as the slice-7 drop phase recorded.
--
-- content.* twin: content.collection_items. A membership written by the owner
-- carries source_id NULL and wins; the connection lane's memberships are
-- replaced wholesale on every projector run (spec §3.2, §3.3).
--
-- Dies with the table: service_category_assignments_app_backend_all (the one
-- RLS policy), service_category_assignments_service_id_fkey and
-- service_category_assignments_service_category_id_fkey (both ON DELETE
-- CASCADE), idx_service_category_assignments_category.
--
-- Verified immediately before writing this file: pg_depend reports NO view
-- over any of the three tables (Task 1's armed migration moved
-- site.public_site_payload off them), and NO foreign key anywhere else in the
-- database points at them.
--
-- No IF EXISTS — fail loudly on schema drift rather than silently skipping.

BEGIN;

DROP TABLE site.service_category_assignments;

COMMIT;
