-- =====================================================================
-- Design kit: row sizing + motion timings
-- =====================================================================
-- Adds three columns to site.design_kits backing the new
-- @partnaau/design-system vars:
--   sizing_row_height          — row-card intrinsic height
--   motion_expand_duration     — expand box-rect transition duration
--   motion_fade_duration       — expanded content opacity transition
--
-- All TEXT NULL — code-side defaults (2.875rem, 400ms, 200ms) in the
-- kit's defaults.ts fill nulls at read time. groupKitColumns in
-- IndividualProfilePayloadBuilder maps sizing_* → sizing.* and
-- motion_* → motion.* automatically, so no PHP change is needed.
-- =====================================================================

BEGIN;

ALTER TABLE site.design_kits
  ADD COLUMN sizing_row_height TEXT NULL,
  ADD COLUMN motion_expand_duration TEXT NULL,
  ADD COLUMN motion_fade_duration TEXT NULL;

COMMIT;
