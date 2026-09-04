-- Delete the routing confidence system's storage (owner, 2026-09-03).
--
-- These three columns recorded a 0–100 score and the gap to the runner-up.
-- Nothing reads them any more: PlacementPolicy's per-class threshold table is
-- gone, and the question those numbers were a proxy for — "does the rule that
-- matched name an ACCOUNT, or only the brand?" — is now asked directly of the
-- catalog by App\Routing\LinkValidity and answered yes or no.
--
-- Safe to drop rather than deprecate:
--   · all three are NULLABLE and carry no constraint anything depends on;
--   · they were diagnostics only — no index, no foreign key, no view;
--   · the one reader was `routing:reproject`, whose report now shows the
--     surface change (the part that was ever actionable) and whether another
--     brand also claims the URL.
--
-- `routing.source_intents.band` deliberately SURVIVES. It still drives the
-- setup dialog's pre-tick, but its meaning changed with this commit: 'auto'
-- used to mean "scored above the class's auto threshold" and now means "the
-- matched rule captured an identifier, so we can name the account". Existing
-- rows keep whatever band they were written with; they are re-banded the next
-- time the link is routed, and a stale band costs at most one wrong pre-tick
-- on a row the person is being asked about anyway.
--
-- link_observations is PARTITIONED BY RANGE — DROP COLUMN on the parent
-- cascades to every partition, so the monthly children need no separate
-- statement.
--
-- One statement per concern; no CONCURRENTLY here, so the file is safe to
-- apply from zero (supabase/migrations/CONVENTIONS.md §1).
--
-- ROLLBACK: NONE for the DATA — three DROP COLUMNs, no pre-image recorded, so
--           every score and margin ever written is gone for good. The SHAPE is
--           re-creatable if some reader is ever found:
--             ALTER TABLE "routing"."link_observations" ADD COLUMN IF NOT EXISTS "confidence" smallint;
--             ALTER TABLE "routing"."link_observations" ADD COLUMN IF NOT EXISTS "margin" smallint;
--             ALTER TABLE "routing"."source_intents" ADD COLUMN IF NOT EXISTS "confidence" smallint;
--           (the columns come back NULL and nothing repopulates them — the
--           code that computed the numbers was deleted in the same commit, so
--           this reverse restores a shape, never a value. Losing them costs
--           nothing operational: they carried no index, key or view, and the
--           one reader was `routing:reproject`'s report.)

ALTER TABLE "routing"."link_observations" DROP COLUMN IF EXISTS "confidence";

ALTER TABLE "routing"."link_observations" DROP COLUMN IF EXISTS "margin";

ALTER TABLE "routing"."source_intents" DROP COLUMN IF EXISTS "confidence";

COMMENT ON COLUMN "routing"."source_intents"."band" IS
    'auto = the matched detector captured an identifier, so the account can be named and the setup dialog pre-ticks the row; suggest = a shape matched but named nobody. Was a confidence-threshold band until 2026-09-03.';
