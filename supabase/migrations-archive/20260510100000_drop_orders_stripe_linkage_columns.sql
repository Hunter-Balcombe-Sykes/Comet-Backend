BEGIN;

-- These columns were added when orders directly tracked their Stripe payment intent and
-- transfer IDs. Post-Phase-3/4, this linkage is obsolete: payouts are the Stripe-level
-- unit of work and commission_payouts.stripe_{payment_intent,transfer}_id carry the IDs.
-- Orders link to payouts via commerce.commission_payout_items, not direct Stripe IDs.
ALTER TABLE commerce.orders DROP COLUMN IF EXISTS stripe_payment_intent_id;
ALTER TABLE commerce.orders DROP COLUMN IF EXISTS stripe_transfer_id;

COMMIT;

-- DOWN (manual rollback):
-- BEGIN;
-- ALTER TABLE commerce.orders ADD COLUMN IF NOT EXISTS stripe_payment_intent_id text;
-- ALTER TABLE commerce.orders ADD COLUMN IF NOT EXISTS stripe_transfer_id text;
-- COMMIT;
