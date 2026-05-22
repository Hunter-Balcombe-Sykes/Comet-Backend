-- Phase 4 follow-up — validate the preferred_payout_method check.
--
-- The previous migration added the CHECK as NOT VALID, then backfilled all rows to
-- 'card' or 'becs' (or left them NULL) — so this VALIDATE pass scans the table under
-- SHARE UPDATE EXCLUSIVE and should succeed without lock contention.

BEGIN;

ALTER TABLE core.professionals
    VALIDATE CONSTRAINT professionals_preferred_payout_method_check;

COMMIT;
