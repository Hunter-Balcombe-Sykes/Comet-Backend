-- ==========================================================================
-- Schema reorganization (2026-05-25)
--
-- Consolidates audit trails into a dedicated 'audit' schema with append-only
-- grants, moves CRM data (customers) to 'site' alongside enquiries, and moves
-- platform catalog/identity tables (themes, handle aliases) into 'core'.
--
-- See docs/superpowers/plans/2026-05-25-schema-reorganization.md
-- ==========================================================================

BEGIN;

-- --------------------------------------------------------------------------
-- 1. Create audit schema
-- --------------------------------------------------------------------------
-- audit is intentionally NOT granted to anon — audit data must never be
-- publicly reachable. service_role + app_backend only.
CREATE SCHEMA IF NOT EXISTS audit;
ALTER SCHEMA audit OWNER TO postgres;

GRANT USAGE ON SCHEMA audit TO service_role;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'GRANT USAGE ON SCHEMA audit TO app_backend';
    END IF;
END;
$$;

-- --------------------------------------------------------------------------
-- 2. Move tables
-- --------------------------------------------------------------------------
-- SET SCHEMA preserves table data, indexes, constraints, triggers, RLS
-- policies, and existing role grants. Cross-table FKs (from other tables)
-- continue to work because PG tracks them by OID, not name.

-- Audit lane (5 tables core -> audit)
ALTER TABLE core.handle_change_log            SET SCHEMA audit;
ALTER TABLE core.staff_audit_log              SET SCHEMA audit;
ALTER TABLE core.data_export_audit            SET SCHEMA audit;
ALTER TABLE core.professional_deletion_audit  SET SCHEMA audit;
ALTER TABLE core.auth_factor_events           SET SCHEMA audit;

-- CRM (customers belongs with site.enquiries)
ALTER TABLE core.customers SET SCHEMA site;

-- Platform catalog (themes has no professional_id or site_id)
ALTER TABLE site.themes SET SCHEMA core;

-- User-level identity (handle is on core.users, not on site)
ALTER TABLE site.professional_handle_aliases SET SCHEMA core;

-- --------------------------------------------------------------------------
-- 3. Refresh plpgsql functions that text-reference moved tables
-- --------------------------------------------------------------------------
-- Plpgsql resolves table names at call time, so these bodies still point at
-- the OLD schema even after SET SCHEMA. CREATE OR REPLACE updates them.

-- Used by site.sites BEFORE INSERT trigger: pick the default theme.
CREATE OR REPLACE FUNCTION core.set_default_theme_for_site()
RETURNS trigger
LANGUAGE plpgsql
SET search_path TO 'pg_catalog'
AS $$
begin
  if new.theme_id is null then
    select id
    into new.theme_id
    from core.themes
    order by is_default desc, created_at
    limit 1;

    if new.theme_id is null then
      raise exception 'Cannot create site: no themes exist in core.themes';
    end if;
  end if;

  return new;
end;
$$;

-- AFTER UPDATE on core.users handle: write old handle into the alias table.
CREATE OR REPLACE FUNCTION core.trg_professional_handle_change()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_reclaim_days int := 14;
    v_redirect_days int := 90;
BEGIN
    INSERT INTO core.professional_handle_aliases
        (professional_id, handle, reclaim_until, expires_at)
    VALUES
        (NEW.id,
         OLD.handle,
         now() + (v_reclaim_days || ' days')::interval,
         now() + (v_redirect_days || ' days')::interval)
    ON CONFLICT DO NOTHING;

    RETURN NEW;
END;
$$;

-- BEFORE UPDATE on core.users handle: reject renames into a still-active alias.
CREATE OR REPLACE FUNCTION core.trg_professional_handle_alias_check()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_blocking_pro uuid;
BEGIN
    IF NEW.handle IS NOT DISTINCT FROM OLD.handle THEN
        RETURN NEW;
    END IF;

    SELECT professional_id INTO v_blocking_pro
      FROM core.professional_handle_aliases
     WHERE LOWER(handle) = LOWER(NEW.handle)
       AND professional_id <> NEW.id
       AND (expires_at IS NULL OR expires_at > now())
     LIMIT 1;

    IF v_blocking_pro IS NOT NULL THEN
        RAISE EXCEPTION 'Handle % is reserved as a redirect for another professional', NEW.handle
            USING ERRCODE = '23505';
    END IF;

    RETURN NEW;
END;
$$;

-- Cosmetic: error messages reference the new schema names.
CREATE OR REPLACE FUNCTION core.trg_handle_change_log_append_only()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'audit.handle_change_log is append-only' USING ERRCODE = '42501';
END;
$$;

CREATE OR REPLACE FUNCTION core.reject_staff_audit_log_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'audit.staff_audit_log is append-only (OPS-2). UPDATE and DELETE are not permitted.';
END;
$$;

-- --------------------------------------------------------------------------
-- 4. Enforce append-only on audit at the GRANT level
-- --------------------------------------------------------------------------
-- The baseline grants core (SELECT,INSERT,UPDATE,DELETE) to app_backend on
-- ALL TABLES. SET SCHEMA preserves those grants, so audit tables still have
-- full CRUD. Revoke UPDATE/DELETE to enforce append-only at the role level
-- (defense in depth alongside the existing reject-mutation triggers).
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'REVOKE UPDATE, DELETE ON ALL TABLES IN SCHEMA audit FROM app_backend';
        -- Future audit tables: default to SELECT + INSERT only.
        EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA audit GRANT SELECT, INSERT ON TABLES TO app_backend';
    END IF;
END;
$$;

-- service_role: also restricted to append-only on audit.
REVOKE UPDATE, DELETE ON ALL TABLES IN SCHEMA audit FROM service_role;
ALTER DEFAULT PRIVILEGES IN SCHEMA audit GRANT SELECT, INSERT ON TABLES TO service_role;

COMMIT;
