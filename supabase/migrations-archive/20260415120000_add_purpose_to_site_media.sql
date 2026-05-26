-- Add a `purpose` discriminator column to site.site_media for brand design
-- assets, replacing the alt_text='logo'|'placeholder' string match used by the
-- previous design-pool singleton index.
--
-- Why: alt_text is supposed to hold accessibility text. Overloading it for slot
-- identification was always fragile, and it can't distinguish the full logo
-- from the square logo (Shopify gives us both — the old code only stored one).
-- A dedicated column lets us:
--   1. Hold logo_full + logo_square as two coexisting singleton rows per site.
--   2. Order placeholders by sort_order within their own per-site namespace.
--   3. Clean up alt_text for actual a11y text in a follow-up.
--
-- This migration is non-destructive: it backfills purpose from alt_text for
-- any existing design-pool rows and keeps alt_text intact (we'll repurpose it
-- to a11y text later, but not in this migration).

BEGIN;

ALTER TABLE site.site_media
    ADD COLUMN IF NOT EXISTS purpose text;

-- Backfill: any existing design-pool rows used alt_text='logo' or 'placeholder'.
-- Map them to the new purpose values. logo → logo_full (the old code only
-- stored one logo per site, conceptually the "full" version).
UPDATE site.site_media
SET purpose = 'logo_full'
WHERE pool = 'design'
  AND alt_text = 'logo'
  AND purpose IS NULL;

UPDATE site.site_media
SET purpose = 'placeholder'
WHERE pool = 'design'
  AND alt_text = 'placeholder'
  AND purpose IS NULL;

-- Drop the old alt_text-scoped singleton index and replace with two
-- purpose-scoped indexes so logo_full and logo_square can coexist.
DROP INDEX IF EXISTS site.site_media_design_logo_uq;

CREATE UNIQUE INDEX site_media_design_logo_full_uq
    ON site.site_media (site_id)
    WHERE pool = 'design'
      AND purpose = 'logo_full'
      AND deleted_at IS NULL;

CREATE UNIQUE INDEX site_media_design_logo_square_uq
    ON site.site_media (site_id)
    WHERE pool = 'design'
      AND purpose = 'logo_square'
      AND deleted_at IS NULL;

-- Placeholders need stable per-site sort_order with no gaps. Scope uniqueness
-- to (site_id, sort_order) for placeholder rows only — gallery/content pools
-- already have their own per-pool index from 20260414100000.
CREATE UNIQUE INDEX site_media_design_placeholder_sort_uq
    ON site.site_media (site_id, sort_order)
    WHERE pool = 'design'
      AND purpose = 'placeholder'
      AND deleted_at IS NULL
      AND is_active = true;

COMMIT;
