-- DATA-2 follow-up — partial index on disconnected_at.
--
-- ReconcileStuckShopifyIntegrationsJob (LIFE-1) reads
-- `whereNotNull('access_token')->whereNull('disconnected_at')` every sweep —
-- the partial index keeps cardinality tiny since most rows will be NULL
-- (connected) at any given time. Mirrors the proven pattern from
-- 20260506200000_add_reconciled_through_to_integrations.sql.
--
-- CONCURRENTLY + outside any transaction per CONVENTIONS.md §1.

CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_professional_integrations_disconnected_at
    ON core.professional_integrations (disconnected_at)
    WHERE disconnected_at IS NOT NULL;
