-- P3: staff free-text search support — trigram GIN index on
-- core.users.last_name. Column is nullable; partial index excludes NULL rows
-- to keep it compact.
--
-- Split into its own file per CONVENTIONS.md §1: CONCURRENTLY cannot run
-- inside a transaction, so this file has NO BEGIN/COMMIT.
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_users_last_name_trgm
    ON core.users USING gin (last_name gin_trgm_ops) WHERE last_name IS NOT NULL;
