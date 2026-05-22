-- Drop duplicate index on analytics.site_visits(professional_id, occurred_at).
-- analytics_site_visits_professional_occurred_idx is kept as the canonical name;
-- site_visits_professional_time_idx is identical and was imposing double write
-- overhead on every INSERT with no query-plan benefit.
DROP INDEX IF EXISTS analytics.site_visits_professional_time_idx;
