-- Stripe Accounts v2 + destination-charge overhaul.
--
-- Pre-this-migration: v1 Express accounts on connected accounts, with brand's
-- saved card living on the brand's own Connect account (PR #38 columns:
-- stripe_connect_customer_id + stripe_connect_payment_method_id).
--
-- After this migration: v2 Accounts with merchant + customer + recipient
-- configurations. Brand's saved card is attached to the brand's v2 Account
-- directly (the Account IS the customer for billing purposes). One ID column
-- per professional, plus card-display fields.
--
-- Commission charges become destination charges on the PLATFORM with
-- on_behalf_of=brand_account + transfer_data.destination=affiliate_account, so
-- the brand is settlement merchant on the cardholder statement and the
-- affiliate sees Partna's application_fee as a line item on their dashboard.

ALTER TABLE core.professionals
    -- Drop legacy v1-era columns no longer used in the v2 destination-charge model.
    DROP COLUMN IF EXISTS stripe_customer_id,                  -- v1 platform customer (SaaS-billing era)
    DROP COLUMN IF EXISTS stripe_connect_customer_id,          -- v1 brand-Connect-scoped customer (PR #38 column)
    DROP COLUMN IF EXISTS stripe_connect_payment_method_id,    -- v1 brand-Connect-scoped PM (PR #38 column)
    DROP COLUMN IF EXISTS stripe_manual_balance_cents,         -- legacy wallet (already deleted; safety cleanup)
    DROP COLUMN IF EXISTS stripe_manual_balance_currency,
    DROP COLUMN IF EXISTS stripe_grace_period_ends_at;         -- not used in v2 flow

-- Ensure card-display columns exist for the new v2 customer-attached PM.
-- stripe_payment_method_id is the pm_... ID attached to brand's v2 Account.
ALTER TABLE core.professionals
    ADD COLUMN IF NOT EXISTS stripe_payment_method_id TEXT,
    ADD COLUMN IF NOT EXISTS stripe_payment_method_brand TEXT,
    ADD COLUMN IF NOT EXISTS stripe_payment_method_last4 TEXT;

COMMENT ON COLUMN core.professionals.stripe_connect_account_id IS
    'Stripe v2 Account ID (acct_...). For brands: merchant + customer configurations. For affiliates: recipient configuration.';

COMMENT ON COLUMN core.professionals.stripe_payment_method_id IS
    'PaymentMethod ID (pm_...) attached to the brand''s v2 Account. Used as the payment_method on commission destination charges. NULL for affiliates.';

-- Status state machine: drop "disconnected" (added in PR #39). The v2 flow uses
-- only not_connected / onboarding / active / restricted. Soft-disconnect is gone
-- in favour of a clean "remove account ID + status=not_connected" reset.
UPDATE core.professionals
   SET stripe_connect_status = 'not_connected',
       stripe_connect_account_id = NULL
 WHERE stripe_connect_status = 'disconnected';

ALTER TABLE core.professionals
    DROP CONSTRAINT IF EXISTS pro_stripe_connect_status_check;

ALTER TABLE core.professionals
    ADD CONSTRAINT pro_stripe_connect_status_check
    CHECK (stripe_connect_status IN ('not_connected', 'onboarding', 'active', 'restricted'));
