-- #DINT-4, second window: validate the CHECK added NOT VALID by
-- 20260731220000. Split per CONVENTIONS.md §2 — ADD ... NOT VALID takes a
-- brief ACCESS EXCLUSIVE lock and does not read the table; VALIDATE CONSTRAINT
-- scans it under a weaker SHARE UPDATE EXCLUSIVE that does not block writes.
-- Doing both in one statement would hold ACCESS EXCLUSIVE for the whole scan.
--
-- ROLLBACK: NONE available for VALIDATE itself — PostgreSQL has no
--           "un-validate". The only reverse is to drop the constraint, which
--           is 20260731220000's ROLLBACK.

BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '60s';

ALTER TABLE "site"."menus"
    VALIDATE CONSTRAINT "menus_dining_modes_is_array";

COMMIT;
