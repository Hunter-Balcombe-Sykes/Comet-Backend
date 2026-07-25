-- #WHK-101 step 1 of 2 — add a nullable webhook_id column to
-- audit.auth_factor_events, as a durable DB-level backstop to the Redis-only
-- idempotency anchor in SupabaseAuthHookController::mfaVerification().
--
-- THE PROBLEM: the only thing preventing a redelivered Supabase auth-hook
-- webhook-id from double-recording a factor event is
-- `Cache::add("supabase:auth-hook:{$id}", ...)` (TTL
-- config('partna.cache.ttls.webhook_idempotency'), default 86400s). That
-- anchor lives purely in Redis. If Redis restarts, fails over, or evicts the
-- key under maxmemory pressure between the original delivery and a Supabase
-- retry, Cache::add succeeds again on the retry and
-- AuthFactorEventRepository::record() — which had no webhook_id column and
-- no uniqueness constraint at all — would insert a second row for the same
-- real-world verification attempt. For a verify_failed/verify_rejected_by_hook
-- pair this inflates countRecentFailures() by one, which can flip a
-- legitimate 4th failure into a false 5th and trigger a premature MFA
-- lockout. The email hook already has this backstop
-- (core.supabase_email_events.webhook_id UNIQUE, see
-- 20260625000000_create_supabase_email_events.sql) — this migration brings
-- the auth hook's audit trail to parity.
--
-- WHY NULLABLE: MfaController::destroy() records an 'unenroll' event through
-- this same repository with no webhook behind it (it's a direct user action,
-- not a webhook delivery) — those rows legitimately carry NULL. The unique
-- index added in the companion migration
-- (20260720110001_add_auth_factor_events_webhook_id_uk.sql) is partial
-- (WHERE webhook_id IS NOT NULL) specifically so it never constrains those
-- non-hook rows.
--
-- NO NEW GRANT NEEDED: app_backend's grant on audit.auth_factor_events is
-- SELECT+INSERT only (UPDATE/DELETE explicitly revoked — baseline
-- lines ~2327-2328, reinforced in 20260527010000). That grant is TABLE-level
-- and already covers this new column; adding a column never needs a fresh
-- GRANT. The repository's dedup path (see AuthFactorEventRepository::record())
-- uses insertOrIgnore(), which compiles to `ON CONFLICT (...) DO NOTHING` —
-- that requires only INSERT privilege (only a `DO UPDATE` arbiter clause
-- would need UPDATE), so the append-only contract on this table is preserved.
--
-- ARCHITECTURE: the Redis Cache::add anchor stays exactly as-is and remains
-- the fast path for the common case (dedup within the TTL window, no DB
-- round-trip beyond the insert itself). This column + the partial unique
-- index in the companion migration is the second line of defence for the
-- rare case where Redis has already forgotten the key.
--
-- ROLLBACK: ALTER TABLE audit.auth_factor_events DROP COLUMN webhook_id;
-- (drop the companion index first if still present — it depends on the column).

BEGIN;

SET LOCAL lock_timeout = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE audit.auth_factor_events
    ADD COLUMN IF NOT EXISTS webhook_id text NULL;

COMMENT ON COLUMN audit.auth_factor_events.webhook_id IS
    'Supabase Standard Webhooks message id (webhook-id header) for hook-originated '
    'rows (verify_success/verify_failed/verify_rejected_by_hook). NULL for rows '
    'recorded by a direct user action (e.g. MfaController::destroy unenroll) — '
    'there is no webhook delivery behind those. Durable backstop to the Redis '
    'Cache::add idempotency anchor in SupabaseAuthHookController; see the partial '
    'unique index auth_factor_events_webhook_id_uk in the companion migration.';

COMMIT;
