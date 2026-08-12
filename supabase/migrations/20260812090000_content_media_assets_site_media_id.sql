-- Slice 1a (media pool): content.media_assets becomes able to point at an
-- owned upload. The asset row is a POINTER into the site.media_variants
-- pipeline, not a snapshot of it — pinning a rendition path here would
-- hard-code one variant and dangle after a reprocess (spec §3.2).
--
-- ON DELETE SET NULL, not RESTRICT: SiteMedia::forceDelete() runs in user
-- deletion flows and must not be blocked by a content row. The resulting
-- all-null shape is deliberately detectable and MediaUrlResolver omits it.
--
-- The partial index on site_media_id IS NOT NULL covers the live pointers;
-- the 485 existing connector-minted rows stay NULL (no-op migration).
--
-- ROLLBACK: ALTER TABLE "content"."media_assets" DROP COLUMN IF EXISTS "site_media_id";

ALTER TABLE "content"."media_assets"
    ADD COLUMN "site_media_id" uuid REFERENCES "site"."site_media" ("id") ON DELETE SET NULL;

CREATE INDEX "idx_media_assets_site_media"
    ON "content"."media_assets" ("site_media_id")
    WHERE "site_media_id" IS NOT NULL;
