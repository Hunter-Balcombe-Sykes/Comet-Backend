-- Drop the glass-surface satellite columns (sitepage rebuild, 2026-07-15).
--
-- With the Surface type axis gone (20260714210000), the glass knobs are
-- orphaned: nothing renders glass chips, scrims, or the shine ring. The
-- design-system package removed effects.scrimBlur / effects.glassBlur /
-- motion.glassShineDuration (and the palette-level glass tint) in the same
-- change. A media-scrim axis returns as its own var if a Staple page needs
-- one (rule-of-two). These were never factor-targetable, but purge any
-- stray contributions defensively.

DELETE FROM site.design_kit_contributions
WHERE target_var IN ('effect_scrim_blur', 'effect_glass_blur', 'motion_glass_shine_duration');

ALTER TABLE site.design_kits
    DROP COLUMN IF EXISTS effect_scrim_blur,
    DROP COLUMN IF EXISTS effect_glass_blur,
    DROP COLUMN IF EXISTS motion_glass_shine_duration;
