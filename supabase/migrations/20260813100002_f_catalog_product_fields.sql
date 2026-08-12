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
-- No CONCURRENTLY: plain ADD COLUMN, no index. Fix round 2, Finding 2
-- correction — content.f_catalog is NOT a brand-new-this-slice, empty
-- table (an earlier version of this comment claimed that; it was wrong —
-- the table predates this slice and holds 33 rows on dev, all Bandcamp
-- music metadata). The safe-without-CONCURRENTLY claim does not depend on
-- emptiness: a nullable ADD COLUMN with no DEFAULT and no index is a
-- catalog-only metadata change on modern Postgres (11+) — it does not
-- rewrite the table or scan existing rows, so the ACCESS EXCLUSIVE lock it
-- briefly takes is held only for the catalog update itself, not for the
-- length of a table scan/rewrite, regardless of row count. A future change
-- to this table that DOES require a scan or rewrite (a NOT NULL, a
-- non-null DEFAULT, a type change) needs its own safe-migration treatment
-- and must not assume the "empty table" excuse this paragraph used to make.
--
-- APPLIED to the shared dev database by the coordinator (Fix round 2) —
-- this task did not run supabase db push / db link / any MCP database call,
-- and must not attempt to (re-)apply it.
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
