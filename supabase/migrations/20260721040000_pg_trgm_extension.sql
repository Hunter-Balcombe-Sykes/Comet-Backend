-- Enable pg_trgm — required by the trigram GIN indexes added in the
-- following migrations (staff free-text search over core.users / site.sites
-- name/handle/email/sector fields). Kept in its own file, ahead of the
-- CONCURRENTLY index files, so the extension exists before any index build
-- references gin_trgm_ops.
CREATE EXTENSION IF NOT EXISTS pg_trgm;
