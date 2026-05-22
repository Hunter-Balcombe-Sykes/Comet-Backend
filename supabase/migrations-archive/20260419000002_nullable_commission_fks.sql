-- Migrate 3 RESTRICT FKs to SET NULL so forceDelete() on a professional does not
-- block. Columns become nullable; application code must tolerate null professional
-- references (rendered as "Deleted user" in staff UI).

-- commerce.commission_payouts — brand_professional_id + affiliate_professional_id
ALTER TABLE commerce.commission_payouts
    ALTER COLUMN brand_professional_id DROP NOT NULL,
    ALTER COLUMN affiliate_professional_id DROP NOT NULL;

ALTER TABLE commerce.commission_payouts
    DROP CONSTRAINT IF EXISTS commission_payouts_brand_professional_id_fkey,
    DROP CONSTRAINT IF EXISTS commission_payouts_affiliate_professional_id_fkey;

ALTER TABLE commerce.commission_payouts
    ADD CONSTRAINT commission_payouts_brand_professional_id_fkey
      FOREIGN KEY (brand_professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL,
    ADD CONSTRAINT commission_payouts_affiliate_professional_id_fkey
      FOREIGN KEY (affiliate_professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL;

-- commerce.brand_commission_topups — brand_professional_id
ALTER TABLE commerce.brand_commission_topups
    ALTER COLUMN brand_professional_id DROP NOT NULL;

ALTER TABLE commerce.brand_commission_topups
    DROP CONSTRAINT IF EXISTS brand_commission_topups_brand_professional_id_fkey;

ALTER TABLE commerce.brand_commission_topups
    ADD CONSTRAINT brand_commission_topups_brand_professional_id_fkey
      FOREIGN KEY (brand_professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL;
