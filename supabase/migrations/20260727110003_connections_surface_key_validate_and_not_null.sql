-- #MIG-2 steps 2 and 3 (CONVENTIONS.md §3 steps 3-4).
--
-- VALIDATE CONSTRAINT scans the table under SHARE UPDATE EXCLUSIVE, which does
-- NOT block reads or writes. Once the IS NOT NULL checks are VALID, PostgreSQL
-- (12+) skips the row scan for SET NOT NULL entirely — it becomes a catalog
-- write. The scaffolding checks are then dropped: the NOT NULL attribute has
-- taken over their job.
--
-- WHY VALIDATE AND SET NOT NULL SHARE ONE FILE rather than the two the four-step
-- pattern draws: guard Check 4's exemption for SET NOT NULL is SAME-FILE scoped
-- ("if the same file also contains VALIDATE CONSTRAINT"). Splitting them would
-- force a guard:no-unsafe-migrations:disable-file marker on this file, which is
-- the opposite of what Unit H is for. The lock cost of sharing a transaction is
-- nil: the expensive part (the validation scan) still runs under SHARE UPDATE
-- EXCLUSIVE, and the ACCESS EXCLUSIVE upgrade happens only after it, for
-- catalog writes alone. See CONVENTIONS.md for the optional guard change that
-- would let these be two files.
--
-- Idempotent: VALIDATE on an already-valid constraint is a no-op, SET NOT NULL
-- on an already-NOT NULL column is a no-op, DROP ... IF EXISTS is a no-op.

BEGIN;
SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '60s';

ALTER TABLE "site"."platform_connections"
    VALIDATE CONSTRAINT "platform_connections_routing_class_check";
ALTER TABLE "site"."platform_connections"
    VALIDATE CONSTRAINT "chk_platform_connections_surface_key_not_null";
ALTER TABLE "site"."platform_connections"
    VALIDATE CONSTRAINT "chk_platform_connections_routing_class_not_null";

ALTER TABLE "site"."platform_connections" ALTER COLUMN "surface_key"   SET NOT NULL;
ALTER TABLE "site"."platform_connections" ALTER COLUMN "routing_class" SET NOT NULL;

ALTER TABLE "site"."platform_connections"
    DROP CONSTRAINT IF EXISTS "chk_platform_connections_surface_key_not_null";
ALTER TABLE "site"."platform_connections"
    DROP CONSTRAINT IF EXISTS "chk_platform_connections_routing_class_not_null";

COMMIT;
