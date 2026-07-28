-- guard:no-unsafe-migrations:disable-file
--
-- DINT-1 / #PRIV-1. Deliberate deviation from CONVENTIONS.md §4 (FK must be
-- NOT VALID then VALIDATE). The safe pattern is UNAVAILABLE on this table:
-- PostgreSQL below 18 rejects ADD CONSTRAINT ... FOREIGN KEY ... NOT VALID on
-- a PARTITIONED table ("cannot add NOT VALID foreign key on partitioned table
-- ... This feature is not yet supported on partitioned tables"). Prod is
-- PostgreSQL 17.6; the CI lane is postgres:16. Both are below that cutoff.
--
-- The alternative -- eight per-partition NOT VALID FKs -- was rejected: the
-- constraint would not live on the parent, so it would NOT propagate to any
-- future partition (a silent re-opening of this exact hole), and \d
-- ingest.record_versions would not show it.
--
-- Cost of the validating form, accepted HERE and ONLY here: ACCESS EXCLUSIVE
-- on the parent plus all 8 partitions, SHARE ROW EXCLUSIVE on ingest.streams,
-- and a full scan of every partition while holding them. Safe today because
-- prod carries no customer data (core.users = 0, CLAUDE.md 2026-07-26) and the
-- ingest fleet landed 2026-07-27, so these tables are effectively empty. This
-- would NOT be safe to re-run post-scale -- record_versions is documented in its
-- own migration as "the highest-volume table in the system".
--
-- The write path is unaffected: RunExecutor::ensureStream() creates or finds a
-- real ingest.streams row and returns its id, and that id is the only value
-- Lander::land() ever writes to stream_id. Every existing row already satisfies
-- this constraint by construction (20260729120000 sweeps any historical stray).

BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '60s';

ALTER TABLE "ingest"."record_versions"
    ADD CONSTRAINT "record_versions_stream_id_fk"
    FOREIGN KEY ("stream_id") REFERENCES "ingest"."streams" ("id") ON DELETE CASCADE;

COMMIT;
