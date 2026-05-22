-- Site media: introduce a dedicated `design` pool for brand singleton assets
-- (logo, placeholder) and rescope the sort_order uniqueness to be per-pool.
--
-- Why: the legacy unique index (site_id, sort_order) was a carry-over from
-- when this table held a single gallery pool (core.site_images). After the
-- multi-pool refactor it forced gallery/content/product/brand_gallery to
-- share one global sort_order namespace per site, so a brand logo upload
-- (sort_order=0) collided with any other image at sort_order=0. Logo and
-- placeholder also have no real ordering meaning — they're singleton design
-- slots — so they get their own pool that the index excludes.

BEGIN;

-- Safety: bail if any site already has more than one active logo row in the
-- legacy content pool. Should be 0 in pre-beta, but guards against a silent
-- data loss on the singleton index that gets created below.
DO $$
DECLARE
    duplicate_count integer;
BEGIN
    SELECT COUNT(*) INTO duplicate_count
    FROM (
        SELECT site_id
        FROM site.site_media
        WHERE pool = 'content'
          AND alt_text = 'logo'
          AND deleted_at IS NULL
        GROUP BY site_id
        HAVING COUNT(*) > 1
    ) dupes;

    IF duplicate_count > 0 THEN
        RAISE EXCEPTION
            'Cannot migrate: % site(s) have duplicate active logo rows in pool=content. Dedupe before re-running.',
            duplicate_count;
    END IF;
END $$;

-- Relocate existing brand design rows from the content pool to the design pool.
UPDATE site.site_media
SET pool = 'design'
WHERE pool = 'content'
  AND alt_text IN ('logo', 'placeholder')
  AND deleted_at IS NULL;

-- Drop the overly-broad legacy index and its redundant duplicate.
DROP INDEX IF EXISTS site.site_images_site_sort_active_unique;
DROP INDEX IF EXISTS site.site_images_site_sort_order_active_uq;

-- Replacement: sort_order uniqueness scoped per pool, and only for pools that
-- actually have ordering semantics. The design pool is excluded so logo and
-- placeholder rows can coexist without sort_order contention.
CREATE UNIQUE INDEX site_media_site_pool_sort_active_uq
    ON site.site_media (site_id, pool, sort_order)
    WHERE deleted_at IS NULL
      AND is_active = true
      AND pool IN ('gallery', 'content', 'product', 'brand_gallery');

-- Singleton enforcement: at most one active logo row per site in the design
-- pool. The upload endpoint soft-deletes any prior logo before inserting, so
-- this index is a backstop against accidental concurrent inserts.
CREATE UNIQUE INDEX site_media_design_logo_uq
    ON site.site_media (site_id)
    WHERE pool = 'design'
      AND alt_text = 'logo'
      AND deleted_at IS NULL;

COMMIT;
