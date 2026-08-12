-- Slice 5a Task 7 fix round 1, Finding 3. content.f_catalog is already the
-- per-item catalogue-identity facet (release_type, track_number, disc_number,
-- isrc, gtin, sku) — the shop reader needs three more product fields that
-- were never captured anywhere in content.*: handle, vendor, and the
-- product's default variant reference.
--
-- variant_ref is NOT gtin: gtin is a real, standardised trade identifier
-- (barcode), a distinct fact from a provider's own internal variant id. The
-- Shopify checkout deep link is built from that variant id, and
-- ShopProductProjection::fromBlob() drops the "Default Title" placeholder
-- variant (the common single-variant case — 17 of 51 real dev products),
-- so today that id is not recoverable from content.item_variants either.
-- variant_ref is the only place it survives the round-trip.
--
-- No CONCURRENTLY: plain ADD COLUMN, no index. content.f_catalog is a
-- brand-new-this-slice table with effectively zero rows anywhere it has
-- been applied (CLAUDE.md: "prod carries no customer data yet"), so there
-- is no lock-contention concern an index would need to dodge.
--
-- NOT APPLIED by this task — see the Task 7 report, Fix round 1. No
-- supabase db push / db link / MCP database call was made.
--
-- ROLLBACK: ALTER TABLE content.f_catalog DROP COLUMN IF EXISTS handle, DROP COLUMN IF EXISTS vendor, DROP COLUMN IF EXISTS variant_ref;

BEGIN;

ALTER TABLE content.f_catalog
    ADD COLUMN IF NOT EXISTS handle text NULL,
    ADD COLUMN IF NOT EXISTS vendor text NULL,
    ADD COLUMN IF NOT EXISTS variant_ref text NULL;

COMMENT ON COLUMN content.f_catalog.handle IS
    'Provider-scoped product slug (Shopify''s product handle, etc.) — shop-specific today, but the column lives on the catalogue-identity facet generically like sku/gtin.';

COMMENT ON COLUMN content.f_catalog.vendor IS
    'The product''s vendor/brand label as the source reported it — distinct from the item''s own storefront (content.storefronts), which is the SELLER, not necessarily the maker.';

COMMENT ON COLUMN content.f_catalog.variant_ref IS
    'The default/first variant''s provider-scoped id (ShopifyScraper''s top-level variantId, etc.), carried separately from content.item_variants because that table drops the single-variant "Default Title" placeholder case entirely. Powers the provider checkout deep link — not a trade identifier, never gtin.';

COMMIT;
