-- =====================================================================
-- Design kit: drop sizing.rowHeight
-- =====================================================================
-- Removes the sizing_row_height column + its responsive companions
-- (tablet, desktop) from site.design_kits.
--
-- The @partnaau/design-system package no longer exposes
-- sizing.rowHeight — the row-card primitive it backed has been
-- removed from shared CSS, and skeleton-1's new image-top card
-- component sets its own height (calc(100% / 3) of content-container)
-- rather than reading a kit var. Skeleton-2's link rows are an open
-- iteration item and will be redesigned in a later pass.
--
-- Matching frontend changes (in lockstep with this migration):
--   - types.ts / defaults.ts / vars.css / validate.ts: sizing.rowHeight
--     (+ sizingTablet.rowHeight + sizingDesktop.rowHeight) removed.
--   - design-styles/components.css: .row-card + .row-card-label rules
--     removed; .card-shell + .card primitives stay.
--
-- Validator rule removal: 'design_kit.sizing_row_height' + tablet +
-- desktop variants are removed from UpdateSiteRequest +
-- StaffUpdateSiteRequest in the same PR. groupKitColumns in
-- IndividualProfilePayloadBuilder iterates real columns; no PHP
-- change there.
-- =====================================================================

BEGIN;

ALTER TABLE site.design_kits
  DROP COLUMN IF EXISTS sizing_row_height,
  DROP COLUMN IF EXISTS sizing_tablet_row_height,
  DROP COLUMN IF EXISTS sizing_desktop_row_height;

COMMIT;
