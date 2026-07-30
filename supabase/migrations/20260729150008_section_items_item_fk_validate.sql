-- ROLLBACK: NONE available for VALIDATE itself -- PostgreSQL has no
--           "un-validate". The only reverse is to drop the constraint, which
--           is 20260729150007's ROLLBACK:
--             ALTER TABLE site.section_items DROP CONSTRAINT IF EXISTS section_items_item_id_fk;
BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '60s';

ALTER TABLE "site"."section_items" VALIDATE CONSTRAINT "section_items_item_id_fk";

COMMIT;
