-- =====================================================================
-- Integrations v2 — provider-agnostic shop + new platform connectors
-- =====================================================================
-- 1) The shop integration becomes provider-agnostic: the connection row is
--    renamed 'shopify' → 'shop'. Its payload is unchanged in shape (a brand
--    map); each brand now carries a `provider` field (shopify / woocommerce /
--    generic) added code-side. resource_id moves with the rename because the
--    single-key trait stores it as the platform name.
-- 2) New connector platforms: spotify, soundcloud, bandcamp (music),
--    humanitix, ticketek (events), skool (community), square, timely
--    (booking links).
-- 3) 'woocommerce' is dropped as a PLATFORM value — it is a shop PROVIDER
--    inside the 'shop' brand map, not a standalone connection (the standalone
--    integration was reverted in PR #201). The lone dev-only test row goes.

ALTER TABLE site.platform_connections
    DROP CONSTRAINT IF EXISTS platform_connections_platform_check;

UPDATE site.platform_connections
SET platform = 'shop',
    resource_id = CASE WHEN resource_id = 'shopify' THEN 'shop' ELSE resource_id END
WHERE platform = 'shopify';

DELETE FROM site.platform_connections WHERE platform = 'woocommerce';

ALTER TABLE site.platform_connections
    ADD CONSTRAINT platform_connections_platform_check
    CHECK (platform IN (
        'shop',
        'eventbrite', 'humanitix', 'ticketek',
        'apple-music', 'apple-podcast', 'spotify', 'soundcloud', 'bandcamp',
        'youtube', 'instagram', 'fresha', 'tiktok', 'facebook',
        'skool', 'square', 'timely'
    )) NOT VALID;

ALTER TABLE site.platform_connections
    VALIDATE CONSTRAINT platform_connections_platform_check;
