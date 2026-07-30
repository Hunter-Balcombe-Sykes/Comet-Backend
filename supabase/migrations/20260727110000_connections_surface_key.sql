-- platform_connections joins the catalog (plan §1): surface_key becomes the
-- identity column, `platform` becomes a GENERATED legacy alias so every
-- existing reader keeps working while no writer can ever create dual truth
-- (writes to a generated column error loudly). The mapping here is the SQL
-- form of App\Catalog\LegacyPlatformMap — CatalogLegacyMapTest pins the two
-- in lockstep. Pseudo buckets map to hidden partna.* surfaces that alias
-- back verbatim: zero behaviour change until P2's reproject.
-- FK to catalog.surfaces: PROMISED FOR P2, AND WITHDRAWN 2026-07-28. The
-- original reason (an empty catalog must never block writes) turned out to be
-- permanent rather than temporary, and a second reason was found. Recorded
-- here rather than quietly dropped, because a promise removed without a stated
-- reason is indistinguishable from a promise forgotten.
--
--   1. `catalog:sync` is not part of the migration path and cannot be. It runs
--      AFTER the code deploy (docs/deploy/routine-deploy.md step 2b, explicitly
--      after step 4) plus hourly as a convergence net, while migrations run
--      BEFORE it at step 2. A from-zero apply — the documented fresh-prod
--      cutover, and scripts/db/fresh-reset.sh locally — therefore finishes with
--      catalog.surfaces EMPTY. An FK would make every connection write fail
--      from the end of migrations until someone remembered to sync, turning an
--      operational lag the runbook explicitly tolerates ("up to an hour later")
--      into user-facing 500s. NOT VALID does not help: it exempts existing rows
--      but still enforces on every INSERT and UPDATE.
--   2. A restore would be permanently un-validatable. `catalog:sync` is
--      upsert+tombstone — rows are never deleted — so on a LONG-LIVED database
--      a retired surface keeps its row and an FK stays satisfiable. A restored
--      dump has no such luck: the R2 dump (the org is on Supabase Free, so it
--      is the only backup) carries platform_connections rows whose surface_key
--      is 'pinterest.profile', while a sync into the fresh project writes only
--      what the CURRENT artefact holds — and Pinterest was retired on
--      2026-07-28. VALIDATE CONSTRAINT would fail, and any UPDATE touching
--      those rows would fail with it.
--
-- What the FK was for is already enforced, and enforced better:
-- IntegrationConnection::booted() rejects any surface_key the catalog does not
-- know (LegacyPlatformMap::isKnownSurface → CompiledCatalog), pinned by
-- tests/Feature/Catalog/ConnectionSurfaceGuardTest.php. That check reads the
-- COMPILED ARTEFACT — the same thing the router reads — whereas an FK would
-- check the DB projection, which lags the artefact by up to an hour by design.
-- The FK would have been the weaker of the two, and the one that fails closed
-- at the wrong moments.
--
-- routing.source_intents.surface_key stays unconstrained for the same reasons;
-- it is written only from a placement whose surface came out of the artefact.
-- Revisit only if catalog:sync ever becomes a migration-ordered step.
--
-- RESTRUCTURED 2026-07-29 (audit Unit H — #MIG-1..4). This file originally did
-- everything in one shot: add columns, three unguarded backfills, plain
-- SET NOT NULL, plain ADD CONSTRAINT CHECK, seven plain index operations, and
-- an unbounded DROP COLUMN + ADD COLUMN ... GENERATED ... STORED rewrite.
--
-- It is applied to DEV (2026-07-27) but NOT to PROD (prod's ledger holds only
-- 20260726000000 + 20260726101212, verified 2026-07-29), so it is still
-- PENDING against production and will execute there at the next db push. A
-- forward repair migration cannot make an earlier file's lock window shorter,
-- so the restructure is done here, in place. The RESULTING SCHEMA is byte-for-
-- byte what dev already carries, which is the condition
-- docs/migration-guidelines.md sets for editing an applied file.
--
-- The work is now spread across 20260727110000..110008 per CONVENTIONS.md §1's
-- same-prefix/sequential-suffix scheme. The historical mapping CASEs were not
-- deleted or rewritten — they moved verbatim to ...110001 (surface backfill)
-- and ...110004 (generated alias), and CatalogLegacyMapTest still pins both
-- pair-for-pair. Git history is the record of what changed and why.
--
-- No guard:no-unsafe-migrations:disable-file marker: this family does not need
-- one any more.
--
-- ROLLBACK: ALTER TABLE site.platform_connections
--             DROP COLUMN IF EXISTS surface_key,
--             DROP COLUMN IF EXISTS routing_class,
--             DROP COLUMN IF EXISTS is_primary,
--             DROP COLUMN IF EXISTS created_by_detector,
--             DROP COLUMN IF EXISTS created_by_catalog_digest;
--           ORDER MATTERS: revert ...110004 FIRST. The generated `platform`
--           column depends on surface_key and blocks the drop. Reverting
--           this also discards ...110001's backfill and every value written
--           since.

BEGIN;
SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

-- All five are catalog-only in PostgreSQL 11+: four nullable, one with a
-- non-volatile constant DEFAULT. No heap rewrite, no row scan.
-- IF NOT EXISTS makes the file re-runnable (#MIG-1's second half).
ALTER TABLE "site"."platform_connections"
    ADD COLUMN IF NOT EXISTS "surface_key"               text,
    ADD COLUMN IF NOT EXISTS "routing_class"             text,
    ADD COLUMN IF NOT EXISTS "is_primary"                boolean NOT NULL DEFAULT false,
    ADD COLUMN IF NOT EXISTS "created_by_detector"       text,
    ADD COLUMN IF NOT EXISTS "created_by_catalog_digest" text;

COMMIT;
