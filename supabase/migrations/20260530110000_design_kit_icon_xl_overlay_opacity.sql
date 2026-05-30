-- 20260530110000_design_kit_icon_xl_overlay_opacity.sql
--
-- Two additive columns for @partnaau/design-system 4.4.0:
--   icons_xl_size           — extra-large icon size (default 1.5rem in
--                             the package), used by skeleton-1's card-
--                             image overlay glyph and any future surface
--                             that needs a confidence-sized icon on top
--                             of imagery.
--   effect_overlay_opacity  — opacity applied to subtle overlay glyphs /
--                             chrome (default 0.5). Re-introduces the
--                             effects group dropped in 3.0.0 with
--                             .btn-glass; single field for now.
--
-- Both NULLABLE — package defaults handle null at read time. No backfill
-- needed; existing rows keep their NULL for these new columns and the
-- package supplies the values.
--
-- Idempotent via IF NOT EXISTS so the migration is safe to re-run.

ALTER TABLE site.design_kits
    ADD COLUMN IF NOT EXISTS icons_xl_size TEXT NULL,
    ADD COLUMN IF NOT EXISTS effect_overlay_opacity TEXT NULL;
