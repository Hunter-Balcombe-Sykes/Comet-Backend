-- Brand-as-Connect-account: add brand-scoped customer + payment-method columns.
--
-- Before this shift, brands paid commissions from a Stripe Customer on Partna's
-- *platform* Stripe account (`stripe_customer_id` / `stripe_payment_method_id`).
-- After the shift, the brand becomes a Connect Express account and the
-- commission charge is a direct charge on the brand's own Connect account,
-- which means the Customer + PaymentMethod must live on the brand's account,
-- not Partna's platform.
--
-- The old columns are kept for the SaaS-billing flow (Partna-platform
-- subscriptions still use them) and affiliate-side webhook handlers.

ALTER TABLE core.professionals
    ADD COLUMN IF NOT EXISTS stripe_connect_customer_id TEXT,
    ADD COLUMN IF NOT EXISTS stripe_connect_payment_method_id TEXT;

COMMENT ON COLUMN core.professionals.stripe_connect_customer_id IS
    'Stripe Customer ID scoped to the brand''s OWN Connect account (not Partna''s platform). Used for direct-charge commission payouts where the brand is merchant of record.';

COMMENT ON COLUMN core.professionals.stripe_connect_payment_method_id IS
    'Stripe PaymentMethod ID scoped to the brand''s OWN Connect account. Used for direct-charge commission payouts.';
