-- Site-level GLOBAL shop link controls (user decision 2026-07-08).
--
-- The per-brand link_mode / auto-latest controls (site.shop_brands.link_mode +
-- .selection_mode, added 20260707030000) collapse into ONE global choice each,
-- applied to EVERY connected store:
--
--   shop_link_mode    'checkout' (product cards deep-link straight to the store
--                     cart/checkout with the chosen variant added — Shopify cart
--                     permalinks + WooCommerce ?add-to-cart; other providers fall
--                     back to the product page) or 'product' (cards link to the
--                     product page). DEFAULT 'checkout' — direct-to-checkout ON.
--   shop_auto_latest  true  → every non-individual store auto-tracks its newest
--                     products (the scheduled shop refresh keeps the selection
--                     synced); false → selections stay hand-picked. DEFAULT true.
--
-- The public payload builder (PublicIntegrationConnectionResource) stamps every
-- brand's linkMode from shop_link_mode at read time, so the sitepage contract is
-- unchanged (pages still read brand.linkMode) — the value now comes from here.
-- The per-brand columns remain as reversible storage the global overrides; no
-- data is dropped. Referral stays PER-BRAND (site.shop_brands.referral_query),
-- captured in the add-store flow.
--
-- Values validated in the request layer (shop settings request) — no CHECK
-- constraint, matching the SQLite-test-mirror convention used by the sibling
-- shop_brand_modes migration.

ALTER TABLE site.sites
    ADD COLUMN IF NOT EXISTS shop_link_mode  text    NOT NULL DEFAULT 'checkout',
    ADD COLUMN IF NOT EXISTS shop_auto_latest boolean NOT NULL DEFAULT true;

-- ROLLBACK:
-- ALTER TABLE site.sites
--     DROP COLUMN IF EXISTS shop_link_mode,
--     DROP COLUMN IF EXISTS shop_auto_latest;
