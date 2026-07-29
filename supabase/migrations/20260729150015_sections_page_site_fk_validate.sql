BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '60s';

ALTER TABLE "site"."sections" VALIDATE CONSTRAINT "sections_page_site_fk";

COMMIT;
