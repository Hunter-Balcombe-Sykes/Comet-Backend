-- P3: staff free-text search support — trigram GIN index on
-- core.users.first_name.
--
-- Split into its own file per CONVENTIONS.md §1: CONCURRENTLY cannot run
-- inside a transaction, so this file has NO BEGIN/COMMIT.
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_users_first_name_trgm
    ON core.users USING gin (first_name gin_trgm_ops);
