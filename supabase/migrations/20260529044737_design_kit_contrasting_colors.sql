-- =====================================================================
-- Contrasting colour pair + placeholder column for site.design_kits
-- =====================================================================
-- Adds three NULLABLE columns to back colors.contrastingBg /
-- colors.contrastingText (new in @partnaau/design-system 2.9.0) and
-- colors.placeholder (added in an earlier package release but never
-- given a backing column — pre-existing orphan, closed here so all
-- three colour vars can be stored). All NULLABLE, no DB-level DEFAULT
-- — code-side defaults in defaults.ts fill nulls at read time via
-- mergeDesignKit().
--
-- Defaults in the package:
--   colors.contrastingBg   → #f9f9f9 (matches colors.bg by default)
--   colors.contrastingText → #0c0c0c (matches colors.text by default)
--   colors.placeholder     → #d6d6d6 (matches kit border-color shade)
--
-- Column naming follows the existing single-token-prefix snake_case
-- convention; the read-side groupKitColumns() in
-- IndividualProfilePayloadBuilder auto-maps color_* → colors.<camelCase>
-- with no PHP change required. Write-side validation rules in
-- UpdateSiteRequest.php are added in the same commit.
-- =====================================================================

ALTER TABLE site.design_kits
  ADD COLUMN color_contrasting_bg TEXT NULL,
  ADD COLUMN color_contrasting_text TEXT NULL,
  ADD COLUMN color_placeholder TEXT NULL;
