-- Trust & Safety indexes. CONCURRENTLY cannot run inside a transaction.
-- See supabase/migrations/CONVENTIONS.md §1.

CREATE INDEX CONCURRENTLY IF NOT EXISTS cases_open_queue_idx
    ON moderation.cases (severity DESC, priority ASC, created_at ASC)
    WHERE status IN ('open', 'triaged', 'under_review');

CREATE INDEX CONCURRENTLY IF NOT EXISTS cases_target_open_idx
    ON moderation.cases (reportable_type, reportable_id)
    WHERE status IN ('open', 'triaged', 'under_review');

CREATE INDEX CONCURRENTLY IF NOT EXISTS cases_sla_due_idx
    ON moderation.cases (sla_due_at)
    WHERE status IN ('open', 'triaged', 'under_review') AND sla_due_at IS NOT NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS cases_owner_status_idx
    ON moderation.cases (reportable_owner_user_id, status)
    WHERE reportable_owner_user_id IS NOT NULL;

CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS case_signals_dedup_uniq
    ON moderation.case_signals (dedup_hash);

CREATE INDEX CONCURRENTLY IF NOT EXISTS case_signals_case_idx
    ON moderation.case_signals (case_id, created_at);

CREATE INDEX CONCURRENTLY IF NOT EXISTS case_signals_reporter_user_idx
    ON moderation.case_signals (reporter_user_id)
    WHERE reporter_user_id IS NOT NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS case_signals_reporter_ip_idx
    ON moderation.case_signals (reporter_ip_hash, created_at)
    WHERE reporter_ip_hash IS NOT NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS evidence_case_idx
    ON moderation.evidence (case_id, captured_at);

CREATE INDEX CONCURRENTLY IF NOT EXISTS evidence_content_hash_idx
    ON moderation.evidence (content_hash)
    WHERE content_hash IS NOT NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS decisions_case_idx
    ON moderation.decisions (case_id, decided_at);

CREATE INDEX CONCURRENTLY IF NOT EXISTS decisions_supersedes_idx
    ON moderation.decisions (supersedes_decision_id)
    WHERE supersedes_decision_id IS NOT NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS action_log_decision_idx
    ON moderation.action_log (decision_id, created_at);

CREATE INDEX CONCURRENTLY IF NOT EXISTS action_log_pending_idx
    ON moderation.action_log (status, created_at)
    WHERE status IN ('pending', 'dispatched');

CREATE INDEX CONCURRENTLY IF NOT EXISTS moderation_events_staff_idx
    ON audit.moderation_events (actor_staff_id, created_at)
    WHERE actor_staff_id IS NOT NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS moderation_events_target_idx
    ON audit.moderation_events (target_type, target_id, created_at)
    WHERE target_id IS NOT NULL;

CREATE INDEX CONCURRENTLY IF NOT EXISTS moderation_events_action_idx
    ON audit.moderation_events (action, created_at);
