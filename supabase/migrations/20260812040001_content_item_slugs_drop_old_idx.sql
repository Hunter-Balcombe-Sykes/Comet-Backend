-- 2 of 2. Drops the non-unique partial index the unique one in …040000
-- replaces: both were `(item_id) WHERE is_current`, so the unique index
-- answers every read the old one did and keeping both would cost a second
-- write on every slug mint for nothing.
--
-- Separate file, and CONCURRENTLY, because a CONCURRENTLY statement must be
-- ALONE in its file (CONVENTIONS.md §1 — the CLI pipelines a multi-statement
-- file and CONCURRENTLY cannot run in one, SQLSTATE 25001), and because a
-- bare DROP INDEX takes ACCESS EXCLUSIVE on the table for the catalog write.
--
-- ROLLBACK: CREATE INDEX CONCURRENTLY IF NOT EXISTS "idx_item_slugs_item"
--             ON "content"."item_slugs" ("item_id") WHERE "is_current";

DROP INDEX CONCURRENTLY IF EXISTS "content"."idx_item_slugs_item";
