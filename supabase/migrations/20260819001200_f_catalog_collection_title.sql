-- 20260819001200_f_catalog_collection_title.sql
--
-- Listen restructure (owner, 2026-08-18): a track carries the RELEASE it
-- belongs to ("Let It Happen · from Currents"). Until now nothing linked a
-- track to its album — two unrelated rows in the listen pool. Nullable text,
-- no default, no backfill; a plain ADD COLUMN takes only a brief lock.
--
-- Rollback: ALTER TABLE content.f_catalog DROP COLUMN collection_title;
ALTER TABLE content.f_catalog ADD COLUMN IF NOT EXISTS collection_title TEXT NULL;
