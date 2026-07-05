-- =====================================================================
-- site.sites — content_instagram_auto_enabled toggle
-- =====================================================================
-- Mirrors the existing nullable-boolean toggle pattern on this table
-- (services_auto_sync_enabled, charlie_enabled, show_branding: null == off).
-- When true, the Content Selection reserves its first two slots for the
-- latest Instagram reel + latest post. Enabled automatically on Instagram
-- connect; the user can turn it off. NULL is treated as false in the model.

BEGIN;
ALTER TABLE site.sites
    ADD COLUMN IF NOT EXISTS content_instagram_auto_enabled boolean;
COMMIT;

-- ROLLBACK:
-- ALTER TABLE site.sites DROP COLUMN IF EXISTS content_instagram_auto_enabled;
