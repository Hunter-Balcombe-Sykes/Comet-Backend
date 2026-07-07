-- Design-kit identity axes (research §5c / factors-engine spec §6, task #41):
-- the five highest identity-per-effort axes + density, all NULLABLE with NO
-- DB defaults (code-side defaults live in @partnaau/design-system/design-kit
-- per the layer-sweep invariant). Selections resolve through the dispatcher
-- to real CSS vars (the motion_pace pattern); layout_density is a Value
-- multiplier consumed via calc() at spacing/line-height sites.
--
--   effect_shadow_style    flat | soft | hard
--   effect_link_style      underline-hover | underline-always | underline-grow | plain
--   effect_button_fill     solid | outline | ghost
--   effect_image_treatment none | mono | duotone | warm | muted
--   layout_density         "0.85".."1.25" (stored as text like every kit value)
--   border_style           solid | double | none

ALTER TABLE site.design_kits
    ADD COLUMN IF NOT EXISTS effect_shadow_style    text,
    ADD COLUMN IF NOT EXISTS effect_link_style      text,
    ADD COLUMN IF NOT EXISTS effect_button_fill     text,
    ADD COLUMN IF NOT EXISTS effect_image_treatment text,
    ADD COLUMN IF NOT EXISTS layout_density         text,
    ADD COLUMN IF NOT EXISTS border_style           text;
