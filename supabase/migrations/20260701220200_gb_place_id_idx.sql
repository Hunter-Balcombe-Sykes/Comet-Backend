-- Indexed reconnect guard: GoogleBusinessEnrichJob::connection() looks up the
-- user's connection by (user_id, place_id). CONCURRENTLY, outside any
-- transaction (CONVENTIONS §1). Partial WHERE deleted_at IS NULL matches the
-- model's soft-delete scope.
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_platform_connections_user_place_id
    ON site.platform_connections (user_id, place_id)
    WHERE deleted_at IS NULL;

-- ROLLBACK:
-- DROP INDEX CONCURRENTLY IF EXISTS site.idx_platform_connections_user_place_id;
