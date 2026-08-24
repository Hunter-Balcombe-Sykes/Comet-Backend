-- 20260819011500_workplace_section_default_active.sql
--
-- Workplace section defaults ON (owner, 2026-08-19). The sitepage's About
-- page renders "Workplace info" for partna accounts from the `workplace`
-- section block, and the dashboard's new "Show on site" switch is opt-OUT.
-- New rows now start active (UserSectionBlockController::syncAllowedSections);
-- this backfills the existing ones.
--
-- NARROWED 2026-08-19 (security review). The original justification — "no user
-- could turn the section on before, so every inactive block is the old default,
-- not a choice" — argues the wrong direction. Turning it ON was impossible;
-- turning it OFF was not. `PUT /api/professional/sections/{blockType}` takes
-- is_active straight from the client (UserSectionBlockController:147) and
-- remove() sets it false outright (:277). The workplace card is PUBLIC — it
-- renders name, description, address_line1 and city on the sitepage — so an
-- unconditional flip republishes a physical address somebody chose to hide,
-- with no audit trail and, per the Rollback note below, nothing to revert to.
--
-- `updated_at = created_at` restricts the backfill to rows never written since
-- they were created, which is what "still on the old default" actually means.
-- It UNDER-flips on purpose: any write bumps updated_at, including a system
-- one, so some genuine old-defaults are skipped. That is the safe direction —
-- the owner can switch those on themselves in one click, whereas a wrongly
-- published address cannot be un-published. Fail closed on privacy.
-- Measured on dev 2026-08-19: 113 section blocks, 84 never touched, 29 not.
--
-- Rollback: none that is honest — after this runs a deliberate "off" and the
-- old default are indistinguishable. Nothing to revert schema-wise.
BEGIN;
-- site.blocks is a HOT_TABLES member (CONVENTIONS.md / guard Check 5): without
-- these the backfill queues behind live traffic instead of failing fast, and
-- `composer test` aborts before Pest because the guard runs first in the script.
SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';
UPDATE site.blocks
   SET is_active = TRUE,
       updated_at = now()
 WHERE block_group = 'sections'
   AND block_type = 'workplace'
   AND is_active = FALSE
   AND updated_at = created_at;
COMMIT;
