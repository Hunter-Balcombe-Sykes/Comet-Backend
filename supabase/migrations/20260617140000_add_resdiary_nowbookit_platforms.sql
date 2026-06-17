-- 20260617140000_add_resdiary_nowbookit_platforms.sql
--
-- Adds 'resdiary' and 'nowbookit' to the platform_connections CHECK constraint.
-- Both are keyless reservation providers (same model as 'opentable'): connect by
-- restaurant link, store the booking-widget embed URL, render it in an iframe.
--
-- Recreates the full list from 20260617120000 verbatim + the two new values.
-- Every existing value is a member of the new list, so validation cannot fail.
--
-- guard:no-unsafe-migrations:disable-file
-- Exempt: same justification as 20260617120000 — site.platform_connections holds
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
        'custom', 'opentable', 'booking', 'reservations', 'online-ordering',
        'resdiary', 'nowbookit'
    ));
