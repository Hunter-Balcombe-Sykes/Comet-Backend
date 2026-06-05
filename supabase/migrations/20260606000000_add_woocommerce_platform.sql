-- =====================================================================
-- Add 'woocommerce' to the platform_connections CHECK constraint
-- =====================================================================
-- The original constraint was applied in 20260602150238_create_platform_connections.sql.
-- Postgres requires dropping and recreating a CHECK constraint to modify it.

ALTER TABLE site.platform_connections
    DROP CONSTRAINT IF EXISTS platform_connections_platform_check;

ALTER TABLE site.platform_connections
    ADD CONSTRAINT platform_connections_platform_check
    CHECK (platform IN (
        'shopify', 'woocommerce', 'eventbrite', 'apple-music', 'apple-podcast',
        'youtube', 'instagram', 'fresha', 'tiktok', 'facebook'
    ));
