-- Backend-owned session reconciliation (Active Sessions UI).
--
-- The "Active Sessions" list tracks each Supabase `session_id` in a per-user
-- Redis set (auth:user-sessions:<uid>). Because the frontend logs out with a
-- client-only Supabase signOut and never calls POST /api/sessions/logout, dead
-- session_ids are never untracked and the list over-reports "active" sessions
-- (one dev user accumulated 86, almost all the same device). This function is
-- the source-of-truth the backend reconciles against: Supabase still holds the
-- live rows in auth.sessions, but app_backend has no access to the `auth`
-- schema ("permission denied for schema auth"), and we deliberately do NOT
-- grant blanket SELECT on auth.sessions.
--
-- Instead — mirroring the audit.prune_* pattern (20260722010000) — a single
-- narrowly-scoped SECURITY DEFINER function exposes ONLY the live session ids
-- for one user. app_backend gets EXECUTE on this function and nothing else.
--
-- SECURITY DEFINER hardening (matches the repo convention): pinned empty
-- search_path with every identifier fully schema-qualified (auth.sessions), so
-- the function cannot be hijacked by a caller-controlled search_path. STABLE —
-- pure read, no writes. Owned by the migration role; that owner must be able to
-- SELECT auth.sessions for the definer to resolve rows (verified post-apply).
--
-- Least privilege: no PUBLIC access; only app_backend may call. Guarded on the
-- role's existence so this is a no-op on a fresh local stack (or when connected
-- as postgres) where app_backend doesn't exist yet.

BEGIN;
SET LOCAL lock_timeout = '2s';

CREATE OR REPLACE FUNCTION core.live_session_ids(p_user_id uuid)
RETURNS SETOF uuid
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = ''
AS $$
    SELECT id FROM auth.sessions WHERE user_id = p_user_id
$$;

REVOKE ALL ON FUNCTION core.live_session_ids(uuid) FROM PUBLIC;
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'GRANT EXECUTE ON FUNCTION core.live_session_ids(uuid) TO app_backend';
    END IF;
END $$;

COMMIT;
