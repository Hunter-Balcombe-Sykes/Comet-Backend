-- Drop the six design-kit selection axes removed on 2026-08-06.
--
-- The sitepage design kit narrowed from eleven user-facing axes to five. These
-- six stop being choices entirely; the CSS vars they used to drive survive in
-- the design system as fixed literals, so no rendered site changes:
--
--   theme_contrast        soft | normal | stark  → gone; one palette survives
--   typography_tracking   tight | normal | wide  → gone; --dk-tracking-body: 0em
--   typography_weight     light | regular | bold → gone; the 400/500 weight
--                                                  SCALE stays, only the
--                                                  register that shifted it goes
--   typography_uppercase  boolean                → gone; --dk-text-transform: none
--   motion_pace           slow | normal | fast   → gone; the four motion vars
--                                                  become literals at 'normal'
--   border_style          solid | double | none  → gone; --dk-border-style: solid
--
-- theme_mode is NOT dropped: it narrows to a single legal value ('bleach') and
-- keeps its column, because the palette anchors still key off it server-side
-- (ThemeModePalettes, email theming) and a future second palette would want it
-- back. Its validation rule narrows to `in:bleach` in the same change set.
--
-- The two CHECK constraints on theme_contrast and typography_tracking drop
-- automatically with their columns — no separate DROP CONSTRAINT needed.
--
-- Ordering: 20260806090000 backfills the surviving values first. This file must
-- not run before it.
--
-- Locking: DROP COLUMN is a catalog-only write in Postgres (the data is left
-- for a later rewrite), so ACCESS EXCLUSIVE is held for microseconds even on a
-- hot table. site.design_kits is on the hot list, hence the short lock_timeout —
-- if the lock cannot be taken promptly the migration fails fast rather than
-- queueing behind a long transaction and blocking every writer behind it.
--
-- ROLLBACK:
--   ALTER TABLE site.design_kits
--       ADD COLUMN theme_contrast text,
--       ADD COLUMN typography_tracking text,
--       ADD COLUMN typography_weight text,
--       ADD COLUMN typography_uppercase boolean,
--       ADD COLUMN motion_pace text,
--       ADD COLUMN border_style text;
--   ALTER TABLE site.design_kits
--       ADD CONSTRAINT design_kits_theme_contrast_check
--       CHECK (theme_contrast IS NULL OR theme_contrast = ANY (ARRAY['soft','normal','stark'])) NOT VALID;
--   ALTER TABLE site.design_kits
--       ADD CONSTRAINT design_kits_typography_tracking_check
--       CHECK (typography_tracking IS NULL OR typography_tracking = ANY (ARRAY['tight','normal','wide'])) NOT VALID;
--   (Structure only — the values are gone. See 20260806090000's rollback note.)

BEGIN;

SET LOCAL lock_timeout = '2s';
SET LOCAL statement_timeout = '60s';

ALTER TABLE "site"."design_kits"
    DROP COLUMN IF EXISTS "theme_contrast",
    DROP COLUMN IF EXISTS "typography_tracking",
    DROP COLUMN IF EXISTS "typography_weight",
    DROP COLUMN IF EXISTS "typography_uppercase",
    DROP COLUMN IF EXISTS "motion_pace",
    DROP COLUMN IF EXISTS "border_style";

COMMIT;
