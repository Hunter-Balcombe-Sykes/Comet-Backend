-- Add domain_wizard_complete flag to brand_store_settings.
-- Tracks whether the brand has completed the domain setup step in the
-- Shopify embedded wizard. Separate from domain_mode so the wizard doesn't
-- incorrectly advance to "Complete" just because domain_mode has a default value.

ALTER TABLE brand.brand_store_settings
    ADD COLUMN IF NOT EXISTS domain_wizard_complete BOOLEAN NOT NULL DEFAULT FALSE;
