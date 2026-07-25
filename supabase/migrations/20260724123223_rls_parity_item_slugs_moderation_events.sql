-- RLS parity for the last four tables in the DB without Row Level Security,
-- plus removal of two spent one-off backup tables.
--
-- TRIGGER: a Supabase security advisory reported site.item_slugs,
-- site.design_kits_font_backup_20260709 and
-- site.design_kit_contributions_font_backup_20260709 as "RLS disabled and
-- exposed to anon/authenticated".
--
-- FILENAME NOTE: applied via the Supabase MCP, which records its own timestamp
-- in supabase_migrations.schema_migrations rather than the filename. This file
-- is named to match the recorded version (20260724123223) so `db push` sees it
-- as already applied instead of replaying it.
--
-- HONEST SCOPE — the "exposed" half of that report is WRONG. Verified against
-- the live dev DB (glncumufgaqcmqhzwrxm): none of these tables carries a single
-- grant to anon, authenticated or PUBLIC, and `site`/`audit` are not in the
-- PostgREST exposed-schema list (20260723140000_pgrst_empty_exposed_schema.sql
-- pins it to an empty schema). PostgREST cannot reach them and no anon/
-- authenticated role holds a privilege for RLS to gate. The `rls_disabled_in_public`
-- lint does not in fact fire on this project today. So there is NO live exposure.
--
-- What IS real is a convention gap: every other table in this DB runs
-- ENABLE + FORCE ROW LEVEL SECURITY, maintained by a run of parity migrations
-- (20260702000000, 20260711160000, 20260722020000). These four are the
-- stragglers. This closes them on the same forward-looking rationale that
-- 20260722020000 documents: if a future migration ever adds a blanket schema
-- grant, or the Data API is re-exposed, the policies are already in place.
--
-- app_backend has BYPASSRLS (rolbypassrls = true, re-verified live), and every
-- model extends BaseModel (forced pgsql connection -> app_backend), so enabling
-- RLS changes NO application code path. The explicit policies below are
-- belt-and-braces for the day BYPASSRLS is revoked.
--
-- All statements are catalog-only metadata operations plus two 2-row/7-row
-- drops — no table scan, no sustained row locks. None of these are hot tables
-- (HOT_TABLES = site.design_kits/sites/blocks, core.users), so no lock or
-- statement timeout guard is required (CONVENTIONS.md §8). Single BEGIN/COMMIT.
--
-- Down:
--   DROP POLICY item_slugs_app_backend_all ON site.item_slugs;
--   ALTER TABLE site.item_slugs NO FORCE ROW LEVEL SECURITY;
--   ALTER TABLE site.item_slugs DISABLE ROW LEVEL SECURITY;
--   DROP POLICY moderation_events_staff_select      ON audit.moderation_events;
--   DROP POLICY moderation_events_app_backend_select ON audit.moderation_events;
--   DROP POLICY moderation_events_app_backend_insert ON audit.moderation_events;
--   ALTER TABLE audit.moderation_events NO FORCE ROW LEVEL SECURITY;
--   ALTER TABLE audit.moderation_events DISABLE ROW LEVEL SECURITY;
--   (the dropped backup tables are not recreatable — their rows are recorded below)

BEGIN;

-- ── 1. Drop the two spent font-migration backup tables ───────────────────────
-- Created by 20260709064322_migrate_retired_font_slugs_one.sql to make the
-- 17->9 font roster swap reversible ("drop them only after V1 ships").
-- Dropping now, ahead of that marker, because both are spent:
--
--   * design_kit_contributions_font_backup_20260709 is ORPHANED — its restore
--     target site.design_kit_contributions no longer exists in the DB, so the
--     documented restore statement cannot run at all.
--   * The forward migration completed cleanly: ZERO rows in site.design_kits
--     still hold any of the 13 retired slugs (verified live), so there is no
--     half-applied state a rollback would repair.
--
-- The 9 rows are recorded verbatim here so the drop loses no information:
--
--   design_kits_font_backup_20260709 (site_id, typography_font_family):
--     019e5c37-9a82-70a3-a04c-f2f20a4dcf70  eb-garamond
--     019ebedc-9f6a-71a8-a0b9-c2b28b294448  tex-gyre-heros
--
--   design_kit_contributions_font_backup_20260709 (id, value):
--     019f20e9-2467-731b-ac5b-e070907902a0  tex-gyre-heros
--     019f20e9-29c9-7369-9cac-d04a0561fbb4  tex-gyre-heros
--     94dd086a-7cf7-48a0-a3af-51c9eb19672e  work-sans
--     96b34fe5-6fb6-4a64-a34b-f7af67c42799  playfair-display
--     b7ea4ab0-49e2-4cc9-9c92-db83f2c9b1cd  tex-gyre-heros
--     bce2aed5-0392-4731-82d9-22202948f94b  tex-gyre-heros
--     ea9cd523-32d9-4730-8026-c590d138a8ee  eb-garamond
--
-- No CASCADE, deliberately: if anything unexpectedly depends on these, the
-- migration must abort rather than silently drop the dependent object.
DROP TABLE IF EXISTS site.design_kits_font_backup_20260709;
DROP TABLE IF EXISTS site.design_kit_contributions_font_backup_20260709;

-- ── 2. site.item_slugs ───────────────────────────────────────────────────────
-- CORRECTION to 20260724120000_create_item_slugs.sql's header, which justified
-- shipping without RLS as "matching its sibling tables site.menu_items and
-- site.platform_connections". Those two are the opposite of that claim — both
-- were given ENABLE + FORCE + an app_backend FOR ALL policy in
-- 20260702000000_rls_parity_platform_connections_menus.sql. This applies the
-- identical shape so the stated parity is actually true.
ALTER TABLE site.item_slugs ENABLE ROW LEVEL SECURITY;
ALTER TABLE site.item_slugs FORCE ROW LEVEL SECURITY;
DROP POLICY IF EXISTS item_slugs_app_backend_all ON site.item_slugs;
CREATE POLICY item_slugs_app_backend_all ON site.item_slugs
    FOR ALL TO app_backend
    USING (true) WITH CHECK (true);

-- ── 3. audit.moderation_events ───────────────────────────────────────────────
-- The only table in the `audit` schema without RLS — its five siblings
-- (staff_audit_log, data_export_audit, handle_change_log, user_deletion_audit,
-- auth_factor_events) all have it. Written solely by
-- App\Services\Moderation\ModerationAuditService via the AuditEvent model.
--
-- Policy shape mirrors audit.staff_audit_log, NOT the FOR ALL used above: the
-- audit schema is append-only and app_backend holds only SELECT + INSERT on
-- this table (verified live). A FOR ALL policy would describe UPDATE/DELETE
-- paths the underlying grants already deny, so the split keeps policy and
-- grant telling the same story.
--
-- NOTE: this adds FORCE, which the five audit siblings still lack (they are
-- ENABLE-only). Bringing those five up to FORCE is a separate parity pass,
-- deliberately not bundled here.
ALTER TABLE audit.moderation_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE audit.moderation_events FORCE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS moderation_events_app_backend_insert ON audit.moderation_events;
CREATE POLICY moderation_events_app_backend_insert ON audit.moderation_events
    FOR INSERT TO app_backend
    WITH CHECK (true);

DROP POLICY IF EXISTS moderation_events_app_backend_select ON audit.moderation_events;
CREATE POLICY moderation_events_app_backend_select ON audit.moderation_events
    FOR SELECT TO app_backend
    USING (true);

-- Staff read path, matching audit.staff_audit_log_staff_select exactly.
DROP POLICY IF EXISTS moderation_events_staff_select ON audit.moderation_events;
CREATE POLICY moderation_events_staff_select ON audit.moderation_events
    FOR SELECT TO authenticated
    USING (EXISTS (
        SELECT 1 FROM core.partna_staff ps
        WHERE ps.auth_user_id = auth.uid() AND ps.role IN ('admin', 'support')
    ));

COMMIT;
