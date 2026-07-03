-- Plan 5 (platform-refresh conditional requests). Store the HTTP validators
-- (ETag / Last-Modified) returned by a connection's last successful refresh, so
-- the next poll can send If-None-Match / If-Modified-Since and short-circuit on a
-- 304 Not Modified — no payload re-download, no payload write, no cache purge.
--
-- Both NULLABLE with NO default and NO CHECK: opaque strings echoed back verbatim.
-- A connection that has never made a conditional request (or whose upstream emits
-- no validators) simply stores NULL and refreshes exactly as today (graceful
-- degradation). ADD COLUMN of a nullable, default-less column is metadata-only
-- (no table rewrite) — safe, non-locking (CONVENTIONS §2).
BEGIN;

SET LOCAL lock_timeout = '3s';
SET LOCAL statement_timeout = '30s';

ALTER TABLE site.platform_connections ADD COLUMN IF NOT EXISTS refresh_etag text;
ALTER TABLE site.platform_connections ADD COLUMN IF NOT EXISTS refresh_last_modified text;

COMMIT;

-- ROLLBACK:
-- BEGIN;
-- ALTER TABLE site.platform_connections DROP COLUMN IF EXISTS refresh_last_modified;
-- ALTER TABLE site.platform_connections DROP COLUMN IF EXISTS refresh_etag;
-- COMMIT;
