-- Claim-invite outreach extensions (spec 2026-07-21-claim-invite-outreach-design).
-- + invited_at  — send idempotency + "already invited" signal (ClaimNotifier stamps it)
-- + auto_invite — false = publish the site but DEFER the invite for manual review + send

BEGIN;

SET LOCAL lock_timeout      = '2s';
SET LOCAL statement_timeout = '10s';

ALTER TABLE core.pre_account_builds
    ADD COLUMN IF NOT EXISTS invited_at  timestamptz NULL,
    ADD COLUMN IF NOT EXISTS auto_invite boolean NOT NULL DEFAULT true;

COMMIT;
