-- Add occurred_at indexes for analytics aggregate queries.
-- queryLedger() filters by (brand_professional_id, occurred_at) and (affiliate_professional_id, occurred_at)
-- without a status filter, so the existing (brand, status, occurred_at) indexes are suboptimal.
CREATE INDEX IF NOT EXISTS idx_cle_brand_occurred_at
    ON commerce.commission_ledger_entries (brand_professional_id, occurred_at);

CREATE INDEX IF NOT EXISTS idx_cle_affiliate_occurred_at
    ON commerce.commission_ledger_entries (affiliate_professional_id, occurred_at);
