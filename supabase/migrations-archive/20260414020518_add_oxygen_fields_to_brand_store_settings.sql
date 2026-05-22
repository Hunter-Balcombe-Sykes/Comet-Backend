-- Add Oxygen deployment credentials to brand_store_settings.
-- oxygen_deployment_token is encrypted at the application layer (Laravel encrypted cast).
-- oxygen_storefront_id is the Shopify Oxygen storefront ID for the brand's deployment.

ALTER TABLE brand.brand_store_settings
    ADD COLUMN oxygen_deployment_token TEXT NULL,
    ADD COLUMN oxygen_storefront_id    VARCHAR(255) NULL;
