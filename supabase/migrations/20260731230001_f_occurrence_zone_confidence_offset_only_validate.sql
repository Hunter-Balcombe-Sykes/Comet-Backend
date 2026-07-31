-- Nightwatch #370, second window: validate the widened CHECK added NOT VALID by
-- 20260731230000. Split per CONVENTIONS.md §2 — ADD ... NOT VALID takes a brief
-- ACCESS EXCLUSIVE lock and does not read the table; VALIDATE CONSTRAINT scans
-- it under a weaker SHARE UPDATE EXCLUSIVE that does not block writes. Doing
-- both in one statement would hold ACCESS EXCLUSIVE for the whole scan.
--
-- A formality on today's data: the new predicate is a strict superset of the
-- one it replaces, so no pre-existing row can fail it. Verified on dev
-- (glncumufgaqcmqhzwrxm) 2026-07-31 — content.f_occurrence held zero rows.
--
-- ROLLBACK: NONE available for VALIDATE itself — PostgreSQL has no
--           "un-validate". The only reverse is to drop the constraint, which
--           is 20260731230000's ROLLBACK.

BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '60s';

ALTER TABLE "content"."f_occurrence"
    VALIDATE CONSTRAINT "f_occurrence_zone_confidence_check";

COMMIT;
