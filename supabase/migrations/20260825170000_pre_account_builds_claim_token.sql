-- ManyChat claim links: a per-build capability that proves invitation.
--
-- Spec: docs/superpowers/specs/2026-08-25-manychat-claim-link-design.md
--
-- Stores the SHA-256 of the token, never the token. A DB read (backup, support
-- query, log) must not yield a working takeover capability for every live
-- build; the plaintext is returned exactly once, at mint time, and never again.
--
-- claim_idempotency_key lets a retried webhook call re-mint safely: the key
-- proves the caller is the one that created this build, which the dedupe path
-- cannot otherwise establish. Without it a lost HTTP response strands the
-- build as permanently unclaimable (spec §5.4).
--
-- No index on either: the token is never a lookup key (the claim path finds the
-- build by subdomain, then compares hashes) and the idempotency key is only
-- ever read on a row already in hand.
--
-- Nullable, no default, no backfill: existing builds keep NULL and continue to
-- claim through the email path exactly as before.
--
-- ROLLBACK: ALTER TABLE core.pre_account_builds
--             DROP COLUMN IF EXISTS claim_token_hash,
--             DROP COLUMN IF EXISTS claim_token_issued_at,
--             DROP COLUMN IF EXISTS claim_idempotency_key;

BEGIN;

ALTER TABLE core.pre_account_builds
    ADD COLUMN IF NOT EXISTS claim_token_hash text,
    ADD COLUMN IF NOT EXISTS claim_token_issued_at timestamptz,
    ADD COLUMN IF NOT EXISTS claim_idempotency_key text;

COMMENT ON COLUMN core.pre_account_builds.claim_token_hash IS
    'SHA-256 of the claim token. Plaintext never stored. Cleared on successful claim (single-use).';
COMMENT ON COLUMN core.pre_account_builds.claim_token_issued_at IS
    'When the current claim token was minted. Observability + rotation only.';
COMMENT ON COLUMN core.pre_account_builds.claim_idempotency_key IS
    'Caller-supplied key from the ManyChat webhook. A retry matching this re-mints instead of stranding the build.';

COMMIT;
