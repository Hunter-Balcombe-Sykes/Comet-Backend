-- 20260819010000_action_needed_refresh_status.sql
--
-- Add 'action_needed' to site.platform_connections.last_refresh_status.
--
-- The routing lane creates a connection 'pending' and hands it to the ingest
-- lane; when the run finishes cleanly but the connector cannot publish until
-- the owner chooses something (a Fresha team member / storewide menu →
-- Note 'no_selection'), the row used to stay 'pending' forever ("Syncing").
-- 'pending' already meant two things — a job owns the row mid-flight, and
-- (custom link cards) a resting state — and a third overload would have made
-- the word meaningless. 'action_needed' is a RESTING state written by
-- App\Ingest\Runtime\IngestStatusWriteback: the dashboard shows "Action
-- needed", scopeDueForRefresh() skips it (nothing to refresh until the owner
-- acts), and the next clean run clears it.
--
-- Rollback:
--   ALTER TABLE site.platform_connections
--     DROP CONSTRAINT IF EXISTS platform_connections_last_refresh_status_check;
--   ALTER TABLE site.platform_connections
--     ADD CONSTRAINT platform_connections_last_refresh_status_check
--     CHECK (last_refresh_status IN ('ok', 'unavailable', 'error', 'pending'));
--   (after UPDATE ... SET last_refresh_status = 'pending' WHERE = 'action_needed')

BEGIN;

SET LOCAL lock_timeout = '3s';
SET LOCAL statement_timeout = '30s';

ALTER TABLE site.platform_connections
    DROP CONSTRAINT IF EXISTS platform_connections_last_refresh_status_check;

-- NOT VALID: no scan of existing rows under the ACCESS EXCLUSIVE catalog
-- write; the widened set is a superset of the old one, so every existing row
-- already satisfies it. VALIDATE below takes only SHARE UPDATE EXCLUSIVE.
ALTER TABLE site.platform_connections
    ADD CONSTRAINT platform_connections_last_refresh_status_check
    CHECK (last_refresh_status IN ('ok', 'unavailable', 'error', 'pending', 'action_needed')) NOT VALID;

COMMIT;

BEGIN;
ALTER TABLE site.platform_connections VALIDATE CONSTRAINT platform_connections_last_refresh_status_check;
COMMIT;
