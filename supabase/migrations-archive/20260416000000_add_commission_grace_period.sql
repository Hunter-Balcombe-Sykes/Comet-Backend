-- Grace period: 30 days from affiliate creation to connect Stripe
ALTER TABLE core.professionals
    ADD COLUMN IF NOT EXISTS stripe_grace_period_ends_at timestamptz;

COMMENT ON COLUMN core.professionals.stripe_grace_period_ends_at
    IS 'Deadline for affiliate to connect Stripe. Set to created_at + 30 days on account creation. NULL = no grace period (brand or already connected).';

-- Void tracking on commission entries
ALTER TABLE commerce.commission_ledger_entries
    ADD COLUMN IF NOT EXISTS voided_at timestamptz,
    ADD COLUMN IF NOT EXISTS void_reason text;

COMMENT ON COLUMN commerce.commission_ledger_entries.voided_at
    IS 'When this commission was voided (affiliate failed to connect Stripe in time).';
COMMENT ON COLUMN commerce.commission_ledger_entries.void_reason
    IS 'Reason for void: no_stripe_connected, grace_period_expired, etc.';

-- Expand status constraint to include 'voided'
ALTER TABLE commerce.commission_ledger_entries
    DROP CONSTRAINT IF EXISTS commission_ledger_status_check;
ALTER TABLE commerce.commission_ledger_entries
    ADD CONSTRAINT commission_ledger_status_check
    CHECK (status IN ('pending', 'approved', 'paid', 'reversed', 'disputed', 'voided'));

-- Index for the void cron: find pending commissions past their void window
CREATE INDEX IF NOT EXISTS idx_cle_voidable
    ON commerce.commission_ledger_entries (affiliate_professional_id, status, created_at)
    WHERE status = 'pending' AND payout_id IS NULL;

-- Index for warning queries: find unconnected affiliates approaching grace deadline
CREATE INDEX IF NOT EXISTS idx_professionals_grace_period
    ON core.professionals (stripe_grace_period_ends_at)
    WHERE stripe_connect_status != 'active'
      AND stripe_grace_period_ends_at IS NOT NULL;

-- Backfill grace period for existing affiliates/influencers who haven't connected Stripe.
-- Sets their grace period to created_at + 30 days. Already-connected affiliates get NULL.
UPDATE core.professionals
SET stripe_grace_period_ends_at = created_at + INTERVAL '30 days'
WHERE professional_type IN ('influencer', 'professional')
  AND stripe_connect_status IN ('not_connected', 'onboarding')
  AND stripe_grace_period_ends_at IS NULL;
