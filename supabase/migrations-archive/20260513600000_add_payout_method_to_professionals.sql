-- Stripe v2 Option A — records which payment method a brand chose for payouts.
--
-- 'card':  brand's saved card is charged on commission settlement (destination charge).
-- 'becs':  brand's saved BECS Direct Debit bank account is charged (T+2 settlement, AU).
-- NULL:    brand has not yet selected a payout method.
--
-- The value is set when syncBrandPaymentMethodFromCheckoutSession() persists the PM from a
-- Stripe Checkout setup session, and is read by CommissionPayoutService to choose
-- payment_method_types on the platform-scope PaymentIntent.

ALTER TABLE core.professionals
    ADD COLUMN IF NOT EXISTS payout_method VARCHAR(10) NULL
    CONSTRAINT professionals_payout_method_check CHECK (payout_method IN ('card', 'becs'));
