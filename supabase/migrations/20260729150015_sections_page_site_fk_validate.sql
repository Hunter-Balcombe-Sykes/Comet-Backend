-- ROLLBACK: NONE available for VALIDATE itself -- PostgreSQL has no
--           "un-validate". The only reverse is to drop the constraint, which
--           is 20260729150014's ROLLBACK:
--             ALTER TABLE site.sections DROP CONSTRAINT IF EXISTS sections_page_site_fk;
BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '60s';

ALTER TABLE "site"."sections" VALIDATE CONSTRAINT "sections_page_site_fk";

COMMIT;
