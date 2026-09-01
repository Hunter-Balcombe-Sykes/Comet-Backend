-- Setup progress ledger (2026-09-02, "showing the setup as it happens"): one
-- append-only row per landed piece of a pre-account build's fan-out, written
-- by the jobs at their existing log lines (App\Services\PreAccount\
-- BuildProgress) and read by the public build poll (the dashboard's signup
-- feed) and the handle progress endpoint (the sitepage's "still being set
-- up" overlay). Cascades with the build: builds:prune-expired tears the
-- build row down and the ledger goes with it. No user_id on purpose — the
-- build is the owner, exactly as core.pre_account_builds already is.
--
-- ROLLBACK: DROP TABLE IF EXISTS core.pre_account_build_events;

BEGIN;
SET LOCAL lock_timeout      = '5s';
SET LOCAL statement_timeout = '30s';

CREATE TABLE IF NOT EXISTS "core"."pre_account_build_events" (
    "id"         uuid PRIMARY KEY,
    "build_id"   uuid NOT NULL REFERENCES "core"."pre_account_builds"("id") ON DELETE CASCADE,
    "stage"      text NOT NULL CHECK ("stage" IN ('identity', 'media', 'workplace', 'platforms', 'listing', 'menu', 'website', 'ready', 'failed')),
    "status"     text NOT NULL CHECK ("status" IN ('started', 'landed', 'skipped', 'failed')),
    "label"      text NOT NULL,
    "payload"    jsonb NOT NULL DEFAULT '{}'::jsonb,
    "created_at" timestamptz NOT NULL DEFAULT now()
);

-- The poll reads one build's rows in order; a fresh, empty table needs no
-- CONCURRENTLY split.
CREATE INDEX IF NOT EXISTS "pre_account_build_events_build_created_idx"
    ON "core"."pre_account_build_events" ("build_id", "created_at");

COMMIT;
