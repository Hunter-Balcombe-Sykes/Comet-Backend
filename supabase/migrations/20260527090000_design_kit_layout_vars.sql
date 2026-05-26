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
-- Note: orphan columns from the wiped earlier vars (color_accent,
-- typography_font_heading, typography_font_body) are left in place
-- intentionally — values are all NULL and dropping requires a separate
-- decision. groupKitColumns in IndividualProfilePayloadBuilder ignores
-- nulls, so they're invisible in the API response.
-- =====================================================================

BEGIN;

ALTER TABLE site.design_kits
  ADD COLUMN typography_font_family TEXT NULL,
  ADD COLUMN typography_font_size TEXT NULL,
  ADD COLUMN typography_font_weight TEXT NULL;

COMMIT;
