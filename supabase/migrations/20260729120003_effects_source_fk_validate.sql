-- ROLLBACK: NONE available for VALIDATE itself -- PostgreSQL has no
--           "un-validate". The only reverse is to drop the constraint, which
--           is 20260729120002's ROLLBACK:
--             ALTER TABLE ingest.effects DROP CONSTRAINT IF EXISTS effects_source_id_fk;
BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '60s';

ALTER TABLE "ingest"."effects" VALIDATE CONSTRAINT "effects_source_id_fk";

COMMIT;
