-- =====================================================================
-- Add 'woocommerce' to the platform_connections CHECK constraint
-- =====================================================================
-- The original constraint was applied in 20260602150238_create_platform_connections.sql.
-- Postgres requires dropping and recreating a CHECK constraint to modify it.
--
-- guard:no-unsafe-migrations:disable-file
-- Exempt: site.platform_connections is empty in this pre-beta app, so rebuilding
-- the CHECK takes a harmless lock (no rows to validate). This migration is
-- intentionally retained after the WooCommerce revert (PR #201) and is already
-- applied to the DBs — rewriting its SQL to the NOT VALID + VALIDATE pattern
-- would diverge from the applied version. The marker satisfies the safety guard
-- without mutating applied SQL.

ALTER TABLE site.platform_connections
    DROP CONSTRAINT IF EXISTS platform_connections_platform_check;

ALTER TABLE site.platform_connections
    ADD CONSTRAINT platform_connections_platform_check
    CHECK (platform IN (
        'shopify', 'woocommerce', 'eventbrite', 'apple-music', 'apple-podcast',
        'youtube', 'instagram', 'fresha', 'tiktok', 'facebook'
    ));
