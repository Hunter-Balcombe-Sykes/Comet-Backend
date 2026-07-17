-- Semantic text scale + corner/border vocabulary remap (2026-07-10). Test
-- users only — destructive remaps are sanctioned (same footing as the
-- 20260710160000 theme/surface rework).
--
-- The 7-step size-named text scale (text_xs .. text_xxl + text_desktop_xs)
-- becomes a 9-slot SEMANTIC scale: caption / body / h3 / h2 / h1 / display
-- plus three desktop companions (body / h1 / display). Columns stay TEXT NULL
-- with no DB defaults — code-side defaults live in
-- @partnaau/design-system/design-kit (spec §15 rule 3).
--
-- Corner + border vocabularies shift at the same time (label-intent shift:
-- each dashboard slot keeps its position but lands on a new, smaller value):
--   border_radius       {0.2, 0.6, 1.2, 2}rem    -> {0, 0.25, 0.85, 1.5}rem
--   border_small_radius {0.15, 0.5, 0.95, 1.6}rem -> {0, 0.2, 0.65, 1}rem
--   border_thickness    {1, 2, 3}px               -> {0.5, 1, 2}px

-- ── site.design_kits: add the nine semantic text columns ──
alter table site.design_kits
  add column if not exists text_caption text null,
  add column if not exists text_body text null,
  add column if not exists text_h3 text null,
  add column if not exists text_h2 text null,
  add column if not exists text_h1 text null,
  add column if not exists text_display text null,
  add column if not exists text_desktop_body text null,
  add column if not exists text_desktop_h1 text null,
  add column if not exists text_desktop_display text null;

-- Users who set a size stored their dashboard tier in text_xs
-- (Small '0.75rem' / Medium '0.85rem' / Large '0.95rem'). Each tier writes
-- the FULL new set; an unrecognised or NULL text_xs leaves the new columns
-- NULL so package defaults apply at read time.
update site.design_kits set
  text_caption         = '0.7rem',
  text_body            = '0.8rem',
  text_h3              = '1.1rem',
  text_h2              = '1.5rem',
  text_h1              = '2rem',
  text_display         = '2.4rem',
  text_desktop_body    = '0.85rem',
  text_desktop_h1      = '2.4rem',
  text_desktop_display = '3rem'
where text_xs = '0.75rem'; -- Small

update site.design_kits set
  text_caption         = '0.75rem',
  text_body            = '0.85rem',
  text_h3              = '1.25rem',
  text_h2              = '1.85rem',
  text_h1              = '2.6rem',
  text_display         = '3.2rem',
  text_desktop_body    = '0.95rem',
  text_desktop_h1      = '3.2rem',
  text_desktop_display = '4.5rem'
where text_xs = '0.85rem'; -- Medium

update site.design_kits set
  text_caption         = '0.8rem',
  text_body            = '0.9rem',
  text_h3              = '1.4rem',
  text_h2              = '2.1rem',
  text_h1              = '3.2rem',
  text_display         = '4rem',
  text_desktop_body    = '1rem',
  text_desktop_h1      = '4rem',
  text_desktop_display = '6rem'
where text_xs = '0.95rem'; -- Large

-- ── drop the seven size-named columns ──
alter table site.design_kits
  drop column if exists text_xs,
  drop column if exists text_sm,
  drop column if exists text_md,
  drop column if exists text_lg,
  drop column if exists text_xl,
  drop column if exists text_xxl,
  drop column if exists text_desktop_xs;

-- ── corner + thickness vocabulary on stored design_kits rows ──
update site.design_kits set border_radius = case border_radius
    when '0.2rem' then '0'
    when '0.6rem' then '0.25rem'
    when '1.2rem' then '0.85rem'
    when '2rem'   then '1.5rem'
    else border_radius end
where border_radius in ('0.2rem', '0.6rem', '1.2rem', '2rem');

update site.design_kits set border_small_radius = case border_small_radius
    when '0.15rem' then '0'
    when '0.5rem'  then '0.2rem'
    when '0.95rem' then '0.65rem'
    when '1.6rem'  then '1rem'
    else border_small_radius end
where border_small_radius in ('0.15rem', '0.5rem', '0.95rem', '1.6rem');

update site.design_kits set border_thickness = case border_thickness
    when '1px' then '0.5px'
    when '2px' then '1px'
    when '3px' then '2px'
    else border_thickness end
where border_thickness in ('1px', '2px', '3px');

-- ── site.design_kit_contributions ──
-- text_xs contributions carry over to text_body (values stay valid body
-- sizes); contributions to the other dropped text columns are meaningless.
update site.design_kit_contributions
   set target_var = 'text_body'
 where target_var = 'text_xs';

delete from site.design_kit_contributions
 where target_var in ('text_sm', 'text_md', 'text_lg', 'text_xl', 'text_xxl', 'text_desktop_xs');

-- Same label-intent value remaps as the design_kits rows above.
update site.design_kit_contributions set value = case value
    when '0.2rem' then '0'
    when '0.6rem' then '0.25rem'
    when '1.2rem' then '0.85rem'
    when '2rem'   then '1.5rem'
    else value end
where target_var = 'border_radius'
  and value in ('0.2rem', '0.6rem', '1.2rem', '2rem');

update site.design_kit_contributions set value = case value
    when '0.15rem' then '0'
    when '0.5rem'  then '0.2rem'
    when '0.95rem' then '0.65rem'
    when '1.6rem'  then '1rem'
    else value end
where target_var = 'border_small_radius'
  and value in ('0.15rem', '0.5rem', '0.95rem', '1.6rem');

update site.design_kit_contributions set value = case value
    when '1px' then '0.5px'
    when '2px' then '1px'
    when '3px' then '2px'
    else value end
where target_var = 'border_thickness'
  and value in ('1px', '2px', '3px');

-- Any remaining off-vocabulary border_radius contribution (factors used to
-- emit 0.3/0.4/0.5/0.8/1rem etc.) snaps to the nearest new stop:
--   < 0.125 -> 0, 0.125–0.55 -> 0.25rem, 0.55–1.175 -> 0.85rem, > 1.175 -> 1.5rem.
update site.design_kit_contributions
   set value = case
       when nullif(regexp_replace(value, '[^0-9.]', '', 'g'), '')::numeric < 0.125 then '0'
       when nullif(regexp_replace(value, '[^0-9.]', '', 'g'), '')::numeric < 0.55  then '0.25rem'
       when nullif(regexp_replace(value, '[^0-9.]', '', 'g'), '')::numeric <= 1.175 then '0.85rem'
       else '1.5rem'
     end
 where target_var = 'border_radius'
   and value not in ('0', '0.25rem', '0.85rem', '1.5rem')
   and regexp_replace(value, '[^0-9.]', '', 'g') ~ '^[0-9]+(\.[0-9]+)?$';
