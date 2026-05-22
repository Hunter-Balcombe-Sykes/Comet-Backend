-- Create core.data_export_audit — audit trail for professional data exports.
--
-- Why: Both self-service and staff-triggered data exports write a row here
-- before the job runs (status=queued). The row survives the professional's
-- hard delete via the *_snapshot columns. record_counts (jsonb) captures
-- what was in the export so we can answer "what did we send and to whom" later.

BEGIN;

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
    CONSTRAINT data_export_audit_triggered_by_chk CHECK (triggered_by IN ('self', 'staff')),
    CONSTRAINT data_export_audit_send_to_chk CHECK (send_to IS NULL OR send_to IN ('professional', 'staff')),
    CONSTRAINT data_export_audit_status_chk CHECK (status IN ('queued', 'processing', 'completed', 'failed'))
);

ALTER TABLE core.data_export_audit OWNER TO postgres;

ALTER TABLE ONLY core.data_export_audit
    ADD CONSTRAINT data_export_audit_professional_fk
    FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL;

ALTER TABLE ONLY core.data_export_audit
    ADD CONSTRAINT data_export_audit_staff_fk
    FOREIGN KEY (triggered_by_staff_id) REFERENCES core.sidest_staff(id) ON DELETE SET NULL;

-- Dedup query: SELECT ... WHERE professional_id = ? AND status IN ('queued','processing') AND created_at > now() - interval '30 minutes'
CREATE INDEX data_export_audit_professional_status_created_idx
    ON core.data_export_audit (professional_id, status, created_at DESC);

-- "Which staff member exported what, when?" — partial index keeps it cheap.
CREATE INDEX data_export_audit_triggered_by_staff_idx
    ON core.data_export_audit (triggered_by_staff_id) WHERE triggered_by_staff_id IS NOT NULL;

ALTER TABLE core.data_export_audit ENABLE ROW LEVEL SECURITY;

CREATE POLICY data_export_audit_app_backend_all
    ON core.data_export_audit
    FOR ALL
    TO app_backend
    USING (true)
    WITH CHECK (true);

GRANT SELECT, INSERT, UPDATE, DELETE ON core.data_export_audit TO app_backend;

COMMENT ON TABLE core.data_export_audit IS
    'Audit trail for professional data exports (self-service + staff-triggered). Snapshot columns survive professional hard-delete.';

COMMIT;
