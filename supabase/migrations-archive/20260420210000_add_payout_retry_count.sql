-- Tracks how many times an admin has manually retried a payout.
-- Used to generate fresh Stripe idempotency keys on each admin retry,
-- preventing the 24-hour TTL window from returning a refunded/failed
-- PaymentIntent or Transfer from a previous attempt.
ALTER TABLE commerce.commission_payouts
  ADD COLUMN IF NOT EXISTS retry_count integer NOT NULL DEFAULT 0;
