-- Integrations audit — remove Booksy, Calendly, Quandoo and Podcast (RSS).
--
-- Their dashboard cards, connect endpoints, scrapers, nightly refresh and
-- sitepage sections are deleted in the same change; this clears any stored
-- connections and tightens the platform CHECK so nothing recreates them.

ALTER TABLE site.platform_connections
    DROP CONSTRAINT IF EXISTS platform_connections_platform_check;

DELETE FROM site.platform_connections WHERE platform IN ('booksy', 'calendly', 'quandoo', 'podcast');

ALTER TABLE site.platform_connections
    ADD CONSTRAINT platform_connections_platform_check
    CHECK (platform IN (
        'shop',
        'eventbrite', 'humanitix',
        'apple-music', 'apple-podcast', 'spotify', 'soundcloud', 'bandcamp',
        'mixcloud', 'deezer', 'tidal',
        'youtube', 'vimeo', 'twitch',
        'instagram', 'pinterest', 'tiktok', 'facebook',
        'fresha',
        'skool', 'strava', 'google-business'
    )) NOT VALID;

ALTER TABLE site.platform_connections
    VALIDATE CONSTRAINT platform_connections_platform_check;
