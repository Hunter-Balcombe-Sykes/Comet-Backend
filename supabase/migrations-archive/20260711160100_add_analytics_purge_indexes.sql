-- #SCALE-2: timestamp-leading indexes so PurgeRawAnalyticsEvents stops
-- sequentially scanning all six raw analytics tables on its daily 03:00 run.
--
-- Every existing index on these tables leads with an id/hash column
-- (professional_id / site_id / ip_hash / user_id / link_block_id / session_id),
-- so the purge's user-agnostic `WHERE <ts> < cutoff` predicate — and the
-- `ctid IN (SELECT ... LIMIT n)` subquery Laravel compiles for the batched
-- DELETE — had no index to seek into and fell back to a seq scan on every run.
--
-- No BEGIN/COMMIT: CREATE INDEX CONCURRENTLY cannot run inside a transaction
-- (CONVENTIONS §1). Plain btree (not BRIN): the batched ctid-LIMIT delete
-- benefits from btree's exact ordering to stop precisely at the batch boundary,
-- and these tables are far below the volume where BRIN's size win matters.
-- Revisit BRIN if any of these grows past ~10^8 rows.
--
-- The timestamp column per table matches PurgeRawAnalyticsEvents::TABLES:
-- all use occurred_at except site_sessions, which ages out on last_seen_at.

CREATE INDEX CONCURRENTLY IF NOT EXISTS link_clicks_occurred_at_idx
    ON analytics.link_clicks (occurred_at);

CREATE INDEX CONCURRENTLY IF NOT EXISTS site_visits_occurred_at_idx
    ON analytics.site_visits (occurred_at);

CREATE INDEX CONCURRENTLY IF NOT EXISTS lead_submissions_occurred_at_idx
    ON analytics.lead_submissions (occurred_at);

CREATE INDEX CONCURRENTLY IF NOT EXISTS section_views_occurred_at_idx
    ON analytics.section_views (occurred_at);

CREATE INDEX CONCURRENTLY IF NOT EXISTS item_views_occurred_at_idx
    ON analytics.item_views (occurred_at);

CREATE INDEX CONCURRENTLY IF NOT EXISTS site_sessions_last_seen_at_idx
    ON analytics.site_sessions (last_seen_at);
