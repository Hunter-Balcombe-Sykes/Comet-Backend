-- =====================================================================
-- Design kit: header height (sizing.headerHeight)
-- =====================================================================
-- Backs the new sizing var introduced by @partnaau/design-system for
-- skeleton-2's bottom-bar / header layout (default 3rem, scales via the
-- responsive companion columns when designers override).
--
-- Same pattern as the other sizing heights:
--   sizing_header_height            — base (mobile)
--   sizing_tablet_header_height     — @media (min-width: 640px) override
--   sizing_desktop_header_height    — @media (min-width: 1024px) override
--
-- All TEXT NULL — code-side defaults in defaults.ts fill nulls at read
-- time. groupKitColumns already routes `sizing_tablet_*` and
-- `sizing_desktop_*` to the matching companion groups via the two-token
-- prefix map; no PHP change needed.
-- =====================================================================

BEGIN;

ALTER TABLE site.design_kits
  ADD COLUMN sizing_header_height TEXT NULL,
  ADD COLUMN sizing_tablet_header_height TEXT NULL,
  ADD COLUMN sizing_desktop_header_height TEXT NULL;

COMMIT;
