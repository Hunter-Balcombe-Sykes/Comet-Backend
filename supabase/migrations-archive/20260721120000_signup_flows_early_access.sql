-- Signup flows & early access (spec 2026-07-21).
-- pre_account_builds: + contact_email (notify + email-gate value), + 'early_access'
--   built_via, expires_at nullable (early-access builds don't expire until approved).
-- early_access_signups: + source_type/source_ref (resolvable build source) + user_id
--   link to the provisional user.
--
-- guard:no-unsafe-migrations:disable-file
-- pre-beta / no live customers (CLAUDE.md) — same near-empty-table exemption class as
-- 20260718000000_pre_account_sites.sql; the CHECK swap below mirrors that file's
-- users_status_check pattern (direct DROP/ADD on a near-empty table).

BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

-- pre_account_builds ---------------------------------------------------------
ALTER TABLE core.pre_account_builds
    ADD COLUMN IF NOT EXISTS contact_email text NULL;

-- Widen built_via to admit early-access-originated builds (inline CHECK is
-- auto-named pre_account_builds_built_via_check).
ALTER TABLE core.pre_account_builds DROP CONSTRAINT pre_account_builds_built_via_check;
ALTER TABLE core.pre_account_builds ADD CONSTRAINT pre_account_builds_built_via_check
    CHECK (built_via IN ('signup', 'staff', 'early_access'));

-- Early-access builds have no expiry until a staff approval opens the claim
-- window. NULL = never-expire. (pre_account_builds_expiry_idx stays valid:
-- Postgres `expires_at < now()` never matches a NULL row, so prune skips them.)
ALTER TABLE core.pre_account_builds ALTER COLUMN expires_at DROP NOT NULL;

-- early_access_signups -------------------------------------------------------
-- NB: the existing `source` column means marketing-vs-manual origin — DO NOT
-- reuse it. These are the resolvable build source (handle / place id).
ALTER TABLE core.early_access_signups
    ADD COLUMN IF NOT EXISTS source_type text NULL
        CHECK (source_type IS NULL OR source_type IN ('instagram', 'google_business')),
    ADD COLUMN IF NOT EXISTS source_ref  text NULL,
    ADD COLUMN IF NOT EXISTS user_id     uuid NULL REFERENCES core.users(id) ON DELETE SET NULL;

-- One early-access signup per provisional user.
CREATE UNIQUE INDEX IF NOT EXISTS early_access_signups_user_id_unique
    ON core.early_access_signups (user_id) WHERE user_id IS NOT NULL;

COMMIT;
