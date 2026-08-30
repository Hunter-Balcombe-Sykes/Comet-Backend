# MFA Foundation

## Overview

This document covers the AAL2/MFA enforcement layer built in the MFA Foundation phase. It is **staff-enforced and dormant for end-users**: staff routes require aal2 immediately; user-facing MFA enrollment is a future phase.

## Key Concepts

### Supabase AAL (Authenticator Assurance Level)

Supabase issues `aal` in the JWT claim:

- `aal1` — password only (no MFA enrolled or not verified this session)
- `aal2` — MFA verified this session

The `amr` (Authentication Methods Reference) array records each step with a `method` and `timestamp`. This lets us detect **freshness**: an `aal2` token can be hours old, but `amr` tells us exactly when the TOTP was last verified.

### Why `amr` for freshness, not `aal`

Supabase keeps `aal` sticky at `aal2` across token refreshes once MFA is verified. You cannot use `aal === 'aal2'` to know if MFA was verified *recently*. Instead, inspect the oldest MFA-method entry in `amr` and compare its `timestamp` to `now()`.

## Request Attributes

`VerifySupabaseJwt` promotes these JWT claims to top-level request attributes:

| Attribute | Source claim | Default |
|-----------|-------------|---------|
| `supabase_uid` | `sub` | — |
| `supabase_aal` | `aal` | `'aal1'` |
| `supabase_amr` | `amr` | `[]` |
| `supabase_session_id` | `session_id` | `null` |

Middlewares and policies read from attributes, not the raw JWT claims, to keep the AAL logic in one place.

## Middleware: `require.aal2`

**Class:** `App\Http\Middleware\Auth\RequireAal2`

**Alias:** `require.aal2`

Returns `401 { "message": "MFA required", "code": "mfa_required" }` if `supabase_aal !== 'aal2'`.

Applied to every staff route group (viewing and admin).

## Policy Helpers: `BasePolicy`

Two helpers are available for any policy to call:

### `requiresAal2(): Response`

Gate: the current request has `aal2`. Use for privileged write operations.

```php
public function destroy(Professional $pro, SomeModel $resource): Response
{
    return $this->requiresAal2();
}
```

### `requiresFreshAal2(?int $maxAgeSeconds = null): Response`

Gate: MFA was verified within `$maxAgeSeconds` (default: `config('partna.mfa.fresh_window_seconds')` = 300s). Inspects `amr` to find the most-recent TOTP/phone/WebAuthn entry.

Use for high-sensitivity actions where a stale `aal2` token is insufficient.

`StaffUserController` uses a local (non-policy) wrapper around the same `Aal2FreshnessGate` for
`updateStatus`, `bulkUpdateStatus`, `update`, `destroy`, `restore`, `forceDestroy` and `releaseClaim` —
a controller cannot call this `protected` policy method directly, so it delegates to the shared gate
service instead of duplicating the freshness logic.

## MFA Factor Unenroll Endpoint

**Route:** `DELETE /api/account/mfa/factors/{factorId}`

**Middleware:** `supabase.jwt`, `current.pro`

**Controller:** `App\Http\Controllers\Api\Professional\Account\MfaController@destroy`

Requires fresh-AAL2 (60s window, overrides the default 300s). Calls `SupabaseAdminService::unenrollMfaFactor()` which hits the Supabase Admin API. Records an `unenroll` event to `core.auth_factor_events`.

Returns `200 {"ok": true}` on success, `502` if Supabase Admin API fails.

## Supabase Auth Hook: MFA Verification

**Route:** `POST /api/webhooks/supabase/auth/mfa-verification`

**Controller:** `App\Http\Controllers\Api\Webhooks\SupabaseAuthHookController@mfaVerification`

This endpoint is registered in Supabase as an **Auth Hook** (MFA Verification). Supabase calls it on every TOTP/phone/WebAuthn verification attempt. It:

1. Verifies the Standard Webhooks HMAC-SHA256 signature (supports multi-sig rotation)
2. Checks brute-force window (default: 5 failures in 300s via `core.auth_factor_events`)
3. Records the event to `core.auth_factor_events`
4. Returns `{ "decision": "continue" | "reject" }` with an optional `message` — a plain `continue` on a failed attempt already fails that attempt (Supabase's default behaviour for `valid: false`); `reject` additionally denies the attempt and logs the user out of all active sessions

### Signature verification

Follows the [Standard Webhooks spec](https://www.standardwebhooks.com/). The signing key comes from `config('services.supabase.auth_hook_secret')` (env: `SUPABASE_AUTH_HOOK_SECRET`). The header is `webhook-signature`, which may contain space-separated multi-sig values — but that's sender-side rotation support (multiple candidate signatures checked against our one configured secret). Supabase holds a single secret per hook and doesn't dual-sign, so this does not by itself give us zero-downtime secret rotation. See `docs/runbooks/secret-rotation.md` for the actual rotation procedure and its accepted downtime window.

## Config Keys (`config/partna.php`)

| Key | Env var | Default | Meaning |
|-----|---------|---------|---------|
| `mfa.fresh_window_seconds` | `SIDEST_MFA_FRESH_WINDOW_SECONDS` | 300 | Max age (seconds) for fresh-AAL2 policy helper |
| `mfa.unenroll_fresh_window_seconds` | `SIDEST_MFA_UNENROLL_WINDOW_SECONDS` | 60 | Fresh-AAL2 window for factor unenroll |
| `mfa.verify_max_failures` | `SIDEST_MFA_VERIFY_MAX_FAILURES` | 5 | Brute-force cap per factor |
| `mfa.verify_failure_window_seconds` | `SIDEST_MFA_VERIFY_WINDOW_SECONDS` | 300 | Brute-force window (seconds) |

## Database: `core.auth_factor_events`

Append-only audit log. No UPDATE or DELETE policies — rows are immutable once written.

| Column | Type | Notes |
|--------|------|-------|
| `id` | uuid PK | |
| `user_id` | uuid | Supabase user UUID |
| `factor_id` | uuid | MFA factor UUID |
| `event_type` | text | `verify_success`, `verify_failed`, `verify_rejected_by_hook`, `unenroll` |
| `ip` | inet | Request IP |
| `user_agent` | text | |
| `created_at` | timestamptz | Immutable insert time |

A partial index on `(user_id, factor_id, created_at)` WHERE `event_type IN ('verify_failed', 'verify_rejected_by_hook')` keeps brute-force window queries fast.

## Testing

### `actingAsProfessional` claims

The `actingAsProfessional($pro, $claims)` helper in `tests/Pest.php` now accepts an optional `$claims` array. Use `aal2ClaimsWithFreshTotp()` from the same file to construct valid aal2 claims:

```php
actingAsProfessional($pro, aal2ClaimsWithFreshTotp(verifiedSecondsAgo: 30))
    ->deleteJson('/api/account/mfa/factors/' . $factorId)
    ->assertNoContent();
```

### Staff tests

All staff integration tests use `actingAsStaffWithUid($uid)` which sets `supabase_aal = 'aal2'` so the `require.aal2` middleware passes automatically.
