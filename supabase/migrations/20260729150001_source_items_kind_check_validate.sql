-- Separate transaction per CONVENTIONS.md §2 (guard Check 8). VALIDATE takes
-- only SHARE UPDATE EXCLUSIVE, so concurrent reads and writes continue.
BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '60s';

ALTER TABLE "content"."source_items" VALIDATE CONSTRAINT "source_items_kind_check";

COMMIT;
