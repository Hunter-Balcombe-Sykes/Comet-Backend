-- =====================================================================
-- Design kit: drop background-image toggle
-- =====================================================================
-- Reverses 20260527120000_design_kit_bg_image_toggle.sql.
--
-- The @partnaau/design-system package no longer exposes the
-- effects.bgImageEnabled boolean — body background images were removed
-- as a feature. Skeleton-2 used to compose .themed-bg-image onto its
-- root; that class is gone from design-styles, and skeleton-1 never
-- adopted it after the cleanup. The `effect_bg_image_enabled` column
-- is orphaned and dropped here.
--
-- Matching frontend changes (in lockstep with this migration):
--   - types.ts / defaults.ts / vars.css / validate.ts in design-kit:
--     effects.bgImageEnabled removed
--   - design-styles/effects.css: .themed-bg-image class removed
--   - partna-pages/src/pages/index.astro: dispatcher's bg-image
--     derivation block removed
--   - skeleton-2.astro: .themed-bg-image removed from root class list
--   - skeleton-1/3/4.astro: content_images? Props field removed
--
-- Validator rule removal: 'design_kit.effect_bg_image_enabled' rule is
-- removed from UpdateSiteRequest + StaffUpdateSiteRequest in the same
-- PR. groupKitColumns in IndividualProfilePayloadBuilder iterates real
-- columns; dropping the column is enough — no PHP change there.
-- =====================================================================

BEGIN;

ALTER TABLE site.design_kits
  DROP COLUMN IF EXISTS effect_bg_image_enabled;

COMMIT;
