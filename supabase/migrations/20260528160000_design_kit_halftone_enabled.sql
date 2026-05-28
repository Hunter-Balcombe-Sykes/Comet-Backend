-- =====================================================================
-- Design kit: effects.halftoneEnabled (boolean toggle)
-- =====================================================================
-- Backs the new boolean kit field introduced by @partnaau/design-system
-- 2.6.12. Default is true (halftone shows everywhere .glass is composed,
-- via the package's --dk-overlay-pattern var declared in vars.css).
-- When stored false, the partna-pages dispatcher emits
-- `--dk-overlay-pattern: none` in the per-user inline style block, so
-- .glass + .btn-glass render as plain frosted glass with no dot grain.
--
-- Same shape as effect_bg_image_enabled:
--   - BOOLEAN NULL column. NULL is treated as "unset" → defaults.ts
--     value (true) fills it via the package's read-time merge.
--   - groupKitColumns routes `effect_halftone_enabled` →
--     effects.halftoneEnabled automatically.
-- =====================================================================

BEGIN;

ALTER TABLE site.design_kits
  ADD COLUMN effect_halftone_enabled BOOLEAN NULL;

COMMIT;
