-- Plan §28.16 Migration A (columns).
--
-- Adds soft-delete to brand.brand_partner_links and the denormalized
-- has_historical_partner_links boolean on core.professionals (audit SCALE-1).
-- The denormalization avoids the 2N exists() pattern on capability lookups in
-- high-traffic notification fan-outs.
--
-- Both ADD COLUMNs are metadata-only in Postgres 11+ (constant default for the
-- boolean, NULLable column for deleted_at) — neither rewrites the table, so
-- the BEGIN/COMMIT wrapper holds ACCESS EXCLUSIVE only briefly for catalog
-- updates. Backfill of has_historical_partner_links runs in the next file so
-- the lock window stays small.
--
-- To revert:
--   ALTER TABLE core.professionals DROP COLUMN has_historical_partner_links;
--   ALTER TABLE brand.brand_partner_links DROP COLUMN deleted_at;

BEGIN;

ALTER TABLE brand.brand_partner_links
    ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMPTZ NULL;

ALTER TABLE core.professionals
    ADD COLUMN IF NOT EXISTS has_historical_partner_links boolean NOT NULL DEFAULT false;

COMMIT;
