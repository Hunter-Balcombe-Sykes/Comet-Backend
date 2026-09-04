-- The settle sweep's candidate query: builds created recently that carry no
-- terminal stamp yet. CONCURRENTLY and alone in this file, outside any
-- transaction, per CONVENTIONS.md §1 -- the CLI pipelines a multi-statement
-- file and CONCURRENTLY cannot run in one (SQLSTATE 25001).
--
--
-- History (2026-09-04): this migration was applied to the dev ref ad hoc via the
-- Supabase MCP, which writes a ledger row and no file. That left version
-- 20260903170001 in schema_migrations with nothing in git, and `supabase db push`
-- then refused EVERY later migration on EVERY lane (LegacyDbPushMissingLocalError)
-- until another session reconstructed a stand-in from the live schema (5a32f9a51).
-- This is that lane's original, restored per that commit's own instruction.
-- Dev's LIVE index was built without CONCURRENTLY by that ad-hoc apply;
-- CONCURRENTLY changes only how an index is built, not its definition, so this
-- file stays a no-op against dev while being correct from zero.
-- ROLLBACK: DROP INDEX CONCURRENTLY IF EXISTS core.pre_account_builds_settle_sweep_idx;

create index concurrently if not exists pre_account_builds_settle_sweep_idx on core.pre_account_builds (created_at) where settled_at is null and setup_stalled_at is null;
