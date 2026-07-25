-- PRIV-2: audit.data_export_audit is operationally MUTABLE (export lifecycle status
-- + retention file-purge), unlike the truly append-only handle_change_log / staff_audit_log.
-- reorganize_schemas (20260527010000) blanket-revoked UPDATE/DELETE on the entire audit
-- schema, which (a) breaks DataExportAudit::markProcessing/markCompleted/markFailed and
-- (b) blocks the keep-row retention prune (gdpr:prune-completed-exports).
-- Re-grant UPDATE only — keep-row retention never DELETEs the audit row (GDPR record
-- that an export happened must survive). Future "harden audit schema" passes must NOT
-- re-revoke UPDATE on this table without updating the prune command and the mark*() lifecycle.

BEGIN;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        EXECUTE 'GRANT UPDATE ON audit.data_export_audit TO app_backend';
    END IF;
END $$;

COMMIT;
