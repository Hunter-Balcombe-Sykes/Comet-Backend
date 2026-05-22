-- Phase B (part 1/3): promote the CONCURRENTLY-built index into a UNIQUE constraint.
-- The NOT NULL enforcement follows in the next three migrations (20260522000004-6)
-- using the four-step pattern required by CONVENTIONS.md §3.
--
-- To revert: ALTER TABLE brand.brand_profiles DROP CONSTRAINT IF EXISTS brand_profiles_signup_code_unique;
-- (The underlying index is dropped automatically when the constraint is dropped.)

BEGIN;

-- Bail if the Artisan backfill has not been run yet.
-- Run: php artisan brand:backfill-signup-codes
DO $$ BEGIN
  IF EXISTS (SELECT 1 FROM brand.brand_profiles WHERE signup_code IS NULL) THEN
    RAISE EXCEPTION 'backfill incomplete: % rows have NULL signup_code',
      (SELECT count(*) FROM brand.brand_profiles WHERE signup_code IS NULL);
  END IF;
END $$;

ALTER TABLE brand.brand_profiles
  ADD CONSTRAINT brand_profiles_signup_code_unique
    UNIQUE USING INDEX brand_profiles_signup_code_unique;

COMMIT;
