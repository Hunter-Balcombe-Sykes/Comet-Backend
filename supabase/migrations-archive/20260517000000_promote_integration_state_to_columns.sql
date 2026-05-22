-- DATA-2: promote disconnected_at and webhook_registration_state from
-- provider_metadata JSONB to real columns. Both gate control-flow decisions
-- (BrandStatusService treats disconnected_at as the first short-circuit;
-- EmbeddedSetupController re-dispatches setup jobs when webhook_registration_state
-- is still 'queued') and benefit from typed columns + indexability over JSONB lookups.
--
-- Also drops the redundant webhooks_state dual-write: RegisterShopifyWebhooksJob
-- and ShopifyAppUninstalledWebhookController previously wrote BOTH keys; reader
-- gravity favours webhook_registration_state, so we keep that name and delete
-- the duplicate. webhooks_state is removed everywhere — backfill COALESCEs both
-- so neither past writer is silently lost.
--
-- Sibling files (per supabase/migrations/CONVENTIONS.md):
--   20260517000001_validate_integration_state_check.sql   — VALIDATE CONSTRAINT
--   20260517000002_index_integration_disconnected_at.sql  — CREATE INDEX CONCURRENTLY

BEGIN;

ALTER TABLE core.professional_integrations
    ADD COLUMN IF NOT EXISTS disconnected_at timestamptz NULL,
    ADD COLUMN IF NOT EXISTS webhook_registration_state text NULL;

-- Defensive check: enumerate the values RegisterShopifyWebhooksJob,
-- EmbeddedSetupController, ShopifyAppUninstalledWebhookController, and
-- ReconcileStuckShopifyIntegrationsJob actually write. Allows NULL for
-- pre-Shopify and non-Shopify integrations. NOT VALID per CONVENTIONS.md §2 —
-- the sibling 20260517000001 file VALIDATEs after the backfill below has
-- made every row compliant.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'professional_integrations_webhook_registration_state_check'
    ) THEN
        ALTER TABLE core.professional_integrations
            ADD CONSTRAINT professional_integrations_webhook_registration_state_check
            CHECK (webhook_registration_state IS NULL OR webhook_registration_state IN (
                'queued',
                'registered',
                'partial',
                'failed',
                'uninstalled'
            )) NOT VALID;
    END IF;
END
$$;

-- Backfill from JSONB so existing brands don't regress to NULL.
-- webhook_registration_state: COALESCE the canonical key with the legacy
-- webhooks_state — both have always been kept in sync by the writer side, but
-- if for any reason one row only has one, we want it. Safe to backfill inside
-- the transaction because the table is small (low hundreds of rows).
UPDATE core.professional_integrations
SET
    disconnected_at = NULLIF(provider_metadata->>'disconnected_at', '')::timestamptz,
    webhook_registration_state = NULLIF(
        COALESCE(
            provider_metadata->>'webhook_registration_state',
            provider_metadata->>'webhooks_state'
        ),
        ''
    )
WHERE provider_metadata ? 'disconnected_at'
   OR provider_metadata ? 'webhook_registration_state'
   OR provider_metadata ? 'webhooks_state';

-- Strip the promoted keys (and the redundant duplicate) from JSONB so
-- there's a single source of truth. After this migration, any read of
-- provider_metadata->>'disconnected_at' will return NULL — the new column
-- is authoritative. Application code is updated to read columns directly
-- in the same deploy.
UPDATE core.professional_integrations
SET provider_metadata = provider_metadata
    - 'disconnected_at'
    - 'webhook_registration_state'
    - 'webhooks_state'
WHERE provider_metadata ?| ARRAY['disconnected_at', 'webhook_registration_state', 'webhooks_state'];

COMMIT;
