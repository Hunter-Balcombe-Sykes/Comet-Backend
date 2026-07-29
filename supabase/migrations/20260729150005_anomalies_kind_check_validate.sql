BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '60s';

ALTER TABLE "ingest"."anomalies" VALIDATE CONSTRAINT "anomalies_kind_check";

COMMIT;
