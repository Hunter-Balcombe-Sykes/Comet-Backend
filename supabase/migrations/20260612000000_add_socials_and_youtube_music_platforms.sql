-- Integrations: add the link-only social platforms (X, LinkedIn, Threads,
-- Reddit — Facebook-style connect, no scraping) and YouTube Music (RSS-fed
-- releases, Vimeo-style latest + items + curated highlights).
--
-- Idempotent on purpose: DROP IF EXISTS + re-ADD so a double-application
-- (MCP apply now, CLI push later replaying the repo file) is harmless.

ALTER TABLE site.platform_connections
    DROP CONSTRAINT IF EXISTS platform_connections_platform_check;

ALTER TABLE site.platform_connections
    ADD CONSTRAINT platform_connections_platform_check
    CHECK (platform IN (
        'shop',
        'eventbrite', 'humanitix',
        'apple-music', 'apple-podcast', 'spotify', 'soundcloud', 'bandcamp',
        'mixcloud', 'deezer', 'tidal', 'youtube-music',
        'youtube', 'vimeo', 'twitch',
        'instagram', 'pinterest', 'tiktok', 'facebook',
        'x', 'linkedin', 'threads', 'reddit',
        'fresha',
        'skool', 'strava', 'google-business'
    )) NOT VALID;

ALTER TABLE site.platform_connections
    VALIDATE CONSTRAINT platform_connections_platform_check;
