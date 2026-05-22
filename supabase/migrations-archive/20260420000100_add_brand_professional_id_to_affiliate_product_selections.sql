-- Selections are now scoped per brand so disconnecting one brand doesn't wipe
-- selections belonging to other brand partners of the same affiliate.
ALTER TABLE commerce.affiliate_product_selections
    ADD COLUMN IF NOT EXISTS brand_professional_id uuid
        REFERENCES core.professionals(id) ON DELETE CASCADE;

-- Backfill from brand_partner_links. Pre-beta, each affiliate has at most
-- one brand link; the primary-slot (slot=0) brand is unambiguous.
UPDATE commerce.affiliate_product_selections s
SET brand_professional_id = l.brand_professional_id
FROM brand.brand_partner_links l
WHERE l.affiliate_professional_id = s.affiliate_professional_id
  AND l.slot = 0
  AND s.brand_professional_id IS NULL;

-- Any remaining NULLs are orphans from prior disconnects (selections whose
-- brand no longer links to this affiliate) and are removed.
DELETE FROM commerce.affiliate_product_selections
WHERE brand_professional_id IS NULL;

ALTER TABLE commerce.affiliate_product_selections
    ALTER COLUMN brand_professional_id SET NOT NULL;

CREATE INDEX IF NOT EXISTS affiliate_product_selections_brand_idx
    ON commerce.affiliate_product_selections (affiliate_professional_id, brand_professional_id);
