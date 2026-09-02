-- The placement decision's band, persisted at write time (setup-dialog run
-- A.1, decision 2). The wire cannot re-derive auto-vs-suggest later: the
-- thresholds are context-adjusted at decision time (indirect penalty,
-- sign-up floors), so the reconciler records what the policy actually
-- decided. Nullable — pre-existing intents and Hold/Note advances carry none.
BEGIN;
ALTER TABLE "routing"."source_intents" ADD COLUMN "band" text;

ALTER TABLE "routing"."source_intents"
    ADD CONSTRAINT source_intents_band_check
    CHECK ("band" IS NULL OR "band" IN ('auto', 'suggest')) NOT VALID;
COMMIT;

-- Validate outside the ADD's transaction (CONVENTIONS.md §2 / guard check 8):
-- SHARE UPDATE EXCLUSIVE only, so writers keep flowing during the scan.
BEGIN;
ALTER TABLE "routing"."source_intents" VALIDATE CONSTRAINT source_intents_band_check;
COMMIT;
