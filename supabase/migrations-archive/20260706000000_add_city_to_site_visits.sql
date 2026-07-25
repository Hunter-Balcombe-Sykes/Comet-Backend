-- =====================================================================
-- analytics.site_visits — city column for viewer demographics
-- =====================================================================
-- Free-text city resolved at the Cloudflare edge (request.cf.city), forwarded
-- by the partna-pages Worker as X-Visitor-City and captured by
-- DetectsClientInfo::detectCity. Best-effort demographics only — region_code
-- gives states; city zooms one level in for the demographics map. NULLABLE with
-- no DB default: the edge fills it when it can, null otherwise. Not trusted for
-- auth/routing (sanitised at ingest).

BEGIN;
ALTER TABLE analytics.site_visits
    ADD COLUMN IF NOT EXISTS city text;
COMMIT;

-- ROLLBACK:
-- ALTER TABLE analytics.site_visits DROP COLUMN IF EXISTS city;
