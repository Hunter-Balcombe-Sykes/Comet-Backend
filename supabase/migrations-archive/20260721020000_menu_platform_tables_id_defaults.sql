-- site.menu_platform_links.id and site.menu_item_platforms.id are uuid PKs
-- with no DEFAULT (both created via CREATE TABLE ... id uuid PRIMARY KEY,
-- with the app supplying gen_random_uuid() at insert time). Same gap as
-- SCHEMA-2 (site.site_subdomain_aliases.id, 20260624010000): a raw-SQL /
-- admin INSERT that omits the id column errors rather than auto-generating
-- a UUID. SET DEFAULT is catalog-only; no row data is touched.
ALTER TABLE site.menu_platform_links ALTER COLUMN id SET DEFAULT gen_random_uuid();
ALTER TABLE site.menu_item_platforms ALTER COLUMN id SET DEFAULT gen_random_uuid();
