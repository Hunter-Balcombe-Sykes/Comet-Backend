-- Retire Pinterest (owner decision, 2026-07-28).
--
-- Partna no longer supports Pinterest: connector, catalog surface, legacy
-- scraper and registry entry are all removed in the same change. This handles
-- the DATA — any connection rows that still point at it.
--
-- Soft-deleted, not hard-deleted. Two reasons: the platform_connections table
-- has soft deletes for every other removal path, so hard-deleting here would
-- be the one place a user's row vanishes without trace; and if this decision
-- is ever reversed, the rows are recoverable rather than gone. The 30-day
-- purge sweep reclaims them on its normal schedule.
--
-- The earlier 20260727110000 backfill CASE still maps 'pinterest' →
-- 'pinterest.profile'. That file is deliberately left alone: it records a
-- one-time operation that really ran, and editing it to hide a later decision
-- would make it lie about history. App\Catalog\LegacyPlatformMap::RETIRED
-- carries the retirement so the lockstep test can tell a genuine drift apart
-- from a deliberate removal.
--
-- SCALE-21 (2026-07-29): bounded. Both UPDATEs were already idempotent and
-- narrowly scoped ("deleted_at IS NULL" / "state IN ('proposed','blocked')"),
-- so batching buys nothing here — one retired connector, bounded row count.
-- What was missing was a lock bound: without it a conflicting lock makes this
-- statement wait indefinitely and stall the whole sequential db push. 2s is the
-- repo's standard (CONVENTIONS.md §8); 30s of statement_timeout is generous for
-- a predicate-scoped soft delete and fails visibly rather than silently.

BEGIN;
SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '30s';

UPDATE "site"."platform_connections"
   SET "deleted_at" = now(),
       "is_active" = false,
       "updated_at" = now()
 WHERE "surface_key" = 'pinterest.profile'
   AND "deleted_at" IS NULL;

-- Routing intents referencing the surface are settled the same way: they can
-- never be applied now, and leaving them 'proposed' would keep offering the
-- user a platform that no longer exists.
UPDATE "routing"."source_intents"
   SET "state" = 'superseded',
       "block_reason" = 'unservable',
       "resolved_at" = now(),
       "updated_at" = now()
 WHERE "surface_key" = 'pinterest.profile'
   AND "state" IN ('proposed', 'blocked');

COMMIT;

-- Catalog projection rows tombstone on the next `catalog:sync` (the artefact
-- no longer contains the brand/surface/detectors), so nothing to do here.
