-- Phase 2 — Backfill from existing commission_ledger_entries into commerce.orders.
-- See docs/analytics-rebuild-plan.md (v3.1) and docs/adr/0001-analytics-rebuild.md.
--
-- Pre-beta state: a single accrual row from 2026-04-04. This migration:
--   1. Inserts the corresponding commerce.orders row (triggers fire → order_items + rollup).
--   2. Inserts a synthesised commerce.order_events row to anchor the audit log.
--   3. Links the ledger entry back to the new order via the order_id FK added in Phase 1.
--
-- Idempotent — safe to re-run. ON CONFLICT clauses + WHERE-NOT-EXISTS guard against
-- duplicates if the migration is replayed (e.g., after a dev DB reset).

BEGIN;

-- ==========================================================================
-- 1. Insert order rows from accruals that don't yet have a paired commerce.orders row
-- ==========================================================================

WITH accruals_to_backfill AS (
    SELECT
        cle.id                                         AS ledger_id,
        cle.shopify_order_id,
        cle.brand_professional_id,
        cle.affiliate_professional_id,
        cle.amount_cents                               AS commission_cents,
        cle.commission_rate,
        cle.rate_source,
        cle.currency_code,
        cle.occurred_at,
        cle.calculation_metadata,
        pi.shopify_shop_domain,
        ((cle.calculation_metadata->>'line_price')::numeric
            * (cle.calculation_metadata->>'quantity')::int * 100)::bigint AS gross_cents
    FROM commerce.commission_ledger_entries cle
    JOIN core.professional_integrations pi
        ON pi.professional_id = cle.brand_professional_id
       AND pi.provider = 'shopify'
       AND pi.shopify_shop_domain IS NOT NULL
    WHERE cle.entry_type = 'accrual'
      AND cle.shopify_order_id IS NOT NULL
      AND cle.order_id IS NULL  -- not yet linked to a commerce.orders row
)
INSERT INTO commerce.orders (
    shopify_order_id, shopify_shop_domain, shopify_updated_at,
    brand_professional_id, affiliate_professional_id,
    status, gross_cents, discount_cents, refund_cents, net_cents,
    commission_cents, commission_rate, rate_source, currency_code,
    line_items, shopify_data, occurred_at
)
SELECT
    a.shopify_order_id,
    a.shopify_shop_domain,
    a.occurred_at,
    a.brand_professional_id,
    a.affiliate_professional_id,
    'approved',
    a.gross_cents,
    0,                       -- discount unknown for backfill — historic data lacks the breakdown
    0,                       -- refund: only present if a 'reversal' ledger entry exists (none here)
    a.gross_cents,           -- net = gross - refund (refund is 0)
    a.commission_cents,
    a.commission_rate,
    a.rate_source,
    a.currency_code,
    -- Per-line commission contract (Phase 1): handler pre-computes per-line, trigger reads it
    jsonb_build_array(jsonb_build_object(
        'shopify_line_item_id', a.calculation_metadata->>'line_item_id',
        'shopify_product_id',   a.calculation_metadata->>'product_id',
        'shopify_variant_id',   NULL,
        'sku',                  NULL,
        'title',                'Backfilled from ledger',
        'quantity',             (a.calculation_metadata->>'quantity')::int,
        'unit_price_cents',     ((a.calculation_metadata->>'line_price')::numeric * 100)::bigint,
        'discount_cents',       0,
        'line_total_cents',     a.gross_cents,
        'commission_cents',     a.commission_cents,
        'commission_rate',      a.commission_rate
    )),
    '{}'::jsonb,             -- shopify_data unavailable for backfill
    a.occurred_at
FROM accruals_to_backfill a
ON CONFLICT (shopify_shop_domain, shopify_order_id) DO NOTHING;

-- ==========================================================================
-- 2. Anchor an order_events row per backfilled order
--    Two events per order: 'created' + 'paid' (status arrived as 'approved').
-- ==========================================================================

INSERT INTO commerce.order_events (
    order_id, event_type, source, shopify_triggered_at, occurred_at, metadata
)
SELECT o.id, 'created', 'system', o.occurred_at, o.created_at, '{"backfilled_from": "commission_ledger_entries"}'::jsonb
FROM commerce.orders o
WHERE NOT EXISTS (
    SELECT 1 FROM commerce.order_events oe
    WHERE oe.order_id = o.id AND oe.event_type = 'created'
);

INSERT INTO commerce.order_events (
    order_id, event_type, source, shopify_triggered_at, occurred_at, metadata, amount_delta_cents
)
SELECT o.id, 'paid', 'system', o.occurred_at, o.created_at,
       '{"backfilled_from": "commission_ledger_entries"}'::jsonb,
       o.commission_cents
FROM commerce.orders o
WHERE o.status = 'approved'
  AND NOT EXISTS (
    SELECT 1 FROM commerce.order_events oe
    WHERE oe.order_id = o.id AND oe.event_type = 'paid'
);

-- ==========================================================================
-- 3. Link ledger entries back to their corresponding orders via the FK added in Phase 1
-- ==========================================================================

UPDATE commerce.commission_ledger_entries cle
SET order_id = o.id
FROM commerce.orders o
WHERE cle.shopify_order_id = o.shopify_order_id
  AND cle.brand_professional_id = o.brand_professional_id
  AND cle.order_id IS NULL;

COMMIT;
