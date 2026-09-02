-- Setup progress ledger: the `shop` stage (2026-09-02, the live signup card
-- shows the store and its products as they sync). Same NOT VALID + VALIDATE
-- shape as the effects_kind_check change; existing rows all carry the old
-- stages, so VALIDATE cannot fail.
--
-- ROLLBACK:
--   ALTER TABLE core.pre_account_build_events DROP CONSTRAINT IF EXISTS pre_account_build_events_stage_check;
--   ALTER TABLE core.pre_account_build_events ADD CONSTRAINT pre_account_build_events_stage_check
--       CHECK (stage IN ('identity','media','workplace','platforms','listing','menu','website','ready','failed')) NOT VALID;

BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '30s';

ALTER TABLE "core"."pre_account_build_events" DROP CONSTRAINT IF EXISTS "pre_account_build_events_stage_check";
ALTER TABLE "core"."pre_account_build_events"
    ADD CONSTRAINT "pre_account_build_events_stage_check"
    CHECK ("stage" IN ('identity', 'media', 'workplace', 'platforms', 'listing', 'menu', 'website', 'shop', 'ready', 'failed')) NOT VALID;
ALTER TABLE "core"."pre_account_build_events" VALIDATE CONSTRAINT "pre_account_build_events_stage_check";

COMMIT;
