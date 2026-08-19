-- #LIFE-5, option (b) — owner ruling 2026-08-19.
--
-- The eager connect run is a ONE-SHOT: IntegrationConnectionObserver::
-- maybeRunEagerly() fires once on creation, nothing retries it, and
-- auto_sync = false keeps SourceScheduler::scoreDue() away no matter what
-- next_attempt_at says. A dispatch lost to a queue blip therefore meant that
-- user's media never arrived — indefinitely, with only a Log::warning.
--
-- This column is the retry. The obligation lives ON THE ROW rather than being
-- re-derived nightly by a reconcile command (option (a), which shipped first
-- and is removed in the same change): the scheduler already knows how to not
-- hammer a source, so putting the flag where scoreDue() can see it inherits
-- every existing guard for free —
--
--   * next_attempt_at <= now()   — so a deferral or a failure backoff is
--                                  respected without a second implementation;
--   * health != 'dead'           — so a source that fails ten times stops;
--   * the in_flight claim        — so two workers cannot both take it.
--
-- Cleared by SourceScheduler::release() on any landing outcome (ok /
-- not_modified / degraded): the eager obligation is discharged the moment
-- content actually arrives, after which the row goes back to being governed by
-- auto_sync alone.
--
-- No index. scoreDue() already reads through idx_ingest_sources_due
-- (next_attempt_at) with a LIMIT, ingest.sources is small (tens of rows per
-- user, not millions), and auto_sync — the column this one sits beside in the
-- same predicate — has never had one either. Speculative index, not added.
--
-- ROLLBACK: ALTER TABLE ingest.sources DROP COLUMN needs_eager_run;

BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

-- Postgres 11+ stores a non-volatile DEFAULT in the catalogue, so this is a
-- metadata-only change: no table rewrite, no long lock, regardless of row count.
ALTER TABLE "ingest"."sources"
    ADD COLUMN IF NOT EXISTS "needs_eager_run" boolean NOT NULL DEFAULT false;

COMMENT ON COLUMN "ingest"."sources"."needs_eager_run" IS
    'LIFE-5: this source owes a one-shot connect run that has not landed yet. Set by IntegrationConnectionObserver::maybeRunEagerly() before dispatch; cleared by SourceScheduler::release() on a landing outcome. Makes scoreDue() consider the row even while auto_sync is false.';

COMMIT;
