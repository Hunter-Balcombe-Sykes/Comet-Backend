-- Shop brands: processed logo marks. ProcessShopBrandLogoJob runs the store's
-- logo/favicon through the logo processor (background removal + vectorize)
-- and stores the public URLs here; NULL until (or unless) processing lands.
--
-- ROLLBACK: ALTER TABLE site.shop_brands
--             DROP COLUMN IF EXISTS logo_mark_url,
--             DROP COLUMN IF EXISTS logo_mark_svg_url;
--           Clean for the SCHEMA. The R2 objects those URLs named are
--           orphaned rather than deleted; ProcessShopBrandLogoJob re-derives
--           them on the next run.

ALTER TABLE "site"."shop_brands"
    ADD COLUMN "logo_mark_url" "text",
    ADD COLUMN "logo_mark_svg_url" "text";
