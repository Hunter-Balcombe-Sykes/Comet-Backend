-- Theme-mode/surface rework (2026-07-10). Test users only — destructive drops
-- are sanctioned. theme_mode becomes a 5-value user selection that OWNS the
-- background (color_bg dies); effect_style's Visual Style bundle becomes the
-- storage-only effect_surface; motion_entrance is removed entirely.

alter table site.design_kits add column if not exists effect_surface text null;
alter table site.design_kits add column if not exists theme_night_shift_auto boolean null;

-- Old 2-value theme_mode → nearest new mode.
update site.design_kits set theme_mode = 'bleach'   where theme_mode = 'light';
update site.design_kits set theme_mode = 'midnight' where theme_mode = 'dark';

-- Best-effort surface carry-over from the old bundle before it drops.
update site.design_kits set effect_surface = case effect_style
    when 'soft-glass' then 'glass'
    when 'bold'       then 'solid'
    when 'sharp'      then 'outline'
    when 'editorial'  then 'outline'
    else null end
where effect_style is not null;

alter table site.design_kits drop column if exists color_bg;
alter table site.design_kits drop column if exists effect_style;
alter table site.design_kits drop column if exists motion_entrance;

-- Factor contributions to the dead columns are meaningless now.
delete from site.design_kit_contributions
where target_var in ('color_bg', 'effect_style', 'motion_entrance');
