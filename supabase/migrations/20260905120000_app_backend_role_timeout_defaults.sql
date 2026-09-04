-- Give the `app_backend` ROLE its own statement_timeout / lock_timeout defaults.
--
-- WHY: today `DatabaseServiceProvider::boot()` issues `SET statement_timeout` and
-- `SET lock_timeout` once per PDO connection. That holds on port 5432 (Supavisor
-- SESSION mode), where one client connection owns one Postgres backend for its
-- whole life, so a connect-time SET sticks for every later statement.
--
-- It does NOT hold on port 6543 (TRANSACTION mode) — the standing fix for the
-- recurring EMAXCONNSESSION pool exhaustion (docs/runbooks/db-pool-exhausted.md).
-- There a backend is borrowed per transaction, so a connect-time SET lands on an
-- arbitrary backend and the NEXT statement may be served by one that never
-- received it. The timeouts would silently stop applying: no error, no log line,
-- just queries that no longer die at 30s. A role-level default is applied by
-- Postgres at backend startup instead, so every backend carries it regardless of
-- which one the pooler hands out.
--
-- This is the same mechanism Supabase uses for its own `authenticator` role
-- (rolconfig: statement_timeout=8s, lock_timeout=8s).
--
-- NO BEHAVIOUR CHANGE TODAY. The values mirror config/database.php's defaults
-- exactly (30000ms / 10000ms; DB_STATEMENT_TIMEOUT and DB_LOCK_TIMEOUT are unset
-- in both deployed envs), and the provider keeps issuing its own SETs, which now
-- merely re-assert the same two numbers. This migration is groundwork so that
-- flipping DB_PORT to 6543 is the only change that flip requires.
--
-- NOT SET HERE — search_path. Its correct value is per-environment and the two
-- deployed envs genuinely differ (dev `core,site,public,analytics,moderation`;
-- prod appends `audit`), so baking one value into a shared migration would
-- silently change name resolution on the other. It is a per-env operator step in
-- the runbook's transaction-mode checklist instead.
--
-- SAFE: a catalog-only write against a role. No table is touched, no user data is
-- locked, and it takes effect on NEW backends only — existing connections keep
-- whatever they already set.
--
-- ROLLBACK:
--   ALTER ROLE app_backend RESET statement_timeout;
--   ALTER ROLE app_backend RESET lock_timeout;

BEGIN;

-- Guarded so a lane that provisions the schema without the role (a bare Postgres
-- container) does not hard-fail here; the baseline creates app_backend, so on
-- every real target this branch is taken.
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'ALTER ROLE app_backend SET statement_timeout = ''30s''';
        EXECUTE 'ALTER ROLE app_backend SET lock_timeout = ''10s''';
    ELSE
        RAISE NOTICE 'role app_backend absent — timeout defaults not set';
    END IF;
END $$;

COMMIT;
