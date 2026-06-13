-- 20260612130000_add_square_platform.sql
--
-- Adds 'square' to the platform_connections CHECK constraint so Square
-- Appointments connections can be stored. Square is a "Book now" deep link: the
-- user pastes their public Square booking URL and the public site renders a
-- button that opens it (no scraping). Fresha + Square are mutually exclusive
-- booking providers, enforced in the controllers.
--
-- Recreates the full list from 20260612100000 verbatim + 'square'. Every existing
-- value is a member of the new list, so validation cannot fail.
--
-- guard:no-unsafe-migrations:disable-file
-- Exempt: same justification as 20260612100000 — site.platform_connections holds
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
        'reddit', 'fresha', 'square', 'skool', 'strava', 'google-business', 'custom'
    ));
