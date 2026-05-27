-- Trust & Safety foundation — moderation schema and audit-events table.
-- Two-file pattern: this file is DDL inside BEGIN/COMMIT.
-- Indexes are in the +1 sibling file (CREATE INDEX CONCURRENTLY).

BEGIN;

-- Note: the `audit` schema is already created by 20260527010000_reorganize_schemas.sql
-- (on origin/development). We only add the moderation schema here.
CREATE SCHEMA IF NOT EXISTS moderation;

-- ── moderation.cases ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS moderation.cases (
    id                       UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    case_type                VARCHAR(32) NOT NULL,
    reportable_type          VARCHAR(64) NOT NULL,
    reportable_id            UUID NOT NULL,
    reportable_owner_user_id UUID NULL,
    severity                 SMALLINT NOT NULL DEFAULT 2,
    status                   VARCHAR(20) NOT NULL DEFAULT 'open',
    signal_count             INTEGER NOT NULL DEFAULT 1,
    auto_actioned            BOOLEAN NOT NULL DEFAULT FALSE,
    priority                 SMALLINT NOT NULL DEFAULT 5,
    sla_due_at               TIMESTAMPTZ NULL,
    triaged_at               TIMESTAMPTZ NULL,
    triaged_by_staff_id      UUID NULL,
    resolved_at              TIMESTAMPTZ NULL,
    created_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT cases_case_type_check CHECK (case_type IN (
        'content_report', 'csam_match', 'trusted_flagger', 'manual', 'esafety_takedown'
    )),
    CONSTRAINT cases_reportable_type_check CHECK (reportable_type IN (
        'Site', 'SiteMedia', 'User', 'Block', 'Service'
    )),
    CONSTRAINT cases_severity_check CHECK (severity BETWEEN 1 AND 5),
    CONSTRAINT cases_status_check CHECK (status IN (
        'open', 'triaged', 'under_review', 'resolved', 'auto_actioned'
    )),
    CONSTRAINT cases_signal_count_check CHECK (signal_count >= 1),
    CONSTRAINT cases_priority_check CHECK (priority BETWEEN 1 AND 10),
    CONSTRAINT cases_owner_user_fk FOREIGN KEY (reportable_owner_user_id)
        REFERENCES core.users(id) ON DELETE SET NULL,
    CONSTRAINT cases_triaged_by_staff_fk FOREIGN KEY (triaged_by_staff_id)
        REFERENCES core.partna_staff(id) ON DELETE SET NULL
);

-- ── moderation.case_signals ──────────────────────────────────
CREATE TABLE IF NOT EXISTS moderation.case_signals (
    id                  UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    case_id             UUID NOT NULL,
    signal_source       VARCHAR(32) NOT NULL,
    signal_data         JSONB NOT NULL DEFAULT '{}'::JSONB,
    reporter_user_id    UUID NULL,
    reporter_email      VARCHAR(255) NULL,
    reporter_ip_hash    VARCHAR(64) NULL,
    reason_code         VARCHAR(64) NOT NULL,
    reason_details      TEXT NULL,
    dedup_hash          VARCHAR(64) NOT NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT case_signals_case_fk FOREIGN KEY (case_id)
        REFERENCES moderation.cases(id) ON DELETE CASCADE,
    CONSTRAINT case_signals_reporter_user_fk FOREIGN KEY (reporter_user_id)
        REFERENCES core.users(id) ON DELETE SET NULL,
    CONSTRAINT case_signals_signal_source_check CHECK (signal_source IN (
        'content_report', 'csam_scan', 'trusted_flagger', 'manual_staff', 'esafety_notice'
    )),
    CONSTRAINT case_signals_reason_code_check CHECK (reason_code IN (
        'spam', 'harassment', 'impersonation', 'illegal_content', 'sexual_content',
        'self_harm', 'hate_speech', 'intellectual_property', 'fake_profile', 'other',
        'auto_csam_hash_match', 'auto_other'
    )),
    CONSTRAINT case_signals_details_length CHECK (
        reason_details IS NULL OR length(reason_details) <= 4000
    )
);

-- ── moderation.evidence ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS moderation.evidence (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    case_id         UUID NOT NULL,
    signal_id       UUID NULL,
    evidence_type   VARCHAR(32) NOT NULL,
    payload         JSONB NOT NULL,
    content_hash    VARCHAR(64) NULL,
    captured_at     TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT evidence_case_fk FOREIGN KEY (case_id)
        REFERENCES moderation.cases(id) ON DELETE CASCADE,
    CONSTRAINT evidence_signal_fk FOREIGN KEY (signal_id)
        REFERENCES moderation.case_signals(id) ON DELETE SET NULL,
    CONSTRAINT evidence_type_check CHECK (evidence_type IN (
        'content_snapshot', 'csam_hash_match', 'upload_metadata', 'staff_attachment'
    ))
);

-- ── moderation.decisions ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS moderation.decisions (
    id                       UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    case_id                  UUID NOT NULL,
    decision_type            VARCHAR(32) NOT NULL,
    reason                   TEXT NULL,
    decided_by_staff_id      UUID NULL,
    decided_by_system        BOOLEAN NOT NULL DEFAULT FALSE,
    auto_actioned            BOOLEAN NOT NULL DEFAULT FALSE,
    supersedes_decision_id   UUID NULL,
    second_staff_approval_id UUID NULL,
    second_staff_approved_at TIMESTAMPTZ NULL,
    decided_at               TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT decisions_case_fk FOREIGN KEY (case_id)
        REFERENCES moderation.cases(id) ON DELETE RESTRICT,
    CONSTRAINT decisions_staff_fk FOREIGN KEY (decided_by_staff_id)
        REFERENCES core.partna_staff(id) ON DELETE SET NULL,
    CONSTRAINT decisions_supersedes_fk FOREIGN KEY (supersedes_decision_id)
        REFERENCES moderation.decisions(id) ON DELETE SET NULL,
    CONSTRAINT decisions_second_staff_fk FOREIGN KEY (second_staff_approval_id)
        REFERENCES core.partna_staff(id) ON DELETE SET NULL,
    CONSTRAINT decisions_decision_type_check CHECK (decision_type IN (
        'dismiss', 'warn', 'hide_content', 'hide_site', 'suspend_user', 'ban_user',
        'override_csam_auto_action', 'escalate_law_enforcement', 'escalate_esafety'
    )),
    CONSTRAINT decisions_actor_xor CHECK (
        (decided_by_staff_id IS NOT NULL AND decided_by_system = FALSE)
        OR
        (decided_by_staff_id IS NULL AND decided_by_system = TRUE)
    ),
    CONSTRAINT decisions_csam_override_requires_second_staff CHECK (
        decision_type <> 'override_csam_auto_action'
        OR (second_staff_approval_id IS NOT NULL AND second_staff_approved_at IS NOT NULL)
    )
);

-- ── moderation.action_log ────────────────────────────────────
CREATE TABLE IF NOT EXISTS moderation.action_log (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    decision_id     UUID NOT NULL,
    action_type     VARCHAR(48) NOT NULL,
    action_target   JSONB NOT NULL DEFAULT '{}'::JSONB,
    job_uuid        VARCHAR(36) NULL,
    status          VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts        SMALLINT NOT NULL DEFAULT 0,
    failure_reason  TEXT NULL,
    dispatched_at   TIMESTAMPTZ NULL,
    completed_at    TIMESTAMPTZ NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT action_log_decision_fk FOREIGN KEY (decision_id)
        REFERENCES moderation.decisions(id) ON DELETE CASCADE,
    CONSTRAINT action_log_action_type_check CHECK (action_type IN (
        'sync_subdomain_kv', 'suspend_user', 'suspend_site', 'quarantine_media',
        'file_cybertip_report', 'notify_reported_user', 'notify_reporter',
        'notify_oncall_staff', 'purge_cloudflare_cache', 'redact_reporter_pii'
    )),
    CONSTRAINT action_log_status_check CHECK (status IN (
        'pending', 'dispatched', 'completed', 'failed', 'cancelled'
    ))
);

-- ── audit.moderation_events ──────────────────────────────────
-- Append-only. `app_backend` should have SELECT/INSERT only on the audit schema.
CREATE TABLE IF NOT EXISTS audit.moderation_events (
    id              UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    actor_kind      VARCHAR(16) NOT NULL,
    actor_staff_id  UUID NULL,
    action          VARCHAR(64) NOT NULL,
    target_type     VARCHAR(32) NULL,
    target_id       UUID NULL,
    payload         JSONB NOT NULL DEFAULT '{}'::JSONB,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT moderation_events_actor_kind_check CHECK (actor_kind IN ('staff', 'system')),
    CONSTRAINT moderation_events_actor_xor CHECK (
        (actor_kind = 'staff' AND actor_staff_id IS NOT NULL)
        OR
        (actor_kind = 'system' AND actor_staff_id IS NULL)
    ),
    CONSTRAINT moderation_events_staff_fk FOREIGN KEY (actor_staff_id)
        REFERENCES core.partna_staff(id) ON DELETE SET NULL
);

COMMIT;
