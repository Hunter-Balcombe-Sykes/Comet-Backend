-- ============================================================================
-- Stripe Billing Integration
-- Adds webhook event log for idempotent processing + new plan tiers
-- ============================================================================

-- Idempotent webhook event log (prevents double-processing on Stripe retries)
CREATE TABLE IF NOT EXISTS billing.webhook_events (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    stripe_event_id text NOT NULL UNIQUE,
    event_type text NOT NULL,
    payload jsonb,
    processed_at timestamptz NOT NULL DEFAULT now()
);

CREATE INDEX idx_webhook_events_type ON billing.webhook_events (event_type);
CREATE INDEX idx_webhook_events_processed_at ON billing.webhook_events (processed_at);

GRANT SELECT, INSERT, UPDATE, DELETE ON billing.webhook_events TO app_backend;
ALTER TABLE billing.webhook_events ENABLE ROW LEVEL SECURITY;

-- ----------------------------------------------------------------------------
-- Update plan seed data: replace old tiers (free/pro/elite) with new tiers
-- (free/professional/brands)
-- ----------------------------------------------------------------------------

-- Deactivate old plans that no longer apply
UPDATE billing.plans SET is_active = false WHERE plan_key IN ('pro', 'elite');

-- Ensure free plan is active
UPDATE billing.plans SET is_active = true WHERE plan_key = 'free';

-- Insert new plans (upsert: if plan_key already exists, update fields)
INSERT INTO billing.plans (id, plan_key, name, stripe_price_id, is_active, sort_order, entitlements, description, price_cents, currency_code, billing_interval)
VALUES
    (gen_random_uuid(), 'professional', 'Professional', 'price_PROFESSIONAL_REPLACE_ME', true, 1,
     '{"sites": 1, "team_members": 5, "services": 50, "analytics": true, "priority_support": true}'::jsonb,
     'For affiliates who want to grow their business', 0, 'AUD', 'month'),
    (gen_random_uuid(), 'brands', 'Brands', 'price_BRANDS_REPLACE_ME', true, 2,
     '{"sites": 1, "team_members": 10, "services": 100, "analytics": true, "store": true, "commission_management": true}'::jsonb,
     'For brands selling through affiliates', 0, 'AUD', 'month')
ON CONFLICT (plan_key) DO UPDATE SET
    name = EXCLUDED.name,
    is_active = true,
    entitlements = EXCLUDED.entitlements,
    description = EXCLUDED.description;
