-- Audit log for MFA factor lifecycle events.
--
-- Single source of truth for "did the user enroll, what factors do they have,
-- have there been failed verifies recently?" Used by:
--   1. SupabaseAuthHookController (writes verify_success / verify_failed /
--      verify_rejected_by_hook on every MFA Verification Hook callback).
--   2. Brute-force enforcement (counts failed events in a rolling window).
--   3. Support tooling + security review (read).
--
-- Append-only by design — no UPDATE / DELETE paths. RLS denies user writes
-- entirely (only the service role inserts via the webhook handler).

CREATE TABLE IF NOT EXISTS core.auth_factor_events (
  id           uuid PRIMARY KEY DEFAULT gen_random_uuid(),
  user_id      uuid NOT NULL,
  session_id   uuid,
  event_type   text NOT NULL CHECK (event_type IN (
                   'enroll_started',
                   'enroll_completed',
                   'unenroll',
                   'challenge_issued',
                   'verify_success',
                   'verify_failed',
                   'verify_rejected_by_hook'
               )),
  factor_id    uuid,
  factor_type  text CHECK (factor_type IS NULL OR factor_type IN ('totp','phone','webauthn','recovery')),
  ip           inet,
  user_agent   text,
  metadata     jsonb NOT NULL DEFAULT '{}'::jsonb,
  created_at   timestamptz NOT NULL DEFAULT now()
);

-- Per-user history (support, security review)
CREATE INDEX IF NOT EXISTS auth_factor_events_user_created_idx
  ON core.auth_factor_events (user_id, created_at DESC);

-- Brute-force window query: partial index keeps it small + cheap.
-- Matches the hot query in SupabaseAuthHookService::countRecentFailures().
CREATE INDEX IF NOT EXISTS auth_factor_events_failed_window_idx
  ON core.auth_factor_events (user_id, factor_id, created_at DESC)
  WHERE event_type IN ('verify_failed', 'verify_rejected_by_hook');

ALTER TABLE core.auth_factor_events ENABLE ROW LEVEL SECURITY;

-- Service role only. No user-level access — Laravel reads via service role
-- through its existing DB connection (app_backend is granted via the role
-- escalation pattern documented in CLAUDE.md).
CREATE POLICY "service role inserts" ON core.auth_factor_events
  FOR INSERT TO service_role WITH CHECK (true);

CREATE POLICY "service role reads" ON core.auth_factor_events
  FOR SELECT TO service_role USING (true);

-- Deliberate: no UPDATE, no DELETE policies. Append-only.

COMMENT ON TABLE core.auth_factor_events IS
  'Append-only audit log for MFA factor lifecycle events. Written by webhook handler from Supabase MFA Verification Hook. Read by brute-force enforcement and support tooling.';
