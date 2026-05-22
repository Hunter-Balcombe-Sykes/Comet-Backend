BEGIN;

ALTER TABLE commerce.commission_payouts
    ADD COLUMN IF NOT EXISTS transfer_completed_at      timestamptz,
    ADD COLUMN IF NOT EXISTS stripe_error_code          text,
    ADD COLUMN IF NOT EXISTS stripe_error_message       text,
    ADD COLUMN IF NOT EXISTS next_retry_at              timestamptz,
    ADD COLUMN IF NOT EXISTS last_retry_at              timestamptz,
    ADD COLUMN IF NOT EXISTS funding_failure_count      integer NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS failure_category           text,
    ADD COLUMN IF NOT EXISTS grace_notifications_sent   jsonb   NOT NULL DEFAULT '[]'::jsonb;

-- Backfill: completed payouts get transfer_completed_at = processed_at as best-effort
UPDATE commerce.commission_payouts
   SET transfer_completed_at = processed_at
 WHERE status = 'completed' AND transfer_completed_at IS NULL;

-- 'order_refunded' is reserved for refund-during-grace cancellations.
ALTER TABLE commerce.commission_payouts
    ADD CONSTRAINT chk_cp_failure_category
    CHECK (failure_category IS NULL OR failure_category IN
        ('brand_funding','affiliate_account','stripe_transient','stripe_terminal','platform','order_refunded'));

ALTER TABLE commerce.commission_payouts
    ADD CONSTRAINT chk_cp_funding_failure_count
    CHECK (funding_failure_count >= 0 AND funding_failure_count <= 50);

CREATE INDEX IF NOT EXISTS idx_cp_completed_status
    ON commerce.commission_payouts (id) WHERE status = 'completed';

CREATE INDEX IF NOT EXISTS idx_cp_pending_funds_next_retry
    ON commerce.commission_payouts (next_retry_at)
    WHERE status = 'pending_funds';

CREATE INDEX IF NOT EXISTS idx_cp_transferring_updated_at
    ON commerce.commission_payouts (updated_at)
    WHERE status = 'transferring';

-- Add masked-card columns on professionals so billing-summary endpoint can
-- display the card without round-tripping to Stripe per request.
ALTER TABLE core.professionals
    ADD COLUMN IF NOT EXISTS stripe_payment_method_brand text,
    ADD COLUMN IF NOT EXISTS stripe_payment_method_last4 char(4);

COMMIT;

-- DOWN (manual rollback):
-- BEGIN;
-- DROP INDEX IF EXISTS commerce.idx_cp_transferring_updated_at;
-- DROP INDEX IF EXISTS commerce.idx_cp_pending_funds_next_retry;
-- DROP INDEX IF EXISTS commerce.idx_cp_completed_status;
-- ALTER TABLE commerce.commission_payouts DROP CONSTRAINT IF EXISTS chk_cp_funding_failure_count;
-- ALTER TABLE commerce.commission_payouts DROP CONSTRAINT IF EXISTS chk_cp_failure_category;
-- ALTER TABLE commerce.commission_payouts
--     DROP COLUMN IF EXISTS grace_notifications_sent,
--     DROP COLUMN IF EXISTS failure_category,
--     DROP COLUMN IF EXISTS funding_failure_count,
--     DROP COLUMN IF EXISTS last_retry_at,
--     DROP COLUMN IF EXISTS next_retry_at,
--     DROP COLUMN IF EXISTS stripe_error_message,
--     DROP COLUMN IF EXISTS stripe_error_code,
--     DROP COLUMN IF EXISTS transfer_completed_at;
-- ALTER TABLE core.professionals
--     DROP COLUMN IF EXISTS stripe_payment_method_last4,
--     DROP COLUMN IF EXISTS stripe_payment_method_brand;
-- COMMIT;
