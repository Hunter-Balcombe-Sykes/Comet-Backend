-- Partial index on stripe_billing_customer_id — sparse column (only populated after the
-- professional's first billing interaction), so the WHERE clause keeps the index small.
-- Used by future webhook handlers that need to resolve a professional from a Stripe
-- customer.* event.
--
-- CONCURRENTLY required; cannot run inside a transaction. See CONVENTIONS.md §1.

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_professionals_stripe_billing_customer
    ON core.professionals (stripe_billing_customer_id)
    WHERE stripe_billing_customer_id IS NOT NULL;
