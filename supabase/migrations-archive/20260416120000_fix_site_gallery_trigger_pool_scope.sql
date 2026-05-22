-- Scope core.enforce_site_gallery_max6() to pool = 'gallery' only.
--
-- Why: the original trigger predates the multi-pool refactor (design/content/
-- brand_gallery/product were all added later). It counts every active
-- site_media row for a site regardless of pool, then errors out at >= 6.
-- That meant a brand with 1 logo + 1 placeholder + a few content images was
-- blocked from uploading anything else with "Gallery limit reached".
--
-- Today each pool enforces its own limits separately:
--   gallery         — 5 via sidest.image_pools.gallery.max (app + advisory lock)
--   content         — 5 via sidest.image_pools.content.max (app + advisory lock)
--   design          — 2 logos + 5 placeholders via BrandDesignMediaService
--   brand_gallery   — enforced elsewhere
--   product         — external Shopify cap
--
-- Application-layer enforcement is the source of truth. This trigger stays
-- as a DB-level backstop for the gallery pool specifically — matching the
-- `max6` in its name (one extra over the app's max=5 as a belt-and-braces
-- margin). Other pools are no longer touched by this trigger.
--
-- Not fixed here: the function is still named "max6" and the threshold is
-- hardcoded. Both are carryover from before config existed. If/when the app
-- cap changes, bump the trigger accordingly or retire it entirely — all
-- downstream pools are already protected by their own enforcement.

BEGIN;

CREATE OR REPLACE FUNCTION core.enforce_site_gallery_max6()
RETURNS trigger
LANGUAGE plpgsql
SET search_path TO 'pg_catalog'
AS $function$
declare
  cnt int;
begin
  -- Non-gallery pools (design / content / brand_gallery / product) enforce
  -- their own limits in the application layer. This backstop only fires for
  -- the gallery pool.
  if new.pool is distinct from 'gallery' then
    return new;
  end if;

  if new.deleted_at is not null then
    return new;
  end if;

  if tg_op = 'UPDATE' then
    if old.site_id = new.site_id and old.deleted_at is null and new.deleted_at is null then
      return new;
    end if;
  end if;

  -- Count only gallery-pool rows for this site. Previously this counted every
  -- pool, which is what produced the false-positive "Gallery limit reached"
  -- on design / content uploads.
  select count(*)
    into cnt
  from site.site_media si
  where si.site_id = new.site_id
    and si.pool = 'gallery'
    and si.deleted_at is null
    and (tg_op <> 'UPDATE' or si.id <> new.id);

  if cnt >= 6 then
    raise exception 'Gallery limit reached: max 6 images per site';
  end if;

  return new;
end;
$function$;

COMMIT;
