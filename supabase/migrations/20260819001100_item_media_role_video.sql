-- 20260819001100_item_media_role_video.sql
--
-- Overnight 2026-08-18, owner ruling R7 (reels play on the sitepage). A
-- media item may now carry a `video` frame beside its cover/gallery stills:
-- InstagramMediaProjector emits the reel's mp4 as role=video (mirrored to R2
-- by MediaMirror), PoolResolver::frames() emits it with kind=video + the
-- cover as poster. Widen the role check to admit it.
--
-- Rollback: DELETE FROM content.item_media WHERE role='video'; then re-add
-- the constraint without 'video'.

BEGIN;

ALTER TABLE content.item_media
    DROP CONSTRAINT IF EXISTS item_media_role_check;

ALTER TABLE content.item_media
    ADD CONSTRAINT item_media_role_check
    CHECK (role IN ('cover', 'gallery', 'poster', 'avatar', 'logo', 'video'));

COMMIT;
