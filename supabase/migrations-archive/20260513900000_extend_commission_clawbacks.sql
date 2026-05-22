-- Stripe v2 Option A — extends commission_clawbacks for destination charge refund tracking.
--
-- Under the old direct-charge model, clawbacks recorded a single TransferReversal (one number,
-- stored in amount_cents). Under destination charges a single $stripe->refunds->create() call
-- atomically performs THREE things proportionally:
--   1. Refunds the original charge to the buyer (refund_amount_cents)
--   2. Claws back the application fee from the platform balance (application_fee_refund_cents)
--   3. Reverses the auto-transfer from the affiliate's balance (transfer_reversal_cents)
--
-- We persist each leg for financial reconciliation and dispute resolution.
--
-- New columns:
--   refund_id                    - The Stripe Refund ID (`re_...`) for matching charge.refunded webhooks.
--   refund_amount_cents          - Charge amount refunded to buyer.
--   application_fee_refund_cents - Platform fee reversed (proportional to refund_amount).
--   transfer_reversal_cents      - Affiliate amount reversed (proportional to refund_amount).
--   is_partial                   - True if refund_amount < charge_amount on the source PI.
--   needs_manual_refund          - True when the atomic refund call failed (typically because the
--                                  affiliate's connected-account balance is insufficient to reverse
--                                  the transfer). Ops dashboard surfaces these for manual recovery.
--
-- The existing stripe_reversal_id column is retained for historical data; new rows populate refund_id.

ALTER TABLE commerce.commission_clawbacks
    ADD COLUMN IF NOT EXISTS refund_id VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS refund_amount_cents INTEGER NULL,
    ADD COLUMN IF NOT EXISTS application_fee_refund_cents INTEGER NULL,
    ADD COLUMN IF NOT EXISTS transfer_reversal_cents INTEGER NULL,
    ADD COLUMN IF NOT EXISTS is_partial BOOLEAN NOT NULL DEFAULT false,
    ADD COLUMN IF NOT EXISTS needs_manual_refund BOOLEAN NOT NULL DEFAULT false;

CREATE UNIQUE INDEX IF NOT EXISTS commission_clawbacks_refund_id_idx
    ON commerce.commission_clawbacks (refund_id)
    WHERE refund_id IS NOT NULL;

-- Hot index for the ops "needs manual refund" dashboard query.
CREATE INDEX IF NOT EXISTS commission_clawbacks_needs_manual_refund_idx
    ON commerce.commission_clawbacks (needs_manual_refund)
    WHERE needs_manual_refund = true;
