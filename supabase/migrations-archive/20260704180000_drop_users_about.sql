-- =====================================================================
-- Pre-pilot P0 CONTRACT — FOUND-37 (drop core.users.about)
-- =====================================================================
-- The contract half of the P0 sweep, split out from the expand migration
-- (20260704150000). Apply to dev Supabase ONLY AFTER the FOUND-37 code has
-- deployed: the currently-deployed OLD code still writes `about` via fill(),
-- so dropping the column before the new code (which no longer touches `about`)
-- is live would 500 the profile-update path. The new code reads credentials/
-- experience from the child tables (20260701150100), so `about` is dead weight
-- once deployed. Dropping the column also removes the users_about_is_object
-- CHECK automatically (it is column-scoped).
-- =====================================================================
BEGIN;
ALTER TABLE core.users DROP COLUMN IF EXISTS about;
COMMIT;

-- ROLLBACK (data is unrecoverable — the column was dead before the drop):
-- BEGIN;
-- ALTER TABLE core.users ADD COLUMN about jsonb NOT NULL DEFAULT '{}'::jsonb;
-- ALTER TABLE core.users ADD CONSTRAINT users_about_is_object CHECK (jsonb_typeof(about) = 'object');
-- COMMIT;
