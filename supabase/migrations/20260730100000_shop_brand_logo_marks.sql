-- Shop brands: processed logo marks. ProcessShopBrandLogoJob runs the store's
-- logo/favicon through the logo processor (background removal + vectorize)
-- and stores the public URLs here; NULL until (or unless) processing lands.

ALTER TABLE "site"."shop_brands"
    ADD COLUMN "logo_mark_url" "text",
    ADD COLUMN "logo_mark_svg_url" "text";
