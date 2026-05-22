-- 20260525000000_rls_policy_sweep.sql
-- RLS policy sweep — closes gaps identified in the security audit.
--
-- Finding → section mapping:
--   RLS-1  : anon SELECT on site.services only (see RLS-1 section for why
--            core.professionals is deliberately excluded)
--   RLS-2  : ENABLE RLS + staff-only policies on core.feature_flags + core.feature_flag_overrides
--   RLS-3  : ENABLE RLS + staff-only policy on brand.signup_code_audit
--   RLS-4  : ENABLE RLS + owner/staff SELECT on commerce.commission_export_audit
--   RLS-5  : owner/staff SELECT on analytics.lead_submissions (RLS already enabled; no policies existed)
--   RLS-6  : owner/staff SELECT on site.enquiries (RLS + app_backend policy existed; staff/owner read missing)
--   RLS-7  : owner/staff SELECT on site.professional_handle_aliases (RLS enabled; no policies existed)
--   RLS-8  : staff SELECT on core.handle_change_log (RLS enabled; no policies existed)
--   RLS-9  : staff SELECT on core.gdpr_requests (RLS + app_backend policy existed; staff read missing)
--   RLS-10 : staff SELECT on core.data_export_audit (RLS + app_backend policy existed; staff read missing)
--   RLS-11a: staff SELECT on billing.webhook_events (RLS enabled; no policies existed)
--   RLS-11b: owner/staff SELECT on analytics.cart_events (service_role policy only)
--   RLS-11c: owner/staff SELECT on analytics.section_views (service_role policy only)
--   RLS-11d: staff SELECT on notifications.broadcast_email_receipts (no RLS / no policies)
--
-- Role conventions used throughout this file:
--   app_backend   — Laravel service account. NOT BYPASSRLS — it is fully subject
--                   to RLS, so every RLS-enabled table MUST have an explicit
--                   app_backend policy or the app loses access. These policies
--                   are load-bearing, not documentation.
--   anon          — Supabase anonymous (unauthenticated) role; public read paths.
--   authenticated — Any logged-in Supabase JWT holder.
--   core.partna_staff (role IN ('admin','support')) — matches the pattern
--                   established in 20260513200000_harden_audit_tables.sql.
--
-- All policies are idempotent: DROP POLICY IF EXISTS before CREATE POLICY.

BEGIN;

-- ==========================================================================
-- RLS-1 — anon SELECT on site.services
--
-- core.professionals is DELIBERATELY EXCLUDED. RLS is row-level, not
-- column-level: an anon SELECT policy on core.professionals would expose
-- every column of every active professional (primary_email, phone, full
-- street address, stripe_* identifiers, admin_notes) to anyone holding the
-- public Supabase anon key. Nothing consumes the anon role for direct table
-- reads — Laravel connects as app_backend and public profile pages render
-- through the Astro/Hydrogen Worker → backend API. If direct anon profile
-- reads are ever needed, expose a PII-free view, never the base table.
--
-- site.services holds no PII (title/description/category/price/duration),
-- so anon read is safe — gated to active services of PUBLISHED sites only,
-- mirroring the blocks_public_read pattern in the v2 baseline.
-- ==========================================================================

-- site.services — anon can read active services belonging to a published site
-- (powers the public services list on individual + partner profiles)
DROP POLICY IF EXISTS services_anon_select ON site.services;
CREATE POLICY services_anon_select ON site.services
    FOR SELECT
    TO anon
    USING (
        is_active = true
        AND deleted_at IS NULL
        AND EXISTS (
            SELECT 1 FROM site.sites s
            WHERE s.professional_id = site.services.professional_id
              AND s.is_published = true
        )
    );

-- ==========================================================================
-- RLS-2 — ENABLE RLS + policies on core.feature_flags + core.feature_flag_overrides
--
-- Feature flags contain internal rollout config; they should never be
-- readable by anonymous or arbitrary authenticated users. Staff (admin/support)
-- can read+write; app_backend gets full access for runtime evaluation.
-- ==========================================================================

ALTER TABLE core.feature_flags ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS feature_flags_app_backend_all ON core.feature_flags;
CREATE POLICY feature_flags_app_backend_all ON core.feature_flags
    FOR ALL
    TO app_backend
    USING (true)
    WITH CHECK (true);

DROP POLICY IF EXISTS feature_flags_staff_all ON core.feature_flags;
CREATE POLICY feature_flags_staff_all ON core.feature_flags
    FOR ALL
    TO authenticated
    USING (
        EXISTS (
            SELECT 1 FROM core.partna_staff ps
            WHERE ps.auth_user_id = auth.uid()
              AND ps.role IN ('admin', 'support')
        )
    )
    WITH CHECK (
        EXISTS (
            SELECT 1 FROM core.partna_staff ps
            WHERE ps.auth_user_id = auth.uid()
              AND ps.role IN ('admin', 'support')
        )
    );

ALTER TABLE core.feature_flag_overrides ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS feature_flag_overrides_app_backend_all ON core.feature_flag_overrides;
CREATE POLICY feature_flag_overrides_app_backend_all ON core.feature_flag_overrides
    FOR ALL
    TO app_backend
    USING (true)
    WITH CHECK (true);

DROP POLICY IF EXISTS feature_flag_overrides_staff_all ON core.feature_flag_overrides;
CREATE POLICY feature_flag_overrides_staff_all ON core.feature_flag_overrides
    FOR ALL
    TO authenticated
    USING (
        EXISTS (
            SELECT 1 FROM core.partna_staff ps
            WHERE ps.auth_user_id = auth.uid()
              AND ps.role IN ('admin', 'support')
        )
    )
    WITH CHECK (
        EXISTS (
            SELECT 1 FROM core.partna_staff ps
            WHERE ps.auth_user_id = auth.uid()
              AND ps.role IN ('admin', 'support')
        )
    );

-- ==========================================================================
-- RLS-3 — ENABLE RLS + staff-only policy on brand.signup_code_audit
--
-- Signup code audit is an append-only forensic log. No tenant SELECT —
-- a brand reads their own code events via the API (app_backend), not direct
-- Supabase client access. Staff (admin/support) can read for forensic queries.
-- ==========================================================================

ALTER TABLE brand.signup_code_audit ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS signup_code_audit_app_backend_all ON brand.signup_code_audit;
CREATE POLICY signup_code_audit_app_backend_all ON brand.signup_code_audit
    FOR ALL
    TO app_backend
    USING (true)
    WITH CHECK (true);

DROP POLICY IF EXISTS signup_code_audit_staff_select ON brand.signup_code_audit;
CREATE POLICY signup_code_audit_staff_select ON brand.signup_code_audit
    FOR SELECT
    TO authenticated
    USING (
        EXISTS (
            SELECT 1 FROM core.partna_staff ps
            WHERE ps.auth_user_id = auth.uid()
              AND ps.role IN ('admin', 'support')
        )
    );

-- ==========================================================================
-- RLS-4 — ENABLE RLS + owner/staff SELECT on commerce.commission_export_audit
--
-- Professionals can read their own export requests (status polling, download
-- links). Staff (admin/support) can read all rows for ops support.
-- professional_id is nullable (ON DELETE SET NULL) — staff policy covers
-- post-purge rows (professional_id = NULL).
-- ==========================================================================

ALTER TABLE commerce.commission_export_audit ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS commission_export_audit_app_backend_all ON commerce.commission_export_audit;
CREATE POLICY commission_export_audit_app_backend_all ON commerce.commission_export_audit
    FOR ALL
    TO app_backend
    USING (true)
    WITH CHECK (true);

DROP POLICY IF EXISTS commission_export_audit_owner_select ON commerce.commission_export_audit;
CREATE POLICY commission_export_audit_owner_select ON commerce.commission_export_audit
    FOR SELECT
    TO authenticated
    USING (
        professional_id = (
            SELECT id FROM core.professionals
            WHERE auth_user_id = auth.uid() AND deleted_at IS NULL
        )
    );

DROP POLICY IF EXISTS commission_export_audit_staff_select ON commerce.commission_export_audit;
CREATE POLICY commission_export_audit_staff_select ON commerce.commission_export_audit
    FOR SELECT
    TO authenticated
    USING (
        EXISTS (
            SELECT 1 FROM core.partna_staff ps
            WHERE ps.auth_user_id = auth.uid()
              AND ps.role IN ('admin', 'support')
        )
    );

-- ==========================================================================
-- RLS-5 — analytics.lead_submissions
--
-- RLS was enabled in the v2 baseline but no policies were created — default-deny.
-- Owner reads their own submissions (analytics); staff reads all.
-- professional_id is nullable (ON DELETE SET NULL).
-- ==========================================================================

DROP POLICY IF EXISTS lead_submissions_owner_select ON analytics.lead_submissions;
CREATE POLICY lead_submissions_owner_select ON analytics.lead_submissions
    FOR SELECT
    TO authenticated
    USING (
        professional_id = (
            SELECT id FROM core.professionals
            WHERE auth_user_id = auth.uid() AND deleted_at IS NULL
        )
    );

DROP POLICY IF EXISTS lead_submissions_staff_select ON analytics.lead_submissions;
CREATE POLICY lead_submissions_staff_select ON analytics.lead_submissions
    FOR SELECT
    TO authenticated
    USING (
        EXISTS (
            SELECT 1 FROM core.partna_staff ps
            WHERE ps.auth_user_id = auth.uid()
              AND ps.role IN ('admin', 'support')
        )
    );

-- ==========================================================================
-- RLS-6 — site.enquiries
--
-- RLS and app_backend_all policy already exist (20260422040000). Adding the
-- owner SELECT (professionals reads their own inbox) and staff SELECT.
-- ==========================================================================

DROP POLICY IF EXISTS enquiries_owner_select ON site.enquiries;
CREATE POLICY enquiries_owner_select ON site.enquiries
    FOR SELECT
    TO authenticated
    USING (
        professional_id = (
            SELECT id FROM core.professionals
            WHERE auth_user_id = auth.uid() AND deleted_at IS NULL
        )
    );

DROP POLICY IF EXISTS enquiries_staff_select ON site.enquiries;
CREATE POLICY enquiries_staff_select ON site.enquiries
    FOR SELECT
    TO authenticated
    USING (
        EXISTS (
            SELECT 1 FROM core.partna_staff ps
            WHERE ps.auth_user_id = auth.uid()
              AND ps.role IN ('admin', 'support')
        )
    );

-- ==========================================================================
-- RLS-7 — site.professional_handle_aliases
--
-- RLS was enabled in 20260508100000 but no policies were defined. Owner can
-- read their own aliases (to show active redirects in the handle settings UI).
-- Staff can read all. The worker reads via app_backend (BYPASSRLS).
-- Expired aliases (expires_at < now()) are intentionally included — the app
-- layer applies the ->active() scope; the policy gate is ownership only.
-- ==========================================================================

DROP POLICY IF EXISTS handle_aliases_owner_select ON site.professional_handle_aliases;
CREATE POLICY handle_aliases_owner_select ON site.professional_handle_aliases
    FOR SELECT
    TO authenticated
    USING (
        professional_id = (
            SELECT id FROM core.professionals
            WHERE auth_user_id = auth.uid() AND deleted_at IS NULL
        )
    );

DROP POLICY IF EXISTS handle_aliases_staff_select ON site.professional_handle_aliases;
CREATE POLICY handle_aliases_staff_select ON site.professional_handle_aliases
    FOR SELECT
    TO authenticated
    USING (
        EXISTS (
            SELECT 1 FROM core.partna_staff ps
            WHERE ps.auth_user_id = auth.uid()
              AND ps.role IN ('admin', 'support')
        )
    );

-- ==========================================================================
-- RLS-8 — core.handle_change_log
--
-- RLS was enabled in 20260519100000 but no policies were defined. This is an
-- append-only audit log; app_backend has INSERT+SELECT grant. No tenant SELECT
-- via direct Supabase client — professionals view their rename history via the
-- API. Staff (admin/support) can read for forensic queries.
-- ==========================================================================

DROP POLICY IF EXISTS handle_change_log_app_backend_all ON core.handle_change_log;
CREATE POLICY handle_change_log_app_backend_all ON core.handle_change_log
    FOR ALL
    TO app_backend
    USING (true)
    WITH CHECK (true);

DROP POLICY IF EXISTS handle_change_log_staff_select ON core.handle_change_log;
CREATE POLICY handle_change_log_staff_select ON core.handle_change_log
    FOR SELECT
    TO authenticated
    USING (
        EXISTS (
            SELECT 1 FROM core.partna_staff ps
            WHERE ps.auth_user_id = auth.uid()
              AND ps.role IN ('admin', 'support')
        )
    );

-- ==========================================================================
-- RLS-9 — core.gdpr_requests
--
-- RLS and app_backend_all policy already exist (20260423000001). Adding staff
-- SELECT. No tenant SELECT — these are Shopify system events, not user-facing.
-- ==========================================================================

DROP POLICY IF EXISTS gdpr_requests_staff_select ON core.gdpr_requests;
CREATE POLICY gdpr_requests_staff_select ON core.gdpr_requests
    FOR SELECT
    TO authenticated
    USING (
        EXISTS (
            SELECT 1 FROM core.partna_staff ps
            WHERE ps.auth_user_id = auth.uid()
              AND ps.role IN ('admin', 'support')
        )
    );

-- ==========================================================================
-- RLS-10 — core.data_export_audit
--
-- RLS and app_backend_all policy already exist (20260425000002). Adding staff
-- SELECT and owner SELECT (professionals may see their own export status).
-- professional_id is nullable (ON DELETE SET NULL) — staff policy covers
-- post-purge rows.
-- ==========================================================================

DROP POLICY IF EXISTS data_export_audit_owner_select ON core.data_export_audit;
CREATE POLICY data_export_audit_owner_select ON core.data_export_audit
    FOR SELECT
    TO authenticated
    USING (
        professional_id = (
            SELECT id FROM core.professionals
            WHERE auth_user_id = auth.uid() AND deleted_at IS NULL
        )
    );

DROP POLICY IF EXISTS data_export_audit_staff_select ON core.data_export_audit;
CREATE POLICY data_export_audit_staff_select ON core.data_export_audit
    FOR SELECT
    TO authenticated
    USING (
        EXISTS (
            SELECT 1 FROM core.partna_staff ps
            WHERE ps.auth_user_id = auth.uid()
              AND ps.role IN ('admin', 'support')
        )
    );

-- ==========================================================================
-- RLS-11 — billing.webhook_events, analytics.cart_events,
--           analytics.section_views, notifications.broadcast_email_receipts
-- ==========================================================================

-- ── billing.webhook_events ──
-- RLS was enabled in 20260407000000 but no policies were defined — default-deny.
-- This is internal Stripe/billing infrastructure; no tenant SELECT.
-- Staff (admin/support) can read for ops investigation.
DROP POLICY IF EXISTS webhook_events_app_backend_all ON billing.webhook_events;
CREATE POLICY webhook_events_app_backend_all ON billing.webhook_events
    FOR ALL
    TO app_backend
    USING (true)
    WITH CHECK (true);

DROP POLICY IF EXISTS webhook_events_staff_select ON billing.webhook_events;
CREATE POLICY webhook_events_staff_select ON billing.webhook_events
    FOR SELECT
    TO authenticated
    USING (
        EXISTS (
            SELECT 1 FROM core.partna_staff ps
            WHERE ps.auth_user_id = auth.uid()
              AND ps.role IN ('admin', 'support')
        )
    );

-- ── analytics.cart_events ──
-- Only a service_role_all policy existed. Read path for professionals and
-- staff goes through the Laravel API (app_backend BYPASSRLS), but adding
-- owner + staff SELECT closes the direct-client gap.
DROP POLICY IF EXISTS cart_events_owner_select ON analytics.cart_events;
CREATE POLICY cart_events_owner_select ON analytics.cart_events
    FOR SELECT
    TO authenticated
    USING (
        professional_id = (
            SELECT id FROM core.professionals
            WHERE auth_user_id = auth.uid() AND deleted_at IS NULL
        )
    );

DROP POLICY IF EXISTS cart_events_staff_select ON analytics.cart_events;
CREATE POLICY cart_events_staff_select ON analytics.cart_events
    FOR SELECT
    TO authenticated
    USING (
        EXISTS (
            SELECT 1 FROM core.partna_staff ps
            WHERE ps.auth_user_id = auth.uid()
              AND ps.role IN ('admin', 'support')
        )
    );

-- ── analytics.section_views ──
-- Same gap as cart_events: only service_role_all policy existed.
DROP POLICY IF EXISTS section_views_owner_select ON analytics.section_views;
CREATE POLICY section_views_owner_select ON analytics.section_views
    FOR SELECT
    TO authenticated
    USING (
        professional_id = (
            SELECT id FROM core.professionals
            WHERE auth_user_id = auth.uid() AND deleted_at IS NULL
        )
    );

DROP POLICY IF EXISTS section_views_staff_select ON analytics.section_views;
CREATE POLICY section_views_staff_select ON analytics.section_views
    FOR SELECT
    TO authenticated
    USING (
        EXISTS (
            SELECT 1 FROM core.partna_staff ps
            WHERE ps.auth_user_id = auth.uid()
              AND ps.role IN ('admin', 'support')
        )
    );

-- ── notifications.broadcast_email_receipts ──
-- Table was created in 20260514200000 with no RLS or grants.
-- This is an internal idempotency sentinel; no tenant SELECT needed.
-- Staff (admin/support) can read for ops/support queries.
ALTER TABLE notifications.broadcast_email_receipts ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS broadcast_email_receipts_app_backend_all ON notifications.broadcast_email_receipts;
CREATE POLICY broadcast_email_receipts_app_backend_all ON notifications.broadcast_email_receipts
    FOR ALL
    TO app_backend
    USING (true)
    WITH CHECK (true);

DROP POLICY IF EXISTS broadcast_email_receipts_staff_select ON notifications.broadcast_email_receipts;
CREATE POLICY broadcast_email_receipts_staff_select ON notifications.broadcast_email_receipts
    FOR SELECT
    TO authenticated
    USING (
        EXISTS (
            SELECT 1 FROM core.partna_staff ps
            WHERE ps.auth_user_id = auth.uid()
              AND ps.role IN ('admin', 'support')
        )
    );

GRANT SELECT, INSERT ON notifications.broadcast_email_receipts TO app_backend;

COMMIT;
