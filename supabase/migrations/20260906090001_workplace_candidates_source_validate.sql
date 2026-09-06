-- Follow-up to 20260906090000 — validates the CHECK constraint that was
-- added NOT VALID so the guard:no-unsafe-migrations lint would pass
-- (CONVENTIONS.md §2). Own transaction, per §2 / guard Check 8.
--
-- ROLLBACK: NONE — PostgreSQL has no "un-validate". The real reverse is
--           20260906090000's own DROP CONSTRAINT, stated in its ROLLBACK header.

BEGIN;

ALTER TABLE site.workplace_candidates
    VALIDATE CONSTRAINT workplace_candidates_source_check;

COMMIT;
