-- Separate transaction per CONVENTIONS.md 2 (guard Check 8). VALIDATE takes
-- only SHARE UPDATE EXCLUSIVE, so concurrent reads and writes continue while it
-- scans. Dev carried 0 violations and prod 0 rows when 20260803100000 was
-- written, so this cannot fail on data.
--
-- ROLLBACK: NONE available for VALIDATE itself -- PostgreSQL has no
--           "un-validate". The only reverse is to drop the constraint, which
--           is 20260803100000's ROLLBACK:
--             ALTER TABLE core.users DROP CONSTRAINT IF EXISTS users_handle_lc_matches_handle;
BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '60s';

ALTER TABLE "core"."users" VALIDATE CONSTRAINT "users_handle_lc_matches_handle";

COMMIT;
