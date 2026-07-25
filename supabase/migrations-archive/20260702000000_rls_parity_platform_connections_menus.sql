-- Defence-in-depth RLS parity for site.platform_connections + the five menu tables.
--
-- WHY: site.platform_connections had RLS ENABLED but lacked FORCE ROW LEVEL SECURITY
-- (added in 20260609000000_harden_platform_connections.sql). FORCE ensures the table
-- owner is also subject to RLS — without it a session connected as the table owner
-- can bypass all policies. This migration adds the missing FORCE.
--
-- The five site.menu_* tables have no RLS at all. They hold per-user menu structures
-- (menus, categories, items, platform links) — data that belongs exclusively to the
-- site owner. Closing this gap now brings them in line with every other site.*
-- table and eliminates a forward-looking exposure: if a future migration adds a
-- blanket site-schema grant or exposes these tables via PostgREST, the policies
-- here prevent any non-app_backend role from reading or writing.
--
-- app_backend has BYPASSRLS (confirmed in the v2 baseline). Enabling + FORCING RLS
-- here therefore does NOT affect any application code path — all models extend
-- BaseModel (pgsql connection → app_backend), and all queue workers run as
-- app_backend. The explicit FOR ALL TO app_backend policies are belt-and-braces so
-- the tables remain writable if BYPASSRLS is ever revoked (mirrors the pattern on
-- moderation.* and site.platform_connections).
--
-- All statements are metadata-only catalog operations (no row scan, no sustained
-- row locks). Wrapped in a single BEGIN/COMMIT.

BEGIN;

-- ── site.platform_connections — add the missing FORCE ────────────────────────
-- ENABLE was already applied in 20260609000000_harden_platform_connections.sql,
-- as was the platform_connections_app_backend_all policy. Only FORCE is missing.
ALTER TABLE site.platform_connections FORCE ROW LEVEL SECURITY;

-- ── site.menus ───────────────────────────────────────────────────────────────
ALTER TABLE site.menus ENABLE ROW LEVEL SECURITY;
ALTER TABLE site.menus FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS menus_app_backend_all ON site.menus;
CREATE POLICY menus_app_backend_all ON site.menus
    FOR ALL TO app_backend
    USING (true) WITH CHECK (true);

-- ── site.menu_categories ─────────────────────────────────────────────────────
ALTER TABLE site.menu_categories ENABLE ROW LEVEL SECURITY;
ALTER TABLE site.menu_categories FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS menu_categories_app_backend_all ON site.menu_categories;
CREATE POLICY menu_categories_app_backend_all ON site.menu_categories
    FOR ALL TO app_backend
    USING (true) WITH CHECK (true);

-- ── site.menu_items ──────────────────────────────────────────────────────────
ALTER TABLE site.menu_items ENABLE ROW LEVEL SECURITY;
ALTER TABLE site.menu_items FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS menu_items_app_backend_all ON site.menu_items;
CREATE POLICY menu_items_app_backend_all ON site.menu_items
    FOR ALL TO app_backend
    USING (true) WITH CHECK (true);

-- ── site.menu_platform_links ─────────────────────────────────────────────────
ALTER TABLE site.menu_platform_links ENABLE ROW LEVEL SECURITY;
ALTER TABLE site.menu_platform_links FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS menu_platform_links_app_backend_all ON site.menu_platform_links;
CREATE POLICY menu_platform_links_app_backend_all ON site.menu_platform_links
    FOR ALL TO app_backend
    USING (true) WITH CHECK (true);

-- ── site.menu_item_platforms ─────────────────────────────────────────────────
ALTER TABLE site.menu_item_platforms ENABLE ROW LEVEL SECURITY;
ALTER TABLE site.menu_item_platforms FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS menu_item_platforms_app_backend_all ON site.menu_item_platforms;
CREATE POLICY menu_item_platforms_app_backend_all ON site.menu_item_platforms
    FOR ALL TO app_backend
    USING (true) WITH CHECK (true);

COMMIT;
