-- Convert commission_ledger_entries professional FKs from CASCADE to SET NULL so
-- that deleting a professional preserves ledger entries for audit trail.
-- Asymmetry with payout_id (already SET NULL) is resolved — all three FK columns
-- now use the same "null the reference, keep the row" retention policy.

ALTER TABLE commerce.commission_ledger_entries
    ALTER COLUMN brand_professional_id DROP NOT NULL,
    ALTER COLUMN affiliate_professional_id DROP NOT NULL;

ALTER TABLE commerce.commission_ledger_entries
    DROP CONSTRAINT IF EXISTS commission_ledger_entries_brand_professional_id_fkey,
    DROP CONSTRAINT IF EXISTS commission_ledger_entries_affiliate_professional_id_fkey;

ALTER TABLE commerce.commission_ledger_entries
    ADD CONSTRAINT commission_ledger_entries_brand_professional_id_fkey
      FOREIGN KEY (brand_professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL,
    ADD CONSTRAINT commission_ledger_entries_affiliate_professional_id_fkey
      FOREIGN KEY (affiliate_professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL;
