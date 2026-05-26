-- Extend billing.webhook_events to dedupe webhooks from multiple providers.
--
-- Pre-existing: column-level UNIQUE on stripe_event_id covered Stripe events only.
-- Shopify webhook controllers were using Cache::add for idempotency (24h TTL,
-- evictable on Redis flush) — adding Shopify event IDs to this same table needs
-- a composite unique so Shopify IDs and Stripe IDs cannot collide (in practice
-- they have different formats but the schema should enforce it).
--
-- Migration steps:
--   1. Add provider column with default 'stripe' so existing rows are tagged correctly.
--   2. Drop the column-level unique on stripe_event_id.
--   3. Recreate as a composite (provider, stripe_event_id) unique index.
--
-- The column name `stripe_event_id` is kept for backward compatibility — semantically
-- it's now an "external_event_id" but renaming would require a coordinated PR sweep.

ALTER TABLE billing.webhook_events
    ADD COLUMN IF NOT EXISTS provider TEXT NOT NULL DEFAULT 'stripe';

-- Drop the column-level unique constraint. The constraint name follows the
-- Postgres convention `<table>_<column>_key`. If it was created differently,
-- the DO block falls back to dropping by index.
DO $$
DECLARE
    constraint_name text;
BEGIN
    SELECT conname INTO constraint_name
    FROM pg_constraint
    WHERE conrelid = 'billing.webhook_events'::regclass
      AND contype = 'u'
      AND pg_get_constraintdef(oid) LIKE '%stripe_event_id%'
      AND pg_get_constraintdef(oid) NOT LIKE '%provider%';

    IF constraint_name IS NOT NULL THEN
        EXECUTE format('ALTER TABLE billing.webhook_events DROP CONSTRAINT %I', constraint_name);
    END IF;
END$$;

-- Composite unique covering both providers.
CREATE UNIQUE INDEX IF NOT EXISTS webhook_events_provider_event_idx
    ON billing.webhook_events (provider, stripe_event_id);

COMMENT ON COLUMN billing.webhook_events.provider IS
    'Webhook source. Values: stripe, shopify. Composite-unique with stripe_event_id.';
