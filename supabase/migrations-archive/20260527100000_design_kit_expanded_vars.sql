-- =====================================================================
-- Expanded design kit vars across borders, spacing, padding, icons,
-- effects, sizing, plus a few more colors and typography
-- =====================================================================
-- Adds 21 columns to back the design kit expansion in
-- @partnaau/design-system. All NULLABLE, no DB-level DEFAULT — code-side
-- defaults in defaults.ts fill nulls at read time via mergeDesignKit().
--
-- Storage convention (unchanged): flat snake_case column names; the
-- API maps to nested camelCase via groupKitColumns in
-- IndividualProfilePayloadBuilder (e.g. border_radius → borders.radius).
--
-- Note: `color_accent` is NOT in this migration — it already exists as
-- an orphan from Phase 7a (was wiped from the package later, now reused
-- by colors.accent in this expansion).
-- =====================================================================

BEGIN;

ALTER TABLE site.design_kits
  -- Colors
  ADD COLUMN IF NOT EXISTS color_text_muted TEXT NULL,
  ADD COLUMN IF NOT EXISTS color_accent_contrast TEXT NULL,

  -- Typography
  ADD COLUMN IF NOT EXISTS typography_title_font_size TEXT NULL,
  ADD COLUMN IF NOT EXISTS typography_title_font_weight TEXT NULL,

  -- Borders
  ADD COLUMN IF NOT EXISTS border_thickness TEXT NULL,
  ADD COLUMN IF NOT EXISTS border_color TEXT NULL,
  ADD COLUMN IF NOT EXISTS border_radius TEXT NULL,

  -- Spacing
  ADD COLUMN IF NOT EXISTS spacing_extra_small TEXT NULL,
  ADD COLUMN IF NOT EXISTS spacing_small TEXT NULL,
  ADD COLUMN IF NOT EXISTS spacing_general TEXT NULL,
  ADD COLUMN IF NOT EXISTS spacing_large TEXT NULL,

  -- Padding
  ADD COLUMN IF NOT EXISTS padding_extra_small TEXT NULL,
  ADD COLUMN IF NOT EXISTS padding_small TEXT NULL,
  ADD COLUMN IF NOT EXISTS padding_general TEXT NULL,
  ADD COLUMN IF NOT EXISTS padding_large TEXT NULL,

  -- Icons
  ADD COLUMN IF NOT EXISTS icon_size TEXT NULL,
  ADD COLUMN IF NOT EXISTS icon_color TEXT NULL,

  -- Effects
  ADD COLUMN IF NOT EXISTS effect_overlay_blur TEXT NULL,
  ADD COLUMN IF NOT EXISTS effect_overlay_opacity TEXT NULL,

  -- Sizing (new group — UI control intrinsic dimensions)
  ADD COLUMN IF NOT EXISTS sizing_button_height TEXT NULL,
  ADD COLUMN IF NOT EXISTS sizing_input_height TEXT NULL;

COMMIT;
