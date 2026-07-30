-- 271-PRIV-1, second half. The whole point of the finding is the rows that
-- already exist: every is_current = false row in production today is
-- accumulated retirement debt. Leaving them NULL would leave the backlog
-- permanently invisible to the prune job -- a retention column that reads as
-- solved and deletes nothing, which is worse than not shipping it.
--
-- Stamped now(), NOT created_at. created_at is when the slug was MINTED
-- (while it was still current), not when it was retired: a slug minted six
-- months ago and demoted yesterday would be stamped six months stale and
-- hard-deleted on the first sweep, killing a one-day-old live 301. now() gives
-- every existing retired row one full clean window from the apply date -- the
-- backlog survives 90 more days and then drains, and no live redirect dies.
--
-- This DIVERGES from the handle-alias precedent, deliberately.
-- PruneExpiredHandleAliases spares legacy NULL-expires_at rows forever
-- (whereNotNull('expires_at'), :29/:31, locked in by
-- PruneExpiredHandleAliasesTest.php:104). Copying that here would reproduce
-- exactly the defect this unit exists to close.
--
-- ROLLBACK: NONE. No pre-image of retired_at is recorded (there was no column
--           to record). The rows this stamped are not distinguishable after
--           the fact from rows the app stamped. If run before any application
--           write, 20260731090000's DROP COLUMN is the only real escape hatch.
--           No PITR, no managed backups (Supabase Free).

UPDATE "site"."item_slugs"
   SET "retired_at" = now()
 WHERE "is_current" = false
   AND "retired_at" IS NULL;
