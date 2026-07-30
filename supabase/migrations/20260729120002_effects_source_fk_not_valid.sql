-- #PRIV-1. ingest.effects is the charge-once MONEY ledger (C6): digest,
-- cost_tag, cost_units, claimed_at/settled_at. ON DELETE CASCADE would make a
-- user deleting their account silently destroy the record of spend we already
-- incurred with a vendor, and -- once the P7 drivers populate body_ref -- would
-- orphan off-Postgres response bodies with no surviving pointer to them.
--
-- SET NULL keeps the spend row for reconciliation while breaking the link.
-- ERASURE is handled explicitly instead, in AccountDeletionService::purge()
-- (purgeIngestEffects), which runs BEFORE forceDelete while ingest.sources
-- still resolves the user's effect rows. That ordering is why an FK cascade is
-- the wrong tool here and an explicit purge is the right one.
--
-- source_id is already nullable, so SET NULL needs no further schema change.
--
-- ROLLBACK: ALTER TABLE ingest.effects
--             DROP CONSTRAINT IF EXISTS effects_source_id_fk;
--           Any source_id already NULLed by ON DELETE SET NULL stays NULL;
--           the original pointer is unrecoverable.

BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '30s';

ALTER TABLE "ingest"."effects"
    ADD CONSTRAINT "effects_source_id_fk"
    FOREIGN KEY ("source_id") REFERENCES "ingest"."sources" ("id") ON DELETE SET NULL
    NOT VALID;

COMMIT;
