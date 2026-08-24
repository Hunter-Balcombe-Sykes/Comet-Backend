-- Validates the constraint the previous file added NOT VALID
-- (CONVENTIONS.md §2) — SHARE UPDATE EXCLUSIVE only, doesn't block
-- concurrent writes. Every row is 'staple' today, so this is a fast no-op
-- scan; the split is about the lock class, not the row count.
--
-- ROLLBACK: NONE — PostgreSQL has no "un-validate"; the sibling file's
-- DROP CONSTRAINT is the real reverse (CONVENTIONS.md §10).

BEGIN;

SET LOCAL lock_timeout = '2s';
SET LOCAL statement_timeout = '60s';

ALTER TABLE "site"."sites" VALIDATE CONSTRAINT sites_architecture_id_check;

COMMIT;
