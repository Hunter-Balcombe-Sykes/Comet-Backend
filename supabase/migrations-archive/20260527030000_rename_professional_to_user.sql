-- ==========================================================================
-- Rename professional → user across the schema (2026-05-25)
--
-- Completes the partial rename from core.professionals → core.users.
-- The original rename only touched the main table; this migration:
--   - renames 24 professional_id FK columns to user_id
--   - renames 3 tables that still carried the professional_ prefix
--   - recreates 5 plpgsql function bodies that text-reference the
--     old column names
--   - renames 4 functions and 3 triggers for naming consistency
--
-- RLS policies are NOT recreated — PostgreSQL stores policy expressions
-- as parsed AST with attribute numbers, so column renames propagate
-- automatically. Plpgsql function bodies, by contrast, are stored as
-- text and re-parsed on each call, so they MUST be recreated.
--
-- Function argument names (e.g., p_professional_id) are left unchanged.
-- They're internal to the function — purely documentation. Renaming
-- them via CREATE OR REPLACE is rejected by PG (must DROP CASCADE),
-- which costs more than the cleanliness is worth here.
--
-- FK constraint names and indexes still containing 'professional_' are
-- intentionally NOT renamed in this migration. They're cosmetic, behave
-- identically, and dropping/recreating them is high churn for zero
-- functional gain. Future hygiene pass can clean those up.
-- ==========================================================================

BEGIN;

-- --------------------------------------------------------------------------
-- 1. Rename FK columns: professional_id → user_id (24 tables)
-- --------------------------------------------------------------------------

-- core (3 tables)
ALTER TABLE core.feature_flag_overrides                RENAME COLUMN professional_id TO user_id;
ALTER TABLE core.professional_confirmation_preferences RENAME COLUMN professional_id TO user_id;
ALTER TABLE core.professional_handle_aliases           RENAME COLUMN professional_id TO user_id;

-- site (6 tables)
ALTER TABLE site.blocks             RENAME COLUMN professional_id TO user_id;
ALTER TABLE site.customers          RENAME COLUMN professional_id TO user_id;
ALTER TABLE site.enquiries          RENAME COLUMN professional_id TO user_id;
ALTER TABLE site.service_categories RENAME COLUMN professional_id TO user_id;
ALTER TABLE site.services           RENAME COLUMN professional_id TO user_id;
ALTER TABLE site.sites              RENAME COLUMN professional_id TO user_id;

-- analytics (6 tables)
ALTER TABLE analytics.lead_submissions     RENAME COLUMN professional_id TO user_id;
ALTER TABLE analytics.link_clicks          RENAME COLUMN professional_id TO user_id;
ALTER TABLE analytics.section_views        RENAME COLUMN professional_id TO user_id;
ALTER TABLE analytics.site_metrics_daily   RENAME COLUMN professional_id TO user_id;
ALTER TABLE analytics.site_metrics_hourly  RENAME COLUMN professional_id TO user_id;
ALTER TABLE analytics.site_visits          RENAME COLUMN professional_id TO user_id;

-- notifications (5 tables)
ALTER TABLE notifications.email_subscriptions             RENAME COLUMN professional_id TO user_id;
ALTER TABLE notifications.notification_email_policies     RENAME COLUMN professional_id TO user_id;
ALTER TABLE notifications.notification_email_preferences  RENAME COLUMN professional_id TO user_id;
ALTER TABLE notifications.notification_receipts           RENAME COLUMN professional_id TO user_id;
ALTER TABLE notifications.notifications                   RENAME COLUMN professional_id TO user_id;

-- audit (4 tables)
ALTER TABLE audit.data_export_audit            RENAME COLUMN professional_id TO user_id;
ALTER TABLE audit.handle_change_log            RENAME COLUMN professional_id TO user_id;
ALTER TABLE audit.professional_deletion_audit  RENAME COLUMN professional_id TO user_id;
ALTER TABLE audit.staff_audit_log              RENAME COLUMN professional_id TO user_id;

-- --------------------------------------------------------------------------
-- 2. Rename tables: 3 tables (professional_ prefix → user_)
-- --------------------------------------------------------------------------
ALTER TABLE audit.professional_deletion_audit          RENAME TO user_deletion_audit;
ALTER TABLE core.professional_confirmation_preferences RENAME TO user_confirmation_preferences;
ALTER TABLE core.professional_handle_aliases           RENAME TO user_handle_aliases;

-- --------------------------------------------------------------------------
-- 3. Recreate plpgsql function bodies (must come AFTER column rename so
--    the new bodies see user_id where they reference it).
--    Note: arg names left unchanged; only function name + body updated.
-- --------------------------------------------------------------------------

CREATE OR REPLACE FUNCTION core.trg_professional_handle_alias_check()
RETURNS trigger LANGUAGE plpgsql AS $func$
DECLARE
    v_blocking_user uuid;
BEGIN
    IF NEW.handle IS NOT DISTINCT FROM OLD.handle THEN
        RETURN NEW;
    END IF;

    SELECT user_id INTO v_blocking_user
      FROM core.user_handle_aliases
     WHERE LOWER(handle) = LOWER(NEW.handle)
       AND user_id <> NEW.id
       AND (expires_at IS NULL OR expires_at > now())
     LIMIT 1;

    IF v_blocking_user IS NOT NULL THEN
        RAISE EXCEPTION 'Handle % is reserved as a redirect for another user', NEW.handle
            USING ERRCODE = '23505';
    END IF;

    RETURN NEW;
END;
$func$;

CREATE OR REPLACE FUNCTION core.trg_professional_handle_change()
RETURNS trigger LANGUAGE plpgsql AS $func$
DECLARE
    v_reclaim_days int := 14;
    v_redirect_days int := 90;
BEGIN
    INSERT INTO core.user_handle_aliases
        (user_id, handle, reclaim_until, expires_at)
    VALUES
        (NEW.id, OLD.handle,
         now() + (v_reclaim_days || ' days')::interval,
         now() + (v_redirect_days || ' days')::interval)
    ON CONFLICT DO NOTHING;

    RETURN NEW;
END;
$func$;

-- compute_professional_url: arg name stays p_professional_id, body uses sites.user_id
CREATE OR REPLACE FUNCTION site.compute_professional_url(p_professional_id uuid)
RETURNS text LANGUAGE plpgsql STABLE AS $func$
DECLARE
    v_subdomain text;
BEGIN
    SELECT s.subdomain INTO v_subdomain
      FROM site.sites s
     WHERE s.user_id = p_professional_id;

    IF v_subdomain IS NULL THEN
        RETURN NULL;
    END IF;

    RETURN 'https://' || v_subdomain || '.partna.au';
END;
$func$;

-- trg_recompute_partna_url: arg name stays p_professional_id, body uses user_id
CREATE OR REPLACE FUNCTION site.trg_recompute_partna_url(p_professional_id uuid)
RETURNS void LANGUAGE plpgsql AS $func$
DECLARE
    v_url text;
BEGIN
    v_url := site.compute_professional_url(p_professional_id);

    UPDATE core.users
       SET partna_url = v_url
     WHERE id = p_professional_id;
END;
$func$;

CREATE OR REPLACE FUNCTION site.trg_sites_url_sync()
RETURNS trigger LANGUAGE plpgsql AS $func$
BEGIN
    PERFORM site.trg_recompute_partna_url(NEW.user_id);
    RETURN NEW;
END;
$func$;

-- --------------------------------------------------------------------------
-- 4. Rename functions to drop the 'professional' prefix
--    ALTER FUNCTION ... RENAME preserves OID, so triggers continue
--    to reference these functions correctly.
-- --------------------------------------------------------------------------
ALTER FUNCTION core.set_professional_confirmation_preferences_updated_at()
    RENAME TO set_user_confirmation_preferences_updated_at;

ALTER FUNCTION core.trg_professional_handle_alias_check()
    RENAME TO trg_user_handle_alias_check;

ALTER FUNCTION core.trg_professional_handle_change()
    RENAME TO trg_user_handle_change;

ALTER FUNCTION site.compute_professional_url(uuid)
    RENAME TO compute_user_url;

-- --------------------------------------------------------------------------
-- 5. Rename triggers for naming consistency
-- --------------------------------------------------------------------------
ALTER TRIGGER professional_handle_alias_check_bu
    ON core.users RENAME TO user_handle_alias_check_bu;

ALTER TRIGGER professional_handle_change_au
    ON core.users RENAME TO user_handle_change_au;

ALTER TRIGGER trg_professional_confirmation_preferences_set_updated_at
    ON core.user_confirmation_preferences
    RENAME TO trg_user_confirmation_preferences_set_updated_at;

COMMIT;
