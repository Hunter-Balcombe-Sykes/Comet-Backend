-- Setup-settled email timing (2026-09-03): both lifecycle emails fired before
-- the build had finished filling in -- the welcome at claim (which no longer
-- waits on the build), the outreach invite at build_state=ready (which
-- precedes the whole cascade). These three stamps are the settle event's
-- record. All nullable, no backfill: existing rows stay NULL and the sweep's
-- 30-minute creation window never looks at them.
--
-- ROLLBACK: DROP INDEX IF EXISTS core.pre_account_builds_settle_sweep_idx;
--           ALTER TABLE core.pre_account_builds DROP COLUMN IF EXISTS settled_at;
--           ALTER TABLE core.pre_account_builds DROP COLUMN IF EXISTS setup_stalled_at;
--           ALTER TABLE core.pre_account_builds DROP COLUMN IF EXISTS welcomed_at;

alter table core.pre_account_builds add column if not exists settled_at timestamptz;

alter table core.pre_account_builds add column if not exists setup_stalled_at timestamptz;

alter table core.pre_account_builds add column if not exists welcomed_at timestamptz;

-- The sweep's candidate query: recent builds with no terminal stamp yet.
create index if not exists pre_account_builds_settle_sweep_idx
    on core.pre_account_builds (created_at)
    where settled_at is null and setup_stalled_at is null;

comment on column core.pre_account_builds.settled_at is 'The setup cascade genuinely finished (BuildProgressReader OUTCOME_SETTLED). Stamped once by builds:settle-sweep.';

comment on column core.pre_account_builds.setup_stalled_at is 'Terminal without settling -- hit the 10-minute ceiling or failed. No email is ever sent for these; builds:stalled reads this.';

comment on column core.pre_account_builds.welcomed_at is 'The welcome email went out. The signup lane''s idempotency guard; cleared on release so a reclaim re-arms it.';
