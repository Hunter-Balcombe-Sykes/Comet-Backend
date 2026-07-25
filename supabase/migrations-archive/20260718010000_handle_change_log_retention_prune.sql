-- PRIV-2: enforce the existing-but-unenforced retention on audit.handle_change_log
-- (config('partna.handle.audit_retention_years'), default 7y).
--
-- app_backend has no UPDATE/DELETE on the audit schema (REVOKE in
-- 20260527010000_reorganize_schemas.sql) and the append-only trigger
-- (handle_change_log_no_update) RAISEs on any UPDATE/DELETE regardless of role.
-- So a retention prune cannot run as a plain app_backend DELETE — it needs a
-- SECURITY DEFINER function, owned by the migration role (postgres), that
-- disables the append-only trigger only for its own atomic delete. DISABLE +
-- DELETE + ENABLE all run in the one implicit function transaction, so any
-- failure (including the RETURN never being reached) rolls everything back and
-- never leaves the guard trigger off.

BEGIN;
SET LOCAL lock_timeout = '2s';

CREATE OR REPLACE FUNCTION audit.prune_handle_change_log(p_cutoff timestamptz)
RETURNS integer
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = ''
AS $$
DECLARE
    v_deleted integer;
BEGIN
    ALTER TABLE audit.handle_change_log DISABLE TRIGGER handle_change_log_no_update;
    DELETE FROM audit.handle_change_log WHERE changed_at < p_cutoff;
    GET DIAGNOSTICS v_deleted = ROW_COUNT;
    ALTER TABLE audit.handle_change_log ENABLE TRIGGER handle_change_log_no_update;
    RETURN v_deleted;
END;
$$;

-- Least privilege: no PUBLIC access; only app_backend (the console command's
-- connection role) may call it. Guarded so this is a no-op on a DB where the
-- role doesn't exist yet (fresh local stack, or connected as postgres).
REVOKE ALL ON FUNCTION audit.prune_handle_change_log(timestamptz) FROM PUBLIC;
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'GRANT EXECUTE ON FUNCTION audit.prune_handle_change_log(timestamptz) TO app_backend';
    END IF;
END $$;

COMMIT;
