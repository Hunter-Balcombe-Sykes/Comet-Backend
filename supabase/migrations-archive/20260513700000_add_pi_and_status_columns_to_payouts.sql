-- Stripe v2 Option A — destination charge tracking + new state machine for commission_payouts.
--
-- payment_intent_id: the platform-scope PI created by the payout job (`pi_...`). Webhook handlers
--                    match payment_intent.succeeded / payment_intent.payment_failed events against
--                    this column to advance/fail the payout. Indexed for webhook lookup speed.
--
-- charge_id:         the charge created by the PI once it settles. Populated from PI.latest_charge
--                    when the PI is created (may be NULL for BECS until T+2 settlement) and
--                    reconciled from charge.refunded / charge.dispute.created events. Stored for
--                    audit and dispute reconciliation.
--
-- Note: there is no payout_eligible_at on commission_payouts — the existing eligible_after column
-- (set to MIN orders.occurred_at + payout_hold_days at batch creation) serves the same role.
-- Per-order grace lives on commerce.orders.payout_eligible_at (added in 20260513500000).
--
-- State machine change:
--   Before: pending | pending_funds | collecting | collected | transferring | completed | failed | cancelled
--   After:  pending | processing | completed | failed | cancelled
--
-- The intermediate states (pending_funds, collecting, collected, transferring) modeled the legacy
-- 3-step direct-charge chain. Under destination charges Stripe handles fund movement atomically at
-- charge settlement, so the only states we own are:
--   pending     - created, awaiting eligibility window
--   processing  - PI created, awaiting payment_intent.succeeded webhook
--   completed   - PI succeeded
--   failed      - PI failed or service error
--   cancelled   - manually cancelled before PI creation

ALTER TABLE commerce.commission_payouts
    ADD COLUMN IF NOT EXISTS payment_intent_id VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS charge_id VARCHAR(255) NULL;

CREATE INDEX IF NOT EXISTS commission_payouts_payment_intent_id_idx
    ON commerce.commission_payouts (payment_intent_id)
    WHERE payment_intent_id IS NOT NULL;

-- Sweep query partial index — narrows the resume scan to active in-flight payouts.
CREATE INDEX IF NOT EXISTS commission_payouts_active_eligible_after_idx
    ON commerce.commission_payouts (eligible_after)
    WHERE status IN ('pending', 'processing');

-- Quarantine any in-flight legacy-state payouts BEFORE we tighten the CHECK constraint,
-- otherwise the ALTER would fail on existing rows with the dropped status values.
UPDATE commerce.commission_payouts
   SET status = 'cancelled',
       failure_code = COALESCE(failure_code, 'pre_v2_state_collapse'),
       failure_reason = COALESCE(failure_reason, 'Status collapsed during v2 state-machine cutover')
 WHERE status IN ('pending_funds', 'collecting', 'collected', 'transferring');

ALTER TABLE commerce.commission_payouts
    DROP CONSTRAINT IF EXISTS commission_payouts_status_check;

ALTER TABLE commerce.commission_payouts
    ADD CONSTRAINT commission_payouts_status_check
    CHECK (status IN ('pending', 'processing', 'completed', 'failed', 'cancelled'));
