-- 20260617000000_add_opentable_platform.sql
--
-- Adds 'opentable' to the platform_connections CHECK constraint so OpenTable
-- reservation connections can be stored. OpenTable is a keyless embed: the user
-- pastes their OpenTable restaurant link, the controller reads the restaurant id
-- (rid) from the URL, and the public site renders OpenTable's reservation widget
-- (live availability + booking) in an iframe — no scraping, no auth.
--
-- Recreates the full list from 20260612130000 verbatim + 'opentable'. Every
-- existing value is a member of the new list, so validation cannot fail.
--
-- guard:no-unsafe-migrations:disable-file
-- Exempt: same justification as 20260612130000 — site.platform_connections holds
-- a handful of pre-beta rows, so the CHECK rebuild takes a harmless lock.

ALTER TABLE site.platform_connections
    DROP CONSTRAINT IF EXISTS platform_connections_platform_check;

ALTER TABLE site.platform_connections
    ADD CONSTRAINT platform_connections_platform_check
    CHECK (platform IN (
        'shop', 'eventbrite', 'humanitix', 'apple-music', 'apple-podcast',
        'spotify', 'soundcloud', 'bandcamp', 'mixcloud', 'deezer', 'tidal',
        'youtube-music', 'youtube', 'vimeo', 'twitch', 'instagram',
        'pinterest', 'tiktok', 'facebook', 'x', 'linkedin', 'threads',
        'reddit', 'fresha', 'square', 'skool', 'strava', 'google-business',
        'custom', 'opentable'
    ));
