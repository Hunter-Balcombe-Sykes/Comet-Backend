-- Step B of 20260730090000 (CONVENTIONS.md §2, guard Check 8): VALIDATE in its own
-- transaction, so the scan runs under SHARE UPDATE EXCLUSIVE and never holds the
-- ADD's heavier catalog-write lock.
--
-- TWO FILES rather than two BEGIN/COMMIT blocks in one. Nothing forces the same-file
-- form here — that was 20260729150018's Check 4 exemption, which is same-file scoped
-- and applies only to ALTER COLUMN ... SET NOT NULL, absent from this pair. And a
-- one-transaction-per-file shape applies cleanly via db push, psql -f AND MCP
-- apply_migration, which wraps a body in a single transaction; the one-file form
-- needs the manual two-call applier caveat 20260729150018 had to document.
--
-- Cannot fail: both CHECKs are WIDENINGS of an existing allowed set ('ig-photo'
-- added), so every existing row already satisfies them.
--
-- No-op on dev, where 20260730090000's original plain-ADD form left both constraints
-- already validated. VALIDATE CONSTRAINT on an already-valid constraint is a no-op —
-- the same property 20260727110003 states and relies on. Ordering-dependent: this
-- errors 42704 if 20260730090000 has not run, which filename order guarantees.
--
-- ROLLBACK: NONE available for VALIDATE itself — PostgreSQL has no "un-validate".
--           The only reverse is to drop the constraints, which is 20260730090000's
--           ROLLBACK.

BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '60s';

ALTER TABLE "site"."content_selection"
    VALIDATE CONSTRAINT "content_selection_entry_type_check";

ALTER TABLE "site"."content_selection"
    VALIDATE CONSTRAINT "content_selection_ref_shape";

COMMIT;
