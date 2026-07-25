-- Make sort_order uniqueness per-pool instead of site-wide.
-- Before: gallery image at sort_order=0 blocked document insert at sort_order=0.
-- After:  each pool tracks its own sort_order sequence independently.
--
-- CONCURRENTLY is required by CONVENTIONS.md §1 — without it, DROP INDEX
-- takes an ACCESS EXCLUSIVE lock (blocks reads AND writes) and CREATE INDEX
-- takes a SHARE lock (blocks writes). Either stalls traffic while it runs.
-- site.site_media already has rows on dev (where this ran
-- clean when small) but will be larger by prod-promotion time. CONCURRENTLY
-- also forbids running inside a transaction block, which is why this file
-- has no BEGIN/COMMIT — that applies to the DROPs below as much as the
-- CREATEs.

DROP INDEX CONCURRENTLY IF EXISTS site.site_images_site_sort_active_unique;
DROP INDEX CONCURRENTLY IF EXISTS site.site_images_site_sort_order_active_uq;

CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS site_images_site_sort_active_unique
    ON site.site_media (site_id, pool, sort_order)
    WHERE (deleted_at IS NULL);

CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS site_images_site_sort_order_active_uq
    ON site.site_media (site_id, pool, sort_order)
    WHERE (deleted_at IS NULL AND is_active = true);
