-- Fix the two account hard-delete bugs — see
-- docs/superpowers/specs/2026-07-23-deletion-path-appendonly-fix-design.md.
--
-- (a) Relax core.users.auth_user_id -> auth.users from ON DELETE CASCADE to SET NULL:
--     deleting the Supabase auth user no longer destroys core.users mid-purge (so the
--     R2/PII cleanup steps run against live rows), and it removes a footgun where deleting
--     an auth user in the Supabase dashboard silently nukes the whole site + all data.
-- (b) SECURITY DEFINER helper that nulls the two append-only audit links for a user about
--     to be hard-deleted, so forceDelete's ON DELETE SET NULL cascade matches 0 rows on
--     those tables and never trips their reject-mutation triggers. Modeled exactly on
--     audit.prune_handle_change_log (20260718010000): disable + update + enable run in the
--     one implicit function transaction, so any failure rolls back and never leaves a
--     guard trigger off. SET NULL keeps the audit event, severs the user link — the
--     schema's own declared intent and GDPR-appropriate.

BEGIN;
SET LOCAL lock_timeout = '2s';

-- (a) auth_user_id FK: CASCADE -> SET NULL. Safe: auth_user_id is nullable (unclaimed /
-- pre-account users already carry NULL) and users_auth_user_id_unique is a partial index
-- (WHERE deleted_at IS NULL) with btree treating NULLs as distinct, so nulling during
-- purge cannot collide. Existing rows already satisfy the FK, so ADD validates cleanly.
ALTER TABLE core.users DROP CONSTRAINT users_auth_user_id_fkey;
ALTER TABLE core.users ADD CONSTRAINT users_auth_user_id_fkey
    FOREIGN KEY (auth_user_id) REFERENCES auth.users(id) ON DELETE SET NULL;

-- (b) Null the append-only audit links for the user being hard-deleted.
CREATE OR REPLACE FUNCTION audit.null_user_audit_links(p_user_id uuid)
RETURNS void
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = ''
AS $$
BEGIN
    ALTER TABLE audit.staff_audit_log DISABLE TRIGGER staff_audit_log_reject_mutation;
    UPDATE audit.staff_audit_log SET user_id = NULL WHERE user_id = p_user_id;
    ALTER TABLE audit.staff_audit_log ENABLE TRIGGER staff_audit_log_reject_mutation;

    ALTER TABLE audit.handle_change_log DISABLE TRIGGER handle_change_log_no_update;
    UPDATE audit.handle_change_log SET user_id = NULL WHERE user_id = p_user_id;
    ALTER TABLE audit.handle_change_log ENABLE TRIGGER handle_change_log_no_update;
END;
$$;

-- Least privilege: no PUBLIC; only app_backend (the app's connection role) may call it.
-- Guarded so this is a no-op where the role doesn't exist yet (fresh local stack /
-- connected as postgres).
REVOKE ALL ON FUNCTION audit.null_user_audit_links(uuid) FROM PUBLIC;
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'GRANT EXECUTE ON FUNCTION audit.null_user_audit_links(uuid) TO app_backend';
    END IF;
END $$;

COMMIT;
