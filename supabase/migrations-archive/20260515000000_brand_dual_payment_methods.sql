-- Phase 4 — multi-PM (BECS + card) for brand Stripe Connect.
--
-- Replaces the single stripe_payment_method_id/brand/last4 + payout_method tuple
-- with per-type columns so a brand can have both a card and a BECS mandate active
-- at once, plus a preferred_payout_method that drives commission charge selection.
--
-- Legacy columns are kept for one release cycle as a snapshot of the primary PM —
-- old callers continue to work. A follow-up migration will drop them once all reads
-- have been cut over to the new columns.

BEGIN;

-- Fail fast (rather than queueing behind a long-running session) if any concurrent
-- transaction is already holding a lock on core.professionals. Transaction-scoped:
-- covers the ALTER TABLE below and both UPDATE backfills. See SCHEMA-1 audit finding
-- and supabase/migrations/CONVENTIONS.md.
SET LOCAL lock_timeout = '3s';
SET LOCAL statement_timeout = '30s';

ALTER TABLE core.professionals
    ADD COLUMN IF NOT EXISTS stripe_card_payment_method_id text,
    ADD COLUMN IF NOT EXISTS stripe_card_brand text,
    ADD COLUMN IF NOT EXISTS stripe_card_last4 text,
    ADD COLUMN IF NOT EXISTS stripe_becs_payment_method_id text,
    ADD COLUMN IF NOT EXISTS stripe_becs_bsb text,
    ADD COLUMN IF NOT EXISTS stripe_becs_last4 text,
    ADD COLUMN IF NOT EXISTS preferred_payout_method text;

-- NOT VALID so we don't scan the whole table under ACCESS EXCLUSIVE — VALIDATE happens
-- in the sibling migration immediately after the backfill, which makes all rows compliant.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'professionals_preferred_payout_method_check'
    ) THEN
        ALTER TABLE core.professionals
            ADD CONSTRAINT professionals_preferred_payout_method_check
            CHECK (preferred_payout_method IS NULL OR preferred_payout_method IN ('card', 'becs'))
            NOT VALID;
    END IF;
END
$$;

-- Backfill from legacy single-PM columns based on payout_method. Idempotent on re-run —
-- the IS NULL guards on the new columns mean a second run is a no-op on already-migrated
-- rows.
UPDATE core.professionals
SET stripe_card_payment_method_id = stripe_payment_method_id,
    stripe_card_brand              = stripe_payment_method_brand,
    stripe_card_last4              = stripe_payment_method_last4,
    preferred_payout_method        = 'card'
WHERE payout_method = 'card'
  AND stripe_payment_method_id IS NOT NULL
  AND stripe_card_payment_method_id IS NULL;

UPDATE core.professionals
SET stripe_becs_payment_method_id = stripe_payment_method_id,
    stripe_becs_bsb                = stripe_payment_method_brand,
    stripe_becs_last4              = stripe_payment_method_last4,
    preferred_payout_method        = 'becs'
WHERE payout_method = 'becs'
  AND stripe_payment_method_id IS NOT NULL
  AND stripe_becs_payment_method_id IS NULL;

COMMIT;
