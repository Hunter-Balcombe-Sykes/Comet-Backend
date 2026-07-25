-- P2-05: least-privilege hardening for app_backend on append-only log tables.
--
-- app_backend holds BYPASSRLS + schema-wide CRUD (baseline decision 16): if its
-- credential leaks, an attacker gets unfiltered read-write over every schema.
-- Removing BYPASSRLS outright is a large, separate effort — every background-job
-- query must be audited and explicit per-table policies added for the KEEP-RLS
-- tables that have no app_backend policy. This migration is an incremental
-- down-payment: revoke the UPDATE/DELETE that the application provably never uses
-- on three write-once / insert-then-mutate log tables, so a stolen credential
-- cannot tamper with or erase them. Mirrors the baseline hardening of
-- core.staff_audit_log / core.handle_change_log / core.auth_factor_events
-- (20260526000000 §append-only).
--
-- Scope verified against application code (2026-06-07):
--   • moderation.decisions  — append-only. A reversal INSERTs a new superseding
--     row (ModerationReverseDecisionCommand, supersedes_decision_id); the original
--     is never UPDATEd or DELETEd. → revoke UPDATE, DELETE.
--   • moderation.action_log — rows ARE updated post-insert by NotifyReporterJob,
--     NotifyOnCallStaffJob and QuarantineMediaJob to record dispatch state, so
--     UPDATE must stay; nothing ever DELETEs a log entry. → revoke DELETE only.
--   • notifications.broadcast_email_receipts — written exclusively via
--     insertOrIgnore (PK dedup) in SendStaffBroadcastEmailToSubscriberJob; never
--     mutated. → revoke UPDATE, DELETE.
--
-- DELIBERATELY NOT TOUCHED: the analytics.* tables. They look append-only but are
-- not — PurgeRawAnalyticsEvents batch-DELETEs link_clicks / site_visits /
-- lead_submissions for retention, and site_metrics_daily/_hourly are UPSERTed
-- (UPDATE on conflict). Revoking their UPDATE/DELETE — as the original audit
-- finding proposed — would break the daily retention purge, a failure CI cannot
-- catch because that job never runs against the SQLite test database.

BEGIN;

-- Guarded so the migration is a no-op where app_backend does not exist (fresh DB
-- before the role is provisioned, or the local stack that connects as postgres).
DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'app_backend') THEN
        -- moderation.decisions — write-once audit of moderation outcomes.
        EXECUTE 'REVOKE UPDATE, DELETE ON moderation.decisions FROM app_backend';
        EXECUTE 'GRANT SELECT, INSERT ON moderation.decisions TO app_backend';

        -- moderation.action_log — insert + update (dispatch state), never delete.
        EXECUTE 'REVOKE DELETE ON moderation.action_log FROM app_backend';
        EXECUTE 'GRANT SELECT, INSERT, UPDATE ON moderation.action_log TO app_backend';

        -- notifications.broadcast_email_receipts — insert-only (insertOrIgnore).
        EXECUTE 'REVOKE UPDATE, DELETE ON notifications.broadcast_email_receipts FROM app_backend';
        EXECUTE 'GRANT SELECT, INSERT ON notifications.broadcast_email_receipts TO app_backend';
    END IF;
END $$;

COMMIT;
