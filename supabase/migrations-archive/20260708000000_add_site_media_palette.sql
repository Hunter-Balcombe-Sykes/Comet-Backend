-- #76 Part A — imagery-palette design factor (band 22, ImageryPaletteFactor).
-- Stores dominant-colour + palette metadata extracted from processed gallery
-- imagery so IdentityEvidence::mediaPalette() has a source. Additive, NULLABLE,
-- NO DB default (grandfathered rows + extraction failures stay NULL, and the
-- factor abstains cleanly for them). Extraction is failure-tolerant server-side
-- GD work in ImageVariantService; a backfill (media:backfill-palette) fills
-- existing ready images.

BEGIN;

-- Dominant colour as a #RRGGBB hex string (convenience / debugging; the factor
-- reads `palette` below). NULL until extracted.
ALTER TABLE site.site_media
    ADD COLUMN IF NOT EXISTS dominant_color TEXT NULL;

-- Full extracted palette blob: { dominant, colors[], saturation, warm }.
--   dominant   text   #RRGGBB of the most-prominent sampled colour
--   colors     text[] up to 5 representative #RRGGBB swatches, most-prominent first
--   saturation float  average pixel saturation, 0..1 (0 = greyscale, 1 = vivid)
--   warm       bool   true when warm hues (reds/oranges/yellows) dominate over cool
-- NULL until extracted / on any extraction failure.
ALTER TABLE site.site_media
    ADD COLUMN IF NOT EXISTS palette JSONB NULL;

COMMENT ON COLUMN site.site_media.dominant_color IS
    '#RRGGBB dominant colour of the processed image, or NULL. Convenience mirror of palette->>dominant. #76.';

COMMENT ON COLUMN site.site_media.palette IS
    'Extracted colour metadata { dominant, colors[], saturation(0..1), warm(bool) } for the ImageryPaletteFactor, or NULL. #76.';

COMMIT;
