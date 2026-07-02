-- Brand-signal analysis of workplaces.previous_website (WebsiteStyleAnalyzer
-- output: {v, url, ok, analyzedAt, accent, tiers{...}, logoCandidates{...}}).
-- System-written only (AnalyzePreviousWebsiteJob), kept in step with the URL
-- by WorkplaceObserver. Feeds the top-priority previous-website design-preset
-- factor. Column-level change only — table grants/RLS unchanged.
ALTER TABLE site.workplaces
  ADD COLUMN IF NOT EXISTS previous_website_analysis jsonb NULL;
