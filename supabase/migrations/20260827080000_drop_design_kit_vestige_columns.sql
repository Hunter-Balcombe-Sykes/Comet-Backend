-- Plan 02 (overnight run 2026-08-27), step 2: three design-kit columns die.
--
--   border_thickness        — the 'default' | 'none' selection. The border is
--                             a constant 1px hairline now; the dashboard card,
--                             validation rule, wire key and THICKNESS_PRESETS
--                             all went in the same change.
--   theme_mode              — one legal value ('bleach') driving no CSS since
--                             the grey ramp replaced the anchor palettes
--                             (2026-08-09). ThemeModePalettes.php keeps the
--                             bleach anchor pair for email theming; its
--                             anchorsFor(null) path is the only caller left.
--   theme_night_shift_auto  — the night-shift FEATURE is removed (pre-paint
--                             clock, [data-shift] blocks, reversed ramp):
--                             sites are always the day palette.
--
-- DROP COLUMN is a catalog-only write (no rewrite, no scan); short lock per
-- CONVENTIONS.md §8. site.create_empty_design_kit() inserts only site_id, so
-- the trigger needs no change.
--
-- ROLLBACK:
--   ALTER TABLE site.design_kits ADD COLUMN border_thickness text;
--   ALTER TABLE site.design_kits ADD COLUMN theme_mode text;
--   ALTER TABLE site.design_kits ADD COLUMN theme_night_shift_auto boolean DEFAULT false;
--   (values are unrecoverable — the axes were vestiges, so nothing renders
--    differently without them)

BEGIN;

SET LOCAL lock_timeout = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE "site"."design_kits"
    DROP COLUMN IF EXISTS "border_thickness",
    DROP COLUMN IF EXISTS "theme_mode",
    DROP COLUMN IF EXISTS "theme_night_shift_auto";

COMMIT;
