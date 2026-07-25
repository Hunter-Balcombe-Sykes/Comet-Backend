-- P3: staff free-text search support — trigram GIN index on
-- site.sites.subdomain.
--
-- Split into its own file per CONVENTIONS.md §1: CONCURRENTLY cannot run
-- inside a transaction, so this file has NO BEGIN/COMMIT.
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_sites_subdomain_trgm
    ON site.sites USING gin (subdomain gin_trgm_ops);
