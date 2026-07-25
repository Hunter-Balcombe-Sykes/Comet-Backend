-- 20260711000300_early_access_signups.sql
--
-- OV-A: early-access programme. Rows arrive from the public marketing form
-- (source='marketing', status='waitlist') or staff manual entry
-- (source='manual'). Staff invites flip status → 'invited' and stamp a
-- sha256 invite-token hash; completing signup through /bootstrap with the
-- token flips status → 'signed_up'. Statuses only move forward.
--
-- invite_meta (manual invites only) carries prefill hints for the invited
-- account: {"integrations": {"instagram": "...", "other": [{"platform","url"}]},
--           "architecture": {...}} — consumed by the signup flow post-OTP.
-- platforms = the 2–3 platforms the signer-upper said they use (marketing form).
-- PII posture mirrors core.waitlist_signups (hashed IP, truncated UA).
-- Down: DROP TABLE IF EXISTS core.early_access_signups;

CREATE TABLE IF NOT EXISTS core.early_access_signups (
    id                    uuid PRIMARY KEY DEFAULT gen_random_uuid(),
    email                 text NOT NULL,
    email_lc              text NOT NULL,
    type                  text NOT NULL CHECK (type IN ('partna', 'business')),
    workplace_or_industry text,
    platforms             jsonb NOT NULL DEFAULT '[]'::jsonb,
    status                text NOT NULL DEFAULT 'waitlist' CHECK (status IN ('waitlist', 'invited', 'signed_up')),
    source                text NOT NULL DEFAULT 'marketing' CHECK (source IN ('marketing', 'manual')),
    invited_at            timestamptz,
    invite_token_hash     text,
    invite_meta           jsonb,
    invited_by            uuid REFERENCES core.partna_staff(id) ON DELETE SET NULL,
    signed_up_at          timestamptz,
    consent_ip_hash       text,
    consent_user_agent    text,
    created_at            timestamptz NOT NULL DEFAULT now(),
    updated_at            timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT early_access_signups_email_lc_unique UNIQUE (email_lc),
    CONSTRAINT early_access_signups_platforms_is_array CHECK (jsonb_typeof(platforms) = 'array')
);

COMMENT ON TABLE core.early_access_signups IS
  'Early-access waitlist + invite lifecycle (waitlist → invited → signed_up). Public marketing endpoint creates; staff manage/invite; bootstrap consumes invite tokens (sha256 in invite_token_hash).';

-- New empty table — same-file indexes exempt from the CONCURRENTLY rule.
CREATE UNIQUE INDEX IF NOT EXISTS early_access_signups_invite_token_hash_unique
    ON core.early_access_signups (invite_token_hash) WHERE invite_token_hash IS NOT NULL;
CREATE INDEX IF NOT EXISTS early_access_signups_status_idx
    ON core.early_access_signups (status);

ALTER TABLE core.early_access_signups ENABLE ROW LEVEL SECURITY;

GRANT SELECT, INSERT, UPDATE, DELETE ON core.early_access_signups TO app_backend;

drop policy if exists early_access_signups_staff_select on core.early_access_signups;
create policy early_access_signups_staff_select on core.early_access_signups
    for select to authenticated
    using (exists (
        select 1 from core.partna_staff ps
        where ps.auth_user_id = auth.uid() and ps.role in ('admin', 'support')
    ));

drop policy if exists early_access_signups_service_role_all on core.early_access_signups;
create policy early_access_signups_service_role_all on core.early_access_signups
    for all to service_role
    using (true) with check (true);
