-- =====================================================================
-- Drop sizing.headerHeight columns from site.design_kits
-- =====================================================================
-- Reverses 20260527150000_design_kit_header_height.sql. The skeleton-2
-- header strip no longer uses a kit-driven height — @partnaau/design-
-- system 2.6.5 removed sizing.headerHeight from types / defaults /
-- vars.css / validate.ts. The shared .header-row now sizes to content
-- with padding-small on every side, so the column is orphaned.
--
-- Drops:
--   sizing_header_height            — base (mobile)
--   sizing_tablet_header_height     — @media (min-width: 640px) override
--   sizing_desktop_header_height    — @media (min-width: 1024px) override
--
-- The UpdateSiteRequest / StaffUpdateSiteRequest validators lose their
-- matching rules in the same PR. groupKitColumns is column-driven (it
-- iterates over whatever's in the table), so no PHP change there.
-- =====================================================================

BEGIN;

ALTER TABLE site.design_kits
  DROP COLUMN IF EXISTS sizing_header_height,
  DROP COLUMN IF EXISTS sizing_tablet_header_height,
  DROP COLUMN IF EXISTS sizing_desktop_header_height;

COMMIT;
