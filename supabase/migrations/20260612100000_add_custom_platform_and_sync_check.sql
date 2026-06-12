-- =====================================================================
-- Add 'custom' to the platform_connections CHECK constraint + sync repo↔DB
-- =====================================================================
-- The live dev/prod constraint had been extended directly on the database
-- (27 platforms) while the repo's last file-tracked version
-- (20260606000000_add_woocommerce_platform.sql) still listed 10. This
-- migration recreates the CHECK with the full live list — verbatim from the
-- dev definition on 2026-06-12 — plus the new 'custom' platform (user-added
-- arbitrary URLs rendered as a Links section: favicon/logo/name/description
-- scraped at connect time, one row per link under resource_id 'link-<hash>').
--
-- guard:no-unsafe-migrations:disable-file
-- Exempt: same justification as 20260606000000 — site.platform_connections
-- holds a handful of pre-beta rows, so the CHECK rebuild takes a harmless
-- lock. All existing values are members of the new list (it is a superset),
-- so validation cannot fail.

ALTER TABLE site.platform_connections
    DROP CONSTRAINT IF EXISTS platform_connections_platform_check;

ALTER TABLE site.platform_connections
    ADD CONSTRAINT platform_connections_platform_check
    CHECK (platform IN (
        'shop', 'eventbrite', 'humanitix', 'apple-music', 'apple-podcast',
        'spotify', 'soundcloud', 'bandcamp', 'mixcloud', 'deezer', 'tidal',
        'youtube-music', 'youtube', 'vimeo', 'twitch', 'instagram',
        'pinterest', 'tiktok', 'facebook', 'x', 'linkedin', 'threads',
        'reddit', 'fresha', 'skool', 'strava', 'google-business', 'custom'
    ));
