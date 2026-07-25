-- Give PostgREST an existent-but-empty schema to expose.
--
-- Supabase implements "Disable Data API" as postgrest `db_schema = ''`, which
-- makes PostgREST point its search_path at the sentinel schema
-- `pg_pgrst_no_exposed_schemas`. That schema deliberately does not exist, so
-- every schema-cache load fails with SQLSTATE 3F000 and PostgREST retries on a
-- capped 32s backoff forever — one ERROR line per retry, permanently, which
-- buries real errors in the Postgres log.
--
-- Pointing `db_schema` at a real but empty schema lets the cache load succeed
-- against zero objects: the Data API stays functionally disabled (no table,
-- view or function is reachable) and the log noise stops.
--
-- Pairs with the Management API setting; the schema alone changes nothing:
--   PATCH /v1/projects/<ref>/postgrest  {"db_schema": "pgrst_exposed_none"}

BEGIN;
SET LOCAL lock_timeout = '2s';

CREATE SCHEMA IF NOT EXISTS pgrst_exposed_none;

COMMENT ON SCHEMA pgrst_exposed_none IS
    'Intentionally empty. Exposed to PostgREST so its schema-cache load succeeds instead of retry-looping on the pg_pgrst_no_exposed_schemas sentinel. Never create objects here and never grant USAGE to anon/authenticated — anything added becomes publicly readable over the Data API.';

-- Fail closed. CREATE SCHEMA grants nothing to the API roles by default; these
-- revokes make that explicit so a future default-privilege change cannot
-- silently open the schema PostgREST is pointed at.
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
