-- 20260530130000_design_kit_icon_stroke_widths.sql
--
-- Switch icon weight semantics from font-weight to SVG stroke-width.
-- Inline SVGs don't honour font-weight, so the prior icons_xxl_weight
-- column was a no-op for the only icon medium the platform actually
-- uses. Replace with two stroke-width columns the kit + design-styles
-- can apply via CSS `stroke-width`:
--   icons_stroke_width        — default for `.icon` (kit default '2')
--   icons_large_stroke_width  — heavier stroke for `.icon-large`
--                                (chevron, close, download)
--                                (kit default '2.5')
--
-- icons_xxl_weight is dropped — no live consumer references it. The
-- xxl class itself is kept in shared CSS for future use; if a hero
-- glyph ever needs a heavier stroke we'll add icons_xxl_stroke_width.
--
-- All new columns NULLABLE; package defaults handle null at read
-- time. Idempotent via IF NOT EXISTS / IF EXISTS.

ALTER TABLE site.design_kits
    ADD COLUMN IF NOT EXISTS icons_stroke_width TEXT NULL,
    ADD COLUMN IF NOT EXISTS icons_large_stroke_width TEXT NULL,
    DROP COLUMN IF EXISTS icons_xxl_weight;
