-- Item 5 (2026-09-01, one media pool): retire the POOL_GALLERY side lane.
--
-- The public wire stopped serving site_media pool='gallery' on 2026-08-14
-- (slice 7 unit E: apps/pages reads ONLY pools.media), but GalleryAutoGrabber
-- kept writing website-grabbed photos into it — rows that rendered nowhere
-- and were invisible to owner curation on /media. The grabber now writes
-- pool='content' (the same byte lane as owner uploads) and bridges each grab
-- into an unpinned media-pool item; this file moves the stranded legacy rows
-- into that lane.
--
--   * Live (deleted_at IS NULL) gallery rows flip to pool='content'. The
--     partial unique indexes site_images_site_sort_active_unique /
--     site_images_site_sort_order_active_uq key on (site_id, pool,
--     sort_order), so flipped rows are renumbered past the site's current
--     content-pool max — owner uploads keep their positions, grabs queue
--     behind them.
--   * Soft-deleted gallery rows flip too (no renumber needed — both unique
--     indexes exclude them) so no 'gallery' row survives at all.
--
-- The content ITEMS for the moved rows cannot be minted in SQL (the manual
-- lane owns identity keys/resolve) — after applying, run:
--   php artisan content:backfill-website-grab-media
-- (idempotent, order-independent: it mints a 'website:' provenance item for
-- every gallery-lane row that has no bridge anchor yet — see the command's
-- docblock for why anchor-absence is the discriminator).
--
-- site_media_pool_check deliberately KEEPS 'gallery': the app-side door
-- closed in the same commit (config partna.upload_pools dropped 'gallery',
-- so POST /uploads now 422s it at validation), but legacy read surfaces
-- (GALLERY_POOLS filters) still name the value — drop the CHECK value with
-- that teardown, not here.
--
-- ROLLBACK: NONE. The UPDATEs record no pre-image: flipped rows blend into
--           genuine content-pool rows (grabbed ones stay identifiable via
--           original_filename LIKE 'auto-gallery.%', hand-uploaded gallery
--           rows do not) and original sort_order values are lost. Only
--           recovery is the partna-db-backup R2 dump if fresher than the
--           apply.

SET lock_timeout      = '2s';
SET statement_timeout = '30s';

WITH content_max AS (
    SELECT site_id, MAX(sort_order) AS max_sort
    FROM site.site_media
    WHERE pool = 'content' AND deleted_at IS NULL
    GROUP BY site_id
),
ranked AS (
    SELECT m.id,
           COALESCE(cm.max_sort, -1)
             + ROW_NUMBER() OVER (
                 PARTITION BY m.site_id
                 ORDER BY m.sort_order, m.created_at, m.id
               ) AS new_sort
    FROM site.site_media m
    LEFT JOIN content_max cm ON cm.site_id = m.site_id
    WHERE m.pool = 'gallery' AND m.deleted_at IS NULL
)
UPDATE site.site_media m
SET pool = 'content', sort_order = r.new_sort, updated_at = now()
FROM ranked r
WHERE m.id = r.id;

UPDATE site.site_media
SET pool = 'content'
WHERE pool = 'gallery' AND deleted_at IS NOT NULL;
