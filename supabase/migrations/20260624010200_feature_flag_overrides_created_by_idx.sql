-- 20260624010200_feature_flag_overrides_created_by_idx.sql
--
-- DINT-8: core.feature_flag_overrides.created_by FK exists
-- (feature_flag_overrides_created_by_fkey, ON DELETE SET NULL) but has no
-- supporting index — cascade-delete scans on core.partna_staff will seq-scan
-- the overrides table.
--
-- Split from 20260624010000 per CONVENTIONS.md §1: CONCURRENTLY cannot run
-- inside a transaction, so this file has NO BEGIN/COMMIT.
--
-- Partial on created_by IS NOT NULL because the column is nullable (SET NULL FK);
-- the NULL rows are never joined via this path.
CREATE INDEX CONCURRENTLY IF NOT EXISTS feature_flag_overrides_created_by_idx
    ON core.feature_flag_overrides (created_by) WHERE created_by IS NOT NULL;
