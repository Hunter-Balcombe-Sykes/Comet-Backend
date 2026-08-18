-- 20260819011500_workplace_section_default_active.sql
--
-- Workplace section defaults ON (owner, 2026-08-19). The sitepage's About
-- page renders "Workplace info" for partna accounts from the `workplace`
-- section block, and the dashboard's new "Show on site" switch is opt-OUT.
-- New rows now start active (UserSectionBlockController::syncAllowedSections);
-- this backfills the existing ones. No user could turn the section on before
-- (the new dashboard had no section toggle), so every inactive workplace block
-- is the old default, not a choice — flip them all.
--
-- Rollback: none that is honest — after this runs a deliberate "off" and the
-- old default are indistinguishable. Nothing to revert schema-wise.
BEGIN;
UPDATE site.blocks
   SET is_active = TRUE,
       updated_at = now()
 WHERE block_group = 'sections'
   AND block_type = 'workplace'
   AND is_active = FALSE;
COMMIT;
