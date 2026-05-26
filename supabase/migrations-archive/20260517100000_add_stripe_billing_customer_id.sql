-- Restore platform-side Stripe Customer ID storage for SaaS subscription billing.
--
-- Background: the previous `core.professionals.stripe_customer_id` column was dropped in
-- 20260513400000_stripe_v2_account_columns.sql alongside the v1 Connect customer/PM cleanup.
-- That drop was correct for the Connect path (the brand's v2 Account replaces the per-Connect
-- Customer) but inadvertently broke SaaS subscription billing, which still needs a persistent
-- Customer ID to (a) reuse across days beyond Stripe's 24h idempotency window, and (b) open
-- the billing portal without re-creating a Customer each time.
--
-- This column is purpose-namespaced — `stripe_billing_customer_id` — so the next refactor that
-- sweeps Connect leftovers doesn't conflate it with the v1 column it replaces.

BEGIN;

ALTER TABLE core.professionals
    ADD COLUMN IF NOT EXISTS stripe_billing_customer_id text;

COMMENT ON COLUMN core.professionals.stripe_billing_customer_id IS
    'Platform-side Stripe Customer ID (cus_...) used for Partna SaaS subscription billing and billing-portal sessions. Distinct from stripe_connect_account_id (the brand''s v2 Connect Account used for commission charges) and from billing.subscriptions.stripe_customer_id (per-subscription denormalization written by the subscription-created webhook).';

COMMIT;
