-- P3: staff free-text search support — trigram GIN index on
-- core.users.primary_email.
--
-- Split into its own file per CONVENTIONS.md §1: CONCURRENTLY cannot run
-- inside a transaction, so this file has NO BEGIN/COMMIT.
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_users_primary_email_trgm
    ON core.users USING gin (primary_email gin_trgm_ops);
