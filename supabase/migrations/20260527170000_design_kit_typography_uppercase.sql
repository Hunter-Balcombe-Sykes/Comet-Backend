-- =====================================================================
-- Design kit: typography.uppercase (boolean toggle)
-- =====================================================================
-- Backs the new boolean kit field introduced by @partnaau/design-system
-- 2.6.6. When true, the partna-pages dispatcher emits
-- `--dk-text-transform: uppercase` in the per-user inline style block,
-- which the shared .layout-stack / .content-container classes consume
-- so all text renders in uppercase.
--
-- Same pattern as effects.bgImageEnabled:
--   - BOOLEAN NULL column. NULL is treated as "unset" → defaults.ts
--     value (false) fills it via the package's read-time merge.
--   - groupKitColumns routes `typography_uppercase` → typography.uppercase
--     automatically (single-token prefix split on the first `_`).
-- =====================================================================

BEGIN;

ALTER TABLE site.design_kits
  ADD COLUMN typography_uppercase BOOLEAN NULL;

COMMIT;
