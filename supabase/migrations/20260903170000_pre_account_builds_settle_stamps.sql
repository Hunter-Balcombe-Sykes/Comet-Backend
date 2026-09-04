-- Setup-settled email timing (2026-09-03): both lifecycle emails fired before
-- the build had finished filling in -- the welcome at claim (which no longer
-- waits on the build), the outreach invite at build_state=ready (which
-- precedes the whole cascade). These three stamps are the settle event's
-- record. All nullable, no backfill: existing rows stay NULL and the sweep's
-- 30-minute creation window never looks at them.
--
-- The sweep's covering index lives in the +1 file, alone, per CONVENTIONS.md §1.
--
-- History (2026-09-04): this migration was applied to the dev ref ad hoc via the
-- Supabase MCP, which writes a ledger row and no file. That left version
-- 20260903170000 in schema_migrations with nothing in git, and `supabase db push`
-- then refused EVERY later migration on EVERY lane (LegacyDbPushMissingLocalError)
-- until another session reconstructed a stand-in from the live schema (5a32f9a51).
-- This is that lane's original, restored per that commit's own instruction.
--
-- ROLLBACK: ALTER TABLE core.pre_account_builds DROP COLUMN IF EXISTS settled_at;
--           ALTER TABLE core.pre_account_builds DROP COLUMN IF EXISTS setup_stalled_at;
--           ALTER TABLE core.pre_account_builds DROP COLUMN IF EXISTS welcomed_at;

begin;

alter table core.pre_account_builds add column if not exists settled_at timestamptz;

alter table core.pre_account_builds add column if not exists setup_stalled_at timestamptz;

alter table core.pre_account_builds add column if not exists welcomed_at timestamptz;

comment on column core.pre_account_builds.settled_at is 'The setup cascade genuinely finished (BuildProgressReader OUTCOME_SETTLED). Stamped once by builds:settle-sweep.';

comment on column core.pre_account_builds.setup_stalled_at is 'Terminal without settling -- hit the 10-minute ceiling or failed. No email is ever sent for these; builds:stalled reads this.';

comment on column core.pre_account_builds.welcomed_at is 'The welcome email went out. The signup lane''s idempotency guard; cleared on release so a reclaim re-arms it.';

commit;
