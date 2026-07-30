-- Give PostgREST an existent-but-empty schema to expose.
--
-- Supabase implements "Disable Data API" as postgrest db_schema = '', which
-- makes PostgREST point its search_path at the sentinel schema
-- pg_pgrst_no_exposed_schemas. That schema deliberately does not exist, so
-- every schema-cache load fails with SQLSTATE 3F000 and PostgREST retries on
-- a capped 32s backoff forever -- one ERROR line per retry, permanently,
-- which buries real errors in the Postgres log.
--
-- Pointing db_schema at a real but empty schema lets the cache load succeed
-- against zero objects: the Data API stays functionally disabled (no table,
-- view or function is reachable) and the log noise stops.
--
-- Pairs with a Management API setting; the schema alone changes nothing:
--   PATCH /v1/projects/<ref>/postgrest {"db_schema": "pgrst_exposed_none"}
--
-- Originally applied to dev 2026-07-23 (see supabase/migrations-archive/) but
-- dropped from the tracked migration set during the 2026-07-26 baseline
-- collapse (the baseline dump is scoped to public/core/site/notifications/
-- analytics/audit only). Re-added here, and separately applied to prod
-- 2026-07-26, so a from-zero apply on either env carries the fix.
--
-- ROLLBACK: DROP SCHEMA IF EXISTS pgrst_exposed_none; -- RESTRICT: must stay empty
--           AND flip the paired Management API setting back:
--             PATCH /v1/projects/<ref>/postgrest {"db_schema": ""}
--           Doing only the DROP is WORSE than not reverting: PostgREST's
--           schema-cache load starts failing 3F000 again on a capped 32s
--           backoff, forever.

BEGIN;
SET LOCAL lock_timeout = '2s';

CREATE SCHEMA IF NOT EXISTS pgrst_exposed_none;

COMMENT ON SCHEMA pgrst_exposed_none IS
    'Intentionally empty. Exposed to PostgREST so its schema-cache load succeeds instead of retry-looping on the pg_pgrst_no_exposed_schemas sentinel. Never create objects here and never grant USAGE to anon/authenticated -- anything added becomes publicly readable over the Data API.';

REVOKE ALL ON SCHEMA pgrst_exposed_none FROM PUBLIC;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'anon') THEN
        EXECUTE 'REVOKE ALL ON SCHEMA pgrst_exposed_none FROM anon';
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'authenticated') THEN
        EXECUTE 'REVOKE ALL ON SCHEMA pgrst_exposed_none FROM authenticated';
    END IF;
END $$;

COMMIT;
