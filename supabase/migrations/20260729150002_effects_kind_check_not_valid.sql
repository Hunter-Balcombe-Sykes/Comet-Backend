-- SCHEMA-2: ingest.effects is the charge-once MONEY ledger (C6). Every other
-- enum-shaped column in 20260727130000_ingest_schema.sql carries a CHECK
-- (sources.health :37, sources.scope :40, streams.health :63, runs.trigger,
-- runs.outcome, effects.status :167, anomalies.severity :185); `kind` at :162
-- documents `-- http | actor | api | ai` and enforces nothing. In the one table
-- whose purpose is auditable cost correctness, that is the wrong column to
-- leave open.
--
-- Domain verified against every writer: EffectLedger::once() (app/Ingest/
-- Runtime/EffectLedger.php:68) writes the caller's $kind; callers are
-- {Doordash,UberEats,Square}MenuConnector + InstagramConnector -> 'actor',
-- GoogleBusinessConnector.php:117 -> 'api'. 'http' is written by the ledger
-- tests. 'ai' is reserved by the comment and kept.
--
-- Data (2026-07-29): dev ingest.effects = 0 rows; prod has no ingest schema
-- yet. VALIDATE cannot fail.
--
-- ROLLBACK: ALTER TABLE ingest.effects DROP CONSTRAINT IF EXISTS effects_kind_check;

BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '30s';

ALTER TABLE "ingest"."effects"
    ADD CONSTRAINT "effects_kind_check" CHECK ("kind" IN ('http', 'actor', 'api', 'ai')) NOT VALID;

COMMIT;
