-- NOT NULL enforcement — Step 1 of 4 (CONVENTIONS.md §3).
-- Add a NOT VALID check constraint. Lock-light: lock released immediately, no row scan.
--
-- To revert: ALTER TABLE brand.brand_profiles DROP CONSTRAINT IF EXISTS chk_brand_profiles_signup_code_not_null;

BEGIN;

ALTER TABLE brand.brand_profiles
  ADD CONSTRAINT chk_brand_profiles_signup_code_not_null
    CHECK (signup_code IS NOT NULL) NOT VALID;

COMMIT;
