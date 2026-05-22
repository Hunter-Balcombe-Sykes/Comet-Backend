BEGIN;

-- stripe_application_fee_id was added in 20260428000000_payout_grace_and_app_fee.sql for a
-- destination-charge + application_fee funding path that was never implemented. The chosen
-- funding architecture uses direct charges to the brand's payment method (wallet top-ups),
-- so this column and its sparse index are dead weight.
DROP INDEX IF EXISTS commerce.commission_payouts_app_fee_idx;
ALTER TABLE commerce.commission_payouts DROP COLUMN IF EXISTS stripe_application_fee_id;

COMMIT;

-- DOWN (manual rollback):
-- BEGIN;
-- ALTER TABLE commerce.commission_payouts ADD COLUMN IF NOT EXISTS stripe_application_fee_id text;
-- CREATE INDEX IF NOT EXISTS commission_payouts_app_fee_idx
--     ON commerce.commission_payouts (stripe_application_fee_id)
--     WHERE stripe_application_fee_id IS NOT NULL;
-- COMMIT;
