-- ROLLBACK: DROP INDEX CONCURRENTLY IF EXISTS site.idx_pages_id_site;
--           in its own one-statement file, no BEGIN/COMMIT (CONVENTIONS.md §1).
--           BUT 20260729150013 promotes this index to constraint pages_id_site_unique
--           and RENAMES it in doing so -- once that file has run, this DROP matches
--           nothing. Drop the constraint instead (20260729150013's ROLLBACK).
CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS "idx_pages_id_site"
    ON "site"."pages" ("id", "site_id");
