-- Per-payout grace window + Stripe Application Fee tracking.
--
-- Two columns on commerce.commission_payouts:
--
--   void_at — each payout's own deadline for the affiliate to have an
--   active Stripe Connect account. 60d from the payout's creation by
--   default; nightly cron voids any unpaid payouts past their void_at
--   when the affiliate's stripe_connect_status is not 'active'. Moves
--   the grace timer off Professional.stripe_grace_period_ends_at and
--   onto each individual payout, so a long-tenured affiliate who lets
--   their connection lapse only loses payouts created after the lapse,
--   not their entire historical balance.
--
--   stripe_application_fee_id — the Stripe `fee_xxx` reference when the
--   payout was funded via the destination-charge + application_fee path
--   (card-funded, hybrid mechanism R4). NULL for wallet-only payouts
--   that use the manual Transfer path. Lets us reconcile our
--   platform_fee_cents column against Stripe's own application fee
--   ledger and listen for `application_fee.refunded` webhooks to mark
--   ledger entries reversed automatically when refunds happen.

ALTER TABLE commerce.commission_payouts
    ADD COLUMN IF NOT EXISTS void_at timestamptz,
    ADD COLUMN IF NOT EXISTS stripe_application_fee_id text;

-- Backfill void_at for existing rows: 60 days after each payout's
-- creation. Existing completed payouts get a non-null timer that's in
-- the past — harmless because the void cron filters by status.
UPDATE commerce.commission_payouts
SET void_at = created_at + interval '60 days'
WHERE void_at IS NULL;

-- Future rows must always carry a void_at — the controller / payout
-- service is responsible for setting it on insert. Enforce at schema
-- level so a buggy code path can't slip a NULL through.
ALTER TABLE commerce.commission_payouts
    ALTER COLUMN void_at SET NOT NULL;

-- Index for the nightly void cron — scans rows that are eligible for
-- voiding (past their deadline, not yet processed). Partial so the
-- index stays compact on a table that grows fast.
CREATE INDEX IF NOT EXISTS commission_payouts_void_at_idx
    ON commerce.commission_payouts (void_at)
    WHERE status IN ('pending', 'pending_funds');

-- Index for cross-referencing Stripe application fees on incoming
-- webhooks (`application_fee.refunded`, etc.). Sparse since most rows
-- in v1 will be wallet-funded with no app fee.
CREATE INDEX IF NOT EXISTS commission_payouts_app_fee_idx
    ON commerce.commission_payouts (stripe_application_fee_id)
    WHERE stripe_application_fee_id IS NOT NULL;
