-- =====================================================================
-- FOUND-15 (expand) — partial index on live_check_enabled column
-- =====================================================================
-- Companion to 20260701170000_promote_block_settings_columns.sql.
-- Creates a partial index on the new live_check_enabled column, replacing
-- the role of the expression index idx_blocks_live_check_enabled.
--
-- CONCURRENTLY: must run OUTSIDE any transaction block (see CONVENTIONS.md §1).
-- The old expression index is left in place until the contract migration
-- (20260701000100) drops it — keeping both is harmless and avoids a name clash.
-- =====================================================================

-- No BEGIN/COMMIT — CONCURRENTLY cannot run inside a transaction.
CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_blocks_live_check_enabled_active
    ON site.blocks (site_id)
    WHERE live_check_enabled AND block_group = 'links'
          AND deleted_at IS NULL AND is_active = true;

-- ROLLBACK:
-- DROP INDEX CONCURRENTLY IF EXISTS site.idx_blocks_live_check_enabled_active;
