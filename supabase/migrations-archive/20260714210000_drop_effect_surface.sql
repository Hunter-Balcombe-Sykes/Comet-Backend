-- Drop the Surface type kit axis (sitepage rebuild, 2026-07-15).
--
-- effect_surface (glass | solid | outline) drove the [data-surface] chip
-- treatment in the pre-rebuild component system. The rebuilt component
-- library derives its look from the kit vars directly, the dashboard card and
-- the package types/validator are removed, and the factor layer no longer
-- emits the column — so the storage goes too. Stale auto-integration
-- contributions targeting it are purged (they would otherwise resolve into a
-- write against a missing column).

BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

DELETE FROM site.design_kit_contributions WHERE target_var = 'effect_surface';

ALTER TABLE site.design_kits DROP COLUMN IF EXISTS effect_surface;

COMMIT;
