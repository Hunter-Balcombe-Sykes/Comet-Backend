-- analytics.cart_events — Tracks cart add and checkout start events fired from
-- Hydrogen storefronts. Feeds the shop analytics funnel: Visitors → Shop Opens
-- → Cart Adds → Checkouts → Orders (orders resolved from commission_ledger_entries).
--
-- event_type values:
--   cart_add       — visitor added a product to cart
--   checkout_start — visitor proceeded to Shopify hosted checkout

CREATE TABLE IF NOT EXISTS analytics.cart_events (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    site_id uuid NOT NULL REFERENCES site.sites(id) ON DELETE CASCADE,
    occurred_at timestamptz NOT NULL DEFAULT now(),
    event_type text NOT NULL,
    session_id uuid,
    visitor_id uuid,
    ip_hash text,
    shopify_product_id text,
    quantity integer,
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT cart_events_type_check CHECK (event_type IN ('cart_add', 'checkout_start'))
);

ALTER TABLE analytics.cart_events OWNER TO postgres;

CREATE INDEX cart_events_professional_occurred_idx ON analytics.cart_events (professional_id, occurred_at DESC);
CREATE INDEX cart_events_site_occurred_idx ON analytics.cart_events (site_id, occurred_at DESC);
CREATE INDEX cart_events_professional_type_idx ON analytics.cart_events (professional_id, event_type, occurred_at DESC);

-- RLS: cart events are write-only from the public ingest path; professionals
-- read their own via the authenticated API, not directly via Supabase client.
ALTER TABLE analytics.cart_events ENABLE ROW LEVEL SECURITY;

CREATE POLICY "cart_events_service_role_all"
    ON analytics.cart_events
    FOR ALL
    TO service_role
    USING (true)
    WITH CHECK (true);
