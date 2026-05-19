-- 20260519100000_handle_alias_lifecycle.sql
-- Handle redirect lifecycle: GRACE → REDIRECT → RELEASED.
-- See docs/superpowers/plans/2026-05-19-handle-redirect-lifecycle.md.

BEGIN;

-- =====================================================
-- 1. Lifecycle columns on the two alias tables
-- =====================================================
-- reclaim_until: while > now(), only the original owner may rename back
--                to this handle for free (no cooldown). NULL = no grace
--                (legacy aliases pre-this-migration).
-- expires_at:    while > now(), the alias still serves 301 redirects.
--                NULL = legacy permanent alias (treated as never expires
--                until the cleanup migration in Task 11). The prune job
--                ONLY deletes rows where expires_at IS NOT NULL AND < now().
-- notified_t3_at / notified_t1_at: stamps to prevent repeat notifications.

ALTER TABLE site.professional_handle_aliases
    ADD COLUMN IF NOT EXISTS reclaim_until timestamptz,
    ADD COLUMN IF NOT EXISTS expires_at    timestamptz,
    ADD COLUMN IF NOT EXISTS notified_t3_at timestamptz,
    ADD COLUMN IF NOT EXISTS notified_t1_at timestamptz;

ALTER TABLE site.site_subdomain_aliases
    ADD COLUMN IF NOT EXISTS reclaim_until timestamptz,
    ADD COLUMN IF NOT EXISTS expires_at    timestamptz,
    ADD COLUMN IF NOT EXISTS notified_t3_at timestamptz,
    ADD COLUMN IF NOT EXISTS notified_t1_at timestamptz;

-- =====================================================
-- 2. Update the auto-alias trigger to set lifecycle columns.
--    Replaces the body from 20260508100000_url_columns_and_triggers.sql.
-- =====================================================
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

    PERFORM site.trg_recompute_affiliate_path(NEW.id, NEW.handle);
    RETURN NEW;
END;
$$;

-- =====================================================
-- 3. Update the BEFORE-UPDATE conflict check to ignore EXPIRED aliases.
--    A renamer can land on a handle whose alias has lapsed.
--    Legacy NULL-expires_at rows still block (treated as permanent).
-- =====================================================
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

-- =====================================================
-- 4. Audit log: append-only, retained per config (default 7 years).
-- =====================================================
CREATE TABLE IF NOT EXISTS core.handle_change_log (
    id              uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    professional_id uuid NOT NULL REFERENCES core.professionals(id) ON DELETE CASCADE,
    old_handle      text,
    new_handle      text NOT NULL,
    reason          text NOT NULL CHECK (reason IN ('rename', 'reclaim', 'staff_rename', 'system')),
    actor_id        uuid,         -- pro who initiated (= professional_id for self-rename, staff id for staff_rename)
    ip_address      inet,
    user_agent      text,
    changed_at      timestamptz NOT NULL DEFAULT now()
);

-- Append-only: no UPDATE/DELETE from app role. Block via trigger.
CREATE OR REPLACE FUNCTION core.trg_handle_change_log_append_only()
RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'core.handle_change_log is append-only' USING ERRCODE = '42501';
END;
$$;

DROP TRIGGER IF EXISTS handle_change_log_no_update ON core.handle_change_log;
CREATE TRIGGER handle_change_log_no_update
    BEFORE UPDATE OR DELETE ON core.handle_change_log
    FOR EACH ROW EXECUTE FUNCTION core.trg_handle_change_log_append_only();

ALTER TABLE core.handle_change_log ENABLE ROW LEVEL SECURITY;
GRANT INSERT, SELECT ON core.handle_change_log TO app_backend;

COMMIT;

-- =====================================================
-- Indexes — must run outside a transaction block (CONCURRENTLY).
-- Partial indexes: only rows with an expiry are scanned by the prune job.
-- =====================================================
CREATE INDEX CONCURRENTLY IF NOT EXISTS professional_handle_aliases_expires_at_idx
    ON site.professional_handle_aliases (expires_at)
    WHERE expires_at IS NOT NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS site_subdomain_aliases_expires_at_idx
    ON site.site_subdomain_aliases (expires_at)
    WHERE expires_at IS NOT NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS handle_change_log_pro_changed_idx
    ON core.handle_change_log (professional_id, changed_at DESC);

CREATE INDEX CONCURRENTLY IF NOT EXISTS handle_change_log_changed_at_idx
    ON core.handle_change_log (changed_at DESC);
