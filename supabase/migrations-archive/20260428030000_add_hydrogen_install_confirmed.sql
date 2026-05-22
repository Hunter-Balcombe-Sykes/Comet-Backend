-- Tracks whether the brand has confirmed they've installed Shopify Hydrogen.
-- Used by the embedded app setup wizard to skip the "Install Hydrogen" step on re-entry.
ALTER TABLE brand.brand_store_settings
    ADD COLUMN IF NOT EXISTS hydrogen_install_confirmed boolean NOT NULL DEFAULT false;
