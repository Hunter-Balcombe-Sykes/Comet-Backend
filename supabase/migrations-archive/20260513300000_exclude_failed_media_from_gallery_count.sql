-- Exclude failed-processing rows from the gallery-slot backstop trigger.
--
-- Why: a SiteMedia row whose processing_state = 'failed' is a terminal state —
-- the original file was never stored, no variants were produced, and the row
-- is cleaned up automatically by the daily purge command (7-day window).
-- The trigger previously counted these rows toward the 6-slot limit, which
-- could permanently reduce effective capacity from 6 to 5 after a single
-- failed video upload (until the owner found and manually deleted the row).
--
-- The primary enforcement lives in ProfessionalUploadController (app layer,
-- max = 5). This trigger is a DB-level backstop at 6. Both now agree: failed
-- rows do not occupy a usable slot.

BEGIN;

CREATE OR REPLACE FUNCTION core.enforce_site_gallery_max6()
RETURNS trigger
LANGUAGE plpgsql
SET search_path TO 'pg_catalog'
AS $function$
declare
  cnt int;
begin
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

  select count(*)
    into cnt
  from site.site_media si
  where si.site_id = new.site_id
    and si.pool = 'gallery'
    and si.deleted_at is null
    and si.processing_state <> 'failed'
    and (tg_op <> 'UPDATE' or si.id <> new.id);

  if cnt >= 6 then
    raise exception 'Gallery limit reached: max 6 images per site';
  end if;

  return new;
end;
$function$;

COMMIT;
