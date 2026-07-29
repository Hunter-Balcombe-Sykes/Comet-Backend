-- guard:no-unsafe-migrations:disable-file
--   CREATE INDEX CONCURRENTLY is not supported on a partitioned table in
--   PostgreSQL (prod 17.6, CI lane 16). The documented alternative — build
--   CONCURRENTLY on each of the 8 record_versions partitions, then
--   CREATE UNIQUE INDEX ... ON ONLY the parent and ALTER INDEX ... ATTACH
--   PARTITION x8 — would be 10 files under the one-CONCURRENTLY-per-file
--   rule (supabase/migrations/CONVENTIONS.md §1).
--
--   Same lock cost, same acceptance argument, same table as
--   20260729120001_record_versions_stream_fk.sql, which already took an
--   ACCESS EXCLUSIVE lock on the parent plus all 8 partitions on exactly
--   this reasoning (prod core.users = 0; ingest fleet landed 2026-07-27).
--   As with that migration, this would NOT be safe to re-run post-scale.
--
--   The partition key (stream_id) is the leading column of this index,
--   satisfying "a UNIQUE index on a partitioned table must include the
--   partition key" — the one thing that makes this index legal at all.
--
-- DINT-16/DINT-9: today idx_record_versions_current is a plain (non-UNIQUE)
-- partial index — a lookup accelerator, not a constraint — so nothing stops
-- two rows for the same (stream_id, key) both carrying is_current=true.
-- Lander::land() now demotes-then-promotes as two ordered statements
-- specifically so this constraint is never transiently violated (a single
-- combined UPDATE could visit rows in either order and violate it
-- mid-statement; partial unique indexes cannot be made DEFERRABLE).
BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '60s';

CREATE UNIQUE INDEX "idx_record_versions_one_current"
    ON "ingest"."record_versions" ("stream_id", "key") WHERE "is_current";

COMMIT;
