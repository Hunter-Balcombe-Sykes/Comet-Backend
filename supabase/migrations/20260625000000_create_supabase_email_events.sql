-- core.supabase_email_events — WHK-3 forensic trail for Supabase auth-email webhook deliveries.
--
-- Why core, not audit: the status column must be UPDATE-able (queued → failed or
-- unhandled). The audit schema is append-only (INSERT/SELECT only for app_backend);
-- updating a row there would violate the audit contract. core has a full CRUD grant
-- for app_backend in the baseline, so no extra GRANT is needed.
--
-- PII posture: recipient email is stored as a SHA256 HMAC hash only — never plaintext.
-- The raw_payload is stripped of all token/secret and plaintext email fields before
-- storage. No token_hash column exists here; replay capability (WHK-4) is deferred
-- and will be added in a future migration when token retention is approved.
--
-- Dedup: UNIQUE(webhook_id) provides a durable backstop to the 300s Redis cache.
-- A Supabase retry arriving after the Redis window cannot create a duplicate row.

BEGIN;

CREATE TABLE IF NOT EXISTS core.supabase_email_events (
    id              uuid        DEFAULT gen_random_uuid() NOT NULL,

    -- Standard Webhooks idempotency key — drives both the Redis short-window dedup
    -- in the controller and the durable UNIQUE constraint here.
    webhook_id      text        NOT NULL,

    -- X-Request-Id from Cloudflare / Laravel Cloud — correlates to Cloud log lines.
    request_id      text        NULL,

    -- Supabase email_data.email_action_type: signup/recovery/magiclink/invite/
    -- email_change/email_change_new/email_change_current/etc.
    action_type     text        NOT NULL,

    -- SHA256 HMAC of the recipient email using app.key as the pepper.
    -- Allows correlation across events for the same recipient without storing plaintext.
    recipient_email_hash  text  NULL,

    -- Redacted copy of the inbound JSON payload. Tokens, token_hash, token_new,
    -- email, and new_email are stripped before storage. Kept for debugging
    -- non-PII structural issues (wrong action type, missing fields, Supabase version
    -- skew). Never contains plaintext email or auth tokens.
    raw_payload     jsonb       NOT NULL,

    -- Outcome. WHK-4 (deferred) would add a 'replayed' status + token retention
    -- column in a future migration once the token-retention PII posture is approved.
    status          text        NOT NULL DEFAULT 'queued',

    -- Error message captured from Throwable::getMessage() on failure.
    error           text        NULL,

    -- Timestamps for each outcome branch. Nullable — only the relevant one is set.
    queued_at       timestamptz NULL,
    failed_at       timestamptz NULL,

    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT supabase_email_events_pkey PRIMARY KEY (id),
    CONSTRAINT supabase_email_events_webhook_id_unique UNIQUE (webhook_id),
    CONSTRAINT supabase_email_events_status_check CHECK (
        status IN ('queued', 'failed', 'unhandled')
        -- WHK-4 (deferred): add 'replayed' here when token retention is approved.
    )
);

ALTER TABLE core.supabase_email_events OWNER TO postgres;

-- Index on (status, created_at DESC) for staff triage dashboards and
-- monitoring queries that filter by outcome and sort by recency.
-- Inline (non-CONCURRENTLY) is safe here because the table is empty on
-- first migration (CONVENTIONS.md §1).
CREATE INDEX supabase_email_events_status_created_idx
    ON core.supabase_email_events (status, created_at DESC);

-- updated_at trigger — reuses the shared public.set_updated_at() function
-- (defined in baseline, same pattern as DINT-10 in 20260624010000).
CREATE OR REPLACE TRIGGER set_timestamp_supabase_email_events
    BEFORE UPDATE ON core.supabase_email_events
    FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- RLS: app_backend writes via the service (no RLS bypass needed — it authenticates
-- as the Supabase service role on the API path). Staff read via authenticated JWT.
-- FORCE RLS so even a direct postgres connection cannot read rows without the policy.
ALTER TABLE core.supabase_email_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE core.supabase_email_events FORCE ROW LEVEL SECURITY;

-- Staff-only SELECT. Mirrors the partna_staff predicate used in feedback,
-- core.partna_staff, and the moderation tables — keyed on auth.uid().
-- No tenant-user USING clause: this table has no user_id column; it is an
-- internal system table, not a per-user resource.
CREATE POLICY supabase_email_events_staff_read ON core.supabase_email_events
    FOR SELECT TO authenticated
    USING (
        EXISTS (
            SELECT 1 FROM core.partna_staff cs
            WHERE cs.auth_user_id = auth.uid()
        )
    );

COMMENT ON TABLE core.supabase_email_events IS
    'WHK-3: Forensic trail of Supabase auth-email webhook outcomes. '
    'One row per unique webhook_id; status is queued/failed/unhandled. '
    'Email is hashed (SHA256 HMAC); raw_payload is token-stripped. '
    'WHK-4 (replay) is deferred — no token column here.';

COMMIT;
