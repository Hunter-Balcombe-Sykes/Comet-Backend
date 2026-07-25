-- F1 (P0): the moderation schema (created in 20260528000000) and its tables
-- (20260528*, 20260529*) were never granted to app_backend. The baseline grants
-- only core/site/notifications/analytics/public; audit was handled in
-- 20260527010000. Without these grants every moderation.* query from Laravel
-- (which connects as app_backend in real dev/prod) fails with
-- "permission denied for schema moderation". Hidden locally because the local
-- stack connects as the postgres superuser.
--
-- Mirrors the app_backend block in the baseline. Runs after all moderation
-- tables exist, so GRANT ON ALL TABLES covers cases, case_signals, evidence,
-- decisions, action_log, csam_quarantine, ncmec_submissions; ALTER DEFAULT
-- PRIVILEGES covers future moderation tables.

BEGIN;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'GRANT USAGE ON SCHEMA moderation TO app_backend';

        EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA moderation TO app_backend';
        EXECUTE 'GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA moderation TO app_backend';

        -- Future moderation tables inherit the same CRUD grant.
        EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA moderation GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_backend';
        EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA moderation GRANT USAGE, SELECT ON SEQUENCES TO app_backend';
    END IF;
END $$;

-- service_role parity with the other application schemas (used by Supabase tooling).
GRANT USAGE ON SCHEMA moderation TO service_role;

COMMIT;
