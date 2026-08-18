-- 20260819004000_media_assets_mirror_eligible.sql
--
-- Corrects a real gap in 20260819003000 (R8), found by measuring dev rather
-- than trusting the query that migration's own comment shipped.
--
-- That comment told a reader the unmirrored tail was:
--
--   WHERE storage_path IS NULL AND site_media_id IS NULL AND source_url IS NOT NULL
--
-- On dev (2026-08-18) that returns 2589 rows, of which **zero** are mirror
-- candidates. The rest are Apple Music (998), Shopify (471), Uber Eats (365),
-- SoundCloud (134), Google Places (123), Spotify, Bandcamp, DoorDash, Vimeo and
-- YouTube artwork — BORROWED media that is correctly never mirrored, because
-- storing it would be a licence violation rather than merely wasted work.
--
-- The reason the query could not do better: owned-ness is decided by
-- MediaMirror::isOwnedEntry(), which reads the projection entry's `ref`
-- ('instagram:<shortcode>:<i>'), and the row stores only 'url-'||sha1(ref) —
-- a one-way hash. So the table could not answer the single question R8 exists
-- to make answerable: of the assets with no bytes, which SHOULD have had them?
--
--   mirror_eligible = true   the entry that minted this row carried an owned
--                            ref, so no bytes means pending, failed or given up
--   mirror_eligible = false  borrowed; unmirrored forever and CORRECT
--   mirror_eligible IS NULL  minted before this column, or by a path other than
--                            the projection writer, which is the only caller
--                            that knows the ref. Self-healing: ProjectionWriter
--                            re-derives the flag for NULL rows on the next sync.
--
-- Deliberately NULLable with no default. A default would have to guess for the
-- ~2900 existing rows, and guessing wrong in either direction is worse than
-- admitting the row predates the column — the same reasoning as
-- mirror_last_attempt_at in 20260819003000.
--
-- No backfill here (CONVENTIONS.md §5) and none needed: the heal converges on
-- the truth one sync at a time, without baking a host-sniffing guess into data.
--
-- The corrected tail query:
--   SELECT mirror_attempts, mirror_last_reason, count(*)
--     FROM content.media_assets
--    WHERE mirror_eligible IS TRUE AND storage_path IS NULL
--    GROUP BY 1, 2;
--
-- Rollback: ALTER TABLE content.media_assets DROP COLUMN mirror_eligible;

BEGIN;

ALTER TABLE "content"."media_assets"
    ADD COLUMN "mirror_eligible" boolean;

COMMIT;
