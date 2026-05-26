-- Harden three post-baseline core.* audit tables that shipped after the
-- mass-RLS migration (20260420200000) and the SET-NULL-on-professional-delete
-- pattern (20260419000002_nullable_commission_fks.sql) were established. Each
-- table had at least one of two gaps:
--
--   * professional_deletion_audit — RLS missing. Contains email PII; without
--     RLS, any authenticated Supabase JWT user can read every row via
--     /rest/v1/professional_deletion_audit (PostgREST applies the authenticated
--     role; app_backend's BYPASSRLS bypasses Laravel but not REST).
--   * wallet_currency_switch_audit — RLS missing AND ON DELETE CASCADE wipes
--     financial audit history when a professional is hard-deleted.
--   * brand_status_history — same shape: RLS missing AND ON DELETE CASCADE.
--
-- Reference implementation: core.data_export_audit (20260425000002) —
-- ENABLE ROW LEVEL SECURITY + ON DELETE SET NULL + *_snapshot columns so audit
-- rows survive professional purge.

BEGIN;

-- ──────────────────────────────────────────────────────────────────────────
-- 1. core.professional_deletion_audit
--    FK is already ON DELETE SET NULL and snapshot columns exist (see
--    20260419000001). Only the RLS gap needs closing.
-- ──────────────────────────────────────────────────────────────────────────

ALTER TABLE core.professional_deletion_audit ENABLE ROW LEVEL SECURITY;

CREATE POLICY professional_deletion_audit_app_backend_all
    ON core.professional_deletion_audit
    FOR ALL
    TO app_backend
    USING (true)
    WITH CHECK (true);

-- Staff (admin or support) can read. Deletion audit is staff-only — the row's
-- owner is, by definition, gone or going. No tenant SELECT policy.
CREATE POLICY professional_deletion_audit_staff_select
    ON core.professional_deletion_audit
    FOR SELECT
    TO authenticated
    USING (EXISTS (
        SELECT 1 FROM core.partna_staff ps
        WHERE ps.auth_user_id = auth.uid()
          AND ps.role IN ('admin', 'support')
    ));

-- ──────────────────────────────────────────────────────────────────────────
-- 2. core.wallet_currency_switch_audit
--    Flip CASCADE → SET NULL, add handle snapshot, enable RLS.
-- ──────────────────────────────────────────────────────────────────────────

ALTER TABLE core.wallet_currency_switch_audit
    ADD COLUMN IF NOT EXISTS professional_handle_snapshot text;

ALTER TABLE core.wallet_currency_switch_audit
    ALTER COLUMN professional_id DROP NOT NULL;

ALTER TABLE core.wallet_currency_switch_audit
    DROP CONSTRAINT IF EXISTS wallet_currency_switch_audit_professional_id_fkey;

-- Safe deviation from CONVENTIONS.md §4 (always NOT VALID first): this table holds
-- well under 1K rows at migration time (wallet-currency switch is a rare per-tenant
-- operation), so the ACCESS EXCLUSIVE window during full-row validation completes
-- in microseconds. The NOT VALID / VALIDATE CONSTRAINT split is unnecessary here.
-- DO NOT copy this pattern onto commerce.* hot tables — for orders / movements /
-- rollups, follow §4 (NOT VALID first, VALIDATE in a separate transaction).
ALTER TABLE core.wallet_currency_switch_audit
    ADD CONSTRAINT wallet_currency_switch_audit_professional_id_fkey
    FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL;

ALTER TABLE core.wallet_currency_switch_audit ENABLE ROW LEVEL SECURITY;

CREATE POLICY wallet_currency_switch_audit_app_backend_all
    ON core.wallet_currency_switch_audit
    FOR ALL
    TO app_backend
    USING (true)
    WITH CHECK (true);

-- Tenant can read their own audit history.
CREATE POLICY wallet_currency_switch_audit_tenant_select
    ON core.wallet_currency_switch_audit
    FOR SELECT
    TO authenticated
    USING (professional_id = (
        SELECT id FROM core.professionals
        WHERE auth_user_id = auth.uid() AND deleted_at IS NULL
    ));

-- Staff (admin or support) can read every row, including post-purge rows
-- with professional_id = NULL.
CREATE POLICY wallet_currency_switch_audit_staff_select
    ON core.wallet_currency_switch_audit
    FOR SELECT
    TO authenticated
    USING (EXISTS (
        SELECT 1 FROM core.partna_staff ps
        WHERE ps.auth_user_id = auth.uid()
          AND ps.role IN ('admin', 'support')
    ));

-- ──────────────────────────────────────────────────────────────────────────
-- 3. core.brand_status_history
--    Same shape as wallet audit: flip CASCADE → SET NULL, add handle snapshot,
--    enable RLS.
-- ──────────────────────────────────────────────────────────────────────────

ALTER TABLE core.brand_status_history
    ADD COLUMN IF NOT EXISTS professional_handle_snapshot text;

ALTER TABLE core.brand_status_history
    ALTER COLUMN professional_id DROP NOT NULL;

ALTER TABLE core.brand_status_history
    DROP CONSTRAINT IF EXISTS brand_status_history_professional_id_fkey;

-- Safe deviation from CONVENTIONS.md §4 — see equivalent block above for the
-- wallet_currency_switch_audit FK. brand_status_history is also a small audit table
-- (rows are appended on brand-state transitions, not per-request), so the full
-- ACCESS EXCLUSIVE validation completes in microseconds. Same warning: do not
-- copy onto hot commerce.* tables.
ALTER TABLE core.brand_status_history
    ADD CONSTRAINT brand_status_history_professional_id_fkey
    FOREIGN KEY (professional_id) REFERENCES core.professionals(id) ON DELETE SET NULL;

ALTER TABLE core.brand_status_history ENABLE ROW LEVEL SECURITY;

CREATE POLICY brand_status_history_app_backend_all
    ON core.brand_status_history
    FOR ALL
    TO app_backend
    USING (true)
    WITH CHECK (true);

CREATE POLICY brand_status_history_tenant_select
    ON core.brand_status_history
    FOR SELECT
    TO authenticated
    USING (professional_id = (
        SELECT id FROM core.professionals
        WHERE auth_user_id = auth.uid() AND deleted_at IS NULL
    ));

CREATE POLICY brand_status_history_staff_select
    ON core.brand_status_history
    FOR SELECT
    TO authenticated
    USING (EXISTS (
        SELECT 1 FROM core.partna_staff ps
        WHERE ps.auth_user_id = auth.uid()
          AND ps.role IN ('admin', 'support')
    ));

-- ──────────────────────────────────────────────────────────────────────────
-- GRANTs are already in place via the schema-level default privileges in
-- 20260403000000_v2_baseline.sql (GRANT SELECT, INSERT, UPDATE, DELETE ON ALL
-- TABLES IN SCHEMA core TO app_backend). The explicit grants below are
-- belt-and-suspenders — harmless duplicates that make the migration
-- self-describing.
-- ──────────────────────────────────────────────────────────────────────────

GRANT SELECT, INSERT ON core.professional_deletion_audit TO app_backend;
GRANT SELECT, INSERT ON core.wallet_currency_switch_audit TO app_backend;
GRANT SELECT, INSERT ON core.brand_status_history TO app_backend;

COMMIT;

COMMENT ON COLUMN core.wallet_currency_switch_audit.professional_handle_snapshot IS
    'Frozen handle captured at insert. Survives professional hard-delete (FK is ON DELETE SET NULL).';

COMMENT ON COLUMN core.brand_status_history.professional_handle_snapshot IS
    'Frozen handle captured at insert. Survives professional hard-delete (FK is ON DELETE SET NULL).';
