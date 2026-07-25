-- Add space_xl and space_desktop_xl columns to site.design_kits.
-- Backs @partnaau/design-system space.xl / spaceDesktop.xl vars added in the
-- design-kit layer (defaults: 2rem mobile, 2.5rem desktop ≥ 700px).
-- NULLABLE, no DB-level DEFAULT — code-side default in defaults.ts fills nulls
-- at read time via mergeDesignKit(), same pattern as existing space_* columns.

ALTER TABLE site.design_kits
  ADD COLUMN IF NOT EXISTS space_xl TEXT NULL,
  ADD COLUMN IF NOT EXISTS space_desktop_xl TEXT NULL;
