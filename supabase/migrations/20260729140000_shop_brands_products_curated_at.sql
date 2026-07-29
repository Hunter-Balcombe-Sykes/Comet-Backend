-- #SEM-1: shop product-picker "manual" guard was inert. ShopFetch's scheduled
-- resync (site.sites.shop_auto_latest) never consulted site.shop_brands, so a
-- professional's hand-picked product selection was wholesale-replaced by the
-- store's newest N products within one refresh cycle (~6h), and again every
-- cycle after.
--
-- Neither existing column can carry "this list was hand-authored":
--   - shop_auto_latest is the right source of truth for POLICY (site-level,
--     user-visible), but making the picker write it would silently disable
--     auto-latest for the user's OTHER stores too.
--   - shop_brands.selection_mode defaults to 'manual' and addBrand() never
--     sets it, so 'manual' means BOTH "user curated this" AND "brand freshly
--     connected, never touched" — structurally incapable of being the guard.
--
-- products_curated_at is a new, explicitly-set per-brand fact recorded at the
-- moment of curation (ShopController::setProducts). NULL = no evidence of
-- human curation (today's behaviour, and the correct reading of every
-- existing row — no backfill needed, nothing changes state on deploy);
-- non-NULL = this list was hand-authored at time T, and ShopFetch's scheduled
-- resync skips it until the user opts back in (selectionMode=latest clears
-- it). This is the shop-side analogue of the field-bindings manual-priority
-- doctrine (supabase/migrations/20260728150000_field_bindings.sql) — "an
-- explicit human choice outranks a background writer" — but a SEPARATE
-- mechanism: shop product selection has no source-key concept and is
-- untouched by any identity stream, so it is not modelled as a bindable field.
--
-- No CONCURRENTLY: nullable add with no default needs no table rewrite, and
-- no index is needed — the sync query is already keyed by connection_id with
-- MAX_BRANDS = 5, so the added predicate filters at most 5 rows.
--
-- ROLLBACK: ALTER TABLE site.shop_brands DROP COLUMN IF EXISTS products_curated_at;

BEGIN;

ALTER TABLE site.shop_brands
    ADD COLUMN IF NOT EXISTS products_curated_at timestamptz NULL;

COMMIT;
