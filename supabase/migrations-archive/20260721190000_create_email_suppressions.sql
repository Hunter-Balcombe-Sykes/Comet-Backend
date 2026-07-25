-- core.email_suppressions — durable send-time suppression list.
--
-- Purpose: a hard bounce (email.bounced type=Permanent) or spam complaint
-- (email.complained) from Resend adds the affected address here, and the
-- MessageSending gate (App\Listeners\BlockSuppressedRecipients) cancels any
-- outbound mail to a suppressed address. This protects the SHARED partna.au
-- sender reputation — re-hitting dead/complaining addresses drags down the
-- high-value OTP path too.
--
-- Why core, not audit: rows are upserted/updated (reason/source/detail refresh,
-- updated_at bumps on re-signal). The audit schema is append-only (INSERT/SELECT
-- only for app_backend); this needs UPDATE. core has a full CRUD grant for
-- app_backend in the baseline, so no extra GRANT is needed.
--
-- PII posture: the recipient email is stored ONLY as a SHA256 HMAC (app.key
-- pepper) — never plaintext. The same App\Support\EmailHasher scheme hashes both
-- the writer (webhook) and the reader (send-time gate), and matches WHK-3's
-- core.supabase_email_events so rows correlate. `detail` holds only a non-PII
-- bounce subtype (e.g. "Suppressed", "MailboxFull"), never the bounce message.
--
-- Idempotency: UNIQUE(email_hash). A repeated Resend event for the same address
-- upserts the existing row rather than creating a duplicate; first_seen_at is
-- preserved across re-signals.

BEGIN;

CREATE TABLE IF NOT EXISTS core.email_suppressions (
    id              uuid        DEFAULT gen_random_uuid() NOT NULL,

    -- SHA256 HMAC of the recipient email (app.key pepper). The lookup key —
    -- UNIQUE both dedups upserts and provides the send-time gate's index.
    email_hash      text        NOT NULL,

    -- Why the address is suppressed. Kept in sync with EmailSuppression::REASON_*.
    reason          text        NOT NULL,

    -- Origin of the signal, e.g. 'resend' (webhook) or 'manual' (ops action).
    source          text        NULL,

    -- Non-PII bounce classifier detail, e.g. the Resend bounce subType
    -- ("Suppressed", "MailboxFull", "MessageRejected"). NEVER the bounce message
    -- (which can echo the recipient address).
    detail          text        NULL,

    -- When we first recorded a suppression signal for this address. Preserved
    -- across later re-signals (created_at also captures row-insert time).
    first_seen_at   timestamptz NULL,

    created_at      timestamptz NOT NULL DEFAULT now(),
    updated_at      timestamptz NOT NULL DEFAULT now(),

    CONSTRAINT email_suppressions_pkey PRIMARY KEY (id),
    CONSTRAINT email_suppressions_email_hash_unique UNIQUE (email_hash),
    CONSTRAINT email_suppressions_reason_check CHECK (
        reason IN ('hard_bounce', 'complaint', 'manual')
    )
);

ALTER TABLE core.email_suppressions OWNER TO postgres;

-- Staff-triage index: list newest suppressions filtered by reason. Inline
-- (non-CONCURRENTLY) is safe — the table is empty on first apply (CONVENTIONS.md
-- §1). Do NOT pair a CONCURRENTLY statement with other statements in one file.
CREATE INDEX email_suppressions_reason_created_idx
    ON core.email_suppressions (reason, created_at DESC);

-- updated_at trigger — reuses the shared public.set_updated_at() function
-- (baseline), same pattern as core.supabase_email_events.
CREATE OR REPLACE TRIGGER set_timestamp_email_suppressions
    BEFORE UPDATE ON core.email_suppressions
    FOR EACH ROW EXECUTE FUNCTION public.set_updated_at();

-- RLS: app_backend writes via the service on the API path (no RLS bypass needed).
-- Staff read via authenticated JWT. FORCE RLS so even a direct postgres
-- connection cannot read rows without the policy.
ALTER TABLE core.email_suppressions ENABLE ROW LEVEL SECURITY;
ALTER TABLE core.email_suppressions FORCE ROW LEVEL SECURITY;

-- Staff-only SELECT. Mirrors supabase_email_events_staff_read — keyed on
-- auth.uid() via core.partna_staff. No tenant-user USING clause: this is an
-- internal system table with no user_id column.
CREATE POLICY email_suppressions_staff_read ON core.email_suppressions
    FOR SELECT TO authenticated
    USING (
        EXISTS (
            SELECT 1 FROM core.partna_staff cs
            WHERE cs.auth_user_id = auth.uid()
        )
    );

COMMENT ON TABLE core.email_suppressions IS
    'Send-time suppression list. One row per suppressed recipient (email stored '
    'as SHA256 HMAC only). reason = hard_bounce | complaint | manual. Written by '
    'the Resend bounce/complaint webhook; read by the MessageSending gate. '
    'Protects shared partna.au sender reputation.';

COMMIT;
