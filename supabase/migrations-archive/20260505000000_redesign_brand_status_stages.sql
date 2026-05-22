-- Expand brand_status to a 7-state lifecycle with numbered stages:
--   onboarding            → Stage 1: fresh signup, no Shopify connection
--   shopify_linked        → Stage 2: OAuth complete, access_token present
--   shopify_configured    → Stage 3: Hydrogen/Oxygen/Domain configured
--   storefront_live       → Stage 4: storefront HTTP-reachable
--   ready_for_affiliates  → Stage 5: full live gate (images + Shopify + Stripe)
--   disconnected          → out-of-band: Shopify app uninstalled
--   systems_down          → out-of-band: manual override
--
-- Maps old → new:
--   building (no Shopify)          → onboarding
--   building (Shopify, incomplete) → shopify_linked or shopify_configured
--   preview                        → storefront_live
--   live                           → ready_for_affiliates
--   systems_down                   → systems_down (unchanged)

ALTER TABLE brand.brand_profiles
  DROP CONSTRAINT IF EXISTS chk_brand_profiles_brand_status;

ALTER TABLE brand.brand_profiles
  ADD CONSTRAINT chk_brand_profiles_brand_status
  CHECK (brand_status IN (
    'onboarding',
    'shopify_linked',
    'shopify_configured',
    'storefront_live',
    'ready_for_affiliates',
    'disconnected',
    'systems_down'
  ));

ALTER TABLE brand.brand_profiles
  ALTER COLUMN brand_status SET DEFAULT 'onboarding';

-- Backfill: existing 'building' with Shopify connected (access_token present) → shopify_linked
-- The BrandStatusService will refine this to shopify_configured/storefront_live on next sync.
UPDATE brand.brand_profiles bp
SET brand_status = 'shopify_linked'
WHERE brand_status = 'building'
  AND EXISTS (
    SELECT 1 FROM core.professional_integrations pi
    WHERE pi.professional_id = bp.professional_id
      AND pi.provider = 'shopify'
      AND pi.access_token IS NOT NULL
  );

-- Remaining 'building' → 'onboarding'
UPDATE brand.brand_profiles
SET brand_status = 'onboarding'
WHERE brand_status = 'building';

-- 'preview' → 'storefront_live'
UPDATE brand.brand_profiles
SET brand_status = 'storefront_live'
WHERE brand_status = 'preview';

-- 'live' → 'ready_for_affiliates'
UPDATE brand.brand_profiles
SET brand_status = 'ready_for_affiliates'
WHERE brand_status = 'live';

-- 'systems_down' unchanged
