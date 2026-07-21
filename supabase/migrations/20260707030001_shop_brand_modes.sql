-- Store link-out + auto-latest + referral controls, per connected shop brand.
--
--   selection_mode  'manual' (user-picked products, today's behavior) or
--                   'latest' (selection auto-tracks the store's newest
--                   products; synced on mode flip and by the scheduled
--                   integration refresh).
--   link_mode       'product' (cards link to product pages, today's behavior)
--                   or 'checkout' (cards deep-link to the store checkout/cart
--                   with the chosen variant added; Shopify cart permalinks +
--                   WooCommerce ?add-to-cart today, other providers fall back
--                   to product pages).
--   referral_query  saved query-string suffix parsed from a user-pasted
--                   referral URL (e.g. 'ref=abc123'); appended to that
--                   brand's outbound product links at render time.
--
-- Values validated in the request layer (UpdateShopBrandRequest) — no CHECK
-- constraints, matching the SQLite-test-mirror convention.

ALTER TABLE site.shop_brands
    ADD COLUMN IF NOT EXISTS selection_mode text NOT NULL DEFAULT 'manual',
    ADD COLUMN IF NOT EXISTS link_mode text NOT NULL DEFAULT 'product',
    ADD COLUMN IF NOT EXISTS referral_query text NOT NULL DEFAULT '';
