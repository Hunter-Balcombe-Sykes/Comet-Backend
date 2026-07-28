BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '60s';

ALTER TABLE "ingest"."effects" VALIDATE CONSTRAINT "effects_source_id_fk";

COMMIT;
