-- Master Pattern 24: Add indexes on FK columns missing them.
-- CREATE INDEX CONCURRENTLY cannot run inside a transaction.
-- No BEGIN/COMMIT here — this file must run outside any explicit transaction block.
--
-- Recovery note: if a CONCURRENTLY build is cancelled mid-flight, Postgres leaves
-- an INVALID index stub. IF NOT EXISTS will skip it, leaving the stub in place.
-- Recovery: DROP INDEX <name>; then re-run this migration.

-- Partial: NULL rows never match the ON DELETE SET NULL trigger lookup — exclude them.
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_sites_theme_id
    ON site.sites (theme_id) WHERE theme_id IS NOT NULL;

-- Full: brand_professional_id is NOT NULL on this table, so no partial needed.
-- The existing composite (affiliate_professional_id, brand_professional_id) can't serve
-- brand-alone FK cascade scans; this single-column index covers that case.
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_aps_brand_professional_id
    ON commerce.affiliate_product_selections (brand_professional_id);

-- Partial: topup_id is nullable (soft FK); NULL rows are never the target of a lookup.
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_wcsa_topup_id
    ON core.wallet_currency_switch_audit (topup_id) WHERE topup_id IS NOT NULL;
