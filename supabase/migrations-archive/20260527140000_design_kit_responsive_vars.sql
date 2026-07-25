-- =====================================================================
-- Design kit: responsive companion vars (tablet + desktop overrides)
-- =====================================================================
-- @partnaau/design-system gains responsive companion groups for the four
-- groups whose vars adapt at viewport breakpoints:
--
--   padding      → paddingTablet, paddingDesktop      (4 vars × 2 = 8 cols)
--   spacing      → spacingTablet, spacingDesktop      (4 vars × 2 = 8 cols)
--   sizing       → sizingTablet, sizingDesktop        (3 height vars × 2 = 6 cols)
--   typography   → typographyTablet, typographyDesktop (2 font-size vars × 2 = 4 cols)
--
-- Total: 26 new columns. All TEXT NULL — code-side defaults in the kit's
-- defaults.ts fill nulls at read time. Per-breakpoint values are partial
-- overrides; an empty tablet/desktop value cascades from the next-smaller
-- breakpoint via CSS @media-rule ordering.
--
-- Breakpoints in CSS:
--   tablet   = (min-width: 640px)
--   desktop  = (min-width: 1024px)
--
-- IndividualProfilePayloadBuilder.groupKitColumns is updated in the same
-- PR to recognise the two-token prefixes (padding_tablet, padding_desktop,
-- etc.) and route them into the matching paddingTablet/paddingDesktop/…
-- groups in the API response.
-- =====================================================================

BEGIN;

ALTER TABLE site.design_kits
  -- Padding (tablet)
  ADD COLUMN IF NOT EXISTS padding_tablet_extra_small TEXT NULL,
  ADD COLUMN IF NOT EXISTS padding_tablet_small TEXT NULL,
  ADD COLUMN IF NOT EXISTS padding_tablet_general TEXT NULL,
  ADD COLUMN IF NOT EXISTS padding_tablet_large TEXT NULL,
  -- Padding (desktop)
  ADD COLUMN IF NOT EXISTS padding_desktop_extra_small TEXT NULL,
  ADD COLUMN IF NOT EXISTS padding_desktop_small TEXT NULL,
  ADD COLUMN IF NOT EXISTS padding_desktop_general TEXT NULL,
  ADD COLUMN IF NOT EXISTS padding_desktop_large TEXT NULL,

  -- Spacing (tablet)
  ADD COLUMN IF NOT EXISTS spacing_tablet_extra_small TEXT NULL,
  ADD COLUMN IF NOT EXISTS spacing_tablet_small TEXT NULL,
  ADD COLUMN IF NOT EXISTS spacing_tablet_general TEXT NULL,
  ADD COLUMN IF NOT EXISTS spacing_tablet_large TEXT NULL,
  -- Spacing (desktop)
  ADD COLUMN IF NOT EXISTS spacing_desktop_extra_small TEXT NULL,
  ADD COLUMN IF NOT EXISTS spacing_desktop_small TEXT NULL,
  ADD COLUMN IF NOT EXISTS spacing_desktop_general TEXT NULL,
  ADD COLUMN IF NOT EXISTS spacing_desktop_large TEXT NULL,

  -- Sizing heights (tablet)
  ADD COLUMN IF NOT EXISTS sizing_tablet_button_height TEXT NULL,
  ADD COLUMN IF NOT EXISTS sizing_tablet_input_height TEXT NULL,
  ADD COLUMN IF NOT EXISTS sizing_tablet_row_height TEXT NULL,
  -- Sizing heights (desktop)
  ADD COLUMN IF NOT EXISTS sizing_desktop_button_height TEXT NULL,
  ADD COLUMN IF NOT EXISTS sizing_desktop_input_height TEXT NULL,
  ADD COLUMN IF NOT EXISTS sizing_desktop_row_height TEXT NULL,

  -- Typography font sizes (tablet)
  ADD COLUMN IF NOT EXISTS typography_tablet_font_size TEXT NULL,
  ADD COLUMN IF NOT EXISTS typography_tablet_title_font_size TEXT NULL,
  -- Typography font sizes (desktop)
  ADD COLUMN IF NOT EXISTS typography_desktop_font_size TEXT NULL,
  ADD COLUMN IF NOT EXISTS typography_desktop_title_font_size TEXT NULL;

COMMIT;
