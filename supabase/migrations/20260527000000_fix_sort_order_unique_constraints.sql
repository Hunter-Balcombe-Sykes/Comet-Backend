-- Make sort_order uniqueness per-pool instead of site-wide.
-- Before: gallery image at sort_order=0 blocked document insert at sort_order=0.
-- After:  each pool tracks its own sort_order sequence independently.
--
-- CONCURRENTLY is required by CONVENTIONS.md §1 — without it, an ACCESS
-- EXCLUSIVE lock blocks writes while the index builds. site.site_media
-- already has rows on dev (where this ran clean when small) but will be
-- larger by prod-promotion time. CONCURRENTLY also forbids running inside
-- a transaction block, which is why this file has no BEGIN/COMMIT.

DROP INDEX IF EXISTS site.site_images_site_sort_active_unique;
DROP INDEX IF EXISTS site.site_images_site_sort_order_active_uq;

CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS site_images_site_sort_active_unique
    ON site.site_media (site_id, pool, sort_order)
    WHERE (deleted_at IS NULL);

CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS site_images_site_sort_order_active_uq
    ON site.site_media (site_id, pool, sort_order)
    WHERE (deleted_at IS NULL AND is_active = true);
