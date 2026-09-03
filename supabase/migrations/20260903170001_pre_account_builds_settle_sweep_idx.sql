-- The settle sweep's candidate query: builds created recently that carry no
-- terminal stamp yet. CONCURRENTLY and alone in this file, outside any
-- transaction, per CONVENTIONS.md §1 -- the CLI pipelines a multi-statement
-- file and CONCURRENTLY cannot run in one (SQLSTATE 25001).
--
-- ROLLBACK: DROP INDEX CONCURRENTLY IF EXISTS core.pre_account_builds_settle_sweep_idx;

create index concurrently if not exists pre_account_builds_settle_sweep_idx on core.pre_account_builds (created_at) where settled_at is null and setup_stalled_at is null;
