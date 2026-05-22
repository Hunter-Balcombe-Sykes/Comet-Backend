-- Add product_gid to site_media for per-product photo association
ALTER TABLE site.site_media
    ADD COLUMN IF NOT EXISTS product_gid text;

CREATE INDEX IF NOT EXISTS site_media_product_gid_idx
    ON site.site_media (site_id, product_gid)
    WHERE product_gid IS NOT NULL;

-- Add per-affiliate custom photo toggle on brand partner links
ALTER TABLE brand.brand_partner_links
    ADD COLUMN IF NOT EXISTS custom_photos_enabled boolean;
