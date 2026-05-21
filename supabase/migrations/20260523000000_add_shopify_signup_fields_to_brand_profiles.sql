-- Add fields populated from Shopify during brand signup / install.
--   slogan + short_description originate from the Storefront Brand object
--   (already fetched by BrandDesignImporter; SyncShopifyBrandDesignJob dual-
--   writes them here in addition to site.settings.design.slogan so the
--   BrandProfile row stays a canonical home for brand-identity data).
--   locale, shopify_plan, money_format originate from shop.json (REST).
-- All nullable text columns. Empty defaults expected when Shopify omits the
-- field or when the brand signed up manually without connecting Shopify.
--
-- See PARTNA-SIGNUP-OVERHAUL-PLAN.md §8.5 for the source-of-truth mapping.
--
-- To revert:
--   BEGIN;
--   ALTER TABLE brand.brand_profiles
--     DROP COLUMN slogan,
--     DROP COLUMN short_description,
--     DROP COLUMN locale,
--     DROP COLUMN shopify_plan,
--     DROP COLUMN money_format;
--   COMMIT;

BEGIN;

ALTER TABLE brand.brand_profiles
  ADD COLUMN slogan text,
  ADD COLUMN short_description text,
  ADD COLUMN locale text,
  ADD COLUMN shopify_plan text,
  ADD COLUMN money_format text;

COMMIT;
