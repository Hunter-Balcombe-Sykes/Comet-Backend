-- Swap the per-integration cover image singleton: drop Fresha's, add Shopify's
-- (products). `purpose` is free-text (no CHECK); the accepted-purpose allowlist
-- lives app-side in SiteMedia::DESIGN_SINGLETON_PURPOSES, so any pre-existing
-- cover_fresha rows simply stop resolving once that constant drops it (the
-- resolver filters by the allowlist). These partial unique indexes are the
-- DB-level singleton backstop, mirroring 20260604000001.
--
-- Index-only migration: CONCURRENTLY, no transaction (CONVENTIONS.md §1).

DROP INDEX CONCURRENTLY IF EXISTS site.site_media_design_cover_fresha_uq;

CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS site_media_design_cover_shopify_uq
    ON site.site_media (site_id)
    WHERE pool = 'design' AND purpose = 'cover_shopify' AND deleted_at IS NULL;
