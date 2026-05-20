-- ============================================================
-- Fix stale table reference in core.prevent_staff_escalation()
-- ============================================================
--
-- The function body (v2 baseline) still reads `core.comet_staff`,
-- a table renamed twice since: comet_staff → sidest_staff
-- (20260404000003) → partna_staff (20260508400000). PL/pgSQL bodies
-- are stored verbatim in pg_proc.prosrc and recompiled on first call
-- per session — so any new pooled connection that triggers a staff
-- UPDATE fails with 42P01: relation "core.comet_staff" does not exist.
--
-- CREATE OR REPLACE with `core.partna_staff`. Body is otherwise
-- byte-identical to the baseline definition.
-- ============================================================

BEGIN;

CREATE OR REPLACE FUNCTION core.prevent_staff_escalation()
RETURNS trigger
LANGUAGE plpgsql
SET search_path TO 'pg_catalog'
AS $$
declare
  uid uuid := (select auth.uid());
  is_admin boolean;
begin
  if uid is null then
    return new;
  end if;

  select exists (
    select 1
    from core.partna_staff cs
    where cs.auth_user_id = uid
      and cs.role = 'admin'
  ) into is_admin;

  if not is_admin then
    if new.role is distinct from old.role then
      raise exception 'Only admins can change staff role';
    end if;

    if new.auth_user_id is distinct from old.auth_user_id then
      raise exception 'Only admins can change auth_user_id';
    end if;
  end if;

  return new;
end;
$$;

COMMIT;
