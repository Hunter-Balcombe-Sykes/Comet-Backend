-- Follow-up to 20260903220001 (and, for block_reason, 20260903210000 before
-- it) — validates the two CHECK constraints that were added NOT VALID so the
-- guard:no-unsafe-migrations lint would pass (CONVENTIONS.md §2).
--
-- Timestamped after 20260903220001 on purpose: that file DROPs and re-ADDs
-- source_intents_block_reason_check (widening 20260903210000's version with
-- 'not_found'), so by the time this file runs the constraint object under
-- that name is 20260903220001's, not 20260903210000's — one VALIDATE here
-- covers the live definition, nothing from the earlier file needs its own.
--
-- Two separate transactions, not one per CONVENTIONS.md §2 / guard Check 8 —
-- bundling a VALIDATE with anything else risks re-triggering the same "same
-- transaction as its ADD" shape the guard flags; keeping every VALIDATE in
-- its own BEGIN/COMMIT sidesteps that regardless of what else this file ever
-- grows to contain.
--
-- ROLLBACK: NONE — PostgreSQL has no "un-validate". The real reverse is each
--           constraint's own DROP CONSTRAINT, stated in 20260903220001's and
--           20260903210000's ROLLBACK headers.

BEGIN;

ALTER TABLE routing.source_intents
    VALIDATE CONSTRAINT source_intents_state_check;

COMMIT;

BEGIN;

ALTER TABLE routing.source_intents
    VALIDATE CONSTRAINT source_intents_block_reason_check;

COMMIT;
