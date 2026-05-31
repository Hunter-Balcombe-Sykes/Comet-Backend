-- 20260531120000_drop_smart_link_discount_kind_value.sql
--
-- Smart Links polish: remove the discounted-PRICE feature (§1.8). A discount is
-- now just a code that auto-applies on the visitor click (Shopify /discount/…,
-- Eventbrite ?discount=) — we no longer store a kind (%/$) or a computed value,
-- and no code reads these columns after this deploy. Dropping discount_kind also
-- drops its inline CHECK. Fast metadata-only operation on a small table.

BEGIN;

ALTER TABLE site.smart_links DROP COLUMN IF EXISTS discount_kind;
ALTER TABLE site.smart_links DROP COLUMN IF EXISTS discount_value;

COMMIT;
