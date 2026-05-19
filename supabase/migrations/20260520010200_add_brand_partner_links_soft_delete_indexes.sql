-- Plan §28.16 Migration A (indexes).
--
-- NOTE: this migration intentionally runs WITHOUT a BEGIN/COMMIT wrapper
-- because CREATE INDEX CONCURRENTLY cannot run inside a transaction block.
-- Do not add transaction wrapping here.
--
-- Two indexes:
--   - brand_partner_links_active_idx: partial — the hot path. Default Eloquent
--     queries via the SoftDeletes trait filter `deleted_at IS NULL`, so a
--     partial index serves them without indexing tombstoned rows.
--   - brand_partner_links_all_idx: composite over (affiliate_professional_id,
--     deleted_at) for ex-partner queries that walk historical links via
--     brandPartnerLinksAll().
--
-- To revert:
--   DROP INDEX CONCURRENTLY IF EXISTS brand.brand_partner_links_active_idx;
--   DROP INDEX CONCURRENTLY IF EXISTS brand.brand_partner_links_all_idx;

CREATE INDEX CONCURRENTLY IF NOT EXISTS brand_partner_links_active_idx
    ON brand.brand_partner_links (affiliate_professional_id)
    WHERE deleted_at IS NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS brand_partner_links_all_idx
    ON brand.brand_partner_links (affiliate_professional_id, deleted_at);
