-- To revert: ALTER TABLE brand.brand_profiles DROP COLUMN signup_code, DROP COLUMN signup_code_active, DROP COLUMN signup_code_rotated_at;

BEGIN;

ALTER TABLE brand.brand_profiles
  ADD COLUMN signup_code text,
  ADD COLUMN signup_code_active boolean NOT NULL DEFAULT true,
  ADD COLUMN signup_code_rotated_at timestamptz;

COMMENT ON COLUMN brand.brand_profiles.signup_code IS
  'Opaque 16-char hex code brands share for en-masse partner onboarding. UNIQUE NOT NULL enforced in 20260522000002/3 after backfill.';

COMMENT ON COLUMN brand.brand_profiles.signup_code_active IS
  'Brands can deactivate their code without rotating it — code stays in column for audit history.';

COMMENT ON COLUMN brand.brand_profiles.signup_code_rotated_at IS
  'Timestamp of the most recent rotation (NULL = never rotated, only generated at create time).';

COMMIT;
