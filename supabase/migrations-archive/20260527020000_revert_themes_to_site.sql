-- ==========================================================================
-- Revert themes to site schema (2026-05-25)
--
-- The prior schema reorganization (20260527010000) moved themes from site
-- to core on the "themes is a platform catalog" rationale. Reverting because
-- the practical preference is to keep all site-rendering data in one schema,
-- even for catalog tables that sites reference via FK.
--
-- See docs/superpowers/plans/2026-05-25-schema-reorganization.md
-- ==========================================================================

BEGIN;

ALTER TABLE core.themes SET SCHEMA site;

-- Refresh the plpgsql trigger function that text-references themes.
-- (Plpgsql resolves table names at call time — must be recreated.)
CREATE OR REPLACE FUNCTION core.set_default_theme_for_site()
RETURNS trigger
LANGUAGE plpgsql
SET search_path TO 'pg_catalog'
AS $func$
begin
  if new.theme_id is null then
    select id
    into new.theme_id
    from site.themes
    order by is_default desc, created_at
    limit 1;

    if new.theme_id is null then
      raise exception 'Cannot create site: no themes exist in site.themes';
    end if;
  end if;

  return new;
end;
$func$;

COMMIT;
