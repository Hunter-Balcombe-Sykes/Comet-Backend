-- Stripe v2 Option A — drop the legacy cp_status_check constraint that
-- migration 20260513700000 missed. The v2 state machine collapsed several
-- intermediate states ({pending_funds, collecting, collected, transferring,
-- reversed}) and added 'processing', but cp_status_check (different name from
-- commission_payouts_status_check) was never dropped, so every sweep crashed
-- when transitioning pending → processing.
--
-- The replacement commission_payouts_status_check from migration 20260513700000
-- already enforces the correct v2 values (pending/processing/completed/failed/cancelled).

ALTER TABLE commerce.commission_payouts
    DROP CONSTRAINT IF EXISTS cp_status_check;
