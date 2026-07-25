-- Remove Mixcloud. No embeddable player metadata, no structured data —
-- the integration is dropped: dashboard card, connect endpoints, API client,
-- nightly refresh and sitepage sections are deleted. Clear stored connections
-- and tighten the CHECK.

ALTER TABLE site.platform_connections
    DROP CONSTRAINT IF EXISTS platform_connections_platform_check;

DELETE FROM site.platform_connections WHERE platform = 'mixcloud';

ALTER TABLE site.platform_connections
    ADD CONSTRAINT platform_connections_platform_check
    CHECK (platform IN (
        'shop',
        'eventbrite', 'humanitix',
        'apple-music', 'apple-podcast', 'spotify', 'soundcloud', 'bandcamp',
        'deezer',
        'youtube', 'vimeo', 'twitch',
        'instagram', 'pinterest', 'tiktok', 'facebook',
        'fresha',
        'skool', 'strava', 'google-business'
    )) NOT VALID;

ALTER TABLE site.platform_connections
    VALIDATE CONSTRAINT platform_connections_platform_check;
