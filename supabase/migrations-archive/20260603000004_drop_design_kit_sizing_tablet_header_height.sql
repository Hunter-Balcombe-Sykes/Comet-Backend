-- 20260603000004_drop_design_kit_sizing_tablet_header_height.sql
--
-- Removes the orphan site.design_kits.sizing_tablet_header_height column.
--
-- The unified-space-scale migration (20260529053028) collapsed the design
-- system to a single desktop breakpoint and dropped every other *_tablet_*
-- column. sizing_tablet_header_height (added in 20260527150000) was missed in
-- that sweep. It has had no write path since — there is no rule for it in
-- DesignKitValidationRules, so writeDesignKit() never persists it and the
-- column is permanently NULL. Dropping it realigns the schema with the design
-- system and makes the TEST-5 drift guard (DesignKitRequestDriftTest) pass on
-- real PostgreSQL (it asserts every column has a matching request rule).
--
-- Safe: the column is always NULL (no data loss) and IF EXISTS makes the
-- migration idempotent on re-run / fresh apply.

BEGIN;

ALTER TABLE site.design_kits
    DROP COLUMN IF EXISTS sizing_tablet_header_height;

COMMIT;
