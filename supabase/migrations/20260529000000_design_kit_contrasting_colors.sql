-- =====================================================================
-- Contrasting colour pair for surfaces that read against page colours
-- =====================================================================
-- Adds colors.contrastingBg + colors.contrastingText (in
-- @partnaau/design-system 2.9.0) as the two paired vars driving the
-- secondary button and the skeleton-1 header. Both NULLABLE, no DB-level
-- DEFAULT — code-side defaults in defaults.ts fill nulls at read time
-- via mergeDesignKit() (defaults #f9f9f9 / #0c0c0c match the page bg/
-- text so an unset pair reads as "no contrast").
--
-- Column naming: `color_contrasting_bg` / `color_contrasting_text` follow
-- the existing single-token-prefix snake_case convention; the read-side
-- groupKitColumns() in IndividualProfilePayloadBuilder automatically maps
-- color_* → colors.<camelCase> with no PHP change required. Write-side
-- validation rules in UpdateSiteRequest.php are added in the same commit.
-- =====================================================================

ALTER TABLE site.design_kits
  ADD COLUMN color_contrasting_bg TEXT NULL,
  ADD COLUMN color_contrasting_text TEXT NULL;
