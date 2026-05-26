-- File: supabase/migrations/20260517300000_create_staff_audit_log.sql
-- OPS-2: Platform audit log of every staff write. One row per POST/PATCH/PUT/DELETE
-- against /staff/* routes. Append-only. RLS: staff-read only.
--
-- Body capture is deliberately omitted; payload_summary holds route bindings only.
-- Body detail can be added per-endpoint later via StaffAuditService::record().
--
-- impersonator_staff_id is nullable today and always NULL until OPS-1 (impersonation)
-- ships. Including the column from day one avoids a backfill later.
--
-- FK pattern mirrors core.professional_deletion_audit:
--   * ON DELETE SET NULL on both staff_id and professional_id
--   * *_snapshot text columns so audit rows survive hard-deletes
-- so the audit row is still legible after the actor or target is gone.

BEGIN;

CREATE TABLE core.staff_audit_log (
    id uuid PRIMARY KEY DEFAULT gen_random_uuid(),

    staff_id uuid NULL REFERENCES core.partna_staff(id) ON DELETE SET NULL,
    staff_email_snapshot text NULL,

    impersonator_staff_id uuid NULL REFERENCES core.partna_staff(id) ON DELETE SET NULL,
    impersonator_email_snapshot text NULL,

    professional_id uuid NULL REFERENCES core.professionals(id) ON DELETE SET NULL,
    professional_handle_snapshot text NULL,

    route text NOT NULL,
    http_method text NOT NULL,
    status_code smallint NOT NULL,
    payload_summary jsonb NOT NULL DEFAULT '{}'::jsonb,

    ip inet NULL,
    user_agent text NULL,

    created_at timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT staff_audit_log_http_method_check
        CHECK (http_method IN ('POST', 'PATCH', 'PUT', 'DELETE')),
    CONSTRAINT staff_audit_log_status_code_check
        CHECK (status_code BETWEEN 100 AND 599)
);

-- Forensic query: "what did staff X do?" — most common path.
CREATE INDEX idx_staff_audit_log_staff_created
    ON core.staff_audit_log (staff_id, created_at DESC);

-- Forensic query: "what happened to brand Y?" — second most common path.
-- Partial index because a meaningful fraction of writes have no professional binding
-- (e.g. /staff/commission-payouts/{payout}/retry).
CREATE INDEX idx_staff_audit_log_professional_created
    ON core.staff_audit_log (professional_id, created_at DESC)
    WHERE professional_id IS NOT NULL;

-- Lock down. app_backend INSERT-only; admin/support staff read; nobody else.
--
-- Append-only is enforced at THREE layers (belt-and-suspenders, because the
-- schema-level grant in 20260403000000_v2_baseline.sql gives app_backend
-- UPDATE/DELETE on every core.* table by default — that grant alone would
-- allow accidental mutation of audit rows despite RLS intent):
--   1. Split RLS policies: FOR INSERT + FOR SELECT, never FOR ALL.
--   2. Explicit REVOKE UPDATE, DELETE — overrides the schema-level grant.
--   3. A BEFORE UPDATE OR DELETE trigger that raises unconditionally —
--      survives any future migration that re-grants privileges or replaces
--      the RLS policies. Mirrors commerce.order_events append-only discipline.
--
-- This is stricter than the existing core.*_audit tables (which use FOR ALL
-- and lean on application discipline). Tightened in response to SCHEMA-1 in
-- audits/ops-2-plan-audit/audit-2026-05-17-full.md.

ALTER TABLE core.staff_audit_log ENABLE ROW LEVEL SECURITY;

CREATE POLICY staff_audit_log_app_backend_insert
    ON core.staff_audit_log
    FOR INSERT
    TO app_backend
    WITH CHECK (true);

CREATE POLICY staff_audit_log_app_backend_select
    ON core.staff_audit_log
    FOR SELECT
    TO app_backend
    USING (true);

CREATE POLICY staff_audit_log_staff_select
    ON core.staff_audit_log
    FOR SELECT
    TO authenticated
    USING (EXISTS (
        SELECT 1 FROM core.partna_staff ps
        WHERE ps.auth_user_id = auth.uid()
          AND ps.role IN ('admin', 'support')
    ));

-- Layer 2: explicit revoke overrides the schema-level baseline grant from
-- 20260403000000_v2_baseline.sql (which granted app_backend UPDATE, DELETE).
-- The subsequent GRANT SELECT, INSERT is additive and only restores the
-- two operations we want app_backend to perform.
REVOKE UPDATE, DELETE ON core.staff_audit_log FROM app_backend;
GRANT SELECT, INSERT ON core.staff_audit_log TO app_backend;

-- Layer 3: trigger-level rejection. Catches anything the grant + policy layers
-- might miss after future migrations (e.g., a migration that re-runs the
-- baseline GRANT or replaces the policies). The trigger is unconditional —
-- there is no legitimate UPDATE/DELETE path on an append-only audit log.
CREATE OR REPLACE FUNCTION core.reject_staff_audit_log_mutation()
    RETURNS trigger
    LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'core.staff_audit_log is append-only (OPS-2). UPDATE and DELETE are not permitted.';
END;
$$;

CREATE TRIGGER staff_audit_log_reject_mutation
    BEFORE UPDATE OR DELETE ON core.staff_audit_log
    FOR EACH ROW
    EXECUTE FUNCTION core.reject_staff_audit_log_mutation();

COMMIT;

COMMENT ON TABLE core.staff_audit_log IS
    'OPS-2: append-only audit log of staff writes. One row per POST/PATCH/PUT/DELETE under /staff/*.';
COMMENT ON COLUMN core.staff_audit_log.route IS
    'Laravel route name (e.g., staff.professionals.update) if the route is named, or the URI template (e.g., api/staff/professionals/{professional}) if not. Staff routes are currently unnamed, so the URI template is stored. Forensic queries: route LIKE ''api/staff/%''.';
COMMENT ON COLUMN core.staff_audit_log.payload_summary IS
    'Route bindings only by default (e.g., {"professional":"<uuid>"}). Body detail is opt-in per controller via StaffAuditService::record().';
COMMENT ON COLUMN core.staff_audit_log.impersonator_staff_id IS
    'Always NULL until OPS-1 (impersonation) ships. Column included from day one to avoid backfill.';
COMMENT ON COLUMN core.staff_audit_log.staff_email_snapshot IS
    'Frozen at insert. Survives staff hard-delete (FK is ON DELETE SET NULL).';
COMMENT ON COLUMN core.staff_audit_log.professional_handle_snapshot IS
    'Frozen at insert. Survives professional hard-delete (FK is ON DELETE SET NULL).';
COMMENT ON TRIGGER staff_audit_log_reject_mutation ON core.staff_audit_log IS
    'Append-only enforcement layer 3 of 3. Never drop without replacing — the table is intentionally immutable.';
