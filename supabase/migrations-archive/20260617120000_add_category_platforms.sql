-- 20260617120000_add_category_platforms.sql
--
-- Adds 'booking', 'reservations', and 'online-ordering' to the
-- platform_connections CHECK constraint. These are the new dashboard
-- integration CATEGORIES:
--   - booking / reservations: single-slot rows holding a custom-fallback entry
--     ({provider:'custom', url, name, favicon, logo}) when a pasted URL matches
--     no known provider. Known providers still store under fresha/square/opentable.
--   - online-ordering: a brand-keyed map row of ordering links (Uber Eats,
--     DoorDash, ...) plus their metadata (delivery/pickup, fees, time).
-- All three are dashboard-only — they are NOT served to the public sitepage
-- (empty allowlist in PublicIntegrationConnectionResource + a whereNotIn guard
-- in PublicIntegrationController).
--
-- Recreates the full list from 20260617000000 verbatim + the three new values.
-- Every existing value is a member of the new list, so validation cannot fail.
--
-- guard:no-unsafe-migrations:disable-file
-- Exempt: same justification as 20260617000000 — site.platform_connections holds
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
        'custom', 'opentable', 'booking', 'reservations', 'online-ordering'
    ));
