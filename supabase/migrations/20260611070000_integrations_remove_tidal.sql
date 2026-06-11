-- Integrations audit follow-up — remove TIDAL.
--
-- TIDAL offers no embeddable player for artist pages (the thing musicians
-- actually paste), so the integration is dropped entirely: dashboard card,
-- connect endpoints, scraper, nightly refresh and sitepage sections are
-- deleted in the same change. Clear stored connections and tighten the CHECK.

ALTER TABLE site.platform_connections
    DROP CONSTRAINT IF EXISTS platform_connections_platform_check;

DELETE FROM site.platform_connections WHERE platform = 'tidal';

ALTER TABLE site.platform_connections
    ADD CONSTRAINT platform_connections_platform_check
    CHECK (platform IN (
        'shop',
        'eventbrite', 'humanitix',
        'apple-music', 'apple-podcast', 'spotify', 'soundcloud', 'bandcamp',
        'mixcloud', 'deezer',
        'youtube', 'vimeo', 'twitch',
        'instagram', 'pinterest', 'tiktok', 'facebook',
        'fresha',
        'skool', 'strava', 'google-business'
    )) NOT VALID;

ALTER TABLE site.platform_connections
    VALIDATE CONSTRAINT platform_connections_platform_check;
