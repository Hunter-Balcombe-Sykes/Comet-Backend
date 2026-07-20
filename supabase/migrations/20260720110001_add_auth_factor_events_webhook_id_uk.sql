-- #WHK-101 step 2 of 2 — durable uniqueness backstop for the Redis-only
-- webhook idempotency anchor. See 20260720110000 for the full rationale.
--
-- SEPARATE FILE, NO BEGIN/COMMIT: CREATE INDEX CONCURRENTLY cannot run
-- inside a transaction. Two-file convention per supabase/migrations/
-- CONVENTIONS.md §1; in-repo precedent for a bare CONCURRENTLY index in its
-- own file: 20260711160200_site_sessions_add_composite_unique.sql.
--
-- PARTIAL (WHERE webhook_id IS NOT NULL): only hook-originated rows carry a
-- webhook_id — direct-action rows (e.g. MfaController::destroy's 'unenroll'
-- event) are recorded with webhook_id = NULL and must never collide with
-- each other or with hook rows.
--
-- INTERRUPTED-BUILD CAVEAT: if this CONCURRENTLY build is interrupted
-- (deploy killed mid-migration, connection dropped), Postgres leaves behind
-- an INVALID index — and `IF NOT EXISTS` will then silently SKIP it on
-- re-run rather than repairing it, because the index technically exists.
-- Before re-running this file, check for and drop any invalid leftover:
--   SELECT indexrelid::regclass FROM pg_index WHERE NOT indisvalid;
--   DROP INDEX CONCURRENTLY IF EXISTS audit.auth_factor_events_webhook_id_uk;
--
-- ROLLBACK: DROP INDEX CONCURRENTLY IF EXISTS audit.auth_factor_events_webhook_id_uk;

CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS auth_factor_events_webhook_id_uk
    ON audit.auth_factor_events (webhook_id)
    WHERE webhook_id IS NOT NULL;
