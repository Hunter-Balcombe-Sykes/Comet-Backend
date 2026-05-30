-- ==========================================================================
-- Partna Standalone-User Baseline Schema
-- Generated: 2026-05-26 (Task 7 of the standalone-pages backend strip)
--
-- Replaces all 146 incremental migrations from 20260403000000_v2_baseline.sql
-- to 20260525000000_rls_policy_sweep.sql (archived to supabase/migrations-archive/).
--
-- This is the schema those migrations would produce IF applied, restricted to
-- the surviving (KEEP) objects per audits/standalone-pages/STRIP-LEDGER.md
-- Section 5, with:
--   * core.professionals renamed to core.users (Task 5). FK *columns* stay
--     named professional_id — only the table name changed.
--   * brand / commerce / billing schemas dropped entirely.
--   * Shopify / Square / Fresha / Stripe / wallet / commission columns dropped.
--   * Stale staff-table references (core.comet_staff / core.sidest_staff)
--     rewritten to core.partna_staff.
--
-- Schemas: core, site, notifications, analytics, public
-- Runtime role: app_backend (NOLOGIN; password + LOGIN set out-of-band).
-- ==========================================================================

BEGIN;

-- ==========================================================================
-- 1. SCHEMA CREATION
-- ==========================================================================

CREATE SCHEMA IF NOT EXISTS core;
CREATE SCHEMA IF NOT EXISTS site;
CREATE SCHEMA IF NOT EXISTS notifications;
CREATE SCHEMA IF NOT EXISTS analytics;
-- public schema exists by default

ALTER SCHEMA core OWNER TO postgres;
ALTER SCHEMA site OWNER TO postgres;
ALTER SCHEMA notifications OWNER TO postgres;
ALTER SCHEMA analytics OWNER TO postgres;

-- ==========================================================================
-- 1b. RUNTIME ROLE (existence only — created here so RLS policies below can
--     reference it). On a fresh DB the role must exist before any
--     CREATE POLICY ... TO app_backend runs (first such policy is far above
--     the full grant block near the end of this file). BYPASSRLS + schema/
--     table grants are applied later, after all tables exist.
-- ==========================================================================
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        -- NOLOGIN; password + LOGIN set out-of-band post-migration:
        --   ALTER ROLE app_backend WITH LOGIN PASSWORD '<from-secret-store>';
        CREATE ROLE app_backend NOLOGIN;
    END IF;
END $$;

-- ==========================================================================
-- 2. SHARED FUNCTIONS
-- ==========================================================================

CREATE OR REPLACE FUNCTION public.set_updated_at()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$;

ALTER FUNCTION public.set_updated_at() OWNER TO postgres;

CREATE OR REPLACE FUNCTION core.set_updated_at()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    NEW.updated_at = now();
    RETURN NEW;
END;
$$;

-- Prevent non-admin staff from escalating roles.
-- Carries the FIXED body from 20260524000000 (partna_staff, not the stale
-- comet_staff reference from the v2 baseline). Fires on every partna_staff
-- UPDATE — a stale table reference here gives 42P01.
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

ALTER FUNCTION core.prevent_staff_escalation() OWNER TO postgres;

-- Auto-assign default theme on site creation.
CREATE OR REPLACE FUNCTION core.set_default_theme_for_site()
RETURNS trigger
LANGUAGE plpgsql
SET search_path TO 'pg_catalog'
AS $$
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
$$;

ALTER FUNCTION core.set_default_theme_for_site() OWNER TO postgres;

-- Enforce max 6 gallery media per site (failed-processing rows excluded).
-- Carries the FIXED body from 20260513300000.
CREATE OR REPLACE FUNCTION core.enforce_site_gallery_max6()
RETURNS trigger
LANGUAGE plpgsql
SET search_path TO 'pg_catalog'
AS $$
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
$$;

ALTER FUNCTION core.enforce_site_gallery_max6() OWNER TO postgres;

-- URL composition: single source of truth for a user's public URL.
-- Brand-free since 20260509100000 (queries site.sites only).
CREATE OR REPLACE FUNCTION site.compute_professional_url(p_professional_id uuid)
RETURNS text LANGUAGE plpgsql STABLE AS $$
DECLARE
    v_subdomain text;
BEGIN
    SELECT s.subdomain INTO v_subdomain
      FROM site.sites s
     WHERE s.professional_id = p_professional_id;

    IF v_subdomain IS NULL THEN
        RETURN NULL;
    END IF;

    RETURN 'https://' || v_subdomain || '.partna.au';
END;
$$;

-- Recompute partna_url on the owning user when their site subdomain changes.
-- Brand cascade removed (no brand_partner_links table in standalone mode).
CREATE OR REPLACE FUNCTION site.trg_recompute_partna_url(p_professional_id uuid)
RETURNS void LANGUAGE plpgsql AS $$
DECLARE
    v_url text;
BEGIN
    v_url := site.compute_professional_url(p_professional_id);

    UPDATE core.users
       SET partna_url = v_url
     WHERE id = p_professional_id;
END;
$$;

-- Trigger fn: site.sites INSERT or UPDATE OF subdomain -> recompute URL.
CREATE OR REPLACE FUNCTION site.trg_sites_url_sync()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    PERFORM site.trg_recompute_partna_url(NEW.professional_id);
    RETURN NEW;
END;
$$;

-- Trigger fn (AFTER): core.users UPDATE OF handle -> record the old handle as
-- an alias with the grace/redirect lifecycle window. The brand affiliate-path
-- recompute (PERFORM site.trg_recompute_affiliate_path) is removed — there is
-- no brand_partner_links table in standalone mode.
CREATE OR REPLACE FUNCTION core.trg_professional_handle_change()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_reclaim_days int := 14;
    v_redirect_days int := 90;
BEGIN
    INSERT INTO site.professional_handle_aliases
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

-- Trigger fn (BEFORE): core.users UPDATE OF handle -> reject a rename into a
-- handle that is still actively aliased (non-expired) to another user.
CREATE OR REPLACE FUNCTION core.trg_professional_handle_alias_check()
RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE
    v_blocking_pro uuid;
BEGIN
    IF NEW.handle IS NOT DISTINCT FROM OLD.handle THEN
        RETURN NEW;
    END IF;

    SELECT professional_id INTO v_blocking_pro
      FROM site.professional_handle_aliases
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

-- Append-only enforcement for core.handle_change_log.
CREATE OR REPLACE FUNCTION core.trg_handle_change_log_append_only()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'core.handle_change_log is append-only' USING ERRCODE = '42501';
END;
$$;

-- Append-only enforcement for core.staff_audit_log (OPS-2 layer 3 of 3).
CREATE OR REPLACE FUNCTION core.reject_staff_audit_log_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'core.staff_audit_log is append-only (OPS-2). UPDATE and DELETE are not permitted.';
END;
$$;

-- Per-table updated_at trigger fns (kept verbatim from v2 baseline).
CREATE OR REPLACE FUNCTION core.set_media_variants_updated_at()
RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN NEW.updated_at = now(); RETURN NEW; END; $$;

CREATE OR REPLACE FUNCTION core.set_professional_confirmation_preferences_updated_at()
RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN NEW.updated_at = now(); RETURN NEW; END; $$;

-- ==========================================================================
-- 3. CORE TABLES
-- ==========================================================================

-- core.users (renamed from core.professionals — Task 5).
-- EDITS applied vs the v2 baseline + later migrations:
--   * dropped: professional_type, all stripe_*, payout_method, qr_slug,
--     headshot_*, icon_* columns (and their constraints/indexes).
--   * dropped: account_type dual-write trigger.
--   * kept: account_type column (decision 10) — constant 'individual'.
--   * folded in: deletion_* columns, about, partna_url, admin_notes,
--     account_type, status CHECK.
CREATE TABLE IF NOT EXISTS core.users (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    auth_user_id uuid NOT NULL,
    handle text NOT NULL,
    display_name text NOT NULL,
    bio text,
    country_code text,
    timezone text,
    status text DEFAULT 'active' NOT NULL,
    onboarding_step integer DEFAULT 0 NOT NULL,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL,
    phone text,
    primary_email text NOT NULL,
    first_name text NOT NULL,
    last_name text,
    public_contact_number text,
    public_contact_email text,
    location_street_address text,
    location_postcode text,
    location_city text,
    location_state text,
    location_country text,
    handle_lc text NOT NULL,
    deleted_at timestamptz,
    account_type text NOT NULL DEFAULT 'individual',
    deletion_token_hash text,
    deletion_requested_at timestamptz,
    deletion_confirmed_at timestamptz,
    deletion_previous_status text,
    about jsonb NOT NULL DEFAULT '{}'::jsonb,
    partna_url text,
    admin_notes text,
    CONSTRAINT users_pkey PRIMARY KEY (id),
    CONSTRAINT users_auth_user_id_fkey FOREIGN KEY (auth_user_id) REFERENCES auth.users(id) ON DELETE CASCADE,
    CONSTRAINT users_status_check CHECK (status IN ('active', 'suspended', 'disabled', 'pending_deletion')),
    CONSTRAINT users_about_is_object CHECK (jsonb_typeof(about) = 'object'),
    CONSTRAINT users_account_type_check CHECK (account_type = 'individual')
);

ALTER TABLE core.users OWNER TO postgres;

CREATE UNIQUE INDEX users_auth_user_id_unique ON core.users (auth_user_id) WHERE (deleted_at IS NULL);
CREATE UNIQUE INDEX core_users_handle_lc_unique ON core.users (handle_lc) WHERE (deleted_at IS NULL);
CREATE UNIQUE INDEX users_email_unique ON core.users (primary_email) WHERE (deleted_at IS NULL);
CREATE UNIQUE INDEX users_public_contact_email_unique ON core.users (public_contact_email) WHERE (public_contact_email IS NOT NULL);
CREATE UNIQUE INDEX users_public_contact_number_unique ON core.users (public_contact_number) WHERE (public_contact_number IS NOT NULL);
CREATE INDEX users_deleted_at_idx ON core.users (deleted_at) WHERE (deleted_at IS NULL);
CREATE INDEX users_email_search_idx ON core.users (lower(primary_email));
CREATE INDEX idx_users_deletion_token_hash ON core.users (deletion_token_hash) WHERE deletion_token_hash IS NOT NULL;
CREATE INDEX idx_users_pending_deletion_cutoff ON core.users (deletion_confirmed_at) WHERE status = 'pending_deletion';
CREATE INDEX users_partna_url_idx ON core.users (partna_url) WHERE partna_url IS NOT NULL;
CREATE INDEX users_account_type_idx ON core.users (account_type);

COMMENT ON COLUMN core.users.public_contact_number IS
  'Optional contact number the user chooses to display publicly on their site. NULL = not shared.';
COMMENT ON COLUMN core.users.public_contact_email IS
  'Optional contact email the user chooses to display publicly on their site. NULL = not shared. Distinct from primary_email which is never exposed publicly.';
COMMENT ON COLUMN core.users.admin_notes IS
  'Staff-only free-text notes. Exposed via the staff resource only — never through /me.';

-- core.partna_staff (renamed from comet_staff -> sidest_staff -> partna_staff).
CREATE TABLE IF NOT EXISTS core.partna_staff (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    auth_user_id uuid NOT NULL,
    role text DEFAULT 'support' NOT NULL,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL,
    primary_email text,
    name text,
    phone text,
    CONSTRAINT partna_staff_pkey PRIMARY KEY (id),
    CONSTRAINT "partna_staff_Primary Email_key" UNIQUE (primary_email),
    CONSTRAINT partna_staff_auth_user_id_key UNIQUE (auth_user_id),
    CONSTRAINT partna_staff_auth_user_id_fkey FOREIGN KEY (auth_user_id) REFERENCES auth.users(id) ON DELETE CASCADE,
    CONSTRAINT partna_staff_role_check CHECK (role IN ('admin', 'support'))
);

ALTER TABLE core.partna_staff OWNER TO postgres;
ALTER TABLE core.partna_staff FORCE ROW LEVEL SECURITY;

-- core.customers
CREATE TABLE IF NOT EXISTS core.customers (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid NOT NULL,
    email text,
    phone text,
    full_name text,
    source text,
    notes text,
    external_id text,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL,
    deleted_at timestamptz,
    marketing_opt_in_cached boolean DEFAULT true,
    CONSTRAINT customers_pkey PRIMARY KEY (id),
    CONSTRAINT customers_professional_fk FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE CASCADE
);

ALTER TABLE core.customers OWNER TO postgres;

CREATE UNIQUE INDEX customers_professional_email_unique ON core.customers (professional_id, lower(email)) WHERE (email IS NOT NULL);
CREATE UNIQUE INDEX customers_professional_phone_unique ON core.customers (professional_id, phone) WHERE (phone IS NOT NULL);
CREATE INDEX customers_professional_deleted_at_idx ON core.customers (professional_id, deleted_at);
CREATE INDEX customers_professional_email_search_idx ON core.customers (professional_id, lower(email)) WHERE (email IS NOT NULL AND deleted_at IS NULL);
CREATE INDEX customers_professional_id_idx ON core.customers (professional_id);
CREATE INDEX customers_professional_name_search_idx ON core.customers (professional_id, lower(full_name)) WHERE (full_name IS NOT NULL AND deleted_at IS NULL);
CREATE INDEX customers_professional_phone_search_idx ON core.customers (professional_id, phone) WHERE (phone IS NOT NULL AND deleted_at IS NULL);
CREATE INDEX customers_marketing_opt_in_cached_idx ON core.customers (professional_id, marketing_opt_in_cached) WHERE (marketing_opt_in_cached IS NOT NULL);

-- core.waitlist_signups
CREATE TABLE IF NOT EXISTS core.waitlist_signups (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    name text NOT NULL,
    email text NOT NULL,
    email_lc text NOT NULL,
    phone text NOT NULL,
    applicant_type text NOT NULL,
    applicant_type_other text NULL,
    industry text NOT NULL,
    industry_other text NULL,
    pilot_program_opt_in boolean NOT NULL DEFAULT false,
    number_of_team_members integer NULL,
    consent_source text NOT NULL DEFAULT 'waitlist_form',
    consent_ip_hash text NULL,
    consent_user_agent text NULL,
    last_submitted_at timestamptz NOT NULL DEFAULT now(),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT waitlist_signups_pkey PRIMARY KEY (id),
    CONSTRAINT waitlist_signups_type_check CHECK (applicant_type IN ('influencer', 'professional', 'other')),
    CONSTRAINT waitlist_signups_industry_check CHECK (
        industry IN ('mens_grooming', 'womens_haircare', 'beauty_products', 'vitamins_and_supplements', 'services_and_software', 'other')
    ),
    CONSTRAINT waitlist_signups_team_members_non_negative CHECK (number_of_team_members IS NULL OR number_of_team_members >= 0),
    CONSTRAINT waitlist_signups_type_other_required CHECK (
        (applicant_type = 'other' AND applicant_type_other IS NOT NULL AND btrim(applicant_type_other) <> '')
        OR (applicant_type <> 'other' AND applicant_type_other IS NULL)
    ),
    CONSTRAINT waitlist_signups_industry_other_required CHECK (
        (industry = 'other' AND industry_other IS NOT NULL AND btrim(industry_other) <> '')
        OR (industry <> 'other' AND industry_other IS NULL)
    )
);

ALTER TABLE core.waitlist_signups OWNER TO postgres;

CREATE UNIQUE INDEX waitlist_signups_email_lc_unique ON core.waitlist_signups (email_lc);
CREATE INDEX waitlist_signups_last_submitted_idx ON core.waitlist_signups (last_submitted_at DESC);
CREATE INDEX waitlist_signups_type_idx ON core.waitlist_signups (applicant_type);
CREATE INDEX waitlist_signups_industry_idx ON core.waitlist_signups (industry);
CREATE INDEX waitlist_signups_pilot_opt_in_idx ON core.waitlist_signups (pilot_program_opt_in);

-- core.professional_confirmation_preferences
CREATE TABLE IF NOT EXISTS core.professional_confirmation_preferences (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid NOT NULL,
    action_key text NOT NULL,
    skip_confirmation boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT professional_confirmation_preferences_pkey PRIMARY KEY (id),
    CONSTRAINT professional_confirmation_preferences_professional_fk FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE CASCADE,
    CONSTRAINT professional_confirmation_preferences_professional_action_uq UNIQUE (professional_id, action_key)
);

CREATE INDEX professional_confirmation_preferences_professional_idx
    ON core.professional_confirmation_preferences (professional_id);

-- core.professional_deletion_audit (survives the user's hard delete).
CREATE TABLE IF NOT EXISTS core.professional_deletion_audit (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid,
    professional_handle_snapshot text NOT NULL,
    professional_email_snapshot text NOT NULL,
    event text NOT NULL,
    ip_address text,
    user_agent text,
    metadata jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    actor_type text NOT NULL,
    actor_id uuid,
    actor_handle_snapshot text,
    reason text,
    CONSTRAINT professional_deletion_audit_pkey PRIMARY KEY (id),
    CONSTRAINT professional_deletion_audit_professional_fk FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE SET NULL,
    CONSTRAINT professional_deletion_audit_event_check CHECK (event IN (
        'requested', 'confirmed', 'cancelled', 'purged', 'purge_failed',
        'admin_initiated', 'admin_cancelled'
    )),
    CONSTRAINT professional_deletion_audit_actor_type_check CHECK (actor_type IN ('professional', 'staff_admin', 'system'))
);

ALTER TABLE core.professional_deletion_audit OWNER TO postgres;

CREATE INDEX idx_pda_professional_id ON core.professional_deletion_audit (professional_id);
CREATE INDEX idx_pda_event_created ON core.professional_deletion_audit (event, created_at DESC);
CREATE INDEX idx_pda_actor_type_created ON core.professional_deletion_audit (actor_type, created_at DESC)
    WHERE actor_type <> 'professional';

-- core.data_export_audit (audit trail for GDPR data exports).
-- triggered_by_staff_id FK rewritten from core.sidest_staff -> core.partna_staff.
CREATE TABLE IF NOT EXISTS core.data_export_audit (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid,
    professional_handle_snapshot text NOT NULL,
    professional_email_snapshot text,
    triggered_by text NOT NULL,
    triggered_by_staff_id uuid,
    recipient_email text NOT NULL,
    send_to text,
    status text NOT NULL DEFAULT 'queued',
    file_path text,
    file_size_bytes bigint,
    file_sha256 text,
    record_counts jsonb,
    error_message text,
    created_at timestamptz DEFAULT now() NOT NULL,
    completed_at timestamptz,
    CONSTRAINT data_export_audit_pkey PRIMARY KEY (id),
    CONSTRAINT data_export_audit_professional_fk FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE SET NULL,
    CONSTRAINT data_export_audit_staff_fk FOREIGN KEY (triggered_by_staff_id) REFERENCES core.partna_staff(id) ON DELETE SET NULL,
    CONSTRAINT data_export_audit_triggered_by_chk CHECK (triggered_by IN ('self', 'staff')),
    CONSTRAINT data_export_audit_send_to_chk CHECK (send_to IS NULL OR send_to IN ('professional', 'staff')),
    CONSTRAINT data_export_audit_status_chk CHECK (status IN ('queued', 'processing', 'completed', 'failed'))
);

ALTER TABLE core.data_export_audit OWNER TO postgres;

CREATE INDEX data_export_audit_professional_status_created_idx
    ON core.data_export_audit (professional_id, status, created_at DESC);
CREATE INDEX data_export_audit_triggered_by_staff_idx
    ON core.data_export_audit (triggered_by_staff_id) WHERE triggered_by_staff_id IS NOT NULL;

-- core.feature_flags
CREATE TABLE IF NOT EXISTS core.feature_flags (
    key text NOT NULL,
    description text NOT NULL DEFAULT '',
    default_enabled boolean NOT NULL DEFAULT false,
    rollout_percent smallint NOT NULL DEFAULT 0,
    deleted_at timestamptz NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT feature_flags_pkey PRIMARY KEY (key),
    CONSTRAINT feature_flags_rollout_percent_range CHECK (rollout_percent >= 0 AND rollout_percent <= 100),
    CONSTRAINT feature_flags_key_length CHECK (length(key) <= 128),
    CONSTRAINT feature_flags_key_format CHECK (key ~ '^[a-z][a-z0-9_]*$')
);

CREATE INDEX feature_flags_active ON core.feature_flags (key) WHERE deleted_at IS NULL;

-- core.feature_flag_overrides
-- EDITS vs the original (20260518010000): brand_id column + the scope_xor
-- constraint + 2 brand indexes dropped; the surviving scope constraint is a
-- plain professional_id-not-null check. created_by FK targets partna_staff
-- (per 20260519010001), not users.
CREATE TABLE IF NOT EXISTS core.feature_flag_overrides (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    flag_key text NOT NULL,
    professional_id uuid NULL,
    enabled boolean NOT NULL,
    reason text NULL,
    expires_at timestamptz NULL,
    created_by uuid NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT feature_flag_overrides_pkey PRIMARY KEY (id),
    CONSTRAINT feature_flag_overrides_flag_key_fkey FOREIGN KEY (flag_key) REFERENCES core.feature_flags(key) ON DELETE CASCADE,
    CONSTRAINT feature_flag_overrides_professional_id_fkey FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE CASCADE,
    CONSTRAINT feature_flag_overrides_created_by_fkey FOREIGN KEY (created_by) REFERENCES core.partna_staff(id) ON DELETE SET NULL,
    CONSTRAINT feature_flag_overrides_scope_set CHECK (professional_id IS NOT NULL)
);

CREATE UNIQUE INDEX feature_flag_overrides_pro_unique
    ON core.feature_flag_overrides (flag_key, professional_id);
CREATE INDEX feature_flag_overrides_pro_lookup
    ON core.feature_flag_overrides (professional_id, flag_key)
    WHERE professional_id IS NOT NULL;
CREATE INDEX feature_flag_overrides_expires_at
    ON core.feature_flag_overrides (expires_at)
    WHERE expires_at IS NOT NULL;
CREATE INDEX feature_flag_overrides_flag_key_created
    ON core.feature_flag_overrides (flag_key, created_at DESC);

-- core.staff_audit_log (OPS-2: append-only audit log of staff writes).
CREATE TABLE IF NOT EXISTS core.staff_audit_log (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    staff_id uuid NULL,
    staff_email_snapshot text NULL,
    impersonator_staff_id uuid NULL,
    impersonator_email_snapshot text NULL,
    professional_id uuid NULL,
    professional_handle_snapshot text NULL,
    route text NOT NULL,
    http_method text NOT NULL,
    status_code smallint NOT NULL,
    payload_summary jsonb NOT NULL DEFAULT '{}'::jsonb,
    ip inet NULL,
    user_agent text NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT staff_audit_log_pkey PRIMARY KEY (id),
    CONSTRAINT staff_audit_log_staff_fk FOREIGN KEY (staff_id) REFERENCES core.partna_staff(id) ON DELETE SET NULL,
    CONSTRAINT staff_audit_log_impersonator_fk FOREIGN KEY (impersonator_staff_id) REFERENCES core.partna_staff(id) ON DELETE SET NULL,
    CONSTRAINT staff_audit_log_professional_fk FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE SET NULL,
    CONSTRAINT staff_audit_log_http_method_check CHECK (http_method IN ('POST', 'PATCH', 'PUT', 'DELETE')),
    CONSTRAINT staff_audit_log_status_code_check CHECK (status_code BETWEEN 100 AND 599)
);

CREATE INDEX idx_staff_audit_log_staff_created
    ON core.staff_audit_log (staff_id, created_at DESC);
CREATE INDEX idx_staff_audit_log_professional_created
    ON core.staff_audit_log (professional_id, created_at DESC)
    WHERE professional_id IS NOT NULL;

-- core.auth_factor_events (append-only MFA factor lifecycle audit log).
CREATE TABLE IF NOT EXISTS core.auth_factor_events (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    user_id uuid NOT NULL,
    session_id uuid,
    event_type text NOT NULL,
    factor_id uuid,
    factor_type text,
    ip inet,
    user_agent text,
    metadata jsonb NOT NULL DEFAULT '{}'::jsonb,
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT auth_factor_events_pkey PRIMARY KEY (id),
    CONSTRAINT auth_factor_events_event_type_check CHECK (event_type IN (
        'enroll_started', 'enroll_completed', 'unenroll', 'challenge_issued',
        'verify_success', 'verify_failed', 'verify_rejected_by_hook'
    )),
    CONSTRAINT auth_factor_events_factor_type_check CHECK (
        factor_type IS NULL OR factor_type IN ('totp', 'phone', 'webauthn', 'recovery')
    )
);

CREATE INDEX auth_factor_events_user_created_idx
    ON core.auth_factor_events (user_id, created_at DESC);
CREATE INDEX auth_factor_events_failed_window_idx
    ON core.auth_factor_events (user_id, factor_id, created_at DESC)
    WHERE event_type IN ('verify_failed', 'verify_rejected_by_hook');

-- core.handle_change_log (append-only handle-rename audit log).
-- professional_id FK is ON DELETE SET NULL (per 20260523000000) so audit rows
-- survive the user's hard delete.
CREATE TABLE IF NOT EXISTS core.handle_change_log (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid,
    old_handle text,
    new_handle text NOT NULL,
    reason text NOT NULL,
    actor_id uuid,
    ip_address inet,
    user_agent text,
    changed_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT handle_change_log_pkey PRIMARY KEY (id),
    CONSTRAINT handle_change_log_professional_id_fkey FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE SET NULL,
    CONSTRAINT handle_change_log_reason_check CHECK (reason IN ('rename', 'reclaim', 'staff_rename', 'system'))
);

CREATE INDEX handle_change_log_pro_changed_idx ON core.handle_change_log (professional_id, changed_at DESC);
CREATE INDEX handle_change_log_changed_at_idx ON core.handle_change_log (changed_at DESC);

-- ==========================================================================
-- 4. SITE TABLES
-- ==========================================================================

-- site.themes
CREATE TABLE IF NOT EXISTS site.themes (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    key text NOT NULL,
    name text NOT NULL,
    description text,
    config jsonb DEFAULT '{}'::jsonb NOT NULL,
    is_default boolean DEFAULT false NOT NULL,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL,
    CONSTRAINT themes_pkey PRIMARY KEY (id),
    CONSTRAINT themes_key_key UNIQUE (key)
);

ALTER TABLE site.themes OWNER TO postgres;

CREATE UNIQUE INDEX themes_single_default ON site.themes (is_default) WHERE (is_default = true);

-- site.sites
-- EDITS vs v2 baseline: banner_* columns + their constraint dropped
-- (20260511100000); unpublished_at added (20260513000000).
CREATE TABLE IF NOT EXISTS site.sites (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid NOT NULL,
    subdomain text NOT NULL,
    theme_id uuid,
    is_published boolean DEFAULT false NOT NULL,
    settings jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL,
    subdomain_changed_at timestamptz,
    unpublished_at timestamptz,
    CONSTRAINT sites_pkey PRIMARY KEY (id),
    CONSTRAINT sites_professional_fk FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE CASCADE,
    CONSTRAINT sites_theme_fk FOREIGN KEY (theme_id) REFERENCES site.themes(id) ON DELETE SET NULL
);

ALTER TABLE site.sites OWNER TO postgres;

CREATE UNIQUE INDEX sites_professional_unique ON site.sites (professional_id);
CREATE UNIQUE INDEX core_sites_subdomain_lower_unique ON site.sites (lower(subdomain));
CREATE INDEX idx_sites_theme_id ON site.sites (theme_id) WHERE theme_id IS NOT NULL;

COMMENT ON COLUMN site.sites.unpublished_at IS
  'Set by AccountDeletionService when a pending_deletion transition forces is_published=false. NULL means the site was manually unpublished.';

-- site.blocks
-- EDITS vs v2 baseline: block_type CHECK constraint added (20260519010002);
-- the 3 partial unique indexes carry the deleted_at IS NULL predicate
-- (20260524000100); the redundant link_blocks_professional_id_idx is dropped
-- (20260427000000).
CREATE TABLE IF NOT EXISTS site.blocks (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid NOT NULL,
    site_id uuid NOT NULL,
    block_type text DEFAULT 'link' NOT NULL,
    title text,
    url text,
    icon_key text,
    sort_order integer DEFAULT 0 NOT NULL,
    is_active boolean DEFAULT true NOT NULL,
    settings jsonb DEFAULT '{}'::jsonb NOT NULL,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL,
    block_group text DEFAULT 'links' NOT NULL,
    deleted_at timestamptz,
    is_enabled boolean NOT NULL DEFAULT false,
    CONSTRAINT link_blocks_pkey PRIMARY KEY (id),
    CONSTRAINT blocks_professional_fk FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE CASCADE,
    CONSTRAINT blocks_site_fk FOREIGN KEY (site_id) REFERENCES site.sites(id) ON DELETE CASCADE,
    CONSTRAINT link_blocks_block_group_check CHECK (block_group = ANY (ARRAY['links', 'sections'])),
    CONSTRAINT blocks_block_type_check CHECK (block_type IN (
        'link',
        'gallery', 'services', 'booking', 'contacts_collection',
        'sitepage_analytics', 'barbershop_info', 'documents', 'newsletter',
        'countdown', 'contact', 'credentials', 'experience', 'bio'
    ))
);

ALTER TABLE site.blocks OWNER TO postgres;

CREATE UNIQUE INDEX blocks_links_site_group_sort_uq ON site.blocks (site_id, block_group, sort_order) WHERE (block_group = 'links' AND deleted_at IS NULL);
CREATE UNIQUE INDEX blocks_sections_site_group_sort_uq ON site.blocks (site_id, block_group, sort_order) WHERE (block_group = 'sections' AND deleted_at IS NULL);
CREATE UNIQUE INDEX blocks_sections_site_group_type_uq ON site.blocks (site_id, block_group, block_type) WHERE (block_group = 'sections' AND deleted_at IS NULL);
CREATE INDEX blocks_site_group_active_idx ON site.blocks (site_id, block_group, sort_order) WHERE (deleted_at IS NULL AND is_active = true);
CREATE INDEX core_link_blocks_professional_sort_idx ON site.blocks (professional_id, sort_order);
CREATE INDEX link_blocks_pro_group_sort_idx ON site.blocks (professional_id, block_group, sort_order);
CREATE INDEX link_blocks_site_group_sort_idx ON site.blocks (site_id, block_group, sort_order);
CREATE INDEX link_blocks_site_id_idx ON site.blocks (site_id, sort_order);
CREATE INDEX blocks_site_type_active_idx ON site.blocks (site_id, block_type, sort_order) WHERE deleted_at IS NULL AND is_active = true;
CREATE INDEX idx_blocks_live_check_enabled ON site.blocks ((settings->>'live_check_enabled'))
    WHERE block_group = 'links' AND deleted_at IS NULL AND is_active = true;

-- site.site_media
-- EDITS vs v2 baseline: caption, original_filename, product_gid, purpose
-- columns folded in; media_type CHECK widened to include 'document'; pool
-- CHECK constraint added (20260519010002).
CREATE TABLE IF NOT EXISTS site.site_media (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    site_id uuid NOT NULL,
    bucket text DEFAULT 'public-assets' NOT NULL,
    path text NOT NULL,
    alt_text text,
    sort_order integer DEFAULT 0 NOT NULL,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL,
    deleted_at timestamptz,
    is_active boolean DEFAULT true NOT NULL,
    pool varchar(20) NOT NULL DEFAULT 'gallery',
    media_type varchar(10) NOT NULL DEFAULT 'image',
    processing_state varchar(20) NOT NULL DEFAULT 'pending',
    original_mime varchar(100),
    original_size_bytes bigint,
    duration_ms integer,
    poster_path text,
    processing_error text,
    caption varchar(200) NULL,
    original_filename varchar(255) NULL,
    product_gid text,
    purpose text,
    CONSTRAINT site_media_pkey PRIMARY KEY (id),
    CONSTRAINT site_media_site_fk FOREIGN KEY (site_id) REFERENCES site.sites(id) ON DELETE CASCADE,
    CONSTRAINT site_media_media_type_check CHECK (media_type IN ('image', 'video', 'document')),
    CONSTRAINT site_media_processing_state_check CHECK (processing_state IN ('pending', 'processing', 'ready', 'failed')),
    CONSTRAINT site_media_pool_check CHECK (pool IN ('gallery', 'content', 'design', 'product', 'brand_gallery', 'documents'))
);

ALTER TABLE site.site_media OWNER TO postgres;

CREATE INDEX sm_pool_active ON site.site_media (site_id, pool, is_active);
CREATE INDEX sm_pool_media_active ON site.site_media (site_id, pool, media_type, sort_order) WHERE deleted_at IS NULL AND is_active = true;
CREATE INDEX site_images_site_active_idx ON site.site_media (site_id) WHERE (deleted_at IS NULL);
CREATE UNIQUE INDEX site_images_site_sort_active_unique ON site.site_media (site_id, sort_order) WHERE (deleted_at IS NULL);
CREATE UNIQUE INDEX site_images_site_sort_order_active_uq ON site.site_media (site_id, sort_order) WHERE (deleted_at IS NULL AND is_active = true);
CREATE INDEX site_images_site_sort_idx ON site.site_media (site_id, sort_order);
CREATE INDEX site_media_site_active_sort_covering_idx
    ON site.site_media (site_id, sort_order)
    INCLUDE (alt_text, caption, media_type, pool, original_mime, original_size_bytes, path, original_filename)
    WHERE deleted_at IS NULL AND is_active = true;
CREATE INDEX site_media_product_gid_idx ON site.site_media (site_id, product_gid) WHERE product_gid IS NOT NULL;
CREATE UNIQUE INDEX site_media_design_logo_full_uq ON site.site_media (site_id)
    WHERE pool = 'design' AND purpose = 'logo_full' AND deleted_at IS NULL;
CREATE UNIQUE INDEX site_media_design_logo_square_uq ON site.site_media (site_id)
    WHERE pool = 'design' AND purpose = 'logo_square' AND deleted_at IS NULL;
CREATE UNIQUE INDEX site_media_design_placeholder_sort_uq ON site.site_media (site_id, sort_order)
    WHERE pool = 'design' AND purpose = 'placeholder' AND deleted_at IS NULL AND is_active = true;

-- site.media_variants
CREATE TABLE IF NOT EXISTS site.media_variants (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    media_id uuid NOT NULL,
    variant_key varchar(40) NOT NULL,
    artifact_type varchar(20) NOT NULL,
    disk varchar(40) NOT NULL DEFAULT 'media',
    path text NOT NULL,
    mime varchar(100),
    width integer,
    height integer,
    bitrate_kbps integer,
    file_size_bytes bigint,
    duration_ms integer,
    metadata jsonb,
    content_hash varchar(16),
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT media_variants_pkey PRIMARY KEY (id),
    CONSTRAINT media_variants_media_id_fkey FOREIGN KEY (media_id) REFERENCES site.site_media(id) ON DELETE CASCADE
);

ALTER TABLE site.media_variants OWNER TO postgres;

CREATE UNIQUE INDEX mv_media_variant_artifact ON site.media_variants (media_id, variant_key, artifact_type);
CREATE INDEX mv_media_id ON site.media_variants (media_id);
CREATE INDEX mv_media_artifact_type ON site.media_variants (media_id, artifact_type);

-- site.site_subdomain_aliases
-- EDITS vs v2 baseline: lifecycle columns (reclaim_until, expires_at,
-- notified_t3_at, notified_t1_at) folded in (20260519100000).
CREATE TABLE IF NOT EXISTS site.site_subdomain_aliases (
    id uuid NOT NULL,
    site_id uuid NOT NULL,
    subdomain varchar(63) NOT NULL,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL,
    reclaim_until timestamptz,
    expires_at timestamptz,
    notified_t3_at timestamptz,
    notified_t1_at timestamptz,
    CONSTRAINT site_subdomain_aliases_pkey PRIMARY KEY (id),
    CONSTRAINT site_subdomain_aliases_site_fk FOREIGN KEY (site_id) REFERENCES site.sites(id) ON DELETE CASCADE
);

ALTER TABLE site.site_subdomain_aliases OWNER TO postgres;

CREATE INDEX core_site_subdomain_aliases_site_id_idx ON site.site_subdomain_aliases (site_id);
CREATE UNIQUE INDEX core_site_subdomain_aliases_subdomain_lower_unique ON site.site_subdomain_aliases (lower(subdomain::text));
CREATE INDEX site_subdomain_aliases_expires_at_idx ON site.site_subdomain_aliases (expires_at) WHERE expires_at IS NOT NULL;

-- site.professional_handle_aliases
-- Created in 20260508100000; lifecycle columns added in 20260519100000.
CREATE TABLE IF NOT EXISTS site.professional_handle_aliases (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid NOT NULL,
    handle varchar(63) NOT NULL,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    reclaim_until timestamptz,
    expires_at timestamptz,
    notified_t3_at timestamptz,
    notified_t1_at timestamptz,
    CONSTRAINT professional_handle_aliases_pkey PRIMARY KEY (id),
    CONSTRAINT professional_handle_aliases_professional_id_fkey FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX professional_handle_aliases_handle_lc_uq ON site.professional_handle_aliases (LOWER(handle));
CREATE INDEX professional_handle_aliases_professional_idx ON site.professional_handle_aliases (professional_id);
CREATE INDEX professional_handle_aliases_expires_at_idx ON site.professional_handle_aliases (expires_at) WHERE expires_at IS NOT NULL;

-- site.service_categories
CREATE TABLE IF NOT EXISTS site.service_categories (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid NOT NULL,
    title text NOT NULL,
    sort_order integer NOT NULL DEFAULT 0,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz NULL,
    CONSTRAINT service_categories_pkey PRIMARY KEY (id),
    CONSTRAINT service_categories_professional_fk FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE CASCADE,
    CONSTRAINT service_categories_id_professional_unique UNIQUE (id, professional_id),
    CONSTRAINT service_categories_sort_order_non_negative CHECK (sort_order >= 0)
);

CREATE UNIQUE INDEX service_categories_unique_title_per_professional
    ON site.service_categories (professional_id, lower(title)) WHERE deleted_at IS NULL;
CREATE INDEX service_categories_professional_sort_idx
    ON site.service_categories (professional_id, sort_order);

-- site.services
-- EDITS vs v2 baseline: the 5 square_* + 5 fresha_* columns dropped, along
-- with their indexes (services_square_catalog_object_id_idx,
-- services_square_variation_id_idx, services_fresha_service_id_idx,
-- services_fresha_variation_id_idx) and the two square/fresha variation
-- UNIQUE constraints (decision 1). deleted_origin added (20260504100000).
CREATE TABLE IF NOT EXISTS site.services (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid NOT NULL,
    title text NOT NULL,
    description text,
    category text,
    price_cents integer NOT NULL,
    currency_code char(3) DEFAULT 'AUD' NOT NULL,
    duration_minutes integer,
    is_active boolean DEFAULT true NOT NULL,
    sort_order integer DEFAULT 0 NOT NULL,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL,
    deleted_at timestamptz,
    category_id uuid NULL,
    deleted_origin varchar(16) NULL,
    CONSTRAINT services_pkey PRIMARY KEY (id),
    CONSTRAINT services_professional_fk FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE CASCADE,
    CONSTRAINT services_category_belongs_to_professional
        FOREIGN KEY (category_id, professional_id)
        REFERENCES site.service_categories(id, professional_id)
        ON DELETE SET NULL,
    CONSTRAINT services_duration_minutes_check CHECK ((duration_minutes IS NULL) OR (duration_minutes > 0)),
    CONSTRAINT services_price_cents_check CHECK (price_cents >= 0)
);

ALTER TABLE site.services OWNER TO postgres;

CREATE INDEX services_active_order_idx ON site.services (professional_id, sort_order) WHERE (deleted_at IS NULL);
CREATE INDEX services_pro_active_sort_covering_idx ON site.services (professional_id, sort_order) INCLUDE (title, price_cents, is_active) WHERE (deleted_at IS NULL AND is_active = true);
CREATE INDEX services_prof_sort_idx ON site.services (professional_id, sort_order, created_at);
CREATE INDEX services_professional_id_deleted_at_idx ON site.services (professional_id, deleted_at);
CREATE UNIQUE INDEX services_professional_sort_order_uq ON site.services (professional_id, sort_order) WHERE (deleted_at IS NULL);
CREATE INDEX services_professional_category_sort_idx ON site.services (professional_id, category_id, sort_order);

-- site.enquiries
-- EDITS vs 20260422040000: email_sent_at folded in (20260514200000).
CREATE TABLE IF NOT EXISTS site.enquiries (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid NOT NULL,
    site_id uuid NOT NULL,
    name varchar(100) NOT NULL,
    email varchar(255) NOT NULL,
    phone varchar(30),
    subject varchar(100) NOT NULL,
    message text NOT NULL,
    ip_hash varchar(64),
    user_agent varchar(500),
    read_at timestamptz,
    deleted_at timestamptz,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL,
    email_sent_at timestamptz,
    CONSTRAINT enquiries_pkey PRIMARY KEY (id),
    CONSTRAINT enquiries_professional_fk FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE CASCADE,
    CONSTRAINT enquiries_site_fk FOREIGN KEY (site_id) REFERENCES site.sites(id) ON DELETE CASCADE
);

ALTER TABLE site.enquiries OWNER TO postgres;

CREATE INDEX enquiries_professional_created_idx ON site.enquiries (professional_id, created_at DESC) WHERE deleted_at IS NULL;
CREATE INDEX enquiries_site_idx ON site.enquiries (site_id) WHERE deleted_at IS NULL;
CREATE INDEX enquiries_ip_hash_idx ON site.enquiries (ip_hash, created_at) WHERE deleted_at IS NULL;

-- ==========================================================================
-- 5. NOTIFICATIONS TABLES
-- ==========================================================================

-- notifications.notifications
-- EDITS vs v2 baseline: dedupe_key (20260423010000), email_sent_at
-- (20260514200000), type CHECK constraint (20260519010004) folded in.
CREATE TABLE IF NOT EXISTS notifications.notifications (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid,
    type text NOT NULL,
    title text NOT NULL,
    body text NOT NULL,
    cta_url text,
    severity text DEFAULT 'info' NOT NULL,
    starts_at timestamptz,
    ends_at timestamptz,
    primary_action_label varchar(255) NULL,
    secondary_action_label varchar(255) NULL,
    secondary_action_url text NULL,
    category text NULL,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL,
    dedupe_key text,
    email_sent_at timestamptz,
    CONSTRAINT notifications_pkey PRIMARY KEY (id),
    CONSTRAINT notifications_professional_fk FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE CASCADE,
    CONSTRAINT notifications_severity_check CHECK (severity = ANY (ARRAY['info', 'warning', 'critical'])),
    CONSTRAINT notifications_type_check CHECK (type IN (
        'Success', 'Critical', 'Warning', 'Invitation', 'To do', 'Info'
    ))
);

ALTER TABLE notifications.notifications OWNER TO postgres;

CREATE INDEX notifications_broadcast_active_idx ON notifications.notifications (created_at DESC) WHERE (professional_id IS NULL);
CREATE INDEX notifications_pro_active_idx ON notifications.notifications (professional_id, created_at DESC) WHERE (professional_id IS NOT NULL);
CREATE UNIQUE INDEX notifications_dedupe_key_per_pro_uq ON notifications.notifications (professional_id, dedupe_key) WHERE dedupe_key IS NOT NULL;

-- notifications.notification_receipts
CREATE TABLE IF NOT EXISTS notifications.notification_receipts (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    notification_id uuid NOT NULL,
    professional_id uuid NOT NULL,
    read_at timestamptz,
    dismissed_at timestamptz,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL,
    CONSTRAINT notification_receipts_pkey PRIMARY KEY (id),
    CONSTRAINT notification_receipts_notification_id_fkey FOREIGN KEY (notification_id) REFERENCES notifications.notifications(id) ON DELETE CASCADE,
    CONSTRAINT notification_receipts_professional_id_fkey FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE CASCADE
);

ALTER TABLE notifications.notification_receipts OWNER TO postgres;

CREATE UNIQUE INDEX notification_receipts_notification_professional_uq ON notifications.notification_receipts (notification_id, professional_id);
CREATE INDEX receipts_pro_idx ON notifications.notification_receipts (professional_id, updated_at DESC);
CREATE INDEX receipts_unread_idx ON notifications.notification_receipts (professional_id, notification_id) WHERE (read_at IS NULL AND dismissed_at IS NULL);

-- notifications.notification_email_preferences
CREATE TABLE IF NOT EXISTS notifications.notification_email_preferences (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid NOT NULL,
    category_key text NOT NULL,
    enabled boolean NOT NULL DEFAULT true,
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT notification_email_preferences_pkey PRIMARY KEY (id),
    CONSTRAINT notification_email_preferences_professional_id_fkey FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE CASCADE,
    CONSTRAINT notification_email_preferences_professional_category_uq UNIQUE (professional_id, category_key)
);

-- notifications.notification_email_policies
CREATE TABLE IF NOT EXISTS notifications.notification_email_policies (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid,
    category_key text NOT NULL,
    mode text NOT NULL DEFAULT 'default',
    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT notification_email_policies_pkey PRIMARY KEY (id),
    CONSTRAINT notification_email_policies_professional_id_fkey FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE CASCADE,
    CONSTRAINT notification_email_policies_mode_check CHECK (mode IN ('default', 'force_on', 'force_off'))
);

CREATE UNIQUE INDEX uq_notif_email_policies_global ON notifications.notification_email_policies (category_key) WHERE professional_id IS NULL;
CREATE UNIQUE INDEX uq_notif_email_policies_per_professional ON notifications.notification_email_policies (professional_id, category_key) WHERE professional_id IS NOT NULL;

-- notifications.email_subscriptions
-- EDITS vs v2 baseline: status CHECK constraint added (20260519010002).
CREATE TABLE IF NOT EXISTS notifications.email_subscriptions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid,
    list_key varchar(50) DEFAULT 'marketing' NOT NULL,
    email text NOT NULL,
    full_name text,
    status varchar(20) DEFAULT 'subscribed' NOT NULL,
    subscribed_at timestamptz,
    unsubscribed_at timestamptz,
    unsubscribe_token varchar(80) NOT NULL,
    consent_source varchar(50),
    consent_ip_hash text,
    consent_user_agent text,
    created_at timestamptz DEFAULT now() NOT NULL,
    updated_at timestamptz DEFAULT now() NOT NULL,
    email_lc text NOT NULL,
    qr_slug text,
    CONSTRAINT email_subscriptions_pkey PRIMARY KEY (id),
    CONSTRAINT email_subscriptions_qr_slug_key UNIQUE (qr_slug),
    CONSTRAINT email_subscriptions_professional_fk FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE CASCADE,
    CONSTRAINT email_subscriptions_status_check CHECK (status IN ('subscribed', 'unsubscribed'))
);

ALTER TABLE notifications.email_subscriptions OWNER TO postgres;

CREATE INDEX email_subs_global_list_status_idx ON notifications.email_subscriptions (list_key, status) WHERE (professional_id IS NULL);
CREATE INDEX email_subs_pro_list_status_idx ON notifications.email_subscriptions (professional_id, list_key, status) WHERE (professional_id IS NOT NULL);
CREATE UNIQUE INDEX email_subscriptions_unique_global_list_email_lc ON notifications.email_subscriptions (list_key, email_lc) WHERE (professional_id IS NULL);
CREATE UNIQUE INDEX email_subscriptions_unique_pro_list_email_lc ON notifications.email_subscriptions (professional_id, list_key, email_lc) WHERE (professional_id IS NOT NULL);
CREATE UNIQUE INDEX email_subscriptions_unsubscribe_token_unique ON notifications.email_subscriptions (unsubscribe_token);

-- notifications.broadcast_email_receipts (idempotency sentinel for broadcast emails).
CREATE TABLE IF NOT EXISTS notifications.broadcast_email_receipts (
    notification_id uuid NOT NULL,
    subscription_id uuid NOT NULL,
    email_sent_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT broadcast_email_receipts_pkey PRIMARY KEY (notification_id, subscription_id),
    CONSTRAINT broadcast_email_receipts_notification_id_fkey FOREIGN KEY (notification_id) REFERENCES notifications.notifications(id) ON DELETE CASCADE
);

-- ==========================================================================
-- 6. ANALYTICS TABLES
-- ==========================================================================

-- analytics.site_visits
CREATE TABLE IF NOT EXISTS analytics.site_visits (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid NOT NULL,
    site_id uuid NOT NULL,
    occurred_at timestamptz DEFAULT now() NOT NULL,
    session_id uuid,
    visitor_id uuid,
    ip_hash text,
    user_agent text,
    referrer text,
    utm_source text,
    utm_medium text,
    utm_campaign text,
    created_at timestamptz DEFAULT now() NOT NULL,
    country_code text,
    device_type text,
    CONSTRAINT site_visits_pkey PRIMARY KEY (id),
    CONSTRAINT site_visits_professional_fk FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE CASCADE,
    CONSTRAINT site_visits_site_fk FOREIGN KEY (site_id) REFERENCES site.sites(id) ON DELETE CASCADE
);

ALTER TABLE analytics.site_visits OWNER TO postgres;

-- site_visits_professional_time_idx was dropped as a duplicate (20260509200000);
-- analytics_site_visits_professional_occurred_idx is the canonical equivalent.
CREATE INDEX analytics_site_visits_professional_occurred_idx ON analytics.site_visits (professional_id, occurred_at);
CREATE INDEX site_visits_site_time_idx ON analytics.site_visits (site_id, occurred_at);
CREATE INDEX site_visits_pro_date_range_idx ON analytics.site_visits (professional_id, occurred_at DESC) INCLUDE (country_code, device_type);

-- analytics.link_clicks
CREATE TABLE IF NOT EXISTS analytics.link_clicks (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid NOT NULL,
    site_id uuid NOT NULL,
    link_block_id uuid NOT NULL,
    occurred_at timestamptz DEFAULT now() NOT NULL,
    session_id uuid,
    visitor_id uuid,
    ip_hash text,
    user_agent text,
    referrer text,
    utm_source text,
    utm_medium text,
    utm_campaign text,
    created_at timestamptz DEFAULT now() NOT NULL,
    CONSTRAINT link_clicks_pkey PRIMARY KEY (id),
    CONSTRAINT link_clicks_professional_fk FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE CASCADE,
    CONSTRAINT link_clicks_site_fk FOREIGN KEY (site_id) REFERENCES site.sites(id) ON DELETE CASCADE,
    CONSTRAINT link_clicks_block_fk FOREIGN KEY (link_block_id) REFERENCES site.blocks(id) ON DELETE CASCADE
);

ALTER TABLE analytics.link_clicks OWNER TO postgres;

-- link_clicks_professional_time_idx was dropped as a duplicate (20260511100000).
CREATE INDEX analytics_link_clicks_professional_occurred_idx ON analytics.link_clicks (professional_id, occurred_at);
CREATE INDEX link_clicks_link_time_idx ON analytics.link_clicks (link_block_id, occurred_at);
CREATE INDEX link_clicks_pro_date_range_idx ON analytics.link_clicks (professional_id, occurred_at DESC) INCLUDE (link_block_id);
CREATE INDEX link_clicks_site_time_idx ON analytics.link_clicks (site_id, occurred_at);

-- analytics.lead_submissions
CREATE TABLE IF NOT EXISTS analytics.lead_submissions (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    occurred_at timestamptz DEFAULT now() NOT NULL,
    subdomain text,
    site_id uuid,
    professional_id uuid,
    customer_id uuid,
    ip_hash text,
    user_agent text,
    referrer text,
    outcome text NOT NULL,
    form_started_at_ms bigint,
    CONSTRAINT lead_submissions_pkey PRIMARY KEY (id),
    CONSTRAINT lead_submissions_professional_fk FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE SET NULL,
    CONSTRAINT lead_submissions_site_fk FOREIGN KEY (site_id) REFERENCES site.sites(id) ON DELETE SET NULL,
    CONSTRAINT lead_submissions_customer_fk FOREIGN KEY (customer_id) REFERENCES core.customers(id) ON DELETE SET NULL
);

ALTER TABLE analytics.lead_submissions OWNER TO postgres;

CREATE INDEX lead_submissions_ip_time_idx ON analytics.lead_submissions (ip_hash, occurred_at DESC);
CREATE INDEX lead_submissions_prof_time_idx ON analytics.lead_submissions (professional_id, occurred_at DESC);
CREATE INDEX lead_submissions_site_time_idx ON analytics.lead_submissions (site_id, occurred_at DESC);
CREATE INDEX lead_submissions_customer_id_idx ON analytics.lead_submissions (customer_id) WHERE customer_id IS NOT NULL;

-- analytics.section_views (per-session per-section visibility events).
CREATE TABLE IF NOT EXISTS analytics.section_views (
    id uuid DEFAULT gen_random_uuid() NOT NULL,
    professional_id uuid NOT NULL,
    site_id uuid NOT NULL,
    block_id uuid,
    section_key text NOT NULL,
    occurred_at timestamptz NOT NULL DEFAULT now(),
    session_id uuid,
    visitor_id uuid,
    ip_hash text,
    user_agent text,
    referrer text,
    utm_source text,
    utm_medium text,
    utm_campaign text,
    country_code text,
    device_type text,
    created_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT section_views_pkey PRIMARY KEY (id),
    CONSTRAINT section_views_professional_fk FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE CASCADE,
    CONSTRAINT section_views_site_fk FOREIGN KEY (site_id) REFERENCES site.sites(id) ON DELETE CASCADE,
    CONSTRAINT section_views_block_fk FOREIGN KEY (block_id) REFERENCES site.blocks(id) ON DELETE SET NULL
);

ALTER TABLE analytics.section_views OWNER TO postgres;

CREATE INDEX section_views_professional_occurred_idx ON analytics.section_views (professional_id, occurred_at DESC);
CREATE INDEX section_views_site_section_occurred_idx ON analytics.section_views (site_id, section_key, occurred_at DESC);
CREATE INDEX section_views_session_section_idx ON analytics.section_views (session_id, section_key);

-- analytics.site_metrics_daily
CREATE TABLE IF NOT EXISTS analytics.site_metrics_daily (
    day date NOT NULL,
    professional_id uuid NOT NULL,
    site_id uuid NOT NULL,
    timezone text NOT NULL,
    visits_count integer NOT NULL DEFAULT 0,
    unique_visitors integer NOT NULL DEFAULT 0,
    clicks_count integer NOT NULL DEFAULT 0,
    unique_clickers integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT site_metrics_daily_pkey PRIMARY KEY (day, professional_id, site_id),
    CONSTRAINT site_metrics_daily_professional_fk FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE CASCADE,
    CONSTRAINT site_metrics_daily_site_fk FOREIGN KEY (site_id) REFERENCES site.sites(id) ON DELETE CASCADE
);

CREATE INDEX site_metrics_daily_professional_day_idx ON analytics.site_metrics_daily (professional_id, day DESC);
CREATE INDEX site_metrics_daily_site_day_idx ON analytics.site_metrics_daily (site_id, day DESC);

-- analytics.site_metrics_hourly
CREATE TABLE IF NOT EXISTS analytics.site_metrics_hourly (
    hour_start timestamptz NOT NULL,
    professional_id uuid NOT NULL,
    site_id uuid NOT NULL,
    timezone text NOT NULL,
    visits_count integer NOT NULL DEFAULT 0,
    unique_visitors integer NOT NULL DEFAULT 0,
    clicks_count integer NOT NULL DEFAULT 0,
    unique_clickers integer NOT NULL DEFAULT 0,
    updated_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT site_metrics_hourly_pkey PRIMARY KEY (hour_start, professional_id, site_id),
    CONSTRAINT site_metrics_hourly_professional_fk FOREIGN KEY (professional_id) REFERENCES core.users(id) ON DELETE CASCADE,
    CONSTRAINT site_metrics_hourly_site_fk FOREIGN KEY (site_id) REFERENCES site.sites(id) ON DELETE CASCADE
);

CREATE INDEX site_metrics_hourly_professional_hour_idx ON analytics.site_metrics_hourly (professional_id, hour_start DESC);
CREATE INDEX site_metrics_hourly_site_hour_idx ON analytics.site_metrics_hourly (site_id, hour_start DESC);

-- ==========================================================================
-- 7. PUBLIC TABLES (Laravel queue infrastructure)
-- ==========================================================================

CREATE TABLE IF NOT EXISTS public.failed_jobs (
    id bigint NOT NULL,
    uuid varchar(255) NOT NULL,
    connection text NOT NULL,
    queue text NOT NULL,
    payload text NOT NULL,
    exception text NOT NULL,
    failed_at timestamp(0) without time zone DEFAULT CURRENT_TIMESTAMP NOT NULL,
    CONSTRAINT failed_jobs_pkey PRIMARY KEY (id),
    CONSTRAINT failed_jobs_uuid_unique UNIQUE (uuid)
);

ALTER TABLE public.failed_jobs OWNER TO postgres;

CREATE SEQUENCE IF NOT EXISTS public.failed_jobs_id_seq
    START WITH 1 INCREMENT BY 1 NO MINVALUE NO MAXVALUE CACHE 1;

ALTER SEQUENCE public.failed_jobs_id_seq OWNER TO postgres;
ALTER SEQUENCE public.failed_jobs_id_seq OWNED BY public.failed_jobs.id;
ALTER TABLE ONLY public.failed_jobs ALTER COLUMN id SET DEFAULT nextval('public.failed_jobs_id_seq'::regclass);

CREATE TABLE IF NOT EXISTS public.job_batches (
    id varchar(255) NOT NULL,
    name varchar(255) NOT NULL,
    total_jobs integer NOT NULL,
    pending_jobs integer NOT NULL,
    failed_jobs integer NOT NULL,
    failed_job_ids text NOT NULL,
    options text,
    cancelled_at integer,
    created_at integer NOT NULL,
    finished_at integer,
    CONSTRAINT job_batches_pkey PRIMARY KEY (id)
);

ALTER TABLE public.job_batches OWNER TO postgres;

-- ==========================================================================
-- 8. VIEWS
-- ==========================================================================

-- site.public_site_payload — complete public site payload.
-- Ported from the post-20260422010000 definition (legal-contents JOIN already
-- removed in 20260404000002; caption + document keys added). References
-- rewritten core.professionals -> core.users.
CREATE OR REPLACE VIEW site.public_site_payload
WITH (security_invoker='on') AS
SELECT
  s.id as site_id,
  s.professional_id,
  s.subdomain,
  jsonb_build_object(
    'site', jsonb_build_object(
      'id', s.id,
      'subdomain', s.subdomain,
      'settings', s.settings,
      'is_published', s.is_published,
      'gallery', COALESCE((
        SELECT jsonb_agg(
          jsonb_build_object(
            'id', sm.id,
            'alt_text', sm.alt_text,
            'caption', sm.caption,
            'sort_order', sm.sort_order,
            'variants', COALESCE((
              SELECT jsonb_object_agg(mv.variant_key, mv.path)
              FROM site.media_variants mv
              WHERE mv.media_id = sm.id AND mv.artifact_type = 'webp'
            ), '{}'::jsonb)
          )
          ORDER BY sm.sort_order, sm.created_at
        )
        FROM site.site_media sm
        WHERE sm.site_id = s.id
          AND sm.pool = 'gallery'
          AND sm.media_type = 'image'
          AND sm.deleted_at IS NULL
          AND sm.is_active = true
      ), '[]'::jsonb),
      'content_images', COALESCE((
        SELECT jsonb_agg(
          jsonb_build_object(
            'id', sm.id,
            'alt_text', sm.alt_text,
            'caption', sm.caption,
            'sort_order', sm.sort_order,
            'variants', COALESCE((
              SELECT jsonb_object_agg(mv.variant_key, mv.path)
              FROM site.media_variants mv
              WHERE mv.media_id = sm.id AND mv.artifact_type = 'webp'
            ), '{}'::jsonb)
          )
          ORDER BY sm.sort_order, sm.created_at
        )
        FROM site.site_media sm
        WHERE sm.site_id = s.id
          AND sm.pool = 'content'
          AND sm.media_type = 'image'
          AND sm.deleted_at IS NULL
          AND sm.is_active = true
      ), '[]'::jsonb),
      'gallery_videos', COALESCE((
        SELECT jsonb_agg(
          jsonb_build_object(
            'id', sm.id,
            'alt_text', sm.alt_text,
            'caption', sm.caption,
            'sort_order', sm.sort_order,
            'media_type', sm.media_type,
            'processing_state', sm.processing_state,
            'duration_ms', sm.duration_ms,
            'poster', sm.poster_path,
            'variants', COALESCE((
              SELECT jsonb_object_agg(mv.variant_key, mv.path)
              FROM site.media_variants mv
              WHERE mv.media_id = sm.id AND mv.artifact_type = 'mp4'
            ), '{}'::jsonb),
            'streams', COALESCE((
              SELECT jsonb_object_agg(mv.variant_key, mv.path)
              FROM site.media_variants mv
              WHERE mv.media_id = sm.id AND mv.artifact_type = 'hls_playlist'
            ), '{}'::jsonb)
          )
          ORDER BY sm.sort_order, sm.created_at
        )
        FROM site.site_media sm
        WHERE sm.site_id = s.id
          AND sm.pool = 'gallery'
          AND sm.media_type = 'video'
          AND sm.deleted_at IS NULL
          AND sm.is_active = true
      ), '[]'::jsonb),
      'content_videos', COALESCE((
        SELECT jsonb_agg(
          jsonb_build_object(
            'id', sm.id,
            'alt_text', sm.alt_text,
            'caption', sm.caption,
            'sort_order', sm.sort_order,
            'media_type', sm.media_type,
            'processing_state', sm.processing_state,
            'duration_ms', sm.duration_ms,
            'poster', sm.poster_path,
            'variants', COALESCE((
              SELECT jsonb_object_agg(mv.variant_key, mv.path)
              FROM site.media_variants mv
              WHERE mv.media_id = sm.id AND mv.artifact_type = 'mp4'
            ), '{}'::jsonb),
            'streams', COALESCE((
              SELECT jsonb_object_agg(mv.variant_key, mv.path)
              FROM site.media_variants mv
              WHERE mv.media_id = sm.id AND mv.artifact_type = 'hls_playlist'
            ), '{}'::jsonb)
          )
          ORDER BY sm.sort_order, sm.created_at
        )
        FROM site.site_media sm
        WHERE sm.site_id = s.id
          AND sm.pool = 'content'
          AND sm.media_type = 'video'
          AND sm.deleted_at IS NULL
          AND sm.is_active = true
      ), '[]'::jsonb),
      'document', (
        SELECT jsonb_build_object(
          'id', sm.id,
          'title', sm.alt_text,
          'caption', sm.caption,
          'original_mime', sm.original_mime,
          'original_size_bytes', sm.original_size_bytes,
          'original_filename', sm.original_filename,
          'preview_url', sm.path,
          'created_at', sm.created_at
        )
        FROM site.site_media sm
        WHERE sm.site_id = s.id
          AND sm.pool = 'documents'
          AND sm.media_type = 'document'
          AND sm.deleted_at IS NULL
          AND sm.is_active = true
        LIMIT 1
      )
    ),
    'professional', jsonb_build_object(
      'id', p.id,
      'handle', p.handle,
      'display_name', p.display_name,
      'bio', p.bio,
      'country_code', p.country_code,
      'timezone', p.timezone,
      'public_contact_number', p.public_contact_number,
      'public_contact_email', p.public_contact_email
    ),
    'theme', CASE WHEN t.id IS NULL THEN NULL::jsonb ELSE jsonb_build_object('id', t.id, 'key', t.key, 'name', t.name, 'config', t.config) END,
    'links', COALESCE((
      SELECT jsonb_agg(
        jsonb_build_object(
          'id', b.id,
          'block_type', b.block_type,
          'title', b.title,
          'url', b.url,
          'icon_key', b.icon_key,
          'sort_order', b.sort_order,
          'settings', b.settings
        )
        ORDER BY b.sort_order, b.created_at
      )
      FROM site.blocks b
      WHERE b.site_id = s.id AND b.block_group = 'links' AND b.is_active = true AND b.deleted_at IS NULL
    ), '[]'::jsonb),
    'sections', COALESCE((
      SELECT jsonb_agg(
        jsonb_build_object(
          'id', b.id,
          'block_type', b.block_type,
          'title', b.title,
          'url', b.url,
          'icon_key', b.icon_key,
          'sort_order', b.sort_order,
          'is_enabled', b.is_enabled,
          'is_active', b.is_active,
          'settings', b.settings
        )
        ORDER BY b.sort_order, b.created_at
      )
      FROM site.blocks b
      WHERE b.site_id = s.id AND b.block_group = 'sections' AND b.is_enabled = true AND b.is_active = true AND b.deleted_at IS NULL
    ), '[]'::jsonb),
    'services', COALESCE((
      SELECT jsonb_agg(
        jsonb_build_object(
          'id', sv.id,
          'title', sv.title,
          'description', sv.description,
          'price_cents', sv.price_cents,
          'currency_code', sv.currency_code,
          'duration_minutes', sv.duration_minutes,
          'is_active', sv.is_active,
          'sort_order', sv.sort_order,
          'category', COALESCE(sc.title, 'Services')
        )
        ORDER BY COALESCE(sc.sort_order, 2147483647), LOWER(COALESCE(sc.title, 'Services')), sv.sort_order, sv.created_at
      )
      FROM site.services sv
      LEFT JOIN site.service_categories sc
        ON sc.id = sv.category_id
        AND sc.deleted_at IS NULL
      WHERE sv.professional_id = p.id AND sv.is_active = true AND sv.deleted_at IS NULL
    ), '[]'::jsonb)
  ) as payload
FROM site.sites s
JOIN core.users p ON p.id = s.professional_id
LEFT JOIN site.themes t ON t.id = s.theme_id
WHERE
  s.is_published = true
  AND p.status = 'active'
  AND p.deleted_at IS NULL;

COMMENT ON VIEW site.public_site_payload IS
    'Complete public site payload with two-flag section visibility (is_enabled + is_active). Includes per-image caption + alt_text, plus singular document reference.';

-- site.all_site_data — staff ops dashboard view.
-- Ported from the post-20260521000000 definition with professional_type
-- DROPPED from the SELECT list (column no longer exists); account_type kept.
-- References rewritten core.professionals -> core.users.
CREATE OR REPLACE VIEW site.all_site_data AS
SELECT
    s.id AS site_id,
    s.subdomain,
    s.is_published,
    s.settings AS site_settings,
    s.created_at AS site_created_at,
    s.updated_at AS site_updated_at,
    t.id AS theme_id,
    t.key AS theme_key,
    t.name AS theme_name,
    t.config AS theme_config,
    p.id AS professional_id,
    p.handle AS professional_handle,
    p.display_name AS professional_display_name,
    p.bio AS professional_bio,
    p.location_street_address AS professional_location_street_address,
    p.location_city AS professional_location_city,
    p.location_state AS professional_location_state,
    p.location_postcode AS professional_location_postcode,
    p.location_country AS professional_location_country,
    COALESCE(jsonb_agg(
      jsonb_build_object(
        'id', b.id,
        'site_id', b.site_id,
        'professional_id', b.professional_id,
        'block_type', b.block_type,
        'block_group', b.block_group,
        'title', b.title,
        'url', b.url,
        'icon_key', b.icon_key,
        'sort_order', b.sort_order,
        'is_active', b.is_active,
        'settings', b.settings,
        'created_at', b.created_at,
        'updated_at', b.updated_at
      )
      ORDER BY b.sort_order
    ) FILTER (WHERE b.id IS NOT NULL), '[]'::jsonb) AS blocks,
    p.account_type
FROM site.sites s
JOIN core.users p ON p.id = s.professional_id
LEFT JOIN site.themes t ON t.id = s.theme_id
LEFT JOIN site.blocks b ON b.site_id = s.id
GROUP BY s.id, t.id, p.id;

-- ==========================================================================
-- 9. TRIGGER BINDINGS
-- ==========================================================================

-- Core triggers
CREATE OR REPLACE TRIGGER set_timestamp_users BEFORE UPDATE ON core.users FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE OR REPLACE TRIGGER set_timestamp_partna_staff BEFORE UPDATE ON core.partna_staff FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE OR REPLACE TRIGGER prevent_staff_escalation BEFORE UPDATE ON core.partna_staff FOR EACH ROW EXECUTE FUNCTION core.prevent_staff_escalation();
CREATE OR REPLACE TRIGGER set_timestamp_customers BEFORE UPDATE ON core.customers FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE OR REPLACE TRIGGER trg_waitlist_signups_set_updated_at BEFORE UPDATE ON core.waitlist_signups FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE OR REPLACE TRIGGER trg_professional_confirmation_preferences_set_updated_at BEFORE UPDATE ON core.professional_confirmation_preferences FOR EACH ROW EXECUTE FUNCTION core.set_professional_confirmation_preferences_updated_at();

-- core.users handle-rename triggers
CREATE TRIGGER professional_handle_change_au
    AFTER UPDATE OF handle ON core.users
    FOR EACH ROW
    WHEN (OLD.handle IS DISTINCT FROM NEW.handle)
    EXECUTE FUNCTION core.trg_professional_handle_change();

CREATE TRIGGER professional_handle_alias_check_bu
    BEFORE UPDATE OF handle ON core.users
    FOR EACH ROW
    WHEN (OLD.handle IS DISTINCT FROM NEW.handle)
    EXECUTE FUNCTION core.trg_professional_handle_alias_check();

-- core.handle_change_log append-only enforcement
CREATE TRIGGER handle_change_log_no_update
    BEFORE UPDATE OR DELETE ON core.handle_change_log
    FOR EACH ROW EXECUTE FUNCTION core.trg_handle_change_log_append_only();

-- core.staff_audit_log append-only enforcement (OPS-2 layer 3 of 3)
CREATE TRIGGER staff_audit_log_reject_mutation
    BEFORE UPDATE OR DELETE ON core.staff_audit_log
    FOR EACH ROW EXECUTE FUNCTION core.reject_staff_audit_log_mutation();

-- core.feature_flags / feature_flag_overrides updated_at triggers
CREATE OR REPLACE TRIGGER set_timestamp_feature_flags BEFORE UPDATE ON core.feature_flags FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE OR REPLACE TRIGGER set_timestamp_feature_flag_overrides BEFORE UPDATE ON core.feature_flag_overrides FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- Site triggers
CREATE OR REPLACE TRIGGER set_timestamp_sites BEFORE UPDATE ON site.sites FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE OR REPLACE TRIGGER set_default_theme_on_sites BEFORE INSERT ON site.sites FOR EACH ROW EXECUTE FUNCTION core.set_default_theme_for_site();
CREATE TRIGGER sites_url_sync_aiu AFTER INSERT OR UPDATE OF subdomain ON site.sites FOR EACH ROW EXECUTE FUNCTION site.trg_sites_url_sync();
CREATE OR REPLACE TRIGGER set_timestamp_link_blocks BEFORE UPDATE ON site.blocks FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE OR REPLACE TRIGGER set_timestamp_site_media BEFORE UPDATE ON site.site_media FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE OR REPLACE TRIGGER enforce_site_gallery_max6 BEFORE INSERT OR UPDATE OF site_id, deleted_at ON site.site_media FOR EACH ROW EXECUTE FUNCTION core.enforce_site_gallery_max6();
CREATE OR REPLACE TRIGGER set_timestamp_site_subdomain_aliases BEFORE UPDATE ON site.site_subdomain_aliases FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE OR REPLACE TRIGGER set_timestamp_themes BEFORE UPDATE ON site.themes FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE OR REPLACE TRIGGER trg_media_variants_set_updated_at BEFORE UPDATE ON site.media_variants FOR EACH ROW EXECUTE FUNCTION core.set_media_variants_updated_at();
CREATE OR REPLACE TRIGGER trg_service_categories_updated_at BEFORE UPDATE ON site.service_categories FOR EACH ROW EXECUTE FUNCTION core.set_updated_at();
CREATE OR REPLACE TRIGGER set_timestamp_services BEFORE UPDATE ON site.services FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE OR REPLACE TRIGGER set_timestamp_enquiries BEFORE UPDATE ON site.enquiries FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- Notifications triggers
CREATE OR REPLACE TRIGGER set_timestamp_notifications BEFORE UPDATE ON notifications.notifications FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE OR REPLACE TRIGGER set_timestamp_notification_receipts BEFORE UPDATE ON notifications.notification_receipts FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE OR REPLACE TRIGGER set_timestamp_notification_email_preferences BEFORE UPDATE ON notifications.notification_email_preferences FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE OR REPLACE TRIGGER set_timestamp_notification_email_policies BEFORE UPDATE ON notifications.notification_email_policies FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();
CREATE OR REPLACE TRIGGER set_timestamp_email_subscriptions BEFORE UPDATE ON notifications.email_subscriptions FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- ==========================================================================
-- 10. RLS POLICIES
--
-- All staff-table references rewritten core.comet_staff / core.sidest_staff
-- -> core.partna_staff. Ported from the v2 baseline, 20260420200000, and
-- 20260525000000 for KEEP tables only.
-- ==========================================================================

-- ── core.users ──
ALTER TABLE core.users ENABLE ROW LEVEL SECURITY;

CREATE POLICY users_all_authenticated ON core.users TO authenticated
    USING ((auth_user_id = auth.uid()) OR (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())))
    WITH CHECK ((auth_user_id = auth.uid()) OR (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())));

-- ── core.partna_staff ──
ALTER TABLE core.partna_staff ENABLE ROW LEVEL SECURITY;

CREATE POLICY partna_staff_select_authenticated ON core.partna_staff FOR SELECT TO authenticated
    USING ((auth_user_id = auth.uid()) OR (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid() AND cs.role = 'admin')));

CREATE POLICY partna_staff_insert_admin ON core.partna_staff FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid() AND cs.role = 'admin'));

CREATE POLICY partna_staff_update_authenticated ON core.partna_staff FOR UPDATE TO authenticated
    USING ((auth_user_id = auth.uid()) OR (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid() AND cs.role = 'admin')))
    WITH CHECK ((auth_user_id = auth.uid()) OR (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid() AND cs.role = 'admin')));

CREATE POLICY partna_staff_delete_admin ON core.partna_staff FOR DELETE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid() AND cs.role = 'admin'));

-- ── core.customers ──
ALTER TABLE core.customers ENABLE ROW LEVEL SECURITY;

CREATE POLICY customers_all_authenticated ON core.customers TO authenticated
    USING ((EXISTS (SELECT 1 FROM core.users p WHERE p.id = customers.professional_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())))
    WITH CHECK ((EXISTS (SELECT 1 FROM core.users p WHERE p.id = customers.professional_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())));

-- ── core.waitlist_signups ──
ALTER TABLE core.waitlist_signups ENABLE ROW LEVEL SECURITY;

CREATE POLICY waitlist_public_insert ON core.waitlist_signups
    FOR INSERT TO anon WITH CHECK (true);

CREATE POLICY waitlist_staff_all ON core.waitlist_signups TO authenticated
    USING (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()));

-- ── core.professional_confirmation_preferences ──
ALTER TABLE core.professional_confirmation_preferences ENABLE ROW LEVEL SECURITY;

CREATE POLICY confirmation_prefs_pro_all ON core.professional_confirmation_preferences TO authenticated
    USING (professional_id = (SELECT id FROM core.users WHERE auth_user_id = auth.uid() AND deleted_at IS NULL))
    WITH CHECK (professional_id = (SELECT id FROM core.users WHERE auth_user_id = auth.uid() AND deleted_at IS NULL));

CREATE POLICY confirmation_prefs_staff_all ON core.professional_confirmation_preferences TO authenticated
    USING (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()));

-- ── core.professional_deletion_audit ──
ALTER TABLE core.professional_deletion_audit ENABLE ROW LEVEL SECURITY;

CREATE POLICY professional_deletion_audit_app_backend_all
    ON core.professional_deletion_audit
    FOR ALL TO app_backend
    USING (true) WITH CHECK (true);

CREATE POLICY professional_deletion_audit_staff_select
    ON core.professional_deletion_audit
    FOR SELECT TO authenticated
    USING (EXISTS (
        SELECT 1 FROM core.partna_staff ps
        WHERE ps.auth_user_id = auth.uid() AND ps.role IN ('admin', 'support')
    ));

-- ── core.data_export_audit ──
ALTER TABLE core.data_export_audit ENABLE ROW LEVEL SECURITY;

CREATE POLICY data_export_audit_app_backend_all
    ON core.data_export_audit
    FOR ALL TO app_backend
    USING (true) WITH CHECK (true);

CREATE POLICY data_export_audit_owner_select ON core.data_export_audit
    FOR SELECT TO authenticated
    USING (professional_id = (
        SELECT id FROM core.users WHERE auth_user_id = auth.uid() AND deleted_at IS NULL
    ));

CREATE POLICY data_export_audit_staff_select ON core.data_export_audit
    FOR SELECT TO authenticated
    USING (EXISTS (
        SELECT 1 FROM core.partna_staff ps
        WHERE ps.auth_user_id = auth.uid() AND ps.role IN ('admin', 'support')
    ));

-- ── core.feature_flags ──
ALTER TABLE core.feature_flags ENABLE ROW LEVEL SECURITY;

CREATE POLICY feature_flags_app_backend_all ON core.feature_flags
    FOR ALL TO app_backend
    USING (true) WITH CHECK (true);

CREATE POLICY feature_flags_staff_all ON core.feature_flags
    FOR ALL TO authenticated
    USING (EXISTS (
        SELECT 1 FROM core.partna_staff ps
        WHERE ps.auth_user_id = auth.uid() AND ps.role IN ('admin', 'support')
    ))
    WITH CHECK (EXISTS (
        SELECT 1 FROM core.partna_staff ps
        WHERE ps.auth_user_id = auth.uid() AND ps.role IN ('admin', 'support')
    ));

-- ── core.feature_flag_overrides ──
ALTER TABLE core.feature_flag_overrides ENABLE ROW LEVEL SECURITY;

CREATE POLICY feature_flag_overrides_app_backend_all ON core.feature_flag_overrides
    FOR ALL TO app_backend
    USING (true) WITH CHECK (true);

CREATE POLICY feature_flag_overrides_staff_all ON core.feature_flag_overrides
    FOR ALL TO authenticated
    USING (EXISTS (
        SELECT 1 FROM core.partna_staff ps
        WHERE ps.auth_user_id = auth.uid() AND ps.role IN ('admin', 'support')
    ))
    WITH CHECK (EXISTS (
        SELECT 1 FROM core.partna_staff ps
        WHERE ps.auth_user_id = auth.uid() AND ps.role IN ('admin', 'support')
    ));

-- ── core.staff_audit_log ──
-- Append-only: split INSERT + SELECT policies, never FOR ALL.
ALTER TABLE core.staff_audit_log ENABLE ROW LEVEL SECURITY;

CREATE POLICY staff_audit_log_app_backend_insert ON core.staff_audit_log
    FOR INSERT TO app_backend
    WITH CHECK (true);

CREATE POLICY staff_audit_log_app_backend_select ON core.staff_audit_log
    FOR SELECT TO app_backend
    USING (true);

CREATE POLICY staff_audit_log_staff_select ON core.staff_audit_log
    FOR SELECT TO authenticated
    USING (EXISTS (
        SELECT 1 FROM core.partna_staff ps
        WHERE ps.auth_user_id = auth.uid() AND ps.role IN ('admin', 'support')
    ));

-- ── core.auth_factor_events ──
ALTER TABLE core.auth_factor_events ENABLE ROW LEVEL SECURITY;

CREATE POLICY "service role inserts" ON core.auth_factor_events
    FOR INSERT TO service_role WITH CHECK (true);

CREATE POLICY "service role reads" ON core.auth_factor_events
    FOR SELECT TO service_role USING (true);

-- ── core.handle_change_log ──
ALTER TABLE core.handle_change_log ENABLE ROW LEVEL SECURITY;

CREATE POLICY handle_change_log_app_backend_all ON core.handle_change_log
    FOR ALL TO app_backend
    USING (true) WITH CHECK (true);

CREATE POLICY handle_change_log_staff_select ON core.handle_change_log
    FOR SELECT TO authenticated
    USING (EXISTS (
        SELECT 1 FROM core.partna_staff ps
        WHERE ps.auth_user_id = auth.uid() AND ps.role IN ('admin', 'support')
    ));

-- ── site.sites ──
ALTER TABLE site.sites ENABLE ROW LEVEL SECURITY;

CREATE POLICY sites_select_authenticated ON site.sites FOR SELECT TO authenticated
    USING ((is_published = true)
        OR (EXISTS (SELECT 1 FROM core.users p WHERE p.id = sites.professional_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())));

CREATE POLICY sites_insert_authenticated ON site.sites FOR INSERT TO authenticated
    WITH CHECK ((EXISTS (SELECT 1 FROM core.users p WHERE p.id = sites.professional_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())));

CREATE POLICY sites_update_authenticated ON site.sites FOR UPDATE TO authenticated
    USING ((EXISTS (SELECT 1 FROM core.users p WHERE p.id = sites.professional_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())))
    WITH CHECK ((EXISTS (SELECT 1 FROM core.users p WHERE p.id = sites.professional_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())));

CREATE POLICY sites_delete_authenticated ON site.sites FOR DELETE TO authenticated
    USING ((EXISTS (SELECT 1 FROM core.users p WHERE p.id = sites.professional_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())));

CREATE POLICY sites_public_read_published ON site.sites FOR SELECT TO anon USING (is_published = true);

-- ── site.blocks ──
ALTER TABLE site.blocks ENABLE ROW LEVEL SECURITY;

CREATE POLICY link_blocks_select_authenticated ON site.blocks FOR SELECT TO authenticated
    USING (((is_active = true) AND (EXISTS (SELECT 1 FROM site.sites s WHERE s.id = blocks.site_id AND s.is_published = true)))
        OR (EXISTS (SELECT 1 FROM core.users p WHERE p.id = blocks.professional_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())));

CREATE POLICY link_blocks_insert_authenticated ON site.blocks FOR INSERT TO authenticated
    WITH CHECK ((EXISTS (SELECT 1 FROM core.users p WHERE p.id = blocks.professional_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())));

CREATE POLICY link_blocks_update_authenticated ON site.blocks FOR UPDATE TO authenticated
    USING ((EXISTS (SELECT 1 FROM core.users p WHERE p.id = blocks.professional_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())))
    WITH CHECK ((EXISTS (SELECT 1 FROM core.users p WHERE p.id = blocks.professional_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())));

CREATE POLICY link_blocks_delete_authenticated ON site.blocks FOR DELETE TO authenticated
    USING ((EXISTS (SELECT 1 FROM core.users p WHERE p.id = blocks.professional_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())));

CREATE POLICY link_blocks_public_read_active_published ON site.blocks FOR SELECT TO anon
    USING ((is_active = true) AND (EXISTS (SELECT 1 FROM site.sites s WHERE s.id = blocks.site_id AND s.is_published = true)));

-- ── site.site_media ──
ALTER TABLE site.site_media ENABLE ROW LEVEL SECURITY;

CREATE POLICY site_media_select_authenticated ON site.site_media FOR SELECT TO authenticated
    USING ((EXISTS (SELECT 1 FROM site.sites s JOIN core.users p ON p.id = s.professional_id WHERE s.id = site_media.site_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())));

CREATE POLICY site_media_insert_authenticated ON site.site_media FOR INSERT TO authenticated
    WITH CHECK ((EXISTS (SELECT 1 FROM site.sites s JOIN core.users p ON p.id = s.professional_id WHERE s.id = site_media.site_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())));

CREATE POLICY site_media_update_authenticated ON site.site_media FOR UPDATE TO authenticated
    USING ((EXISTS (SELECT 1 FROM site.sites s JOIN core.users p ON p.id = s.professional_id WHERE s.id = site_media.site_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())))
    WITH CHECK ((EXISTS (SELECT 1 FROM site.sites s JOIN core.users p ON p.id = s.professional_id WHERE s.id = site_media.site_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL))
        OR (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid())));

CREATE POLICY site_media_delete_staff ON site.site_media FOR DELETE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid()));

CREATE POLICY site_media_public_read_published ON site.site_media FOR SELECT TO anon
    USING ((deleted_at IS NULL) AND (EXISTS (SELECT 1 FROM site.sites s WHERE s.id = site_media.site_id AND s.is_published = true)));

-- ── site.media_variants ──
ALTER TABLE site.media_variants ENABLE ROW LEVEL SECURITY;

CREATE POLICY media_variants_pro_all ON site.media_variants TO authenticated
    USING (EXISTS (
        SELECT 1 FROM site.site_media sm
        JOIN site.sites si ON si.id = sm.site_id
        JOIN core.users p ON p.id = si.professional_id
        WHERE sm.id = media_variants.media_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL
    ))
    WITH CHECK (EXISTS (
        SELECT 1 FROM site.site_media sm
        JOIN site.sites si ON si.id = sm.site_id
        JOIN core.users p ON p.id = si.professional_id
        WHERE sm.id = media_variants.media_id AND p.auth_user_id = auth.uid() AND p.deleted_at IS NULL
    ));

CREATE POLICY media_variants_staff_all ON site.media_variants TO authenticated
    USING (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY media_variants_public_read ON site.media_variants FOR SELECT TO anon
    USING (EXISTS (
        SELECT 1 FROM site.site_media sm
        JOIN site.sites si ON si.id = sm.site_id
        WHERE sm.id = media_variants.media_id AND sm.deleted_at IS NULL AND si.is_published = true
    ));

-- ── site.site_subdomain_aliases ──
ALTER TABLE site.site_subdomain_aliases ENABLE ROW LEVEL SECURITY;

CREATE POLICY aliases_pro_all ON site.site_subdomain_aliases TO authenticated
    USING (EXISTS (SELECT 1 FROM site.sites s JOIN core.users p ON p.id = s.professional_id WHERE s.id = site_subdomain_aliases.site_id AND p.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM site.sites s JOIN core.users p ON p.id = s.professional_id WHERE s.id = site_subdomain_aliases.site_id AND p.auth_user_id = auth.uid()));

CREATE POLICY aliases_staff_all ON site.site_subdomain_aliases TO authenticated
    USING (EXISTS (SELECT 1 FROM core.partna_staff WHERE partna_staff.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.partna_staff WHERE partna_staff.auth_user_id = auth.uid()));

CREATE POLICY aliases_public_read ON site.site_subdomain_aliases FOR SELECT TO anon
    USING (EXISTS (SELECT 1 FROM site.sites s WHERE s.id = site_subdomain_aliases.site_id AND s.is_published = true));

-- ── site.professional_handle_aliases ──
ALTER TABLE site.professional_handle_aliases ENABLE ROW LEVEL SECURITY;

CREATE POLICY handle_aliases_owner_select ON site.professional_handle_aliases
    FOR SELECT TO authenticated
    USING (professional_id = (
        SELECT id FROM core.users WHERE auth_user_id = auth.uid() AND deleted_at IS NULL
    ));

CREATE POLICY handle_aliases_staff_select ON site.professional_handle_aliases
    FOR SELECT TO authenticated
    USING (EXISTS (
        SELECT 1 FROM core.partna_staff ps
        WHERE ps.auth_user_id = auth.uid() AND ps.role IN ('admin', 'support')
    ));

-- ── site.themes ──
ALTER TABLE site.themes ENABLE ROW LEVEL SECURITY;

CREATE POLICY themes_select_authenticated ON site.themes FOR SELECT TO authenticated USING (true);
CREATE POLICY themes_public_read ON site.themes FOR SELECT TO anon USING (true);

CREATE POLICY themes_insert_staff ON site.themes FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid()));

CREATE POLICY themes_update_staff ON site.themes FOR UPDATE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid()));

CREATE POLICY themes_delete_staff ON site.themes FOR DELETE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid()));

-- ── site.services ──
ALTER TABLE site.services ENABLE ROW LEVEL SECURITY;

CREATE POLICY services_pro_all ON site.services TO authenticated
    USING (professional_id = (SELECT id FROM core.users WHERE auth_user_id = auth.uid() AND deleted_at IS NULL))
    WITH CHECK (professional_id = (SELECT id FROM core.users WHERE auth_user_id = auth.uid() AND deleted_at IS NULL));

CREATE POLICY services_staff_all ON site.services TO authenticated
    USING (EXISTS (SELECT 1 FROM core.partna_staff WHERE partna_staff.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.partna_staff WHERE partna_staff.auth_user_id = auth.uid()));

CREATE POLICY services_anon_select ON site.services FOR SELECT TO anon
    USING (
        is_active = true
        AND deleted_at IS NULL
        AND EXISTS (
            SELECT 1 FROM site.sites s
            WHERE s.professional_id = site.services.professional_id
              AND s.is_published = true
        )
    );

-- ── site.service_categories ──
ALTER TABLE site.service_categories ENABLE ROW LEVEL SECURITY;

CREATE POLICY service_categories_pro_all ON site.service_categories TO authenticated
    USING (professional_id = (SELECT id FROM core.users WHERE auth_user_id = auth.uid() AND deleted_at IS NULL))
    WITH CHECK (professional_id = (SELECT id FROM core.users WHERE auth_user_id = auth.uid() AND deleted_at IS NULL));

CREATE POLICY service_categories_staff_all ON site.service_categories TO authenticated
    USING (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY service_categories_public_read ON site.service_categories FOR SELECT TO anon
    USING (deleted_at IS NULL AND EXISTS (
        SELECT 1 FROM site.sites si
        WHERE si.professional_id = service_categories.professional_id AND si.is_published = true
    ));

-- ── site.enquiries ──
ALTER TABLE site.enquiries ENABLE ROW LEVEL SECURITY;

CREATE POLICY enquiries_app_backend_all ON site.enquiries
    FOR ALL TO app_backend
    USING (true) WITH CHECK (true);

CREATE POLICY enquiries_owner_select ON site.enquiries
    FOR SELECT TO authenticated
    USING (professional_id = (
        SELECT id FROM core.users WHERE auth_user_id = auth.uid() AND deleted_at IS NULL
    ));

CREATE POLICY enquiries_staff_select ON site.enquiries
    FOR SELECT TO authenticated
    USING (EXISTS (
        SELECT 1 FROM core.partna_staff ps
        WHERE ps.auth_user_id = auth.uid() AND ps.role IN ('admin', 'support')
    ));

-- ── notifications.notifications ──
ALTER TABLE notifications.notifications ENABLE ROW LEVEL SECURITY;

CREATE POLICY notifications_pro_select ON notifications.notifications FOR SELECT TO authenticated
    USING (
        professional_id IS NULL
        OR professional_id = (SELECT id FROM core.users WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid())
    );

CREATE POLICY notifications_staff_write ON notifications.notifications FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY notifications_staff_update ON notifications.notifications FOR UPDATE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY notifications_staff_delete ON notifications.notifications FOR DELETE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()));

-- ── notifications.notification_receipts ──
ALTER TABLE notifications.notification_receipts ENABLE ROW LEVEL SECURITY;

CREATE POLICY notification_receipts_pro_all ON notifications.notification_receipts TO authenticated
    USING (professional_id = (SELECT id FROM core.users WHERE auth_user_id = auth.uid() AND deleted_at IS NULL))
    WITH CHECK (professional_id = (SELECT id FROM core.users WHERE auth_user_id = auth.uid() AND deleted_at IS NULL));

CREATE POLICY notification_receipts_staff_all ON notifications.notification_receipts TO authenticated
    USING (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()));

-- ── notifications.notification_email_preferences ──
ALTER TABLE notifications.notification_email_preferences ENABLE ROW LEVEL SECURITY;

CREATE POLICY email_prefs_pro_all ON notifications.notification_email_preferences TO authenticated
    USING (professional_id = (SELECT id FROM core.users WHERE auth_user_id = auth.uid() AND deleted_at IS NULL))
    WITH CHECK (professional_id = (SELECT id FROM core.users WHERE auth_user_id = auth.uid() AND deleted_at IS NULL));

CREATE POLICY email_prefs_staff_all ON notifications.notification_email_preferences TO authenticated
    USING (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()));

-- ── notifications.notification_email_policies ──
ALTER TABLE notifications.notification_email_policies ENABLE ROW LEVEL SECURITY;

CREATE POLICY email_policies_read ON notifications.notification_email_policies FOR SELECT TO authenticated
    USING (
        professional_id IS NULL
        OR professional_id = (SELECT id FROM core.users WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid())
    );

CREATE POLICY email_policies_staff_write ON notifications.notification_email_policies FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY email_policies_staff_update ON notifications.notification_email_policies FOR UPDATE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY email_policies_staff_delete ON notifications.notification_email_policies FOR DELETE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()));

-- ── notifications.email_subscriptions ──
ALTER TABLE notifications.email_subscriptions ENABLE ROW LEVEL SECURITY;

CREATE POLICY email_subs_pro_all ON notifications.email_subscriptions TO authenticated
    USING (professional_id = (SELECT id FROM core.users WHERE auth_user_id = auth.uid() AND deleted_at IS NULL))
    WITH CHECK (professional_id = (SELECT id FROM core.users WHERE auth_user_id = auth.uid() AND deleted_at IS NULL));

CREATE POLICY email_subs_public_insert ON notifications.email_subscriptions FOR INSERT TO anon WITH CHECK (true);
CREATE POLICY email_subs_public_unsubscribe ON notifications.email_subscriptions FOR SELECT TO anon USING (unsubscribe_token IS NOT NULL);

CREATE POLICY email_subs_staff_all ON notifications.email_subscriptions TO authenticated
    USING (EXISTS (SELECT 1 FROM core.partna_staff WHERE partna_staff.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.partna_staff WHERE partna_staff.auth_user_id = auth.uid()));

-- ── notifications.broadcast_email_receipts ──
ALTER TABLE notifications.broadcast_email_receipts ENABLE ROW LEVEL SECURITY;

CREATE POLICY broadcast_email_receipts_app_backend_all ON notifications.broadcast_email_receipts
    FOR ALL TO app_backend
    USING (true) WITH CHECK (true);

CREATE POLICY broadcast_email_receipts_staff_select ON notifications.broadcast_email_receipts
    FOR SELECT TO authenticated
    USING (EXISTS (
        SELECT 1 FROM core.partna_staff ps
        WHERE ps.auth_user_id = auth.uid() AND ps.role IN ('admin', 'support')
    ));

-- ── analytics.site_visits ──
ALTER TABLE analytics.site_visits ENABLE ROW LEVEL SECURITY;

CREATE POLICY site_visits_anyone_insert_valid_site ON analytics.site_visits FOR INSERT TO anon WITH CHECK (EXISTS (
    SELECT 1 FROM site.sites s WHERE s.id = site_visits.site_id AND s.professional_id = site_visits.professional_id AND s.is_published = true
));

CREATE POLICY site_visits_staff_all ON analytics.site_visits TO authenticated
    USING (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid()));

-- ── analytics.link_clicks ──
ALTER TABLE analytics.link_clicks ENABLE ROW LEVEL SECURITY;

CREATE POLICY link_clicks_anyone_insert_valid_block ON analytics.link_clicks FOR INSERT TO anon WITH CHECK (EXISTS (
    SELECT 1 FROM site.blocks b JOIN site.sites s ON s.id = b.site_id
    WHERE b.id = link_clicks.link_block_id AND b.site_id = link_clicks.site_id
    AND b.professional_id = link_clicks.professional_id AND b.is_active = true AND s.is_published = true
));

CREATE POLICY link_clicks_staff_all ON analytics.link_clicks TO authenticated
    USING (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid()));

-- ── analytics.lead_submissions ──
ALTER TABLE analytics.lead_submissions ENABLE ROW LEVEL SECURITY;

CREATE POLICY lead_submissions_owner_select ON analytics.lead_submissions
    FOR SELECT TO authenticated
    USING (professional_id = (
        SELECT id FROM core.users WHERE auth_user_id = auth.uid() AND deleted_at IS NULL
    ));

CREATE POLICY lead_submissions_staff_select ON analytics.lead_submissions
    FOR SELECT TO authenticated
    USING (EXISTS (
        SELECT 1 FROM core.partna_staff ps
        WHERE ps.auth_user_id = auth.uid() AND ps.role IN ('admin', 'support')
    ));

-- ── analytics.section_views ──
ALTER TABLE analytics.section_views ENABLE ROW LEVEL SECURITY;

CREATE POLICY "section_views_service_role_all" ON analytics.section_views
    FOR ALL TO service_role
    USING (true) WITH CHECK (true);

CREATE POLICY section_views_owner_select ON analytics.section_views
    FOR SELECT TO authenticated
    USING (professional_id = (
        SELECT id FROM core.users WHERE auth_user_id = auth.uid() AND deleted_at IS NULL
    ));

CREATE POLICY section_views_staff_select ON analytics.section_views
    FOR SELECT TO authenticated
    USING (EXISTS (
        SELECT 1 FROM core.partna_staff ps
        WHERE ps.auth_user_id = auth.uid() AND ps.role IN ('admin', 'support')
    ));

-- ── analytics.site_metrics_daily ──
ALTER TABLE analytics.site_metrics_daily ENABLE ROW LEVEL SECURITY;

CREATE POLICY site_metrics_daily_pro_select ON analytics.site_metrics_daily FOR SELECT TO authenticated
    USING (
        professional_id = (SELECT id FROM core.users WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid())
    );

CREATE POLICY site_metrics_daily_staff_write ON analytics.site_metrics_daily FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY site_metrics_daily_staff_update ON analytics.site_metrics_daily FOR UPDATE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()));

-- ── analytics.site_metrics_hourly ──
ALTER TABLE analytics.site_metrics_hourly ENABLE ROW LEVEL SECURITY;

CREATE POLICY site_metrics_hourly_pro_select ON analytics.site_metrics_hourly FOR SELECT TO authenticated
    USING (
        professional_id = (SELECT id FROM core.users WHERE auth_user_id = auth.uid() AND deleted_at IS NULL)
        OR EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid())
    );

CREATE POLICY site_metrics_hourly_staff_write ON analytics.site_metrics_hourly FOR INSERT TO authenticated
    WITH CHECK (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()));

CREATE POLICY site_metrics_hourly_staff_update ON analytics.site_metrics_hourly FOR UPDATE TO authenticated
    USING (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()));

-- ── public.failed_jobs ──
ALTER TABLE public.failed_jobs ENABLE ROW LEVEL SECURITY;

CREATE POLICY failed_jobs_staff_all ON public.failed_jobs TO authenticated
    USING (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()));

-- ── public.job_batches ──
ALTER TABLE public.job_batches ENABLE ROW LEVEL SECURITY;

CREATE POLICY job_batches_staff_all ON public.job_batches TO authenticated
    USING (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()))
    WITH CHECK (EXISTS (SELECT 1 FROM core.partna_staff s WHERE s.auth_user_id = auth.uid()));

-- ==========================================================================
-- 11. SEED DATA
-- ==========================================================================

-- Seed themes (theme-3 + theme-4 are valid per settings.design.theme validation;
-- only theme-1/-2/-3 carry seed rows historically — kept identical to baseline).
INSERT INTO site.themes (key, name, description, config, is_default)
VALUES
    ('theme-1', 'Theme 1', 'Default theme.', '{}'::jsonb, true),
    ('theme-2', 'Theme 2', 'Theme 2 placeholder theme.', '{}'::jsonb, false),
    ('theme-3', 'Theme 3', 'Theme 3 placeholder theme.', '{}'::jsonb, false)
ON CONFLICT (key) DO UPDATE SET
    name = EXCLUDED.name,
    description = EXCLUDED.description,
    config = EXCLUDED.config,
    is_default = EXCLUDED.is_default,
    updated_at = now();

-- ==========================================================================
-- 12. ROLE PERMISSIONS
-- ==========================================================================

-- Schema usage grants for Supabase roles.
GRANT USAGE ON SCHEMA analytics TO anon, authenticated, service_role;
GRANT USAGE ON SCHEMA core TO anon, authenticated, service_role;
GRANT USAGE ON SCHEMA site TO anon, authenticated, service_role;
GRANT USAGE ON SCHEMA notifications TO anon, authenticated, service_role;
GRANT USAGE ON SCHEMA public TO postgres, anon, authenticated, service_role;

-- app_backend runtime role.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        -- Created NOLOGIN; password + LOGIN must be set out-of-band after migration runs:
        --   ALTER ROLE app_backend WITH LOGIN PASSWORD '<from-secret-store>';
        -- Real credential lives in Laravel Cloud env (DB_PASSWORD), never in git.
        CREATE ROLE app_backend NOLOGIN;
    END IF;

    -- app_backend is the Laravel service DB user; it bypasses RLS so queue
    -- workers, webhooks, and background jobs are never silently blocked
    -- (decision 16 — several KEEP RLS tables have no explicit app_backend
    -- policy, so revoking BYPASSRLS would default-deny them).
    EXECUTE 'ALTER ROLE app_backend BYPASSRLS';

    -- Schema usage.
    EXECUTE 'GRANT USAGE ON SCHEMA core TO app_backend';
    EXECUTE 'GRANT USAGE ON SCHEMA site TO app_backend';
    EXECUTE 'GRANT USAGE ON SCHEMA notifications TO app_backend';
    EXECUTE 'GRANT USAGE ON SCHEMA analytics TO app_backend';
    EXECUTE 'GRANT USAGE ON SCHEMA public TO app_backend';

    -- Full CRUD on all schemas.
    EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA core TO app_backend';
    EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA site TO app_backend';
    EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA notifications TO app_backend';
    EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA analytics TO app_backend';
    EXECUTE 'GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO app_backend';

    -- Sequences.
    EXECUTE 'GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA core TO app_backend';
    EXECUTE 'GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA site TO app_backend';
    EXECUTE 'GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA notifications TO app_backend';
    EXECUTE 'GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA analytics TO app_backend';
    EXECUTE 'GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO app_backend';

    -- Default privileges for future tables.
    EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA core GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_backend';
    EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA site GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_backend';
    EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA notifications GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_backend';
    EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA analytics GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_backend';
    EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO app_backend';

    EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA core GRANT USAGE, SELECT ON SEQUENCES TO app_backend';
    EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA site GRANT USAGE, SELECT ON SEQUENCES TO app_backend';
    EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA notifications GRANT USAGE, SELECT ON SEQUENCES TO app_backend';
    EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA analytics GRANT USAGE, SELECT ON SEQUENCES TO app_backend';
    EXECUTE 'ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO app_backend';
END $$;

-- ── Append-only table hardening: revoke UPDATE/DELETE then restore SELECT,INSERT.
-- These three tables are append-only audit logs; the schema-level grant above
-- gave app_backend UPDATE/DELETE — explicitly revoke it (the trigger-level
-- rejection on staff_audit_log / handle_change_log is the belt-and-suspenders
-- third layer). Mirrors 20260517300000 + 20260519100000.
REVOKE UPDATE, DELETE ON core.staff_audit_log FROM app_backend;
GRANT SELECT, INSERT ON core.staff_audit_log TO app_backend;

REVOKE UPDATE, DELETE ON core.handle_change_log FROM app_backend;
GRANT SELECT, INSERT ON core.handle_change_log TO app_backend;

-- core.auth_factor_events is append-only by design (no UPDATE/DELETE path).
REVOKE UPDATE, DELETE ON core.auth_factor_events FROM app_backend;
GRANT SELECT, INSERT ON core.auth_factor_events TO app_backend;

-- ── Explicit GRANTs ported from late migrations (belt-and-suspenders; the
-- schema-level grants above already cover them — kept self-describing).
GRANT SELECT, INSERT, UPDATE, DELETE ON site.enquiries TO app_backend;
GRANT SELECT, INSERT, UPDATE, DELETE ON core.data_export_audit TO app_backend;
GRANT SELECT, INSERT ON core.professional_deletion_audit TO app_backend;
GRANT SELECT, INSERT ON notifications.broadcast_email_receipts TO app_backend;

COMMIT;
