-- Master Pattern 22 Step 1 — partial UNIQUE index on commerce.commission_payouts
-- so a concurrent payout sweep cannot create two open payouts for the same
-- natural batch identifier. CommissionPayoutService::createPayoutBatch already
-- wraps the eligible-orders query in `lockForUpdate`; this index is the
-- second-layer defence required by the lockForUpdate + UNIQUE pattern (Phase 2
-- Pattern D) for code paths that bypass the row lock.
--
-- Natural key columns:
--   brand_professional_id
--   affiliate_professional_id
--   currency_code              — the sweep groups by currency, so different
--                                currencies are legitimately separate batches.
--   (eligible_after AT TIME ZONE 'UTC')::date
--                              — eligible_after is set from
--                                now()->utc()->subDays($holdDays), microsecond
--                                precise. Two concurrent sweeps within the
--                                same second produce different timestamps,
--                                which would defeat the index entirely. The
--                                expression buckets eligible_after to its UTC
--                                date so concurrent sweeps on the same day
--                                conflict as intended. AT TIME ZONE 'UTC' is
--                                IMMUTABLE on timestamptz; the cast to date is
--                                IMMUTABLE; the composite is index-eligible.
--
-- Partial scope: the index excludes the terminal-failure states (cancelled,
-- failed) so the system is free to retry batching for the same natural key
-- after a prior attempt was voided. Successful (completed) and in-flight
-- (pending / processing) payouts hold the slot. The v2 status enum no longer
-- includes 'reversed' (collapsed into 'failed' / 'cancelled' in 20260514000000),
-- so the WHERE clause does not list it.
--
-- NULLs: post-20260419000002 the brand/affiliate FK columns are nullable
-- (ON DELETE SET NULL). Postgres treats NULLs as distinct in unique indexes,
-- so rows for deleted professionals are excluded from conflicts automatically
-- — re-batching is never blocked by a soft-deleted counterparty.
--
-- CONCURRENTLY: commission_payouts is on the hot-tables list in
-- supabase/migrations/CONVENTIONS.md §6. The non-blocking index build is
-- required so the migration does not freeze production payout writes.
-- IF NOT EXISTS guards re-runs.

CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS commission_payouts_natural_key_uq
    ON commerce.commission_payouts (
        brand_professional_id,
        affiliate_professional_id,
        currency_code,
        ((eligible_after AT TIME ZONE 'UTC')::date)
    )
    WHERE status NOT IN ('cancelled', 'failed');
