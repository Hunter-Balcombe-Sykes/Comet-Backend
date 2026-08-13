-- Slice 3b: a natural key for machine-derived collections, a soft-delete for
-- owner-deleted ones, and the per-source selection the Fresha connector needs.
--
-- guard:no-unsafe-migrations:disable-file
-- Justification: the unique index below is intentionally non-CONCURRENTLY.
-- Reason: Postgres requires the ON CONFLICT target to be a real, immediately
-- visible unique index, and Task 5's upsert() needs it to exist as soon as
-- this migration commits -- a CONCURRENTLY build run as a separate follow-up
-- file would leave a window where the natural-key upsert has nothing to
-- conflict on. content.collections is not in CONVENTIONS.md's HOT_TABLES
-- list, and as of this writing holds 9 rows on dev with zero rows anywhere
-- on production (core.users = 0, pre-pilot) -- the build is momentary and
-- uncontended on both, wherever this file is applied. Check 1 (CREATE INDEX
-- without CONCURRENTLY) doesn't distinguish hot from non-hot tables, hence
-- this explicit opt-out rather than a silent pass.
--
-- ROLLBACK: ALTER TABLE content.collections DROP COLUMN IF EXISTS external_ref,
--             DROP COLUMN IF EXISTS removed_at;
--           DROP INDEX IF EXISTS content.collections_user_kind_external_ref_uq;
--           ALTER TABLE ingest.sources DROP COLUMN IF EXISTS selection_ref;

ALTER TABLE content.collections ADD COLUMN IF NOT EXISTS external_ref TEXT;
ALTER TABLE content.collections ADD COLUMN IF NOT EXISTS removed_at TIMESTAMPTZ;

-- Deliberately NOT partial. `WHERE external_ref IS NOT NULL` reads better, but
-- Postgres requires a partial index's predicate inside ON CONFLICT, and
-- Laravel's upsert() emits only the column list -- the write would fail with
-- "no unique or exclusion constraint matching the ON CONFLICT specification".
-- NULLs are distinct by default, so user-created rows stay unconstrained.
CREATE UNIQUE INDEX IF NOT EXISTS collections_user_kind_external_ref_uq
    ON content.collections (user_id, kind, external_ref);

-- Which sub-account's view of the remote thing to fetch. Three states:
-- an employee id, the literal 'storewide', or NULL (nothing chosen).
ALTER TABLE ingest.sources ADD COLUMN IF NOT EXISTS selection_ref TEXT;

COMMENT ON COLUMN content.collections.external_ref IS
    'Provider-side stable id for a machine-derived collection; NULL when user-created.';
COMMENT ON COLUMN content.collections.removed_at IS
    'Owner deleted this collection. One-way: never set or cleared by a projection run.';
COMMENT ON COLUMN ingest.sources.selection_ref IS
    'Connector-specific sub-account selector, passed through Pull.config. NULL = nothing chosen.';
