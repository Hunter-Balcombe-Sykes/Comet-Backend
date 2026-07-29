BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '60s';

ALTER TABLE "site"."section_items" VALIDATE CONSTRAINT "section_items_item_id_fk";

COMMIT;
