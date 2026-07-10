-- Surface-system backend activation (2026-07-10, pages-content-and-surfaces
-- plan Parts 2-3). Test users only — the destructive drop is sanctioned.
--
-- Buttons fold into the surface chip treatment: primary/secondary buttons
-- render as the kit's effect_surface chip (glass|solid|outline), ghost/link
-- become component variants — so the per-kit effect_button_fill selection
-- retires. The glass surface gains its two kit knobs; the glass TINT is a
-- theme_mode palette value emitted by the dispatcher, NOT a kit column.

alter table site.design_kits drop column if exists effect_button_fill;

-- Factor contributions to the dead column are meaningless now.
delete from site.design_kit_contributions where target_var = 'effect_button_fill';

-- Glass knobs — nullable, no DB defaults (code-side defaults live in
-- @partnaau/design-system/design-kit: blur '5px', shine sweep '6s').
alter table site.design_kits add column if not exists effect_glass_blur text null;
alter table site.design_kits add column if not exists motion_glass_shine_duration text null;
