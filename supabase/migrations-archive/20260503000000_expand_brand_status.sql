-- Expand brand_status from binary active/deactivated to a 4-state lifecycle:
--   building    → default on signup, getting Shopify/Hydrogen configured
--   preview     → wizard complete, storefront reachable, brand content not ready
--   live        → fully ready, can send invites
--   systems_down → manual override for platform issues (future: staff UI)
--
-- Phase 1 (this migration): keep old values valid so existing code doesn't break
-- during deploy. A follow-up migration tightens the constraint once code is live.

ALTER TABLE brand.brand_profiles
  DROP CONSTRAINT IF EXISTS chk_brand_profiles_brand_status;

ALTER TABLE brand.brand_profiles
  ADD CONSTRAINT chk_brand_profiles_brand_status
  CHECK (brand_status IN ('active', 'deactivated', 'building', 'preview', 'live', 'systems_down'));

ALTER TABLE brand.brand_profiles
  ALTER COLUMN brand_status SET DEFAULT 'building';

-- Backfill: 'active' → 'live' (brands already fully onboarded)
UPDATE brand.brand_profiles
SET brand_status = 'live'
WHERE brand_status = 'active';

-- Backfill: 'deactivated' with complete Shopify wizard → 'preview'
-- A complete wizard = hydrogen confirmed + token saved + storefront ID + domain done
UPDATE brand.brand_profiles bp
SET brand_status = 'preview'
WHERE brand_status = 'deactivated'
  AND EXISTS (
    SELECT 1 FROM brand.brand_store_settings bss
    WHERE bss.professional_id = bp.professional_id
      AND bss.hydrogen_install_confirmed = true
      AND bss.oxygen_storefront_id IS NOT NULL
      AND bss.domain_wizard_complete = true
  );

-- Remaining 'deactivated' → 'building' (wizard incomplete or no store settings)
UPDATE brand.brand_profiles
SET brand_status = 'building'
WHERE brand_status = 'deactivated';
