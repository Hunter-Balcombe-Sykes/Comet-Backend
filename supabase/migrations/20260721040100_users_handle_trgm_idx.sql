-- P3: staff free-text search support — trigram GIN index on core.users.handle.
--
-- Split into its own file per CONVENTIONS.md §1: CONCURRENTLY cannot run
-- inside a transaction, so this file has NO BEGIN/COMMIT.
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_users_handle_trgm
    ON core.users USING gin (handle gin_trgm_ops);
