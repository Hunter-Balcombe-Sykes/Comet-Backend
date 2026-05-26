-- Feedback submissions from authenticated users via the dashboard.
--
-- Foundational schema with extension points reserved for: anonymous
-- submissions (user_id nullable, source enum), public-site embeds
-- (source='public_site' allowed), admin triage UI (status, internal_notes,
-- tags), attachments (no schema change needed; reuse SiteMedia pool later),
-- and Slack/webhook channels (handled in jobs, not schema).
--
-- See docs/superpowers/plans/2026-05-25-feedback-system.md for design rationale.

BEGIN;

CREATE TABLE IF NOT EXISTS core.feedback (
    id uuid DEFAULT gen_random_uuid() NOT NULL,

    -- Who. user_id nullable + ON DELETE SET NULL preserves submission for
    -- product analytics while severing PII linkage on account deletion.
    user_id uuid NULL,
    reply_email text NULL,

    -- What
    kind text NOT NULL,
    severity text NULL,
    message text NOT NULL,

    -- Debugging context — request_id correlates to Cloud logs (see #LIFE-2/3
    -- where request_id was added to every Supabase hook log).
    page_url text NULL,
    user_agent text NULL,
    viewport text NULL,
    app_version text NULL,
    request_id text NULL,

    -- Triage. Schema reserves space for the future admin UI so adding it
    -- requires zero migration.
    status text NOT NULL DEFAULT 'new',
    internal_notes jsonb NOT NULL DEFAULT '[]'::jsonb,
    tags jsonb NOT NULL DEFAULT '[]'::jsonb,

    -- Source. Always 'dashboard' for now; extending the CHECK adds future
    -- entry points (public site embed, mobile, email-in) without a migration
    -- to schema.
    source text NOT NULL DEFAULT 'dashboard',

    -- IP stored as SHA256(ip || pepper). Pepper lives in env. Raw IP never
    -- persists.
    ip_hash text NULL,

    created_at timestamptz NOT NULL DEFAULT now(),
    updated_at timestamptz NOT NULL DEFAULT now(),
    deleted_at timestamptz NULL,

    CONSTRAINT feedback_pkey PRIMARY KEY (id),
    CONSTRAINT feedback_user_fk FOREIGN KEY (user_id)
        REFERENCES core.users(id) ON DELETE SET NULL,
    CONSTRAINT feedback_kind_check CHECK (
        kind IN ('bug', 'idea', 'praise', 'question', 'other')
    ),
    CONSTRAINT feedback_severity_check CHECK (
        severity IS NULL
        OR severity IN ('low', 'medium', 'high', 'critical')
    ),
    CONSTRAINT feedback_message_length_check CHECK (
        char_length(message) BETWEEN 1 AND 5000
    ),
    CONSTRAINT feedback_status_check CHECK (
        status IN ('new', 'triaged', 'in_progress', 'shipped', 'wontfix', 'duplicate')
    ),
    CONSTRAINT feedback_source_check CHECK (
        source IN ('dashboard', 'public_site', 'mobile', 'email', 'api')
    )
);

ALTER TABLE core.feedback OWNER TO postgres;

-- Indexes — inline non-CONCURRENTLY is safe here because the table is empty
-- on first migration. CONCURRENTLY is only required when adding indexes to
-- an already-populated table (CONVENTIONS.md §1).
CREATE INDEX feedback_user_id_idx
    ON core.feedback (user_id)
    WHERE deleted_at IS NULL;

CREATE INDEX feedback_status_created_idx
    ON core.feedback (status, created_at DESC)
    WHERE deleted_at IS NULL;

CREATE INDEX feedback_kind_idx
    ON core.feedback (kind)
    WHERE deleted_at IS NULL;

CREATE INDEX feedback_created_at_idx
    ON core.feedback (created_at DESC)
    WHERE deleted_at IS NULL;

-- For duplicate-window check + future abuse correlation.
CREATE INDEX feedback_ip_hash_recent_idx
    ON core.feedback (ip_hash, created_at DESC)
    WHERE deleted_at IS NULL AND ip_hash IS NOT NULL;

-- Standard updated_at trigger — mirrors set_timestamp_customers, etc.
CREATE OR REPLACE TRIGGER set_timestamp_feedback
    BEFORE UPDATE ON core.feedback
    FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- RLS. App access flows through the app_backend role (schema-wide grants
-- already set in baseline). RLS exists as defence-in-depth: the
-- authenticated policy lets a logged-in user touch only their own rows,
-- and staff bypass via the partna_staff check (mirrors site.customers).
ALTER TABLE core.feedback ENABLE ROW LEVEL SECURITY;

CREATE POLICY feedback_all_authenticated ON core.feedback TO authenticated
    USING (
        (EXISTS (
            SELECT 1 FROM core.users p
            WHERE p.id = feedback.user_id
              AND p.auth_user_id = auth.uid()
              AND p.deleted_at IS NULL
        ))
        OR (EXISTS (
            SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid()
        ))
    )
    WITH CHECK (
        (EXISTS (
            SELECT 1 FROM core.users p
            WHERE p.id = feedback.user_id
              AND p.auth_user_id = auth.uid()
              AND p.deleted_at IS NULL
        ))
        OR (EXISTS (
            SELECT 1 FROM core.partna_staff cs WHERE cs.auth_user_id = auth.uid()
        ))
    );

COMMENT ON TABLE core.feedback IS
    'In-app feedback submissions (bug/idea/praise/question/other). '
    'Authenticated dashboard submissions only at first; source/user_id '
    'are nullable-friendly to accept anonymous public-site submissions '
    'in a future iteration without schema change. Notification delivery '
    'is fire-and-forget via SendFeedbackEmailJob — failures do not block '
    'submission.';

COMMIT;
