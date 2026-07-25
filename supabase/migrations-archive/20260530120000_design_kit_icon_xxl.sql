-- 20260530120000_design_kit_icon_xxl.sql
--
-- Two additive columns for @partnaau/design-system 4.6.0's hero-icon
-- scale:
--   icons_xxl_size   — extra-extra-large icon size (default 2.5rem
--                      in the package).
--   icons_xxl_weight — companion weight (default 500). Maps to
--                      font-weight in the .icon-xxl class — affects
--                      text/font icons; no-op on inline SVG.
--
-- Both NULLABLE; package defaults handle null at read time. No
-- backfill needed. Idempotent via IF NOT EXISTS.

ALTER TABLE site.design_kits
    ADD COLUMN IF NOT EXISTS icons_xxl_size TEXT NULL,
    ADD COLUMN IF NOT EXISTS icons_xxl_weight TEXT NULL;
