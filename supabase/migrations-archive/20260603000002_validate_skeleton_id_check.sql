-- =====================================================================
-- VALIDATE the skeleton_id CHECK + establish the two-step constraint pattern.
--
-- Context: 20260527070000_skeleton_system_cleanup.sql adds skeleton_id with an
-- INLINE, already-valid CHECK. An inline CHECK validates immediately under
-- ACCESS EXCLUSIVE, so on a fresh deploy that original migration still takes
-- the strong lock for its (currently tiny) row scan — this follow-up does NOT
-- retroactively change that. The proper fix for the production lock is the
-- two-step pattern (ADD CONSTRAINT ... NOT VALID, then VALIDATE CONSTRAINT
-- under SHARE UPDATE EXCLUSIVE), which all FUTURE constraints on populated
-- tables must use. We accept the existing inline lock as brief pre-pilot
-- rather than rewriting the destructive cleanup migration.
--
-- What this migration actually does: (1) documents/anchors the pattern, and
-- (2) acts as a real validation step in any environment where the constraint
-- was instead added as NOT VALID. It is a safe no-op when the constraint is
-- already validated, and is safe to re-run.
--
-- Constraint name: sites_skeleton_id_check
--   PostgreSQL auto-names an inline column CHECK as <table>_<column>_check,
--   so an inline CHECK on site.sites.skeleton_id becomes
--   sites_skeleton_id_check. Confirmed by the constraint name asserted in
--   tests/Feature/Database/SkeletonSystemConstraintsTest.php.
-- =====================================================================

BEGIN;

DO $$
BEGIN
    -- Guard: only run VALIDATE if the constraint actually exists.
    -- Calling VALIDATE CONSTRAINT on a non-existent name raises an error,
    -- so this guard makes the migration idempotent across envs where the
    -- constraint may have been added differently (e.g. a fresh DB reset).
    IF EXISTS (
        SELECT 1
        FROM pg_constraint c
        JOIN pg_class t ON c.conrelid = t.oid
        JOIN pg_namespace n ON t.relnamespace = n.oid
        WHERE n.nspname = 'site'
          AND t.relname = 'sites'
          AND c.conname = 'sites_skeleton_id_check'
          AND c.contype = 'c'
    ) THEN
        ALTER TABLE site.sites VALIDATE CONSTRAINT sites_skeleton_id_check;
    END IF;
END $$;

COMMIT;
