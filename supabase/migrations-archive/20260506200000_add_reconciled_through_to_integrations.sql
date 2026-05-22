-- Phase 3 follow-up: promote reconciled_through from provider_metadata JSON
-- to a real column for queryability and indexability.
-- Read/written by ReconcileShopifyOrders command on each successful sweep.

ALTER TABLE core.professional_integrations
    ADD COLUMN IF NOT EXISTS reconciled_through timestamptz NULL;

CREATE INDEX IF NOT EXISTS idx_professional_integrations_reconciled_through
    ON core.professional_integrations (reconciled_through)
    WHERE reconciled_through IS NOT NULL;
