-- 20260624010100_section_views_block_id_idx.sql
--
-- DINT-7: analytics.section_views.block_id FK exists (section_views_block_fk,
-- ON DELETE SET NULL) but has no supporting index — cascade-delete scans on
-- site.blocks will seq-scan section_views, which will grow large.
--
-- Split from 20260624010000 per CONVENTIONS.md §1: CONCURRENTLY cannot run
-- inside a transaction, so this file has NO BEGIN/COMMIT.
--
-- Partial on block_id IS NOT NULL because the column is nullable (SET NULL FK);
-- the NULL rows are never joined and excluding them keeps the index compact.
CREATE INDEX CONCURRENTLY IF NOT EXISTS section_views_block_id_idx
    ON analytics.section_views (block_id) WHERE block_id IS NOT NULL;
