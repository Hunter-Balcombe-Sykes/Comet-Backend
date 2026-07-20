# MFA Foundation — Operator Runbook

## What this ships

- `core.auth_factor_events` — append-only audit log for MFA lifecycle.
- `VerifySupabaseJwt` exposes `aal`, `amr`, `session_id` on request attributes.
- `require.aal2` middleware — rejects AAL1 requests with `401 {code: "mfa_required"}`.
- `BasePolicy::requiresAal2()` / `requiresFreshAal2($seconds)` helpers.
- All staff API routes (`/api/staff/*`) require AAL2.
- `SupabaseAuthHookController::mfaVerification` — receives every Supabase MFA verify attempt, enforces a 5/5min brute-force threshold.
- `DELETE /api/account/mfa/factors/{factorId}` — self-service unenroll, requires fresh AAL2 (60s).

## Rollout sequence (do these in order)

1. ✅ Merge this PR.
2. ✅ Confirm `/api/webhooks/supabase/auth/mfa-verification` is reachable on dev.
   The route is POST-only (`routes/api.php`) — a bare `curl -i` defaults to GET and
   dies at routing with 405 before ever reaching the signature check, so it proves
   nothing about the auth gate. Use `-X POST` with no body/signature headers:
   ```bash
   curl -i -X POST https://dev-api.partna.au/api/webhooks/supabase/auth/mfa-verification
   ```
   Expected: `401 {"error":"invalid_signature","message":"Invalid webhook signature."}`
   (NOT 404 — the route exists; NOT 405 — POST is required to reach
   `VerifySupabaseHookSignature` rather than dying at routing).
3. ✅ In Supabase Dashboard for the dev project (`glncumufgaqcmqhzwrxm`):
   - Authentication → Hooks → enable **MFA Verification Hook**.
   - URL: `https://dev-api.partna.au/api/webhooks/supabase/auth/mfa-verification`.
   - Secret: paste the value from `SUPABASE_AUTH_HOOK_SECRET`.
4. ✅ Enable TOTP MFA in the same project's Authentication → Settings → MFA.
5. ✅ Smoke-test from a frontend test session: enroll a TOTP factor, verify it, confirm the dashboard shows the factor.
6. ✅ Check `core.auth_factor_events` populates correctly:
   ```sql
   SELECT event_type, factor_type, created_at
   FROM core.auth_factor_events
   ORDER BY created_at DESC
   LIMIT 20;
   ```
7. ✅ Soak on dev for 1 week. Watch Nightwatch for any 5xx on the webhook endpoint.
8. ✅ Repeat steps 3–6 on prod (`edplucmvkcnokyygxqsb`).

## How to test brute-force rejection (without locking yourself out)

Enroll a TOTP factor against your test user. Then deliberately enter 6 wrong codes back-to-back in the frontend. The 6th attempt should surface "Too many failed verification attempts. Try again in 5 minutes."

Verify in DB:

```sql
SELECT event_type, count(*) FROM core.auth_factor_events
WHERE user_id = '<your-test-uid>'
GROUP BY event_type;
```

Expected: 5× `verify_failed`, 1× `verify_rejected_by_hook`.

To reset for retry: wait 5 minutes, or `DELETE FROM core.auth_factor_events WHERE user_id = '<your-test-uid>' AND event_type IN ('verify_failed','verify_rejected_by_hook');` (test data only — never on prod).

## "I'm locked out" support procedure

A user with a lost authenticator factor cannot self-recover (we don't issue recovery codes — see decisions in this plan).

Support steps:
1. Verify the user's identity out-of-band (call back to a verified phone number, or photo ID match on a video call — adapt to your policy).
2. Find the user in Supabase Dashboard → Authentication → Users.
3. Click into the user → Multi-factor authentication → remove the factor manually.
4. Tell the user to enroll a new factor on their next login.

This is intentionally manual — automating it via a `support:remove-mfa-factor` Artisan command is deferred until support volume warrants it.

## Tunables (`config/partna.php` → `mfa`)

| Key | Default | When to change |
|---|---|---|
| `fresh_window_seconds` | 300 | Make tighter only if you see step-up bypass abuse |
| `unenroll_fresh_window_seconds` | 60 | Probably leave |
| `verify_max_failures` | 5 | Lower if real attacks measured |
| `verify_failure_window_seconds` | 300 | |

Change via env var (e.g. `SIDEST_MFA_VERIFY_MAX_FAILURES=3`) — no redeploy required, just `config:clear`.

## Adding AAL2 to a user route later

```php
// In a policy:
public function update(User $user, Site $site): Response
{
    if ($user->id !== $site->user_id) return Response::denyWithStatus(404, 'Not found');
    return $this->requiresFreshAal2(); // uses config default (300s)
}
```

Or apply standing AAL2 at the route level by adding `require.aal2` to the middleware chain.

## What's deliberately NOT here

- **SMS / phone factor** — deferred (SIM-swap risk, Twilio cost).
- **WebAuthn / passkey** — deferred until Supabase marks GA.
- **SSO (SAML/OIDC)** — deferred until first enterprise customer.
- **Risk-based / adaptive auth** — deferred until measured attack pressure exists.
- **Audit log retention/archival job** — current schema keeps everything; revisit at scale.
