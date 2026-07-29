-- Steps 3 and 4 of CONVENTIONS.md §3, in ONE file but TWO transactions.
--
-- Why they share a file: scripts/guard-no-unsafe-migrations.php Check 4 fails
-- any file containing ALTER COLUMN ... SET NOT NULL unless the same file also
-- contains VALIDATE CONSTRAINT (that is the guard's stated exemption for step
-- 4 of the four-step pattern). Splitting them into two files would trip it and
-- need a disable-file marker for no gain.
--
-- Why two transactions and not one: VALIDATE must not hold its lock into the
-- SET NOT NULL. Check 8 only fires when VALIDATE shares a transaction with its
-- own ADD ... NOT VALID -- that ADD is in 20260729150016, a different file --
-- so this arrangement is compliant on both checks.
--
-- Step 4 is near-instant: PostgreSQL 12+ skips the row scan for SET NOT NULL
-- when a VALIDATED CHECK (col IS NOT NULL) already exists. That is the entire
-- reason for steps 1-3. The scaffold CHECK is dropped once the column
-- attribute has replaced it.
--
-- MCP NOTE: apply_migration wraps a body in a single transaction. Apply this
-- file as TWO separate apply_migration calls (the VALIDATE block, then the
-- SET NOT NULL block) recorded under this one filename, or apply it with
-- psql -f (simple protocol, no --single-transaction) as the prod cutover does.
--
-- ROLLBACK: ALTER TABLE site.platform_connections
--             ALTER COLUMN created_at DROP NOT NULL,
--             ALTER COLUMN updated_at DROP NOT NULL;

BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '60s';

ALTER TABLE "site"."platform_connections"
    VALIDATE CONSTRAINT "chk_platform_connections_timestamps_not_null";

COMMIT;

BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '30s';

ALTER TABLE "site"."platform_connections" ALTER COLUMN "created_at" SET NOT NULL;
ALTER TABLE "site"."platform_connections" ALTER COLUMN "updated_at" SET NOT NULL;

ALTER TABLE "site"."platform_connections"
    DROP CONSTRAINT "chk_platform_connections_timestamps_not_null";

COMMIT;
