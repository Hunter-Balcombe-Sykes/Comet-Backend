-- ROLLBACK: NONE available for VALIDATE itself -- PostgreSQL has no
--           "un-validate". The only reverse is to drop the constraint, which
--           is 20260729150004's ROLLBACK:
--             ALTER TABLE ingest.anomalies DROP CONSTRAINT IF EXISTS anomalies_kind_check;
BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '60s';

ALTER TABLE "ingest"."anomalies" VALIDATE CONSTRAINT "anomalies_kind_check";

COMMIT;
