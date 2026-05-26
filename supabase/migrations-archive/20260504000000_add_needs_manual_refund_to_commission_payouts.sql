-- When a Stripe transfer fails and the subsequent auto-refund also fails, the
-- brand is left charged with no operational surface to detect or remediate.
-- This flag is set to true in that scenario; staff must acknowledge (POST
-- /staff/commission-payouts/{payout}/acknowledge-manual-refund) before retrying.

ALTER TABLE commerce.commission_payouts
    ADD COLUMN IF NOT EXISTS needs_manual_refund BOOLEAN NOT NULL DEFAULT FALSE;

-- Index for staff dashboard filter (?needs_manual_refund=true).
CREATE INDEX IF NOT EXISTS idx_commission_payouts_needs_manual_refund
    ON commerce.commission_payouts (needs_manual_refund)
    WHERE needs_manual_refund = TRUE;
