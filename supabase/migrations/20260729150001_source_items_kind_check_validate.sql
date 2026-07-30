-- Separate transaction per CONVENTIONS.md §2 (guard Check 8). VALIDATE takes
-- only SHARE UPDATE EXCLUSIVE, so concurrent reads and writes continue.
--
-- ROLLBACK: NONE available for VALIDATE itself -- PostgreSQL has no
--           "un-validate". The only reverse is to drop the constraint, which
--           is 20260729150000's ROLLBACK:
--             ALTER TABLE content.source_items DROP CONSTRAINT IF EXISTS source_items_kind_check;
BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '60s';

ALTER TABLE "content"."source_items" VALIDATE CONSTRAINT "source_items_kind_check";

COMMIT;
