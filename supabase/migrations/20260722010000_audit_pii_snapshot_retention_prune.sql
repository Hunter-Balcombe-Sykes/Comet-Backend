-- B8 / models-data PRIV-2 + PRIV-3: bound the retention of the PII email/handle
-- snapshots on the two sibling audit tables (audit.user_deletion_audit and
-- audit.data_export_audit). 20260527010000 REVOKEs UPDATE, DELETE on ALL TABLES IN
-- SCHEMA audit, so app_backend cannot redact audit.user_deletion_audit itself.
-- audit.data_export_audit is the exception — 20260624000000 re-GRANTed UPDATE on it
-- to app_backend for the markProcessing/markCompleted/markFailed lifecycle — so that
-- one alone COULD be redacted by a plain app_backend UPDATE. We deliberately route
-- BOTH through a SECURITY DEFINER function anyway (Josh: one consistent least-privilege
-- path for the sibling tables; DELETE stays revoked on both). Unlike
-- audit.handle_change_log, NEITHER table carries an append-only TRIGGER (only the
-- schema-wide REVOKE), so a postgres-owned SECURITY DEFINER function can UPDATE
-- directly with no trigger disable/enable step.
--
-- We ANONYMISE in place rather than delete: the deletion/export EVENT record survives
-- for compliance and fraud/dispute investigation, and only the personal data is
-- bounded — mirroring gdpr:prune-completed-exports, which likewise keeps the
-- data_export_audit row and drops only the export artifact. NOT NULL snapshot columns
-- (email/handle/recipient) are set to a '[redacted]' sentinel; nullable PII and
-- free-text columns are NULLed. The sentinel also makes each function idempotent — a
-- re-run skips already-redacted rows, so the daily job only touches (and counts) rows
-- that have newly crossed the retention cutoff.
--
-- Retention window: config('partna.audit.pii_retention_years') (default 7y, matching
-- the audit.handle_change_log fraud-investigation window), passed as p_cutoff by the
-- audit:prune-pii-snapshots console command.
--
-- Mirrors 20260718010000_handle_change_log_retention_prune.sql: SECURITY DEFINER,
-- pinned empty search_path (every identifier fully schema-qualified), least-privilege
-- grant guarded on app_backend's existence, owned by the migration role.
--
-- No created_at index is added here (audit.handle_change_log has one on changed_at):
-- these tables gain roughly one row per account-deletion event / data export, and the
-- prune matches zero rows for the first 7 years, so the daily seq scan is negligible.
-- Add a `CREATE INDEX CONCURRENTLY ... (created_at)` follow-up (its own one-statement
-- file, per CONVENTIONS.md §1) only if either table ever grows large.

BEGIN;
SET LOCAL lock_timeout = '2s';

CREATE OR REPLACE FUNCTION audit.prune_user_deletion_audit(p_cutoff timestamptz)
RETURNS integer
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = ''
AS $$
DECLARE
    v_redacted integer;
BEGIN
    UPDATE audit.user_deletion_audit
       SET professional_email_snapshot = '[redacted]',
           professional_handle_snapshot = '[redacted]',
           ip_address = NULL,
           user_agent = NULL,
           actor_handle_snapshot = NULL,
           reason = NULL,
           metadata = NULL
     WHERE created_at < p_cutoff
       AND professional_email_snapshot <> '[redacted]';
    GET DIAGNOSTICS v_redacted = ROW_COUNT;
    RETURN v_redacted;
END;
$$;

CREATE OR REPLACE FUNCTION audit.prune_data_export_audit(p_cutoff timestamptz)
RETURNS integer
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = ''
AS $$
DECLARE
    v_redacted integer;
BEGIN
    UPDATE audit.data_export_audit
       SET professional_email_snapshot = NULL,
           recipient_email = '[redacted]',
           professional_handle_snapshot = '[redacted]',
           error_message = NULL
     WHERE created_at < p_cutoff
       AND recipient_email <> '[redacted]';
    GET DIAGNOSTICS v_redacted = ROW_COUNT;
    RETURN v_redacted;
END;
$$;

-- Least privilege: no PUBLIC access; only app_backend (the console command's
-- connection role) may call. Guarded so this is a no-op on a DB where the role
-- doesn't exist yet (fresh local stack, or connected as postgres).
REVOKE ALL ON FUNCTION audit.prune_user_deletion_audit(timestamptz) FROM PUBLIC;
REVOKE ALL ON FUNCTION audit.prune_data_export_audit(timestamptz) FROM PUBLIC;
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'GRANT EXECUTE ON FUNCTION audit.prune_user_deletion_audit(timestamptz) TO app_backend';
        EXECUTE 'GRANT EXECUTE ON FUNCTION audit.prune_data_export_audit(timestamptz) TO app_backend';
    END IF;
END $$;

COMMIT;
