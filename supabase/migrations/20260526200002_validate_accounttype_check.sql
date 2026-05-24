-- B11/P2-13: Validate account_type CHECK constraint.
-- Separate transaction to avoid ACCESS EXCLUSIVE lock during constraint scan.
-- See supabase/migrations/CONVENTIONS.md §2 for rationale.

BEGIN;

ALTER TABLE core.users VALIDATE CONSTRAINT users_account_type_individual;

COMMIT;
