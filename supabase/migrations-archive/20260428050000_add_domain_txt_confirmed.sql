-- Track whether the brand has completed the Shopify domain TXT verification step
-- in the embedded wizard. Set to true after Side St provisions the Cloudflare
-- TXT record on the brand's behalf.

ALTER TABLE brand.brand_store_settings
    ADD COLUMN IF NOT EXISTS domain_txt_confirmed BOOLEAN NOT NULL DEFAULT FALSE;
