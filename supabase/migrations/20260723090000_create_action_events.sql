-- Raw exposure/tap events for the unified actions system (2026-07-23 rebuild).
-- Mirrors analytics.item_views' shape + posture — same denormalized nullable
-- user_id (fail-open write path), same RLS/grants, same purge/export/erasure
-- treatment (see PurgeRawAnalyticsEvents::TABLES, DataExportPayloadBuilder::
-- COVERED_PII_TABLES, AccountDeletionService::PURGED_PII_TABLES + the new
-- purgeActionEventsPii()). Read by App\Services\Analytics\RankedActionsComputer
-- (session-distinct exposure/tap counts -> demand-rate scoring); written by
-- the action-seen/action-tap ingest endpoints.
--
-- FK + CHECK constraints are added INLINE, not the NOT VALID -> VALIDATE split
-- item_views needed retroactively (20260720100400 / 20260720100500) — this
-- table is created empty in this same transaction, so there is no existing
-- data to validate and no concurrent writer yet. The two-step pattern exists
-- for retrofitting constraints onto tables that already carry traffic
-- (CONVENTIONS.md §2/§4); a table born here doesn't need it, same reasoning
-- item_views' own original migration used for its plain (non-CONCURRENTLY)
-- indexes below.
--
-- action_id is validated in-app only (App\Services\PublicSite\Actions\
-- ActionVocabulary::isValidId at the ingest request layer) — deliberately NO
-- DB CHECK constraint on its vocabulary, unlike item_views.item_type. The
-- vocabulary includes two open-ended dynamic families (`ordering:<id>`,
-- `custom:<key>`) whose keys are arbitrary per-site identifiers, not a fixed
-- enum a CHECK could express short of a fragile regex; the 24 static ids are
-- covered by ActionVocabulary + its LOCKSTEP frontend test instead.
--
-- Down: DROP TABLE IF EXISTS analytics.action_events;

CREATE TABLE IF NOT EXISTS analytics.action_events (
    id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id      uuid,             -- site owner (denormalized; nullable = fail-open, mirrors item_views)
    site_id      uuid NOT NULL,
    action_id    text NOT NULL,    -- ActionVocabulary id: a static id or '<family>:<key>'
    event        text NOT NULL,    -- 'seen' | 'tap'
    occurred_at  timestamptz NOT NULL DEFAULT now(),
    session_id   uuid,
    visitor_id   uuid,
    ip_hash      text,
    user_agent   text,
    referrer     text,
    country_code text,
    device_type  text,
    created_at   timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT action_events_site_fk FOREIGN KEY (site_id) REFERENCES site.sites(id) ON DELETE CASCADE,
    CONSTRAINT action_events_event_check CHECK (event IN ('seen', 'tap'))
);

COMMENT ON TABLE analytics.action_events IS
  'Raw exposure/tap events for the unified actions system. action_id = ActionVocabulary id. Read by RankedActionsComputer for demand-rate scoring; PII purged on account deletion (AccountDeletionService::purgeActionEventsPii).';

-- Scoring read pattern: WHERE site_id = ?, GROUP BY action_id per day bucket.
CREATE INDEX IF NOT EXISTS action_events_site_occurred_idx
    ON analytics.action_events (site_id, occurred_at);

-- Purge read pattern (PurgeRawAnalyticsEvents): WHERE occurred_at < cutoff,
-- no site_id filter — the site_id-leading index above can't serve a bare
-- occurred_at scan (leftmost-prefix rule). Mirrors item_views_occurred_at_idx
-- (20260711160100_add_analytics_purge_indexes.sql). Keep in lockstep with
-- PurgeRawAnalyticsEvents::TABLES.
CREATE INDEX IF NOT EXISTS action_events_occurred_at_idx
    ON analytics.action_events (occurred_at);

ALTER TABLE analytics.action_events ENABLE ROW LEVEL SECURITY;

GRANT SELECT, INSERT, UPDATE, DELETE ON analytics.action_events TO app_backend;
