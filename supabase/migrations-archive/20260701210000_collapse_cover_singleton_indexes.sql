-- FOUND-3: collapse the per-purpose design-singleton partial unique indexes into
-- ONE composite. The five per-cover indexes (20260604000001 + 20260604000002) and
-- the two baseline logo indexes each enforce the same intent — "one row per (site,
-- purpose)" — for a single hard-coded purpose. A composite (site_id, purpose) index
-- enforces it for EVERY design purpose at once, so a new platform cover slot needs
-- zero DB changes (the app-side allowlist now derives from the platform registry:
-- SiteMedia::designSingletonPurposes()). The dead `cover_shopify` slot is dropped,
-- not migrated (no `shopify` platform exists).
--
-- Strictly stronger: the composite rejects a 2nd live row for ANY (site, purpose)
-- pair, which is a superset of every index it replaces. It also subsumes the two
-- baseline logo indexes (logo_full / logo_square), which are dropped here as
-- redundant. It additionally introduces a (currently dormant) one-`placeholder`-
-- per-site guard — no code creates design-pool placeholders, and the baseline
-- `site_media_design_placeholder_sort_uq` (a different shape) is intentionally LEFT
-- in place. `purpose` is free-text (no CHECK); the design pool is already permitted
-- by site_media_pool_check, so this is index-only.
--
-- Index-only migration: CONCURRENTLY, no transaction (CONVENTIONS.md §1).

-- Drop the five per-purpose cover indexes (incl. the dead cover_shopify).
DROP INDEX CONCURRENTLY IF EXISTS site.site_media_design_cover_youtube_uq;
DROP INDEX CONCURRENTLY IF EXISTS site.site_media_design_cover_apple_music_uq;
DROP INDEX CONCURRENTLY IF EXISTS site.site_media_design_cover_apple_podcast_uq;
DROP INDEX CONCURRENTLY IF EXISTS site.site_media_design_cover_eventbrite_uq;
DROP INDEX CONCURRENTLY IF EXISTS site.site_media_design_cover_shopify_uq;

-- Drop the two baseline logo indexes (subsumed by the composite below).
DROP INDEX CONCURRENTLY IF EXISTS site.site_media_design_logo_full_uq;
DROP INDEX CONCURRENTLY IF EXISTS site.site_media_design_logo_square_uq;

-- One composite singleton guard over every design purpose.
CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS site_media_design_singleton_purpose_uq
    ON site.site_media (site_id, purpose)
    WHERE pool = 'design' AND deleted_at IS NULL;

-- ROLLBACK:
-- DROP INDEX CONCURRENTLY IF EXISTS site.site_media_design_singleton_purpose_uq;
-- -- recreate the two baseline logo indexes (verbatim from 20260526000000_baseline_standalone_user.sql):
-- CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS site_media_design_logo_full_uq ON site.site_media (site_id)
--     WHERE pool = 'design' AND purpose = 'logo_full' AND deleted_at IS NULL;
-- CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS site_media_design_logo_square_uq ON site.site_media (site_id)
--     WHERE pool = 'design' AND purpose = 'logo_square' AND deleted_at IS NULL;
-- -- recreate the FOUR live cover indexes (NOT cover_shopify — dead; NOT cover_fresha — already dropped pre-collapse):
-- CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS site_media_design_cover_youtube_uq ON site.site_media (site_id)
--     WHERE pool = 'design' AND purpose = 'cover_youtube' AND deleted_at IS NULL;
-- CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS site_media_design_cover_apple_music_uq ON site.site_media (site_id)
--     WHERE pool = 'design' AND purpose = 'cover_apple_music' AND deleted_at IS NULL;
-- CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS site_media_design_cover_apple_podcast_uq ON site.site_media (site_id)
--     WHERE pool = 'design' AND purpose = 'cover_apple_podcast' AND deleted_at IS NULL;
-- CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS site_media_design_cover_eventbrite_uq ON site.site_media (site_id)
--     WHERE pool = 'design' AND purpose = 'cover_eventbrite' AND deleted_at IS NULL;
