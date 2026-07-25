-- Follow-up hardening for core.feedback (audit-2026-05-25-core: #SCHEMA-1, #SCHEMA-2).
--
-- 1. FORCE ROW LEVEL SECURITY: by default, table owners and superusers bypass
--    RLS. FORCE applies the policy to everyone including the owner, so a
--    direct connection as the postgres role cannot leak rows. Defence-in-depth
--    that matches the security posture we want on PII-bearing tables.
--
-- 2. Composite index on (user_id, created_at DESC): the dashboard listing
--    query `WHERE user_id = ? AND deleted_at IS NULL ORDER BY created_at DESC`
--    benefits from a covering index. The existing single-column user_id index
--    cannot satisfy the ORDER BY; Postgres would sort in memory. With volume
--    that becomes a hot-path cost. Partial-on-deleted_at IS NULL keeps the
--    index small.
--
-- Both changes are non-blocking on a new table (zero rows in prod yet) and
-- non-blocking in general per CONVENTIONS.md (FORCE is metadata-only;
-- CONCURRENTLY index avoids any write lock).

BEGIN;

ALTER TABLE core.feedback FORCE ROW LEVEL SECURITY;

COMMIT;

-- Index creation outside any transaction (CREATE INDEX CONCURRENTLY cannot run
-- inside a BEGIN/COMMIT block — see CONVENTIONS.md §1).
CREATE INDEX CONCURRENTLY IF NOT EXISTS feedback_user_created_idx
    ON core.feedback (user_id, created_at DESC)
    WHERE deleted_at IS NULL;
