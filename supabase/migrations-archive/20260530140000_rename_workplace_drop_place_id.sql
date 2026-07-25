-- 20260530140000_rename_workplace_drop_place_id.sql
--
-- Rename site.sites.settings.google_business_profile → settings.workplace
-- and drop the `place_id` subkey. The Google "smart sync" concept (and
-- its only artefact, place_id) is being retired: the workplace card is
-- one shape whether the dashboard's autofill suggestions populated the
-- fields or the user hand-typed them. The endpoint + controller + Form
-- Request all rename to match (`/site/workplace`).
--
-- Idempotent in three ways:
--   1. The first UPDATE skips rows without a google_business_profile key.
--   2. COALESCE prefers any pre-existing workplace key (paranoia — there
--      shouldn't be one, but if a partial migration ran twice the second
--      pass won't overwrite the renamed data).
--   3. The second UPDATE skips rows without place_id (handles the case
--      where this migration is run before any rows had place_id).
--
-- Rollback would re-rename + restore place_id from a backup; not provided
-- here because the rename is the desired terminal state.

BEGIN;

-- Step 1: Move the JSONB blob to its new key. Any existing workplace key
-- (none expected) wins via COALESCE.
UPDATE site.sites
SET settings = jsonb_set(
        settings - 'google_business_profile',
        '{workplace}',
        COALESCE(settings -> 'workplace', settings -> 'google_business_profile')
    )
WHERE settings ? 'google_business_profile';

-- Step 2: Drop place_id from inside the (now-named) workplace blob.
UPDATE site.sites
SET settings = jsonb_set(
        settings,
        '{workplace}',
        (settings -> 'workplace') - 'place_id'
    )
WHERE settings ? 'workplace'
  AND (settings -> 'workplace') ? 'place_id';

COMMIT;
