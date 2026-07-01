-- FOUND-18: promote the Google Business async enrichment state out of the
-- site.platform_connections.payload JSONB.
--   • apify_status — fully promoted (stripped from payload in file 2, re-injected
--     into the dashboard resource in app code). CHECK adds 'pending' and omits
--     'error' vs last_refresh_status — intentional (separate state machine).
--   • place_id — an INDEXED MIRROR. placeId STAYS in payload (first-class
--     selection identifier); the column exists only to index the enrich-job
--     reconnect guard.
-- ADD COLUMN is metadata-only; the CHECK uses NOT VALID -> VALIDATE (CONVENTIONS
-- §2). All existing rows have NULL in the new column, so VALIDATE is instant.
BEGIN;

SET LOCAL lock_timeout = '3s';
SET LOCAL statement_timeout = '30s';

ALTER TABLE site.platform_connections ADD COLUMN IF NOT EXISTS apify_status text;
ALTER TABLE site.platform_connections ADD COLUMN IF NOT EXISTS place_id text;

ALTER TABLE site.platform_connections
    ADD CONSTRAINT platform_connections_apify_status_check
    CHECK (apify_status IS NULL OR apify_status IN ('pending', 'ok', 'unavailable')) NOT VALID;
ALTER TABLE site.platform_connections VALIDATE CONSTRAINT platform_connections_apify_status_check;

COMMIT;

-- ROLLBACK:
-- BEGIN;
-- ALTER TABLE site.platform_connections DROP CONSTRAINT IF EXISTS platform_connections_apify_status_check;
-- ALTER TABLE site.platform_connections DROP COLUMN IF EXISTS apify_status;
-- ALTER TABLE site.platform_connections DROP COLUMN IF EXISTS place_id;
-- COMMIT;
-- (Run the file-2 re-inject below BEFORE dropping apify_status if any rows exist.)
