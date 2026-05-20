-- ============================================================
-- Block unique indexes — exclude soft-deleted rows
-- ============================================================
--
-- The Block model gained the SoftDeletes trait. Deleted rows now
-- linger with deleted_at set instead of being hard-DELETEd. The
-- three partial unique indexes on site.blocks filtered only on
-- block_group, so a soft-deleted block kept occupying its
-- (site_id, block_group, sort_order) / (..., block_type) slot —
-- causing 23505 unique_violation on the next block create/reorder.
--
-- Recreate each index with `AND deleted_at IS NULL` so soft-deleted
-- rows free their slot. CONCURRENTLY — not transactional. See
-- CONVENTIONS.md §1.
-- ============================================================

DROP INDEX CONCURRENTLY IF EXISTS site.blocks_links_site_group_sort_uq;
CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS blocks_links_site_group_sort_uq
    ON site.blocks (site_id, block_group, sort_order)
    WHERE block_group = 'links' AND deleted_at IS NULL;

DROP INDEX CONCURRENTLY IF EXISTS site.blocks_sections_site_group_sort_uq;
CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS blocks_sections_site_group_sort_uq
    ON site.blocks (site_id, block_group, sort_order)
    WHERE block_group = 'sections' AND deleted_at IS NULL;

DROP INDEX CONCURRENTLY IF EXISTS site.blocks_sections_site_group_type_uq;
CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS blocks_sections_site_group_type_uq
    ON site.blocks (site_id, block_group, block_type)
    WHERE block_group = 'sections' AND deleted_at IS NULL;
