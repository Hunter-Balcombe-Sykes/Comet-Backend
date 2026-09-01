-- 9h (2026-09-01): tier markers on the poll wire. A build's life after
-- 'ready' has two more tiers the timeline campaign measures — content filled
-- (first gallery/menu content actually visible) and enriched (workplace
-- chain landed). Stamped LAZILY by the public poll endpoint the first time
-- it observes each condition (PreAccountBuild::observeTierMarkers), which
-- also emits the per-tier timing telemetry line; columns are nullable and
-- never required by any flow.
--
-- ROLLBACK: ALTER TABLE core.pre_account_builds DROP COLUMN IF EXISTS content_filled_at;
--           ALTER TABLE core.pre_account_builds DROP COLUMN IF EXISTS enriched_at;

alter table core.pre_account_builds add column if not exists content_filled_at timestamptz;

alter table core.pre_account_builds add column if not exists enriched_at timestamptz;
