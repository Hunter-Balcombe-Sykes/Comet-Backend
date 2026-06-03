-- =====================================================================
-- Unified `space` scale + single 550px breakpoint on site.design_kits
-- =====================================================================
-- Backs @partnaau/design-system 2.10.0 cleanup:
--   1. Drops `padding_*` and `spacing_*` (base + desktop) — replaced by
--      one `space_*` scale used for both CSS padding props AND
--      flex/grid gap props.
--   2. Drops all `*_tablet_*` columns — the kit now ships a single
--      desktop breakpoint at 550px (no tablet tier).
--   3. Adds `space_*` (mobile base) and `space_desktop_*` (≥ 550px
--      override) columns. All NULLABLE, no DB-level DEFAULT — code-side
--      defaults in defaults.ts fill nulls at read time via
--      mergeDesignKit().
--
-- Storage convention is unchanged — flat snake_case column names. The
-- read-side groupKitColumns() in IndividualProfilePayloadBuilder auto-
-- maps `space_*` → `space.<camelCase>` and `space_desktop_*` →
-- `spaceDesktop.<camelCase>`.
--
-- Single ALTER TABLE keeps the schema migration atomic — partial state
-- if it fails halfway leaves the table on the prior shape.
-- =====================================================================

ALTER TABLE site.design_kits
  -- Drop old padding scale (base + desktop)
  DROP COLUMN IF EXISTS padding_extra_small,
  DROP COLUMN IF EXISTS padding_small,
  DROP COLUMN IF EXISTS padding_general,
  DROP COLUMN IF EXISTS padding_large,
  DROP COLUMN IF EXISTS padding_desktop_extra_small,
  DROP COLUMN IF EXISTS padding_desktop_small,
  DROP COLUMN IF EXISTS padding_desktop_general,
  DROP COLUMN IF EXISTS padding_desktop_large,

  -- Drop old spacing scale (base + desktop)
  DROP COLUMN IF EXISTS spacing_extra_small,
  DROP COLUMN IF EXISTS spacing_small,
  DROP COLUMN IF EXISTS spacing_general,
  DROP COLUMN IF EXISTS spacing_large,
  DROP COLUMN IF EXISTS spacing_desktop_extra_small,
  DROP COLUMN IF EXISTS spacing_desktop_small,
  DROP COLUMN IF EXISTS spacing_desktop_general,
  DROP COLUMN IF EXISTS spacing_desktop_large,

  -- Drop the tablet tier entirely
  DROP COLUMN IF EXISTS padding_tablet_extra_small,
  DROP COLUMN IF EXISTS padding_tablet_small,
  DROP COLUMN IF EXISTS padding_tablet_general,
  DROP COLUMN IF EXISTS padding_tablet_large,
  DROP COLUMN IF EXISTS spacing_tablet_extra_small,
  DROP COLUMN IF EXISTS spacing_tablet_small,
  DROP COLUMN IF EXISTS spacing_tablet_general,
  DROP COLUMN IF EXISTS spacing_tablet_large,
  DROP COLUMN IF EXISTS sizing_tablet_button_height,
  DROP COLUMN IF EXISTS sizing_tablet_input_height,
  DROP COLUMN IF EXISTS typography_tablet_font_size,
  DROP COLUMN IF EXISTS typography_tablet_title_font_size,

  -- Add unified space scale (mobile base)
  ADD COLUMN IF NOT EXISTS space_xs TEXT NULL,
  ADD COLUMN IF NOT EXISTS space_s TEXT NULL,
  ADD COLUMN IF NOT EXISTS space_regular TEXT NULL,
  ADD COLUMN IF NOT EXISTS space_medium TEXT NULL,
  ADD COLUMN IF NOT EXISTS space_large TEXT NULL,

  -- Add desktop overrides for the space scale (≥ 550px)
  ADD COLUMN IF NOT EXISTS space_desktop_xs TEXT NULL,
  ADD COLUMN IF NOT EXISTS space_desktop_s TEXT NULL,
  ADD COLUMN IF NOT EXISTS space_desktop_regular TEXT NULL,
  ADD COLUMN IF NOT EXISTS space_desktop_medium TEXT NULL,
  ADD COLUMN IF NOT EXISTS space_desktop_large TEXT NULL;
