-- 20260612120000_account_type_partna_business.sql
--
-- Introduces the two-account-type model:
--   * 'partna'   — the standard account (every pre-existing account).
--   * 'business' — "Business Partna".
--
-- Replaces the individual-only stub where account_type was pinned to
-- 'individual'. Both types are functionally identical today — this only records
-- which one a user picked at signup or switched to in settings. Nothing branches
-- on the value yet; that is a later, deliberate change.
--
-- Order matters: drop the old single-value CHECK, backfill existing 'individual'
-- rows to 'partna', flip the column default, then add the two-value CHECK. On a
-- fresh DB the UPDATE is a harmless no-op (baseline created the column with the
-- old constraint first), so this stays forward-compatible with the baseline.
--
-- The legacy 'individual' value is intentionally NOT permitted going forward. The
-- AccountType enum keeps an Individual case only so Eloquent casting never throws
-- on a row read between the code deploy and this backfill — never as a selectable
-- type (request validation rejects it).

ALTER TABLE core.users DROP CONSTRAINT IF EXISTS users_account_type_check;
-- The dev DB also carries a second, directly-added constraint enforcing the same
-- individual-only rule under a different name; drop it too or the backfill below
-- is blocked. IF EXISTS keeps this a no-op on any DB that never had it.
ALTER TABLE core.users DROP CONSTRAINT IF EXISTS users_account_type_individual;

UPDATE core.users SET account_type = 'partna' WHERE account_type = 'individual';

ALTER TABLE core.users ALTER COLUMN account_type SET DEFAULT 'partna';

ALTER TABLE core.users
    ADD CONSTRAINT users_account_type_check CHECK (account_type IN ('partna', 'business'));
