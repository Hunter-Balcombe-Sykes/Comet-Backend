-- #SCHEMA-1: FORCE ROW LEVEL SECURITY on the three post-baseline analytics tables.
--
-- WHY: analytics.site_sessions, analytics.item_views and
-- analytics.content_popularity_scores each had RLS ENABLED but not FORCED. FORCE
-- makes the table owner also subject to the policies; without it a session
-- connected as the table owner bypasses them. This brings the three in line with
-- the hardening convention already applied to site.design_kits, the moderation.*
-- tables, and site.platform_connections (20260702000000).
--
-- SCOPE / HONEST NOTE: the practical security delta here is near-nil today — these
-- tables are owned by `postgres` (a superuser, which bypasses RLS regardless of
-- FORCE) and the app connects as `app_backend` (BYPASSRLS). FORCE only ever bites a
-- NON-superuser table owner, which does not exist on these tables now. This is
-- consistency hygiene / forward-looking defence-in-depth (if a table is ever
-- re-owned to a non-superuser role), not a live exposure — re-tiered P3 from the
-- audit's P2. No application code path changes (all models run as app_backend).
--
-- The four baseline analytics event tables (site_visits / link_clicks /
-- section_views / lead_submissions) are intentionally left as-is — grandfathered,
-- and out of scope for this finding, which named only site_sessions. item_views and
-- content_popularity_scores are included because they share the identical gap one
-- file away and leaving them would just regenerate the finding next sweep.
--
-- analytics.site_metrics_daily / site_metrics_hourly ALSO have enable-without-force
-- (and, unlike the raw event tables, authenticated-facing policies) but are
-- deliberately EXCLUDED: they are inert vestigial tables with no reader/writer in
-- the codebase (see ComputeContentPopularityScores, which reads only raw events).
-- Forcing RLS on dead tables is churn; they should be dropped, not hardened.
--
-- Metadata-only catalog operations (no row scan, no sustained locks).

BEGIN;

ALTER TABLE analytics.site_sessions FORCE ROW LEVEL SECURITY;
ALTER TABLE analytics.item_views FORCE ROW LEVEL SECURITY;
ALTER TABLE analytics.content_popularity_scores FORCE ROW LEVEL SECURITY;

COMMIT;
