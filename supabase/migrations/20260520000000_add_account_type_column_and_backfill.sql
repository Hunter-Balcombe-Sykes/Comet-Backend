-- Plan §28.1 step 1 (audit MIG-4 / MIG-6 / MIG-7).
--
-- Adds the new canonical `account_type` column on core.professionals and
-- backfills it in the same transaction so the column is never observed in a
-- partially-applied state (all-NULL) by application code.
--
-- The legacy `professional_type` column stays in place and is dual-written
-- (trigger added in the next migration) until reads are fully migrated.
--
-- Backfill rules per plan §8:
--   professional_type='brand'                            -> 'brand'
--   professional_type IN ('professional','influencer')
--     AND has any brand_partner_links row                -> 'partner'
--   professional_type IN ('professional','influencer')
--     AND has NO brand_partner_links row                 -> 'individual'
--
-- Every UPDATE branch is guarded with `WHERE account_type IS NULL` so re-runs
-- are idempotent and don't clobber rows the dual-write trigger has legitimately
-- mutated since the initial backfill.
--
-- To revert: ALTER TABLE core.professionals DROP COLUMN account_type;

BEGIN;

ALTER TABLE core.professionals
    ADD COLUMN IF NOT EXISTS account_type text NULL;

-- Bucket 1: brand -> brand
UPDATE core.professionals
   SET account_type = 'brand'
 WHERE account_type IS NULL
   AND professional_type = 'brand';

-- Bucket 2: non-brand with an active BrandPartnerLink -> partner
UPDATE core.professionals AS p
   SET account_type = 'partner'
 WHERE p.account_type IS NULL
   AND p.professional_type IN ('professional', 'influencer')
   AND EXISTS (
       SELECT 1
         FROM brand.brand_partner_links l
        WHERE l.affiliate_professional_id = p.id
   );

-- Bucket 3: non-brand with no BrandPartnerLink -> individual
UPDATE core.professionals AS p
   SET account_type = 'individual'
 WHERE p.account_type IS NULL
   AND p.professional_type IN ('professional', 'influencer')
   AND NOT EXISTS (
       SELECT 1
         FROM brand.brand_partner_links l
        WHERE l.affiliate_professional_id = p.id
   );

COMMIT;
