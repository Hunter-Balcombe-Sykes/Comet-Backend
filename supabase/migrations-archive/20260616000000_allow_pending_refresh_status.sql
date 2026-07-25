-- The async Instagram connect writes a 'pending' placeholder row before the
-- scrape job runs, but site.platform_connections.last_refresh_status only
-- allowed ('ok','unavailable','error') — so the placeholder insert 23514'd
-- (check violation) before the job could be dispatched. Widen the check to
-- include 'pending'. (SQLite-backed tests don't enforce the check constraint,
-- which is why this slipped past CI and only surfaced on dev Postgres.)
--
-- guard:no-unsafe-migrations:disable-file
-- Exempt: site.platform_connections holds a handful of pre-beta rows, so the
-- CHECK rebuild takes a harmless lock (same justification as the platform-enum
-- CHECK migrations).
ALTER TABLE site.platform_connections
  DROP CONSTRAINT IF EXISTS platform_connections_last_refresh_status_check;

ALTER TABLE site.platform_connections
  ADD CONSTRAINT platform_connections_last_refresh_status_check
  CHECK (last_refresh_status IN ('ok', 'unavailable', 'error', 'pending'));
