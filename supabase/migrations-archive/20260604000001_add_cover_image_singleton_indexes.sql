-- Per-integration cover images are design-pool singletons (one row per site per
-- platform), mirroring the existing logo_full / logo_square indexes in the
-- baseline (site.site_media section). `purpose` is free-text (no CHECK) and the
-- `design` pool is already allowed by site_media_pool_check, so this migration
-- only adds the singleton-enforcing partial unique indexes. The allowlist of
-- accepted purposes lives app-side in SiteMedia::DESIGN_SINGLETON_PURPOSES, and
-- the upload service also replaces an existing row of the same purpose, so these
-- indexes are a DB-level integrity backstop rather than the primary guard.
--
-- Index-only migration: CONCURRENTLY, no transaction (CONVENTIONS.md §1).

CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS site_media_design_cover_fresha_uq
    ON site.site_media (site_id)
    WHERE pool = 'design' AND purpose = 'cover_fresha' AND deleted_at IS NULL;

CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS site_media_design_cover_youtube_uq
    ON site.site_media (site_id)
    WHERE pool = 'design' AND purpose = 'cover_youtube' AND deleted_at IS NULL;

CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS site_media_design_cover_apple_music_uq
    ON site.site_media (site_id)
    WHERE pool = 'design' AND purpose = 'cover_apple_music' AND deleted_at IS NULL;

CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS site_media_design_cover_apple_podcast_uq
    ON site.site_media (site_id)
    WHERE pool = 'design' AND purpose = 'cover_apple_podcast' AND deleted_at IS NULL;

CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS site_media_design_cover_eventbrite_uq
    ON site.site_media (site_id)
    WHERE pool = 'design' AND purpose = 'cover_eventbrite' AND deleted_at IS NULL;
