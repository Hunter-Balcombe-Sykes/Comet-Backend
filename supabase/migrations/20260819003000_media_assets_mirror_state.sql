-- 20260819003000_media_assets_mirror_state.sql
--
-- R8 (Instagram build wave, 2026-08-18): 32 owned assets finished the wave
-- with no bytes in R2 and exactly ONE warning line between them. The cause was
-- not a swallowed exception — MediaMirror logs every reason it has. It was that
-- `storage_path IS NULL` collapsed four different states into one value:
--
--   never dispatched (skipped by a filter) · queued · running · permanently dead
--
-- and nothing on the row could tell them apart. These three columns make the
-- row the record, so the tail is readable from the DB instead of inferred from
-- the absence of a log line (which is also what LOG_LEVEL can silence).
--
--   mirror_attempts        consecutive FAILURES; reset to 0 by any success.
--                          Also the retry terminator — dispatchMirrors stops
--                          re-queuing at partna.media_mirror_max_attempts, so a
--                          dead CDN link is no longer re-fetched every sync
--                          forever.
--   mirror_last_attempt_at when we last tried at all, success or failure.
--   mirror_last_reason     the fail() slug (fetch_failed, undecodable,
--                          store_failed, body_rejected, video_too_large).
--                          Deliberately NO CHECK constraint: the reason
--                          vocabulary is owned by MediaMirror and adding a slug
--                          must not require a migration.
--
-- Reading the tail — ⚠️ THE QUERY BELOW IS WRONG, see 20260819004000.
-- Measured on dev the same day: it returns 2589 rows of which ZERO are mirror
-- candidates. It cannot tell a borrowed Apple Music / Shopify / Google asset
-- (unmirrored forever, and correctly so) from an owned one that lost its bytes,
-- because owned-ness was not recorded anywhere on the row. `mirror_eligible`
-- fixes that; use the corrected query in 20260819004000's header instead.
--
--   SELECT mirror_attempts, mirror_last_reason, count(*)
--     FROM content.media_assets
--    WHERE storage_path IS NULL AND site_media_id IS NULL AND source_url IS NOT NULL
--    GROUP BY 1, 2;
--   attempts = 0 → pending · > 0 → tried and failed · >= cap → given up (logged)
--
-- No index: the dispatch path selects by primary key (whereIn on the chunk's
-- ids), and the ops query above is an occasional full scan of a small table.
--
-- ADD COLUMN with a non-volatile DEFAULT is metadata-only from PG 11 — no heap
-- rewrite, no ACCESS EXCLUSIVE held over a scan — so the NOT NULL here does not
-- need CONVENTIONS.md §3's four-step pattern (that governs ALTER COLUMN SET NOT
-- NULL on an existing column, which does scan).
--
-- Rollback:
--   ALTER TABLE content.media_assets
--       DROP COLUMN mirror_attempts,
--       DROP COLUMN mirror_last_attempt_at,
--       DROP COLUMN mirror_last_reason;

BEGIN;

ALTER TABLE "content"."media_assets"
    ADD COLUMN "mirror_attempts" integer NOT NULL DEFAULT 0,
    ADD COLUMN "mirror_last_attempt_at" timestamp with time zone,
    ADD COLUMN "mirror_last_reason" text;

COMMIT;
