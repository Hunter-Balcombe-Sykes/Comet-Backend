-- Analytics & Data Layer Rebuild — Phase 1 schema
-- See: docs/adr/0001-analytics-rebuild.md  and  docs/analytics-rebuild-plan.md (v3.1)
--
-- This migration:
--   1. Adds order_id columns (nullable) to commerce.commission_ledger_entries
--      and commerce.commission_payout_items so they can later FK to commerce.orders.
--   2. Adds 'clawback' and 'adjustment' to the ledger entry_type CHECK constraint.
--   3. Creates commerce.orders, commerce.order_events, commerce.order_items,
--      commerce.brand_affiliate_rollup with full indexes + RLS.
--   4. Creates jsonb_strip_pii() function for GDPR redaction.
--   5. Creates rollup-maintaining triggers (orders → rollup, ledger → rollup-clawback,
--      orders → order_items diff).
--
-- NOTE: The rename of commission_ledger_entries → commission_movements (Decision #1)
-- is DEFERRED to Phase 4 cleanup. The rename touches ~30 PHP files (webhook jobs,
-- services, controllers, tests) which are rewritten in Phase 3 anyway. Doing the
-- rename in Phase 1 would force a 30-file PHP update with nothing else changing,
-- so we keep the legacy table name through Phases 2–3 and rename atomically with
-- the cleanup PR.
--
-- All RLS policies follow the existing commerce.* pattern: party-select for brand/affiliate,
-- staff-all for manual corrections, app_backend role bypasses RLS for webhook writes.

BEGIN;

SET LOCAL search_path = commerce, public;

-- ==========================================================================
-- 1. Extend commission_ledger_entries entry_type CHECK to permit clawback + adjustment
-- ==========================================================================

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.check_constraints
         WHERE constraint_schema = 'commerce'
           AND constraint_name LIKE '%entry_type%'
           AND check_clause LIKE '%clawback%'
    ) THEN
        ALTER TABLE commerce.commission_ledger_entries
            DROP CONSTRAINT IF EXISTS commission_ledger_entries_entry_type_check;
        ALTER TABLE commerce.commission_ledger_entries
            ADD CONSTRAINT commission_ledger_entries_entry_type_check
            CHECK (entry_type IN ('accrual','reversal','payout','clawback','adjustment'));
    END IF;
END $$;

-- ==========================================================================
-- 2. Add order_id columns to commission_payout_items and commission_ledger_entries
--    (nullable; FKs added LATER in this file after commerce.orders is created).
-- ==========================================================================

ALTER TABLE commerce.commission_payout_items
    ADD COLUMN IF NOT EXISTS order_id uuid;

-- order_id on ledger entries — populated for clawbacks (and reversals during backfill).
-- Allows the clawback trigger to look up rollup target via FK instead of shopify_order_id
-- which has no cross-shop uniqueness guarantee.
ALTER TABLE commerce.commission_ledger_entries
    ADD COLUMN IF NOT EXISTS order_id uuid;

CREATE INDEX IF NOT EXISTS idx_cle_order_id
    ON commerce.commission_ledger_entries(order_id) WHERE order_id IS NOT NULL;

-- ==========================================================================
-- 3. jsonb_strip_pii — IMMUTABLE function for GDPR redaction
-- ==========================================================================

CREATE OR REPLACE FUNCTION public.jsonb_strip_pii(input jsonb, paths text[])
RETURNS jsonb LANGUAGE plpgsql IMMUTABLE AS $$
DECLARE
    result jsonb := input;
    path_str text;
    path_parts text[];
    array_field text;
    sub_path text[];
    arr jsonb;
    i int;
    new_arr jsonb;
BEGIN
    IF input IS NULL THEN RETURN NULL; END IF;

    FOREACH path_str IN ARRAY paths LOOP
        IF position('[*]' IN path_str) > 0 THEN
            -- Wildcard form: "field[*].subpath" — iterate array, recurse into each element
            array_field := split_part(path_str, '[*]', 1);
            sub_path := string_to_array(
                regexp_replace(split_part(path_str, '[*]', 2), '^\.', ''),
                '.'
            );

            arr := result #> string_to_array(array_field, '.');
            IF arr IS NOT NULL AND jsonb_typeof(arr) = 'array' THEN
                new_arr := '[]'::jsonb;
                FOR i IN 0 .. (jsonb_array_length(arr) - 1) LOOP
                    IF array_length(sub_path, 1) > 0 THEN
                        new_arr := new_arr || jsonb_build_array(
                            jsonb_set(arr->i, sub_path, '"REDACTED"'::jsonb, false)
                        );
                    ELSE
                        new_arr := new_arr || '["REDACTED"]'::jsonb;
                    END IF;
                END LOOP;
                result := jsonb_set(result, string_to_array(array_field, '.'), new_arr, false);
            END IF;
        ELSE
            -- Plain dotted path
            path_parts := string_to_array(path_str, '.');
            IF result #> path_parts IS NOT NULL THEN
                result := jsonb_set(result, path_parts, '"REDACTED"'::jsonb, false);
            END IF;
        END IF;
    END LOOP;

    RETURN result;
END;
$$;

COMMENT ON FUNCTION public.jsonb_strip_pii(jsonb, text[]) IS
    'GDPR helper: replaces values at dotted paths (with optional [*] array wildcards) '
    'with the literal "REDACTED". Used by RedactCustomerJob/RedactShopJob.';

-- ==========================================================================
-- 4. commerce.orders
-- ==========================================================================

CREATE TABLE commerce.orders (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),

    -- Shopify identity
    shopify_order_id text NOT NULL,
    shopify_shop_domain text NOT NULL,
    shopify_updated_at timestamptz NOT NULL,

    -- Tenancy
    brand_professional_id uuid NOT NULL REFERENCES core.professionals(id),
    affiliate_professional_id uuid NOT NULL REFERENCES core.professionals(id),
    customer_id uuid REFERENCES core.customers(id) ON DELETE SET NULL,

    -- State
    status text NOT NULL DEFAULT 'pending'
        CHECK (status IN ('stub','pending','approved','partially_refunded',
                          'refunded','cancelled','voided')),

    -- Money (bigint — overflow-safe)
    gross_cents bigint NOT NULL DEFAULT 0,
    discount_cents bigint NOT NULL DEFAULT 0,
    refund_cents bigint NOT NULL DEFAULT 0,
    net_cents bigint NOT NULL DEFAULT 0,
    commission_cents bigint NOT NULL DEFAULT 0,
    commission_rate numeric(7,4) NOT NULL DEFAULT 0,
    rate_source text NOT NULL DEFAULT 'pending',  -- 'product_metafield'|'brand_default'|'platform_default'|'manual'|'pending'
    currency_code char(3) NOT NULL DEFAULT 'AUD',

    -- Raw + reconciliation
    line_items jsonb NOT NULL DEFAULT '[]',
    shopify_data jsonb NOT NULL DEFAULT '{}',
    reconciled_at timestamptz,

    -- Stripe linkage
    stripe_payment_intent_id text,
    stripe_transfer_id text,
    payout_id uuid REFERENCES commerce.commission_payouts(id) ON DELETE SET NULL,

    occurred_at timestamptz NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now()
);

CREATE UNIQUE INDEX uq_orders_shop_order
    ON commerce.orders(shopify_shop_domain, shopify_order_id);

CREATE INDEX idx_orders_brand_status_occurred
    ON commerce.orders(brand_professional_id, status, occurred_at DESC);

CREATE INDEX idx_orders_affiliate_status_occurred
    ON commerce.orders(affiliate_professional_id, status, occurred_at DESC);

CREATE INDEX idx_orders_brand_affiliate_occurred
    ON commerce.orders(brand_professional_id, affiliate_professional_id, occurred_at DESC);

-- Audit fix (scale): per-brand-per-affiliate breakdown queries with status filter.
-- Without this, queries like "WHERE brand=? AND affiliate=? AND status IN (...) AND occurred_at BETWEEN ?"
-- scan all rows for the pair regardless of status. At 30 brands × 50 affiliates × thousands of orders
-- per pair, that's a multi-million-row scan per dashboard load.
CREATE INDEX idx_orders_brand_affiliate_status_occurred
    ON commerce.orders(brand_professional_id, affiliate_professional_id, status, occurred_at DESC);

-- BRIN: cheap append-only timeseries scan. Effective only if backfill inserts
-- in occurred_at order. See plan §"BRIN caveat".
CREATE INDEX idx_orders_occurred_brin
    ON commerce.orders USING BRIN(occurred_at)
    WITH (pages_per_range = 32);

CREATE INDEX idx_orders_unreconciled
    ON commerce.orders(brand_professional_id, shopify_updated_at)
    WHERE reconciled_at IS NULL;

-- Payout eligibility — 'approved' = paid + (no refund OR refund < gross)
CREATE INDEX idx_orders_payable
    ON commerce.orders(affiliate_professional_id, currency_code)
    WHERE status = 'approved' AND payout_id IS NULL;

ALTER TABLE commerce.orders OWNER TO postgres;
ALTER TABLE commerce.orders ENABLE ROW LEVEL SECURITY;

CREATE POLICY orders_party_select ON commerce.orders FOR SELECT TO authenticated
    USING (
        brand_professional_id = (SELECT id FROM core.professionals
            WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR affiliate_professional_id = (SELECT id FROM core.professionals
            WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

CREATE POLICY orders_staff_write ON commerce.orders FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY orders_staff_update ON commerce.orders FOR UPDATE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- Now that commerce.orders exists, add the FK constraints deferred from section 2
ALTER TABLE commerce.commission_payout_items
    ADD CONSTRAINT commission_payout_items_order_id_fkey
    FOREIGN KEY (order_id) REFERENCES commerce.orders(id) ON DELETE RESTRICT;

ALTER TABLE commerce.commission_ledger_entries
    ADD CONSTRAINT commission_ledger_entries_order_id_fkey
    FOREIGN KEY (order_id) REFERENCES commerce.orders(id) ON DELETE SET NULL;

-- ==========================================================================
-- 5. commerce.order_events  (append-only audit log)
-- ==========================================================================

CREATE TABLE commerce.order_events (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    order_id uuid NOT NULL REFERENCES commerce.orders(id) ON DELETE CASCADE,

    event_type text NOT NULL CHECK (event_type IN (
        'created','paid','edited','fulfilled',
        'partially_refunded','refunded','cancelled','voided',
        'commission_approved','commission_paid','commission_clawed_back',
        'manual_adjustment'
    )),

    amount_delta_cents bigint,
    metadata jsonb NOT NULL DEFAULT '{}',

    source text NOT NULL CHECK (source IN ('webhook','reconciler','manual','stripe','system')),
    shopify_event_id text,
    shopify_triggered_at timestamptz NOT NULL,

    occurred_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_order_events_order
    ON commerce.order_events(order_id, shopify_triggered_at);

-- Canonical webhook idempotency — Shopify's documented X-Shopify-Event-Id
CREATE UNIQUE INDEX uq_order_events_shopify_event
    ON commerce.order_events(shopify_event_id) WHERE shopify_event_id IS NOT NULL;

ALTER TABLE commerce.order_events OWNER TO postgres;
ALTER TABLE commerce.order_events ENABLE ROW LEVEL SECURITY;

CREATE POLICY order_events_party_select ON commerce.order_events FOR SELECT TO authenticated
    USING (EXISTS (
        SELECT 1 FROM commerce.orders o
        WHERE o.id = commerce.order_events.order_id
        AND (
            o.brand_professional_id = (SELECT id FROM core.professionals
                WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
            OR o.affiliate_professional_id = (SELECT id FROM core.professionals
                WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
            OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
        )
    ));

CREATE POLICY order_events_staff_write ON commerce.order_events FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- 6. commerce.order_items  (normalized mirror of line_items JSONB)
-- ==========================================================================

CREATE TABLE commerce.order_items (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    order_id uuid NOT NULL REFERENCES commerce.orders(id) ON DELETE CASCADE,

    shopify_line_item_id text NOT NULL,
    shopify_product_id text NOT NULL,
    shopify_variant_id text,
    sku text,
    title text NOT NULL,

    quantity integer NOT NULL,
    unit_price_cents bigint NOT NULL,
    discount_cents bigint NOT NULL DEFAULT 0,
    line_total_cents bigint NOT NULL,
    commission_cents bigint NOT NULL,
    commission_rate numeric(7,4) NOT NULL,

    -- Denormalized for analytics filtering (no extra join)
    brand_professional_id uuid NOT NULL,
    affiliate_professional_id uuid NOT NULL,
    occurred_at timestamptz NOT NULL,
    currency_code char(3) NOT NULL,

    UNIQUE (order_id, shopify_line_item_id)
);

CREATE INDEX idx_order_items_brand_product_occurred
    ON commerce.order_items(brand_professional_id, shopify_product_id, occurred_at DESC);

CREATE INDEX idx_order_items_affiliate_product_occurred
    ON commerce.order_items(affiliate_professional_id, shopify_product_id, occurred_at DESC);

ALTER TABLE commerce.order_items OWNER TO postgres;
ALTER TABLE commerce.order_items ENABLE ROW LEVEL SECURITY;

CREATE POLICY order_items_party_select ON commerce.order_items FOR SELECT TO authenticated
    USING (
        brand_professional_id = (SELECT id FROM core.professionals
            WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR affiliate_professional_id = (SELECT id FROM core.professionals
            WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

CREATE POLICY order_items_staff_write ON commerce.order_items FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- 7. commerce.brand_affiliate_rollup  (trigger-maintained incremental rollup)
-- ==========================================================================

CREATE TABLE commerce.brand_affiliate_rollup (
    day date NOT NULL,                              -- UTC date — see ADR
    brand_professional_id uuid NOT NULL,
    affiliate_professional_id uuid NOT NULL,
    currency_code char(3) NOT NULL,

    orders_count integer NOT NULL DEFAULT 0,
    gross_cents bigint NOT NULL DEFAULT 0,
    refund_cents bigint NOT NULL DEFAULT 0,
    net_cents bigint NOT NULL DEFAULT 0,
    commission_cents bigint NOT NULL DEFAULT 0,
    reversed_commission_cents bigint NOT NULL DEFAULT 0,

    updated_at timestamptz NOT NULL DEFAULT now(),
    PRIMARY KEY (day, brand_professional_id, affiliate_professional_id, currency_code)
);

-- Audit fix (scale): brand-leading index for the dashboard's primary read pattern.
-- The PK leads with `day` so a query like "WHERE brand=? AND day BETWEEN ?" can't use the PK
-- efficiently — at 2.7M rollup rows over 5 years, that's a sequential scan per dashboard load.
CREATE INDEX idx_rollup_brand_day
    ON commerce.brand_affiliate_rollup(brand_professional_id, day DESC);

CREATE INDEX idx_rollup_affiliate_day
    ON commerce.brand_affiliate_rollup(affiliate_professional_id, day DESC);

ALTER TABLE commerce.brand_affiliate_rollup OWNER TO postgres;
ALTER TABLE commerce.brand_affiliate_rollup ENABLE ROW LEVEL SECURITY;

CREATE POLICY rollup_party_select ON commerce.brand_affiliate_rollup FOR SELECT TO authenticated
    USING (
        brand_professional_id = (SELECT id FROM core.professionals
            WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR affiliate_professional_id = (SELECT id FROM core.professionals
            WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid())
    );

-- Rollup is trigger-maintained; INSERT/UPDATE happen via trigger context, not user code.
-- Staff get full access for emergency manual repair.
CREATE POLICY rollup_staff_all ON commerce.brand_affiliate_rollup TO authenticated
    USING (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.sidest_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- 8. Trigger: rollup_apply_delta on commerce.orders
-- ==========================================================================

CREATE OR REPLACE FUNCTION commerce.rollup_apply_delta()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
DECLARE
    _day date;
    _orders_delta integer := 0;
    _gross_delta bigint := 0;
    _refund_delta bigint := 0;
    _net_delta bigint := 0;
    _commission_delta bigint := 0;
    _reversed_delta bigint := 0;
BEGIN
    -- Skip stub rows — they don't represent real orders yet
    IF NEW.status = 'stub' THEN
        RETURN NEW;
    END IF;

    _day := DATE(NEW.occurred_at AT TIME ZONE 'UTC');

    IF TG_OP = 'INSERT' OR (TG_OP = 'UPDATE' AND OLD.status = 'stub') THEN
        -- New order, OR stub being promoted to a real order
        _orders_delta := 1;
        _gross_delta := NEW.gross_cents;
        _refund_delta := NEW.refund_cents;
        _net_delta := NEW.net_cents;
        _commission_delta := NEW.commission_cents;
        _reversed_delta := 0;

    ELSIF TG_OP = 'UPDATE' THEN
        -- Status moves to terminal cancelled/voided: reverse all previously counted values
        IF NEW.status IN ('cancelled','voided') AND OLD.status NOT IN ('cancelled','voided') THEN
            _orders_delta := -1;
            _gross_delta := -OLD.gross_cents;
            _refund_delta := -OLD.refund_cents;
            _net_delta := -OLD.net_cents;
            _commission_delta := -OLD.commission_cents;
            _reversed_delta := OLD.commission_cents;  -- track the full reversal
        ELSE
            -- Generic field-level deltas
            _orders_delta := 0;
            _gross_delta := NEW.gross_cents - OLD.gross_cents;
            _refund_delta := NEW.refund_cents - OLD.refund_cents;
            _net_delta := NEW.net_cents - OLD.net_cents;
            _commission_delta := NEW.commission_cents - OLD.commission_cents;
            -- Proportional commission reversal for refund_cents increases
            _reversed_delta := COALESCE(
                ROUND(((NEW.refund_cents - OLD.refund_cents)::numeric
                       / NULLIF(NEW.gross_cents, 0))
                      * NEW.commission_cents),
                0
            );
        END IF;
    END IF;

    -- No-op shortcut
    IF _orders_delta = 0 AND _gross_delta = 0 AND _refund_delta = 0
       AND _net_delta = 0 AND _commission_delta = 0 AND _reversed_delta = 0 THEN
        RETURN NEW;
    END IF;

    INSERT INTO commerce.brand_affiliate_rollup (
        day, brand_professional_id, affiliate_professional_id, currency_code,
        orders_count, gross_cents, refund_cents, net_cents,
        commission_cents, reversed_commission_cents, updated_at
    )
    VALUES (
        _day, NEW.brand_professional_id, NEW.affiliate_professional_id, NEW.currency_code,
        _orders_delta, _gross_delta, _refund_delta, _net_delta,
        _commission_delta, _reversed_delta, now()
    )
    ON CONFLICT (day, brand_professional_id, affiliate_professional_id, currency_code)
    DO UPDATE SET
        orders_count = brand_affiliate_rollup.orders_count + EXCLUDED.orders_count,
        gross_cents = brand_affiliate_rollup.gross_cents + EXCLUDED.gross_cents,
        refund_cents = brand_affiliate_rollup.refund_cents + EXCLUDED.refund_cents,
        net_cents = brand_affiliate_rollup.net_cents + EXCLUDED.net_cents,
        commission_cents = brand_affiliate_rollup.commission_cents + EXCLUDED.commission_cents,
        reversed_commission_cents = brand_affiliate_rollup.reversed_commission_cents
                                  + EXCLUDED.reversed_commission_cents,
        updated_at = now();

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_rollup
    AFTER INSERT OR UPDATE ON commerce.orders
    FOR EACH ROW EXECUTE FUNCTION commerce.rollup_apply_delta();

-- ==========================================================================
-- 9. Trigger: rollup_apply_clawback on commerce.commission_movements
--    Post-payout clawbacks bypass the order-level trigger; sync directly to rollup.
-- ==========================================================================

CREATE OR REPLACE FUNCTION commerce.rollup_apply_clawback()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
DECLARE
    _day date;
    _brand uuid;
    _affiliate uuid;
    _currency char(3);
BEGIN
    IF NEW.entry_type != 'clawback' THEN
        RETURN NEW;
    END IF;

    IF NEW.order_id IS NULL THEN
        -- Clawback must reference its parent order so the rollup can find the right (day, brand, affiliate, currency).
        RAISE EXCEPTION 'Clawback movement requires order_id (movement id=%)', NEW.id;
    END IF;

    SELECT DATE(o.occurred_at AT TIME ZONE 'UTC'),
           o.brand_professional_id,
           o.affiliate_professional_id,
           o.currency_code
    INTO _day, _brand, _affiliate, _currency
    FROM commerce.orders o
    WHERE o.id = NEW.order_id;

    IF _day IS NULL THEN
        RAISE EXCEPTION 'Clawback references missing order_id=%', NEW.order_id;
    END IF;

    INSERT INTO commerce.brand_affiliate_rollup (
        day, brand_professional_id, affiliate_professional_id, currency_code,
        reversed_commission_cents, updated_at
    )
    VALUES (_day, _brand, _affiliate, _currency, ABS(NEW.amount_cents), now())
    ON CONFLICT (day, brand_professional_id, affiliate_professional_id, currency_code)
    DO UPDATE SET
        reversed_commission_cents = brand_affiliate_rollup.reversed_commission_cents
                                  + EXCLUDED.reversed_commission_cents,
        updated_at = now();

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_rollup_clawback
    AFTER INSERT ON commerce.commission_ledger_entries
    FOR EACH ROW EXECUTE FUNCTION commerce.rollup_apply_clawback();

-- ==========================================================================
-- 10. Trigger: order_items_diff on commerce.orders
--     Reconciles commerce.order_items rows from line_items JSONB.
--     The webhook handler MUST pre-compute commission_cents and commission_rate
--     on each line_items element before upserting orders. See plan §order_items.
-- ==========================================================================

CREATE OR REPLACE FUNCTION commerce.order_items_diff()
RETURNS TRIGGER LANGUAGE plpgsql AS $$
BEGIN
    -- Skip stubs — line_items=[]; nothing to diff
    IF NEW.status = 'stub' OR jsonb_array_length(NEW.line_items) = 0 THEN
        RETURN NEW;
    END IF;

    -- Delete rows no longer in line_items
    DELETE FROM commerce.order_items
    WHERE order_id = NEW.id
      AND shopify_line_item_id NOT IN (
          SELECT li->>'shopify_line_item_id'
          FROM jsonb_array_elements(NEW.line_items) li
      );

    -- Upsert rows present in line_items
    INSERT INTO commerce.order_items (
        order_id, shopify_line_item_id, shopify_product_id, shopify_variant_id,
        sku, title, quantity, unit_price_cents, discount_cents, line_total_cents,
        commission_cents, commission_rate,
        brand_professional_id, affiliate_professional_id, occurred_at, currency_code
    )
    SELECT
        NEW.id,
        li->>'shopify_line_item_id',
        li->>'shopify_product_id',
        li->>'shopify_variant_id',
        li->>'sku',
        li->>'title',
        (li->>'quantity')::integer,
        (li->>'unit_price_cents')::bigint,
        COALESCE((li->>'discount_cents')::bigint, 0),
        (li->>'line_total_cents')::bigint,
        (li->>'commission_cents')::bigint,
        (li->>'commission_rate')::numeric(7,4),
        NEW.brand_professional_id,
        NEW.affiliate_professional_id,
        NEW.occurred_at,
        NEW.currency_code
    FROM jsonb_array_elements(NEW.line_items) li
    ON CONFLICT (order_id, shopify_line_item_id)
    DO UPDATE SET
        quantity = EXCLUDED.quantity,
        unit_price_cents = EXCLUDED.unit_price_cents,
        discount_cents = EXCLUDED.discount_cents,
        line_total_cents = EXCLUDED.line_total_cents,
        commission_cents = EXCLUDED.commission_cents,
        commission_rate = EXCLUDED.commission_rate,
        title = EXCLUDED.title,
        sku = EXCLUDED.sku;

    RETURN NEW;
END;
$$;

CREATE TRIGGER trg_order_items_diff
    AFTER INSERT OR UPDATE OF line_items ON commerce.orders
    FOR EACH ROW EXECUTE FUNCTION commerce.order_items_diff();

-- ==========================================================================
-- 11. BRIN indexes on analytics.* event tables
--     Audit fix (scale): site_visits / link_clicks / cart_events are append-only event
--     streams. At 30 brands × 50 affiliates × ~1k visits/aff/month they reach ~90M rows
--     in 5 years. BRIN on occurred_at is kilobytes (vs gigabytes for B-tree) and effective
--     because rows are inserted in occurred_at order. Time-range scans (the dominant
--     analytics query pattern) use BRIN to skip blocks cheaply.
-- ==========================================================================

CREATE INDEX IF NOT EXISTS idx_site_visits_occurred_brin
    ON analytics.site_visits USING BRIN(occurred_at)
    WITH (pages_per_range = 64);

CREATE INDEX IF NOT EXISTS idx_link_clicks_occurred_brin
    ON analytics.link_clicks USING BRIN(occurred_at)
    WITH (pages_per_range = 64);

CREATE INDEX IF NOT EXISTS idx_cart_events_occurred_brin
    ON analytics.cart_events USING BRIN(occurred_at)
    WITH (pages_per_range = 64);

COMMIT;
