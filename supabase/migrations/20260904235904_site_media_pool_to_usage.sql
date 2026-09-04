-- Rename site.site_media.pool → site.site_media.usage.
--
-- WHY: "pool" named two unrelated things. This column answers "what KIND of
-- file is this" (content | design | documents) and is invisible to visitors.
-- The content.* pools answer "which SECTION of the public page" (custom_links,
-- events, listen, media, menus, reviews, services, shop, watch) and are the
-- whole public wire. The words collided worst on the value 'content': a row
-- with pool='content' is an owner photo, and it surfaces publicly in
-- pools.media — same picture, two vocabularies, neither matching.
--
-- After this migration "pool" means a public page section and nothing else.
-- The upload table says what it actually stores: a usage.
--
-- Note this column already outgrew the word: it holds 'documents', which are
-- not images and were never a pool of anything.
--
-- SAFE: a column rename is a catalog-only write. Postgres rewrites dependent
-- index and view definitions in place, so the 7 indexes over this column and
-- the site.public_site_payload view keep working untouched. Two index NAMES
-- and the CHECK constraint name still said "pool"; renamed here for
-- consistency (also catalog-only).
--
-- NOT SAFE for zero-downtime: this is a breaking rename, not an expand/
-- contract. Deployed code reading "pool" gets 42703 the moment it lands.
-- Apply it in the same window as the code deploy — see the deploy note in
-- docs/api.md §Media pools. Acceptable here because dev is the only
-- environment carrying this schema (prod is 100+ migrations behind and has
-- never had a customer row).
--
-- ROLLBACK: ALTER TABLE site.site_media RENAME COLUMN "usage" TO "pool";
--             ALTER TABLE site.site_media RENAME CONSTRAINT site_media_usage_check TO site_media_pool_check;
--             ALTER INDEX site.sm_usage_active RENAME TO sm_pool_active;
--             ALTER INDEX site.sm_usage_media_active RENAME TO sm_pool_media_active;
--           (Reversible in full — catalog-only, no data movement.)

BEGIN;

ALTER TABLE "site"."site_media" RENAME COLUMN "pool" TO "usage";

ALTER TABLE "site"."site_media"
    RENAME CONSTRAINT "site_media_pool_check" TO "site_media_usage_check";

ALTER INDEX "site"."sm_pool_active"       RENAME TO "sm_usage_active";
ALTER INDEX "site"."sm_pool_media_active" RENAME TO "sm_usage_media_active";

COMMENT ON COLUMN "site"."site_media"."usage" IS
    'What the uploaded file is FOR: content (owner photos, bridged into the media pool via content.media_assets.site_media_id) | design (logo/favicon/brand assets, never published as a card) | documents (downloadable PDFs). This is NOT a content.* pool — those are public page sections and this column has no relationship to them.';

COMMIT;
