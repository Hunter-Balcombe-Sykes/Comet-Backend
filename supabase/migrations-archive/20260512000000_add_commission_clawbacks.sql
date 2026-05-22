-- Post-payout refund clawback ledger.
--
-- When a Shopify refund arrives AFTER a CommissionPayout has already settled
-- (status='completed'), the affiliate has been paid and the brand is now refunding
-- the customer. Previously the code logged and returned, leaving the brand out of
-- pocket. This table tracks the Stripe Transfer Reversal we issue to recover the
-- affiliate's share, with a fallback to manual-recovery when the reversal fails
-- (typically insufficient connected-account balance).
--
-- One row per Shopify refund event per (payout, order). Sequential refunds on the
-- same order produce sequential clawback rows.

CREATE TABLE IF NOT EXISTS commerce.commission_clawbacks (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    payout_id UUID NOT NULL REFERENCES commerce.commission_payouts(id) ON DELETE RESTRICT,
    order_id UUID NOT NULL REFERENCES commerce.orders(id) ON DELETE RESTRICT,
    -- The Shopify refund.id that triggered this clawback. Nullable because some
    -- recovery paths (manual ops actions) won't have one.
    shopify_refund_id TEXT,
    stripe_reversal_id TEXT,
    amount_cents BIGINT NOT NULL CHECK (amount_cents >= 0),
    currency_code CHAR(3) NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('reversed', 'reversal_failed', 'manual_recovered')),
    failure_reason TEXT,
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);

-- Dedup the same Shopify refund event hitting this code twice (cache eviction +
-- Shopify replay). Partial because manual rows have shopify_refund_id NULL.
CREATE UNIQUE INDEX commission_clawbacks_payout_order_refund_idx
    ON commerce.commission_clawbacks (payout_id, order_id, shopify_refund_id)
    WHERE shopify_refund_id IS NOT NULL;

-- Hot index for the ops "needs manual recovery" dashboard query.
CREATE INDEX commission_clawbacks_status_idx
    ON commerce.commission_clawbacks (status)
    WHERE status = 'reversal_failed';

-- Lookup by Stripe reversal ID for webhook reconciliation.
CREATE UNIQUE INDEX commission_clawbacks_stripe_reversal_idx
    ON commerce.commission_clawbacks (stripe_reversal_id)
    WHERE stripe_reversal_id IS NOT NULL;

COMMENT ON TABLE commerce.commission_clawbacks IS
    'Records Stripe Transfer Reversals issued when a Shopify refund arrives after a CommissionPayout has settled. One row per Shopify refund event per (payout, order). Dedup partial-unique on (payout, order, shopify_refund_id).';
