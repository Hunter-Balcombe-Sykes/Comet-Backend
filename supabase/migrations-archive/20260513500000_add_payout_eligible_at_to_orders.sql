-- Stripe v2 Option A — adds payout grace period tracking per order.
--
-- payout_eligible_at is set at order creation time:
--   created_at + (brand_store_settings.payout_hold_days * INTERVAL '1 day')
--
-- The daily payout sweep filters orders where payout_eligible_at <= now() AND payout_id IS NULL,
-- so orders inside the grace window are NOT included in a payout batch yet.

ALTER TABLE commerce.orders
    ADD COLUMN IF NOT EXISTS payout_eligible_at TIMESTAMPTZ NULL;

CREATE INDEX IF NOT EXISTS orders_payout_eligible_at_idx
    ON commerce.orders (payout_eligible_at)
    WHERE payout_id IS NULL AND payout_eligible_at IS NOT NULL;
