-- Affiliates can now choose a subset of a product's variants to surface on their
-- storefront. NULL means "show every brand-enabled variant" — the default, so
-- nothing has to be written for existing selections. A populated array is
-- intersected with each variant's sidest.enabled metafield at read time, so
-- brand-side variant disables continue to win.
--
-- Stored as jsonb (not text[]) to play nicely with Eloquent's native `array`
-- cast — we never need Postgres array operators on this column because all
-- filtering happens in PHP during catalog assembly.
ALTER TABLE commerce.affiliate_product_selections
    ADD COLUMN IF NOT EXISTS selected_variant_gids jsonb NULL;

COMMENT ON COLUMN commerce.affiliate_product_selections.selected_variant_gids IS
    'Optional JSON array of Shopify variant GIDs the affiliate wants surfaced for this product. NULL = show every brand-enabled variant (default).';
