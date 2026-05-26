-- File: 202605190000001_fix_feature_flag_overrides_created_by_fk.sql
-- Repoint feature_flag_overrides.created_by FK from core.professionals to core.partna_staff.
--
-- The original migration (202605180000000_create_feature_flags.sql) declared:
--     created_by uuid NULL REFERENCES core.professionals(id) ON DELETE SET NULL
-- but StaffFeatureFlagOverrideController::store passes $staff->id, which is a
-- core.partna_staff UUID. Every staff-created override would raise an FK
-- violation in production. The SQLite test suite masks the bug because SQLite
-- does not enforce FKs by default.
--
-- The table has zero rows today, so the constraint swap is data-safe; we still
-- follow CONVENTIONS.md §4 (NOT VALID → VALIDATE in separate transactions) so
-- guard:no-unsafe-migrations doesn't flag the file and the pattern is durable
-- if the table ever holds data before this is shipped.

-- Step 1: drop the wrong constraint.
BEGIN;
ALTER TABLE core.feature_flag_overrides
    DROP CONSTRAINT feature_flag_overrides_created_by_fkey;
COMMIT;

-- Step 2: re-add pointing at core.partna_staff, NOT VALID (no row scan).
BEGIN;
ALTER TABLE core.feature_flag_overrides
    ADD CONSTRAINT feature_flag_overrides_created_by_fkey
    FOREIGN KEY (created_by) REFERENCES core.partna_staff(id) ON DELETE SET NULL
    NOT VALID;
COMMIT;

-- Step 3: validate the constraint. Acquires SHARE UPDATE EXCLUSIVE only, so
-- concurrent reads/writes continue. Empty table today => near-instant.
BEGIN;
ALTER TABLE core.feature_flag_overrides
    VALIDATE CONSTRAINT feature_flag_overrides_created_by_fkey;
COMMIT;
