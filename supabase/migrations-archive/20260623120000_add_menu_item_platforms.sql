-- =====================================================================
-- Menu items — per-platform availability column
-- =====================================================================
-- The menu now UNIONs every connected ordering platform instead of letting
-- Uber Eats win outright: a dish available on both UE and DoorDash is one row
-- that records BOTH platforms (each with its own price, the modes that
-- platform's store link supports, and that platform's order URL). DoorDash-only
-- dishes are no longer dropped.
--
-- `platforms` is the per-item availability list:
--   [ { platform: 'uber-eats'|'doordash',
--       price:    number|null,
--       modes:    ['pickup','delivery'],   -- subset the store link supports
--       url:      string|null } ]          -- this platform's order link
--
-- The existing base_price / pickup_price / delivery_price / *_source columns are
-- kept and recomputed as aggregates over `platforms` (min across platforms, min
-- among pickup-capable, min among delivery-capable) so existing consumers — the
-- public sitepage (base_price) and the dashboard back-compat fields — keep
-- working unchanged.

ALTER TABLE site.menu_items
    ADD COLUMN IF NOT EXISTS platforms jsonb;
