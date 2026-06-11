-- =====================================================================
-- Integrations v3 — probe-verified platform set
-- =====================================================================
-- 1) Removes the three link-only platforms that could not graduate to real
--    data: 'ticketek' (Akamai 403s all automated reads), 'square' and
--    'timely' (booking pages are empty JS shells with no extractable
--    business data). Their connection rows go with them.
-- 2) Adds the twelve probe-verified keyless platforms:
--      vimeo      — legacy Simple API (info.json + videos.json)
--      twitch     — og-tag scrape + keyless live player embed
--      mixcloud   — open JSON API (profile + cloudcasts) + widget embed
--      deezer     — open JSON API (artist) + keyless widget embed
--      tidal      — public oEmbed (embed player)
--      podcast    — any RSS feed (direct or autodiscovered from a page)
--      pinterest  — profile page JSON + public RSS pin feed
--      booksy     — listing-page JSON-LD (name / rating / reviews)
--      calendly   — public booking-profile JSON API (+ event types)
--      quandoo    — restaurant-page JSON-LD (rating / cuisine / address)
--      strava     — club-page og tags + member count
--      google-business — Maps share-link parse (name / coords / map embed)
--    Shop PROVIDERS squarespace + bigcartel are payload-level (inside the
--    'shop' brand map), not platform values — same as woocommerce.

ALTER TABLE site.platform_connections
    DROP CONSTRAINT IF EXISTS platform_connections_platform_check;

DELETE FROM site.platform_connections WHERE platform IN ('ticketek', 'square', 'timely');

ALTER TABLE site.platform_connections
    ADD CONSTRAINT platform_connections_platform_check
    CHECK (platform IN (
        'shop',
        'eventbrite', 'humanitix',
        'apple-music', 'apple-podcast', 'spotify', 'soundcloud', 'bandcamp',
        'mixcloud', 'deezer', 'tidal',
        'youtube', 'vimeo', 'twitch',
        'podcast',
        'instagram', 'pinterest', 'tiktok', 'facebook',
        'fresha', 'booksy', 'calendly', 'quandoo',
        'skool', 'strava', 'google-business'
    )) NOT VALID;

ALTER TABLE site.platform_connections
    VALIDATE CONSTRAINT platform_connections_platform_check;
