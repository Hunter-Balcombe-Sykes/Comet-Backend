-- DINT-8, CONVENTIONS.md §3 four-step, step 1. site.platform_connections.
-- created_at/updated_at have carried DEFAULT now() with no NOT NULL since the
-- baseline (20260726000000_baseline_pilot.sql:1853-1854), unlike every sibling
-- (site.service_categories in the same file is DEFAULT now() NOT NULL). A
-- DEFAULT does not fire on an explicit NULL, so the columns are genuinely
-- nullable -- which is why IntegrationConnection.php:43-44 has to warn every
-- consumer and scopeStrandedPending (IntegrationConnection.php:318-325) has to
-- carry whereNotNull('updated_at').
--
-- lock_timeout is set even though this table is NOT in the guard's HOT_TABLES:
-- it is a real, populated table (216 rows on dev), the alter class is ACCESS
-- EXCLUSIVE, and per Unit H #MIG-2 the habit is what carries forward.
--
-- ROLLBACK: ALTER TABLE site.platform_connections
--             DROP CONSTRAINT IF EXISTS chk_platform_connections_timestamps_not_null;

BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '30s';

ALTER TABLE "site"."platform_connections"
    ADD CONSTRAINT "chk_platform_connections_timestamps_not_null"
    CHECK ("created_at" IS NOT NULL AND "updated_at" IS NOT NULL) NOT VALID;

COMMIT;
