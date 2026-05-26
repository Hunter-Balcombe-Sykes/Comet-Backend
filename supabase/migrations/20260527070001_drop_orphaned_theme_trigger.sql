-- 20260527070001_drop_orphaned_theme_trigger.sql
-- Follow-up to 20260527070000_skeleton_system_cleanup.sql.
--
-- The prior migration ran `DROP FUNCTION IF EXISTS set_default_theme_for_site CASCADE`
-- unqualified, which resolved to nothing (no function by that name in the default
-- search_path). The actual function lives in the `core` schema as
-- `core.set_default_theme_for_site()` and survived. Its trigger
-- `set_default_theme_on_sites` (BEFORE INSERT on site.sites) also survived and was
-- still firing on inserts — calling a function that references the now-dropped
-- `site.sites.theme_id` column and the dropped `site.themes` table. Result: any
-- new site insert would have thrown a column-reference error.
--
-- This migration completes the cleanup intended by the prior file.
--
-- Spec reference: docs/superpowers/specs/2026-05-26-skeleton-system-design.md §8.1
-- (the SQL there should have been `DROP FUNCTION IF EXISTS core.set_default_theme_for_site CASCADE`).

DROP TRIGGER IF EXISTS set_default_theme_on_sites ON site.sites;
DROP FUNCTION IF EXISTS core.set_default_theme_for_site() CASCADE;
