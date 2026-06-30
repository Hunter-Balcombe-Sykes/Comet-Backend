-- Sync site.design_kits to the value/inferred/selection var system.
--
-- The public read path (IndividualProfilePayloadBuilder::loadDesignKit) is
-- SELECT * + null-filter + prefix grouping, so it never references a specific
-- column — these changes don't affect rendering (all columns are null today).
-- This just gives every current kit var a nullable column so the (future)
-- editor can store it; all inferred vars keep a column for promotion.
--
-- Pairs with the @partnaau/design-system var-system overhaul + the new
-- text/weight/theme prefixes in groupKitColumns().

-- ── Add columns for the current kit ──
ALTER TABLE site.design_kits
  ADD COLUMN IF NOT EXISTS color_secondary_text TEXT NULL,
  ADD COLUMN IF NOT EXISTS typography_line_height TEXT NULL,
  ADD COLUMN IF NOT EXISTS typography_logo_height TEXT NULL,
  ADD COLUMN IF NOT EXISTS text_xs TEXT NULL,
  ADD COLUMN IF NOT EXISTS text_sm TEXT NULL,
  ADD COLUMN IF NOT EXISTS text_md TEXT NULL,
  ADD COLUMN IF NOT EXISTS text_lg TEXT NULL,
  ADD COLUMN IF NOT EXISTS text_xl TEXT NULL,
  ADD COLUMN IF NOT EXISTS text_xxl TEXT NULL,
  ADD COLUMN IF NOT EXISTS text_desktop_xs TEXT NULL,
  ADD COLUMN IF NOT EXISTS weight_regular TEXT NULL,
  ADD COLUMN IF NOT EXISTS weight_medium TEXT NULL,
  ADD COLUMN IF NOT EXISTS weight_semibold TEXT NULL,
  ADD COLUMN IF NOT EXISTS weight_bold TEXT NULL,
  ADD COLUMN IF NOT EXISTS border_small_radius TEXT NULL,
  ADD COLUMN IF NOT EXISTS space_xxs TEXT NULL,
  ADD COLUMN IF NOT EXISTS icons_large_size TEXT NULL,
  ADD COLUMN IF NOT EXISTS icons_brand_logo_height TEXT NULL,
  ADD COLUMN IF NOT EXISTS motion_pace TEXT NULL,
  ADD COLUMN IF NOT EXISTS motion_spin_duration TEXT NULL,
  ADD COLUMN IF NOT EXISTS motion_spring_curve TEXT NULL,
  ADD COLUMN IF NOT EXISTS effect_style TEXT NULL,
  ADD COLUMN IF NOT EXISTS theme_mode TEXT NULL;

-- ── Drop columns for removed vars (old typography roles, sizing, buttons,
--    effect values, border focus, per-step desktop space) ──
ALTER TABLE site.design_kits
  DROP COLUMN IF EXISTS typography_font_size,
  DROP COLUMN IF EXISTS typography_font_weight,
  DROP COLUMN IF EXISTS typography_h1_font_size,
  DROP COLUMN IF EXISTS typography_h1_font_weight,
  DROP COLUMN IF EXISTS typography_h2_font_size,
  DROP COLUMN IF EXISTS typography_h2_font_weight,
  DROP COLUMN IF EXISTS typography_desktop_font_size,
  DROP COLUMN IF EXISTS typography_desktop_h1_font_size,
  DROP COLUMN IF EXISTS typography_desktop_h2_font_size,
  DROP COLUMN IF EXISTS border_focus_color,
  DROP COLUMN IF EXISTS effect_overlay_blur,
  DROP COLUMN IF EXISTS effect_overlay_opacity,
  DROP COLUMN IF EXISTS sizing_button_height,
  DROP COLUMN IF EXISTS sizing_input_height,
  DROP COLUMN IF EXISTS sizing_desktop_button_height,
  DROP COLUMN IF EXISTS sizing_desktop_input_height,
  DROP COLUMN IF EXISTS button_primary_bg,
  DROP COLUMN IF EXISTS button_primary_text,
  DROP COLUMN IF EXISTS button_secondary_bg,
  DROP COLUMN IF EXISTS button_secondary_text,
  DROP COLUMN IF EXISTS button_general_bg,
  DROP COLUMN IF EXISTS button_general_text,
  DROP COLUMN IF EXISTS space_desktop_xs,
  DROP COLUMN IF EXISTS space_desktop_s,
  DROP COLUMN IF EXISTS space_desktop_medium,
  DROP COLUMN IF EXISTS space_desktop_large,
  DROP COLUMN IF EXISTS space_desktop_xl;
