-- Backfill routing.item_tombstones from historical user deletions (P8 blocker B5).
--
-- THE HAZARD THIS CLOSES. Under the legacy pipeline a user's refusal WAS the
-- soft-deleted connection row: LinkRouter's seeders each checked "is there a
-- soft-deleted row for this platform with no live sibling?" and stood down
-- (e.g. ShopBrandSeeder.php:51-59). The new pipeline records refusal in
-- routing.item_tombstones instead — SourceReconciler::applyIntent only ever
-- looks for a LIVE row (whereNull('deleted_at')), so a soft-deleted
-- connection is invisible to it and a re-scan simply creates a new one.
--
-- Migrating any scan path to the new router before this backfill therefore
-- resurrects connections users deliberately deleted, silently, with no test
-- catching it. That is why the readiness doc orders this FIRST and not after.
--
-- The reader this must satisfy: App\Routing\PlacementPolicy::isTombstoned()
-- (PlacementPolicy.php:123-134). It matches on user_id + source_ref only, in
-- either of two shapes — a bare `surface_key` (refuse the whole surface) or
-- `surface_key:identifier` (refuse this exact item). `identifier` is the value
-- SourceReconciler writes to platform_connections.resource_id
-- (SourceReconciler.php:42, :198), so `surface_key || ':' || resource_id` is
-- the correct join and the narrower of the two shapes — the same shape the
-- only live writer emits (SuggestionsController.php:160).
--
-- SCOPE OF WHAT SURVIVES TO BE BACKFILLED. Soft-deleted connections are
-- hard-deleted 30 days after deleted_at by `PurgeSoftDeleted`
-- (config partna.soft_delete_retention_days). This backfill can therefore only
-- see a rolling ~30-day window; refusals older than that were already gone
-- before this file was written and are unrecoverable. Nothing here can change
-- that — it is recorded so the number is not later mistaken for a bug.
--
-- WHAT IS DELIBERATELY EXCLUDED, and why each is a system delete rather than a
-- user's refusal (the table has no actor column, no reason column and no
-- deleted_origin discriminator, so every exclusion has to be argued):
--
--   1. Rows with a LIVE sibling for the same (user, surface, resource).
--      A user who deleted and then re-added the same account did not refuse
--      it. Tombstoning would leave their live connection permanently
--      un-reconcilable.
--   2. surface_key = 'pinterest.profile'. Every one of these was soft-deleted
--      by 20260728100000 — an owner decision to retire the brand, not a user
--      choice. Inserting them would record a refusal that never happened. (It
--      would also be inert: the surface is gone from the catalog, so
--      PlacementPolicy returns Note/'unservable' at :39-41, before the
--      tombstone check at :55 is ever reached.)
--   3. Connections belonging to users with status='unclaimed'. A provisional
--      pre-account build has no authenticated session, so nobody could have
--      deleted anything — every soft-delete on those rows came from
--      PruneExpiredPreAccountBuilds' teardown.
--
-- Everything else is included on purpose, including the partna.* pseudo-bucket
-- surfaces and surfaces whose identifiers the new router may never re-derive.
-- The asymmetry decides it: a ref that never matches costs one row and blocks
-- nothing, while a refusal wrongly left out silently resurrects. Err toward
-- including.
--
-- KNOWN CONSEQUENCE, not a side effect: PlacementPolicy::isTombstoned() does
-- not consider RoutingContext::isDirectRequest(), so a backfilled tombstone
-- also blocks the user pasting that link back by hand — not just a re-import.
-- That is the reader's existing semantics (the same is already true of every
-- tombstone SuggestionsController writes), applied to a wider set of rows. If
-- re-add should beat a tombstone, the fix belongs in PlacementPolicy as an
-- origin-aware check, NOT in this backfill — a narrower backfill would just
-- restore the resurrection hazard.
--
-- No BEGIN/COMMIT: CONVENTIONS.md §5 — a data backfill never runs inside a
-- migration transaction. Guard Check 5 does not apply (no ALTER/UPDATE against
-- a hot table; core.users is read-only here). Re-runnable: ON CONFLICT DO
-- NOTHING against idx_item_tombstones_unique, so a repeat apply is a no-op and
-- refusals already recorded by the suggestions inbox are left alone.

INSERT INTO "routing"."item_tombstones" ("user_id", "source_ref", "scope", "reason", "created_at")
SELECT pc."user_id",
       pc."surface_key" || ':' || pc."resource_id",
       'this_source',
       'legacy soft-deleted connection, backfilled 2026-07-28',
       -- The refusal is dated when it happened, not when this ran: the
       -- tombstone's created_at is the only surviving record of when the user
       -- said no.
       MAX(pc."deleted_at")
  FROM "site"."platform_connections" pc
  JOIN "core"."users" u ON u."id" = pc."user_id"
 WHERE pc."deleted_at" IS NOT NULL
   AND pc."surface_key" <> 'pinterest.profile'
   AND u."status" <> 'unclaimed'
   AND NOT EXISTS (
       SELECT 1
         FROM "site"."platform_connections" live
        WHERE live."user_id" = pc."user_id"
          AND live."surface_key" = pc."surface_key"
          AND live."resource_id" = pc."resource_id"
          AND live."deleted_at" IS NULL
   )
 GROUP BY pc."user_id", pc."surface_key", pc."resource_id"
    ON CONFLICT ("user_id", "source_ref", "scope") DO NOTHING;
