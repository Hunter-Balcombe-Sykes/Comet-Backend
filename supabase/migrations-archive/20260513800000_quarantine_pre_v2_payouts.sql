-- Stripe v2 Option A — quarantine pre-cutover payouts.
--
-- After migration 20260513700000 the state machine is {pending, processing, completed, failed, cancelled}.
-- Any non-terminal payout created before the v2 cutover date references the old direct-charge model
-- (PI on brand's connected account + separate Transfer), which the new service does NOT understand.
--
-- We mark these as failed with a sentinel failure_code so:
--   1. they are filtered out of resume queries (which only re-process status IN ('pending','processing'))
--   2. their orders are released (payout_id = NULL) so the next sweep can pick them up under v2
--   3. ops can list them later via failure_code='pre_v2_quarantine' for manual reconciliation
--
-- Note: 20260513700000 already collapsed the legacy intermediate states. This migration handles any
-- remaining 'pending' rows that survived the state-machine cutover.

UPDATE commerce.commission_payouts
   SET status = 'failed',
       failure_code = 'pre_v2_quarantine',
       failure_reason = 'Quarantined during v2 cutover — manual review required',
       processed_at = now()
 WHERE status NOT IN ('completed', 'failed', 'cancelled')
   AND created_at < '2026-05-13 00:00:00+00';

-- Release orders attached to quarantined payouts so the next sweep can re-batch them under v2.
UPDATE commerce.orders o
   SET payout_id = NULL
  FROM commerce.commission_payouts p
 WHERE o.payout_id = p.id
   AND p.failure_code = 'pre_v2_quarantine';
