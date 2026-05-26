-- Phase 3.1 backfill: copy payout_id from accrual ledger entries to commerce.orders
-- so the historical payout linkage survives Phase 4's DELETE of accrual/reversal rows.
--
-- Mapping uses commission_ledger_entries.order_id (a FK to commerce.orders, populated
-- by Phase 2 backfill in 20260506100000_backfill_existing_orders.sql). This is cleaner
-- than the shopify_shop_domain + shopify_order_id pair join because the FK is already
-- there.
--
-- Idempotent: only updates rows where commerce.orders.payout_id IS NULL, so re-running
-- is safe and does not clobber any payout_id stamped by the new write path.

BEGIN;

WITH paid_accruals AS (
    SELECT DISTINCT
        cle.order_id,
        cle.payout_id
    FROM commerce.commission_ledger_entries cle
    WHERE cle.entry_type = 'accrual'
      AND cle.payout_id IS NOT NULL
      AND cle.order_id IS NOT NULL
)
UPDATE commerce.orders o
   SET payout_id = pa.payout_id
  FROM paid_accruals pa
 WHERE o.id = pa.order_id
   AND o.payout_id IS NULL;

-- Sanity counts: surface data integrity smells via NOTICE rather than aborting.
-- A non-zero orphan_count means a paid accrual lacks a matching orders row
-- (Phase 2 backfill incomplete). A non-zero conflict_count means an order already
-- had a different payout_id stamped (writer-flow drift between ledger and orders).
DO $$
DECLARE
    orphan_count int;
    conflict_count int;
BEGIN
    SELECT count(*) INTO orphan_count
      FROM commerce.commission_ledger_entries cle
     WHERE cle.entry_type = 'accrual'
       AND cle.payout_id IS NOT NULL
       AND (cle.order_id IS NULL
            OR NOT EXISTS (SELECT 1 FROM commerce.orders o WHERE o.id = cle.order_id));

    SELECT count(*) INTO conflict_count
      FROM commerce.commission_ledger_entries cle
      JOIN commerce.orders o ON o.id = cle.order_id
     WHERE cle.entry_type = 'accrual'
       AND cle.payout_id IS NOT NULL
       AND o.payout_id IS NOT NULL
       AND o.payout_id <> cle.payout_id;

    RAISE NOTICE 'Phase 3.1 backfill: % accrual(s) with payout_id but no/missing matching order; % accrual(s) where orders.payout_id disagrees with ledger.payout_id', orphan_count, conflict_count;
END $$;

COMMIT;
