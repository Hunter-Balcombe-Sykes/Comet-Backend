-- NOT NULL enforcement — Steps 3 and 4 of the four-step pattern (CONVENTIONS.md §3).
-- Validate + promote in the same transaction. Postgres skips the row scan during
-- SET NOT NULL when a validated NOT NULL check constraint already exists, making
-- the promotion near-instant (metadata-only catalog write).
--
-- Step 2 (backfill) was: php artisan brand:backfill-signup-codes
--
-- To revert:
--   ALTER TABLE brand.brand_profiles ALTER COLUMN signup_code DROP NOT NULL;
--   ALTER TABLE brand.brand_profiles ADD CONSTRAINT chk_brand_profiles_signup_code_not_null
--       CHECK (signup_code IS NOT NULL) NOT VALID;

BEGIN;

-- Step 3: validate (SHARE UPDATE EXCLUSIVE — allows concurrent writes)
ALTER TABLE brand.brand_profiles
  VALIDATE CONSTRAINT chk_brand_profiles_signup_code_not_null;

-- Step 4: promote to true NOT NULL (near-instant — row scan skipped because validated check exists)
ALTER TABLE brand.brand_profiles ALTER COLUMN signup_code SET NOT NULL;
ALTER TABLE brand.brand_profiles DROP CONSTRAINT chk_brand_profiles_signup_code_not_null;

COMMIT;
