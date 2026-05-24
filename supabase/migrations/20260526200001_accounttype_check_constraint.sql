-- B11/P2-13: Enforce account_type = 'individual' on core.users.
-- The standalone strip (2026-05-22) reduced AccountType enum to a single case.
-- Step 1: normalise any stale rows from pre-strip environments.
-- Step 2: add CHECK constraint with NOT VALID (lock-light).

BEGIN;

-- 1. Normalise stale rows (safe no-op on fresh DBs).
UPDATE core.users
SET account_type = 'individual'
WHERE account_type IS DISTINCT FROM 'individual';

-- 2. Add the constraint with NOT VALID (prevents new violations; existing rows skip scan).
-- VALIDATE happens in 20260524200003_validate_accounttype_check.sql
ALTER TABLE core.users
    ADD CONSTRAINT users_account_type_individual
    CHECK (account_type = 'individual') NOT VALID;

COMMIT;
