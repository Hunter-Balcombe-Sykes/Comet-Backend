BEGIN;

-- #STRIPE-4 fix: separate the warning-clock anchor from void_at.
--
-- Background: markPendingFunding resets void_at = now() + 60d on every retry to
-- prevent VoidExpiredPayoutsJob from cancelling a payout that's mid-retry.
-- That reset means the T-30/T-7/T-1 warning windows (which key off void_at)
-- never match for actively-retrying payouts. An affiliate could have a payout
-- bouncing for 50 days without receiving a single warning.
--
-- Fix: stamp grace_started_at ONCE on the first markPendingFunding call.
-- void_at keeps doing retry-safety; warnings count down from grace_started_at.

ALTER TABLE commerce.commission_payouts
    ADD COLUMN IF NOT EXISTS grace_started_at timestamptz;

-- Backfill in-flight pending_funds rows so currently-stuck payouts get warnings.
-- Anchor on void_at - 60d (the moment markPendingFunding most recently stamped
-- void_at). For payouts that have been bouncing for weeks this restarts the
-- 60-day warning clock from the migration date — gentle by design, avoids a
-- surprise burst of T-1 emails on rollout. Flip to created_at if you want
-- existing stuck rows to receive warnings on the next nightly run.
UPDATE commerce.commission_payouts
   SET grace_started_at = void_at - interval '60 days'
 WHERE status = 'pending_funds' AND grace_started_at IS NULL;

-- Partial index supports fireGraceWarnings' between-window query.
-- Bound by the same status filter the query uses; non-pending rows never need lookup.
CREATE INDEX IF NOT EXISTS idx_cp_grace_started_at
    ON commerce.commission_payouts (grace_started_at)
    WHERE grace_started_at IS NOT NULL AND status IN ('pending', 'pending_funds');

COMMIT;

-- DOWN (manual rollback):
-- BEGIN;
-- DROP INDEX IF EXISTS commerce.idx_cp_grace_started_at;
-- ALTER TABLE commerce.commission_payouts DROP COLUMN IF EXISTS grace_started_at;
-- COMMIT;
