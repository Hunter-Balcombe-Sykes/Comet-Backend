-- Defence-in-depth RLS on the moderation schema (audit #P3-16).
--
-- WHY: the five moderation tables (cases, case_signals, evidence, decisions,
-- action_log) hold the most sensitive data on the platform — reporter PII
-- (email, IP hash, reporter_user_id), case types, evidence payloads, and staff
-- decision rationale. They currently have NO Row Level Security.
--
-- The exposure is purely forward-looking TODAY: `moderation` is not in
-- `api.schemas` (supabase/config.toml) and neither `anon` nor `authenticated`
-- has USAGE on the schema, so PostgREST cannot reach these tables. But if a
-- future change ever adds `moderation` to api.schemas or grants USAGE without
-- also enabling RLS, every case row — including reporter PII — would become
-- immediately readable by any authenticated JWT via /rest/v1. The `audit`
-- schema is already hardened (append-only grants + RLS on staff_audit_log);
-- moderation was the gap. This migration closes it.
--
-- app_backend (the Laravel runtime role) has BYPASSRLS — confirmed in the
-- baseline (`ALTER ROLE app_backend BYPASSRLS`) and live on the dev DB
-- (pg_roles.rolbypassrls = true). Enabling + FORCING RLS here therefore does
-- NOT affect any application code path: every moderation.* model extends
-- BaseModel (forced pgsql connection → app_backend), and queue workers,
-- webhooks, and jobs all run as app_backend. The explicit app_backend FOR ALL
-- policies below are belt-and-braces so the tables stay writable even if
-- BYPASSRLS is ever revoked (mirrors core.handle_change_log / feature_flags).
--
-- Policy shape: the staff-only SELECT predicate mirrors
-- audit.staff_audit_log_staff_select (FORCE is mirrored separately, below):
--   * staff (admin or support) get SELECT — the staff moderation dashboard
--     reads cases via app_backend today, but a direct-JWT staff read path is
--     the only legitimate non-app consumer, so SELECT is staff-only.
--   * app_backend gets FOR ALL (the runtime writes/updates every row).
--   * authenticated/anon get NOTHING — no end user ever reads moderation data
--     (unlike site.design_kits, which has a public profile read path).
--
-- FORCE ROW LEVEL SECURITY matches the hardening on site.design_kits and
-- core.partna_staff: it ensures the table owner is also subject to RLS, so a
-- compromised owner-role session cannot bypass the policies.
--
-- ALTER TABLE ENABLE/FORCE RLS and CREATE POLICY are catalog-only metadata
-- operations (no table scan, no sustained row locks) — safe on populated
-- tables. Wrapped in BEGIN/COMMIT.

BEGIN;

-- Staff-membership predicate reused by every staff SELECT policy below.
-- Admin OR support: both staff roles legitimately read moderation cases.
-- (Mirrors audit.staff_audit_log_staff_select which gates on the same set.)

-- ── moderation.cases ─────────────────────────────────────────
ALTER TABLE moderation.cases ENABLE ROW LEVEL SECURITY;
ALTER TABLE moderation.cases FORCE ROW LEVEL SECURITY;

CREATE POLICY cases_app_backend_all ON moderation.cases
    FOR ALL TO app_backend
    USING (true) WITH CHECK (true);

CREATE POLICY cases_staff_select ON moderation.cases
    FOR SELECT TO authenticated
    USING (EXISTS (
        SELECT 1 FROM core.partna_staff cs
        WHERE cs.auth_user_id = auth.uid() AND cs.role IN ('admin', 'support')
    ));

-- ── moderation.case_signals ──────────────────────────────────
-- Holds reporter PII (reporter_email, reporter_ip_hash, reporter_user_id).
ALTER TABLE moderation.case_signals ENABLE ROW LEVEL SECURITY;
ALTER TABLE moderation.case_signals FORCE ROW LEVEL SECURITY;

CREATE POLICY case_signals_app_backend_all ON moderation.case_signals
    FOR ALL TO app_backend
    USING (true) WITH CHECK (true);

CREATE POLICY case_signals_staff_select ON moderation.case_signals
    FOR SELECT TO authenticated
    USING (EXISTS (
        SELECT 1 FROM core.partna_staff cs
        WHERE cs.auth_user_id = auth.uid() AND cs.role IN ('admin', 'support')
    ));

-- ── moderation.evidence ──────────────────────────────────────
ALTER TABLE moderation.evidence ENABLE ROW LEVEL SECURITY;
ALTER TABLE moderation.evidence FORCE ROW LEVEL SECURITY;

CREATE POLICY evidence_app_backend_all ON moderation.evidence
    FOR ALL TO app_backend
    USING (true) WITH CHECK (true);

CREATE POLICY evidence_staff_select ON moderation.evidence
    FOR SELECT TO authenticated
    USING (EXISTS (
        SELECT 1 FROM core.partna_staff cs
        WHERE cs.auth_user_id = auth.uid() AND cs.role IN ('admin', 'support')
    ));

-- ── moderation.decisions ─────────────────────────────────────
ALTER TABLE moderation.decisions ENABLE ROW LEVEL SECURITY;
ALTER TABLE moderation.decisions FORCE ROW LEVEL SECURITY;

CREATE POLICY decisions_app_backend_all ON moderation.decisions
    FOR ALL TO app_backend
    USING (true) WITH CHECK (true);

CREATE POLICY decisions_staff_select ON moderation.decisions
    FOR SELECT TO authenticated
    USING (EXISTS (
        SELECT 1 FROM core.partna_staff cs
        WHERE cs.auth_user_id = auth.uid() AND cs.role IN ('admin', 'support')
    ));

-- ── moderation.action_log ────────────────────────────────────
ALTER TABLE moderation.action_log ENABLE ROW LEVEL SECURITY;
ALTER TABLE moderation.action_log FORCE ROW LEVEL SECURITY;

CREATE POLICY action_log_app_backend_all ON moderation.action_log
    FOR ALL TO app_backend
    USING (true) WITH CHECK (true);

CREATE POLICY action_log_staff_select ON moderation.action_log
    FOR SELECT TO authenticated
    USING (EXISTS (
        SELECT 1 FROM core.partna_staff cs
        WHERE cs.auth_user_id = auth.uid() AND cs.role IN ('admin', 'support')
    ));

COMMIT;
