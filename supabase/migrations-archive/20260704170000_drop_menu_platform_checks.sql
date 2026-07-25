-- =====================================================================
-- FOUND-23 — drop the 7 CHECK constraints that hardcoded the menu-platform
-- slug list ('uber-eats','doordash'). config('partna.menu.platforms') is now
-- the single source of truth for valid menu platforms (enforced at the app
-- layer by the registry-driven MenuFetchJob write path). Adding a menu platform
-- is now a config entry + a MenuPlatformDriver class — zero migration.
-- (menu_item_platforms.platform was already un-CHECKed by design.)
-- =====================================================================
BEGIN;
ALTER TABLE site.menus              DROP CONSTRAINT IF EXISTS menus_content_source_check;
ALTER TABLE site.menus              DROP CONSTRAINT IF EXISTS menus_pickup_platform_check;
ALTER TABLE site.menus              DROP CONSTRAINT IF EXISTS menus_delivery_platform_check;
ALTER TABLE site.menu_categories    DROP CONSTRAINT IF EXISTS menu_categories_source_platform_check;
ALTER TABLE site.menu_items         DROP CONSTRAINT IF EXISTS menu_items_pickup_source_check;
ALTER TABLE site.menu_items         DROP CONSTRAINT IF EXISTS menu_items_delivery_source_check;
ALTER TABLE site.menu_platform_links DROP CONSTRAINT IF EXISTS menu_platform_links_platform_check;
COMMIT;

-- ROLLBACK (re-add the two-slug CHECKs):
-- BEGIN;
-- ALTER TABLE site.menus ADD CONSTRAINT menus_content_source_check CHECK (content_source IN ('uber-eats','doordash'));
-- ALTER TABLE site.menus ADD CONSTRAINT menus_pickup_platform_check CHECK (pickup_platform IN ('uber-eats','doordash'));
-- ALTER TABLE site.menus ADD CONSTRAINT menus_delivery_platform_check CHECK (delivery_platform IN ('uber-eats','doordash'));
-- ALTER TABLE site.menu_categories ADD CONSTRAINT menu_categories_source_platform_check CHECK (source_platform IN ('uber-eats','doordash'));
-- ALTER TABLE site.menu_items ADD CONSTRAINT menu_items_pickup_source_check CHECK (pickup_source IN ('uber-eats','doordash'));
-- ALTER TABLE site.menu_items ADD CONSTRAINT menu_items_delivery_source_check CHECK (delivery_source IN ('uber-eats','doordash'));
-- ALTER TABLE site.menu_platform_links ADD CONSTRAINT menu_platform_links_platform_check CHECK (platform IN ('uber-eats','doordash'));
-- COMMIT;
