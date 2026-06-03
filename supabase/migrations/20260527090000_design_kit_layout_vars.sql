-- =====================================================================
-- Design kit layout vars — fontFamily, fontSize, fontWeight
-- =====================================================================
-- Adds the 3 typography vars that drive the body's default text rendering
-- (font, size, weight) per skeleton-system spec §5.1. All columns NULLABLE,
-- no DB-level DEFAULT — code-side defaults in
-- @partnaau/design-system/design-kit fill nulls at read time via
-- mergeDesignKit(). Storage convention: flat snake_case column names
-- (group prefix + key); the API maps to nested camelCase
-- (typography_font_family → designKit.typography.fontFamily).
--
-- Note: color_accent persists as an orphan from the earlier wiped vars.
-- typography_font_heading and typography_font_body were also orphaned here
-- but have since been dropped by 20260603000001.
-- =====================================================================

BEGIN;

ALTER TABLE site.design_kits
  ADD COLUMN IF NOT EXISTS typography_font_family TEXT NULL,
  ADD COLUMN IF NOT EXISTS typography_font_size TEXT NULL,
  ADD COLUMN IF NOT EXISTS typography_font_weight TEXT NULL;

COMMIT;
