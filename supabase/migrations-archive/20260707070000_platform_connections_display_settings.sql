-- Per-integration public display toggles (e.g. Google Business "show
-- reviews"). Sparse map of toggle key → false; absent key/null column means
-- everything shows (the default). Toggle sets are declared per-platform on
-- PlatformDescriptor::displayToggles.
ALTER TABLE site.platform_connections ADD COLUMN IF NOT EXISTS display_settings JSONB NULL;
