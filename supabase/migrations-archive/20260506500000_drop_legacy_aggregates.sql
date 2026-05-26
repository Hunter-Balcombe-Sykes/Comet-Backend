-- Phase 4: drop legacy aggregate tables and obsolete FK column.
-- Order is load-bearing (audit fix PLANV3-7):
--   1. SET NOT NULL on commerce.commission_payout_items.order_id
--      (requires Phase 2 backfill complete)
--   2. DROP COLUMN commission_ledger_entry_id (FK drops here, before the DELETE)
--   3. DELETE accrual/reversal rows (safe now that the FK is gone — Phase 3+
--      writes only `payout`, `clawback`, `adjustment` via commerce.orders /
--      commerce.commission_payouts; accruals/reversals are now derived from
--      commerce.orders.commission_cents and brand_affiliate_rollup.reversed_*)
--   4. DROP TABLE the eight legacy analytics aggregate tables
-- All wrapped in a single transaction so partial failure leaves the database
-- in pre-Phase-4 state. No `IF EXISTS` on `DROP TABLE` — fail loudly on schema
-- drift instead of silently skipping.
--
-- Naming note: the upstream plan vocabulary uses `commission_movements` /
-- `commission_movement_id`. The actual schema still carries the pre-Phase-1
-- names (`commission_ledger_entries`, `commission_ledger_entry_id`) because
-- the rename was deferred. Phase 4 stays consistent with reality; the rename
-- is a separate future PR.

BEGIN;

ALTER TABLE commerce.commission_payout_items
    ALTER COLUMN order_id SET NOT NULL;

ALTER TABLE commerce.commission_payout_items
    DROP COLUMN commission_ledger_entry_id;

DELETE FROM commerce.commission_ledger_entries
 WHERE entry_type IN ('accrual', 'reversal');

DROP TABLE analytics.brand_metrics_daily,
           analytics.brand_metrics_hourly,
           analytics.brand_affiliate_daily,
           analytics.brand_commission_daily,
           analytics.professional_metrics_daily,
           analytics.professional_metrics_hourly,
           analytics.site_metrics_daily,
           analytics.site_metrics_hourly;

COMMIT;
