-- SCHEMA-4 — site.shop_brands.selection_mode / .link_mode CHECK constraints.
--
-- 20260707030000_shop_brand_modes.sql's own header says "no CHECK constraints,
-- matching the SQLite-test-mirror convention" — but site.sites.booking_mode
-- (same schema, same table family) already carries a real DB CHECK despite
-- the identical concern, so that rationale doesn't hold up. App-side source
-- of truth: UpdateShopBrandRequest (selectionMode 'in:manual,latest',
-- linkMode 'in:product,checkout').
--
-- NOTE for future readers: these two per-brand columns are DORMANT-BUT-STILL-
-- WRITTEN, not a live read-path guard. site.sites.shop_link_mode (see
-- 20260708120000_sites_shop_global_settings.sql + sites_shop_link_mode_check
-- in this same batch) is now the GLOBAL switch the public payload builder
-- (PublicIntegrationConnectionResource) actually stamps onto every brand's
-- linkMode at read time — it overrides these per-brand columns. This
-- constraint protects data integrity of storage that's kept as reversible
-- history, not a currently-read setting.
--
-- Dev census (glncumufgaqcmqhzwrxm, 2026-07-20): link_mode is 'product' on
-- all rows; selection_mode is 'manual' x6 / 'latest' x1. VALIDATE is a clean
-- pass on both.
--
-- site.shop_brands is not a HOT_TABLE, but the NOT VALID -> VALIDATE split
-- (CONVENTIONS.md §2) still applies, so it gets the same two-window shape.

-- Window 1: add both CHECKs in NOT VALID form.
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE site.shop_brands
    ADD CONSTRAINT shop_brands_selection_mode_check
    CHECK (selection_mode IN ('manual', 'latest')) NOT VALID,
    ADD CONSTRAINT shop_brands_link_mode_check
    CHECK (link_mode IN ('product', 'checkout')) NOT VALID;

COMMIT;

-- Window 2: validate both in a second transaction.
BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE site.shop_brands VALIDATE CONSTRAINT shop_brands_selection_mode_check;
ALTER TABLE site.shop_brands VALIDATE CONSTRAINT shop_brands_link_mode_check;

COMMIT;

-- ROLLBACK:
-- BEGIN;
-- ALTER TABLE site.shop_brands
--     DROP CONSTRAINT IF EXISTS shop_brands_selection_mode_check,
--     DROP CONSTRAINT IF EXISTS shop_brands_link_mode_check;
-- COMMIT;
