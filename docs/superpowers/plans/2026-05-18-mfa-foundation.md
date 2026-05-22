# MFA Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Establish the durable backend foundation for MFA — extract `aal`/`amr`/`session_id` claims from Supabase JWTs, gate staff routes on AAL2, audit factor lifecycle events with server-side brute-force protection, and ship a self-service unenroll endpoint protected by fresh-MFA (60s). No user-facing AAL2 enforcement on professional or brand routes yet — foundation only.

**Architecture:** The backend never owns factor state. Supabase Auth handles enrollment/challenge/verify; we read `aal` and `amr` claims from the JWT and gate routes/policies accordingly. An auth webhook receives every MFA verification attempt from Supabase, enforces a server-side brute-force threshold, and writes audit events to `core.auth_factor_events`. The foundation is composed of: (1) JWT middleware extension to expose claims as request attributes, (2) `RequireAal2` middleware, (3) `BasePolicy` helpers for AAL checks, (4) audit log table, (5) webhook handler with rate-limited rejection, (6) one user-facing unenroll endpoint as the only consumer of fresh-AAL2 today.

**Tech Stack:** Laravel 12, PHP 8.2, Pest 4, Supabase Auth + JWT (RS256/ES256), PostgreSQL (Supabase), Redis (cache only — brute-force tracking lives in the audit log table for durability across worker restarts).

---

## Decisions locked in (from spec)

| Decision | Choice | Rationale |
|---|---|---|
| Factor types at launch | **TOTP only** | No SMS (SIM-swap, Twilio cost); WebAuthn deferred until Supabase marks GA |
| Enforcement scope | **Staff-only mandatory; no user AAL2 gating yet** | Foundation in place, dormant for users. Future policy additions are one-line changes |
| Fresh-MFA TTL (general) | **300s** | Matches GitHub/Stripe; configurable in `config/sidest.php` |
| Fresh-MFA TTL (unenroll) | **60s** | Tighter window — the user is about to remove their own MFA, force re-verification with the factor they're removing |
| Brute-force threshold | **5 failed verifies per 5min** | GitHub-standard; tunable in `config/sidest.php` |
| Audit retention | **2 years hot, no expiration job in this plan** | Revisit at scale |
| MFA secrets | **Never touch — Supabase only** | Defense in depth; never put ourselves in the "we manage secrets" business |

## Pre-flight (operator steps — done by Josh, not the implementer)

1. **Dev only first.** Enable TOTP MFA in Supabase Dashboard for the **dev** project (`glncumufgaqcmqhzwrxm`): Authentication → Settings → MFA → enable TOTP. **Do NOT enable on prod (`edplucmvkcnokyygxqsb`) until dev has soaked for 1 week.**
2. **Generate webhook secret:** `openssl rand -hex 32`. Save it to Laravel Cloud env (dev environment first) as `SUPABASE_AUTH_HOOK_SECRET`. Note this secret — Supabase needs it too.
3. **Do NOT configure the MFA Verification Hook in Supabase yet.** That happens after this PR ships and the endpoint smoke-tests against dev. Order: (a) merge PR; (b) verify endpoint reachable; (c) configure hook in Supabase dashboard pointing to `https://dev-api.partna.au/api/webhooks/supabase/auth/mfa-verification`; (d) smoke-test a real enrollment.

## File map

### New files

| Path | Responsibility |
|---|---|
| `supabase/migrations/20260518100000_create_auth_factor_events.sql` | Audit log table + indexes + RLS |
| `app/Http/Middleware/Auth/RequireAal2.php` | Reject AAL1 requests on gated routes |
| `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php` | Receive MFA Verification Hook callbacks |
| `app/Services/Auth/SupabaseAuthHookService.php` | Verify Standard Webhooks signature, evaluate brute-force threshold, record events |
| `app/Services/Auth/AuthFactorEventRepository.php` | Typed DB access for `core.auth_factor_events` |
| `app/Http/Controllers/Api/Professional/Account/MfaController.php` | Self-service unenroll endpoint |
| `app/Http/Requests/Account/UnenrollMfaFactorRequest.php` | Form Request validation for unenroll |
| `tests/Feature/Auth/RequireAal2MiddlewareTest.php` | AAL middleware behavior |
| `tests/Feature/Auth/BasePolicyAalHelpersTest.php` | `hasAal2` / `hasFreshAal2` semantics |
| `tests/Feature/Auth/VerifySupabaseJwtClaimsExposureTest.php` | New attributes set on request |
| `tests/Feature/Security/Aal2RouteCoverageTest.php` | Sweep test — every staff route has `require.aal2` |
| `tests/Feature/Webhooks/SupabaseAuthHookSignatureTest.php` | Signature verification rejects forgeries |
| `tests/Feature/Webhooks/SupabaseAuthHookBruteForceTest.php` | Threshold rejection logic |
| `tests/Feature/Account/UnenrollMfaFactorTest.php` | End-to-end unenroll flow |
| `docs/auth/mfa-foundation.md` | Operator runbook + rollout checklist |

### Modified files

| Path | Change |
|---|---|
| `config/sidest.php` | Add `mfa` config block (windows, thresholds) |
| `config/supabase.php` | Add `auth_hook_secret` and `admin.base_url` |
| `app/Http/Middleware/Auth/VerifySupabaseJwt.php` | `setSupabaseContext` exposes `aal`/`amr`/`session_id` |
| `app/Policies/BasePolicy.php` | Add `requiresAal2()` and `requiresFreshAal2()` helpers |
| `bootstrap/app.php` | Register `require.aal2` middleware alias |
| `routes/api/staff.php` | Apply `require.aal2` to staff group |
| `routes/api.php` | Register webhook route (unauthenticated, signature-gated) |
| `app/Services/Auth/SupabaseAdminService.php` | Add `unenrollMfaFactor(uid, factorId)` |
| `tests/Pest.php` | Extend `actingAsProfessional` to accept claim overrides; add `setupAuthFactorEventsTable()` |

---

## Task 0: Branch prep

- [ ] **Step 1: Fetch + pull development, confirm working tree clean before branching**

```bash
git fetch origin
git checkout development
git pull origin development
git log --oneline -10
git status
```

Expected: working tree clean (other than untracked files already in `git status` from the snapshot).

- [ ] **Step 2: Create feature branch**

```bash
git checkout -b feat/mfa-foundation
```

- [ ] **Step 3: Verify composer autoload is clean (worktree gotcha)**

```bash
composer dump-autoload -o
```

This avoids the autoload-poisoning trap documented in memory `feedback_composer_autoload_worktrees`.

---

## Task 1: Config additions

**Files:**
- Modify: `config/sidest.php` — add `mfa` block
- Modify: `config/supabase.php` — add `auth_hook_secret`, `admin.base_url`

- [ ] **Step 1: Add MFA config block to `config/sidest.php`**

Append a new top-level key (place near other feature blocks). The exact location should match the file's existing structure; the block to add is:

```php
'mfa' => [
    /*
    | Default "fresh MFA" window in seconds — how long after a successful
    | TOTP/WebAuthn verify a request still counts as freshly-verified.
    | Used by BasePolicy::requiresFreshAal2() unless an explicit override
    | is passed (e.g. unenroll uses a tighter 60s window).
    */
    'fresh_window_seconds' => (int) env('SIDEST_MFA_FRESH_WINDOW_SECONDS', 300),

    /*
    | Tighter window specifically for the "remove my own MFA factor"
    | flow. The user is about to disable their own protection; force a
    | re-verification within the last minute with the factor they're
    | about to remove.
    */
    'unenroll_fresh_window_seconds' => (int) env('SIDEST_MFA_UNENROLL_WINDOW_SECONDS', 60),

    /*
    | Brute-force protection: maximum failed verifies (per user+factor)
    | within the rolling window. On the (N+1)-th attempt, the MFA
    | Verification Hook returns {decision: reject} for the duration of
    | the window. This is enforced BEFORE Supabase accepts the verify,
    | so the session never reaches aal2 from a brute-force attempt.
    */
    'verify_max_failures'  => (int) env('SIDEST_MFA_VERIFY_MAX_FAILURES', 5),
    'verify_failure_window_seconds' => (int) env('SIDEST_MFA_VERIFY_WINDOW_SECONDS', 300),
],
```

- [ ] **Step 2: Add hook secret and admin base URL to `config/supabase.php`**

In `config/supabase.php`, append before the closing `];`:

```php
    /*
    | Shared secret for Supabase Auth Hooks (Standard Webhooks signing).
    | Set in Supabase Dashboard → Authentication → Hooks alongside the
    | hook URL. Rotate via env var + dashboard update simultaneously.
    */
    'auth_hook_secret' => env('SUPABASE_AUTH_HOOK_SECRET'),

    /*
    | Admin API base URL — typically <SUPABASE_URL>/auth/v1/admin. Split
    | as its own config so we can point staging at a different host if
    | needed (e.g. for hermetic tests).
    */
    'admin' => [
        'base_url' => env('SUPABASE_ADMIN_BASE_URL', rtrim((string) env('SUPABASE_URL'), '/').'/auth/v1/admin'),
    ],
```

- [ ] **Step 3: Add env keys to `.env.example`**

Append these lines to `.env.example` (in the Supabase / Sidest section):

```
SIDEST_MFA_FRESH_WINDOW_SECONDS=300
SIDEST_MFA_UNENROLL_WINDOW_SECONDS=60
SIDEST_MFA_VERIFY_MAX_FAILURES=5
SIDEST_MFA_VERIFY_WINDOW_SECONDS=300
SUPABASE_AUTH_HOOK_SECRET=
SUPABASE_ADMIN_BASE_URL=
```

- [ ] **Step 4: Verify config loads without error**

```bash
php artisan config:clear && php artisan config:cache && php artisan config:clear
```

Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add config/sidest.php config/supabase.php .env.example
git commit -m "feat(mfa): add config block for MFA windows and hook secret

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 2: Audit log migration

**Files:**
- Create: `supabase/migrations/20260518100000_create_auth_factor_events.sql`

> Filename uses today's date prefix; bump the suffix if the timestamp collides with another migration added in the meantime. The remote Supabase timestamp convention is enforced by recent rename work (see commit `c550dd1d` — phone migration renamed to match remote).

- [ ] **Step 1: Write the migration**

Create `supabase/migrations/20260518100000_create_auth_factor_events.sql`:

```sql
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
```

- [ ] **Step 2: Apply to dev Supabase**

```bash
supabase link --project-ref glncumufgaqcmqhzwrxm
supabase db push --dry-run
```

Inspect the dry-run output — should show ONLY the new migration. If it shows other unexpected changes, STOP and investigate before pushing.

```bash
supabase db push
```

- [ ] **Step 3: Verify the table exists in dev**

```bash
supabase db reset --linked   # NO — DON'T DO THIS. Just verifying syntax.
```

Instead, verify via the Supabase MCP:

Use the `mcp__claude_ai_Supabase__list_tables` tool with `schemas=["core"]` and confirm `auth_factor_events` is in the list with the expected columns.

- [ ] **Step 4: Commit the migration**

```bash
git add supabase/migrations/20260518100000_create_auth_factor_events.sql
git commit -m "feat(mfa): add core.auth_factor_events audit log table

Append-only event log written by the MFA verification webhook handler.
Used for brute-force enforcement (partial index on failed events) and
support/security review.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 3: Test scaffolding — extend `actingAsProfessional` and add table helper

**Files:**
- Modify: `tests/Pest.php` — extend `actingAsProfessional` to accept Supabase claim overrides; add `setupAuthFactorEventsTable()`

The existing `actingAsProfessional()` (in `tests/Pest.php`) only sets `supabase_uid`. We need it to also set `supabase_claims`, `supabase_aal`, `supabase_amr`, `supabase_session_id` so AAL-aware tests can simulate aal1 vs aal2 sessions.

- [ ] **Step 1: Extend `actingAsProfessional` signature**

In `tests/Pest.php`, locate the existing `actingAsProfessional` function (around line 79). Replace its body with:

```php
function actingAsProfessional(
    \App\Models\Core\Professional\Professional $professional,
    array $claims = [],
): \Pest\Support\HigherOrderTapProxy {
    $supabaseUid = $professional->auth_user_id ?? (string) \Illuminate\Support\Str::uuid();

    $defaultClaims = [
        'sub' => $supabaseUid,
        'aal' => 'aal1',
        'amr' => [],
        'session_id' => (string) \Illuminate\Support\Str::uuid(),
    ];
    $resolvedClaims = array_merge($defaultClaims, $claims);

    app()->bind(\App\Http\Middleware\Auth\VerifySupabaseJwt::class, function () use ($supabaseUid, $resolvedClaims) {
        return new class($supabaseUid, $resolvedClaims)
        {
            public function __construct(
                private readonly string $uid,
                private readonly array $claims,
            ) {}

            public function handle(\Illuminate\Http\Request $request, \Closure $next)
            {
                $request->attributes->set('supabase_uid', $this->uid);
                $request->attributes->set('supabase_claims', $this->claims);
                $request->attributes->set('supabase_aal', $this->claims['aal'] ?? 'aal1');
                $request->attributes->set('supabase_amr', $this->claims['amr'] ?? []);
                $request->attributes->set('supabase_session_id', $this->claims['session_id'] ?? null);

                return $next($request);
            }
        };
    });

    app()->bind(\App\Http\Middleware\Context\LoadCurrentProfessional::class, function () use ($professional) {
        return new class($professional)
        {
            public function __construct(private readonly \App\Models\Core\Professional\Professional $pro) {}

            public function handle(\Illuminate\Http\Request $request, \Closure $next)
            {
                $request->attributes->set('professional', $this->pro);

                return $next($request);
            }
        };
    });

    return test();
}
```

- [ ] **Step 2: Add a helper for aal2 with a fresh totp amr entry**

Below `actingAsProfessional`, add a convenience helper:

```php
/**
 * Build an aal2 claim set with a TOTP verification timestamp in the
 * `amr` array. Use when a test needs "the user just verified MFA".
 *
 * @param  int  $verifiedSecondsAgo  How long ago the totp verify happened
 * @return array{aal: string, amr: array<int, array{method: string, timestamp: int}>}
 */
function aal2ClaimsWithFreshTotp(int $verifiedSecondsAgo = 0): array
{
    return [
        'aal' => 'aal2',
        'amr' => [
            ['method' => 'totp', 'timestamp' => time() - $verifiedSecondsAgo],
            ['method' => 'magiclink', 'timestamp' => time() - $verifiedSecondsAgo - 60],
        ],
    ];
}
```

- [ ] **Step 3: Add `setupAuthFactorEventsTable()` helper**

After other `setupXxxTable()` helpers (around line 569 where `setupProfessionalIntegrationsTable` lives), add:

```php
/**
 * core.auth_factor_events — append-only MFA audit log.
 * Mirrors the production schema closely enough for hook + brute-force tests.
 */
function setupAuthFactorEventsTable(): void
{
    attachTestSchemas();
    \Illuminate\Support\Facades\DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS core.auth_factor_events (
        id TEXT PRIMARY KEY,
        user_id TEXT NOT NULL,
        session_id TEXT NULL,
        event_type TEXT NOT NULL,
        factor_id TEXT NULL,
        factor_type TEXT NULL,
        ip TEXT NULL,
        user_agent TEXT NULL,
        metadata TEXT NULL DEFAULT \'{}\',
        created_at TEXT NULL
    )');
}
```

- [ ] **Step 4: Verify Pest still boots**

```bash
composer test -- --filter "actingAsProfessional"
```

Expected: tests that already use `actingAsProfessional()` still pass. If any test fails because the new signature broke them, that's a real regression — the new `array $claims = []` default should be fully backward-compatible.

- [ ] **Step 5: Commit**

```bash
git add tests/Pest.php
git commit -m "test(mfa): extend actingAsProfessional with claim overrides

Adds optional \$claims argument so tests can simulate aal1 vs aal2
sessions. Includes aal2ClaimsWithFreshTotp() and a SQLite-shaped
core.auth_factor_events table helper for upcoming hook tests.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 4: Extend `VerifySupabaseJwt` to expose AAL / AMR / session_id

**Files:**
- Modify: `app/Http/Middleware/Auth/VerifySupabaseJwt.php:127-139` (`setSupabaseContext`)
- Test: `tests/Feature/Auth/VerifySupabaseJwtClaimsExposureTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Auth/VerifySupabaseJwtClaimsExposureTest.php`:

```php
<?php

use App\Models\Core\Professional\Professional;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    setupProfessionalsTable();

    // Tiny inline route that echoes the supabase request attributes so we
    // can assert what the middleware exposed.
    Route::middleware(['supabase.jwt'])->get('/__test/supabase-attrs', function () {
        $request = request();
        return response()->json([
            'uid' => $request->attributes->get('supabase_uid'),
            'aal' => $request->attributes->get('supabase_aal'),
            'amr' => $request->attributes->get('supabase_amr'),
            'session_id' => $request->attributes->get('supabase_session_id'),
            'claims_present' => $request->attributes->has('supabase_claims'),
        ]);
    });
});

it('exposes aal, amr, and session_id on the request attributes', function () {
    $pro = Professional::factory()->create();

    actingAsProfessional($pro, aal2ClaimsWithFreshTotp(30))
        ->getJson('/__test/supabase-attrs')
        ->assertOk()
        ->assertJson([
            'aal' => 'aal2',
            'claims_present' => true,
        ])
        ->assertJsonPath('amr.0.method', 'totp');
});

it('defaults aal to aal1 when the claim is absent', function () {
    $pro = Professional::factory()->create();

    actingAsProfessional($pro) // no claim override
        ->getJson('/__test/supabase-attrs')
        ->assertOk()
        ->assertJson([
            'aal' => 'aal1',
            'amr' => [],
        ]);
});
```

- [ ] **Step 2: Run the test — confirm it FAILS for the right reason**

```bash
composer test -- --filter VerifySupabaseJwtClaimsExposure
```

Expected: the test will pass for the `actingAsProfessional` path because Task 3 already sets the attributes in the stub. **However**, this test will not yet cover the *real* `VerifySupabaseJwt` middleware. The point of the test is to lock the contract: anything that satisfies the `supabase.jwt` middleware alias MUST set these attributes. Updating the production middleware is Step 3.

If the test fails because `aal2ClaimsWithFreshTotp` isn't found, double-check Task 3 was committed.

- [ ] **Step 3: Update the production middleware**

In `app/Http/Middleware/Auth/VerifySupabaseJwt.php`, modify `setSupabaseContext` (currently at lines 127-139). Replace its body with:

```php
private function setSupabaseContext(Request $request, string $uid, ?array $claims = null): void
{
    $request->attributes->set('supabase_uid', $uid);

    if ($claims !== null) {
        $request->attributes->set('supabase_claims', $claims);
        // Hot-accessed claims promoted to top-level attributes so policies
        // and middleware don't reparse $claims on every check.
        $request->attributes->set('supabase_aal', $claims['aal'] ?? 'aal1');
        $request->attributes->set('supabase_amr', $claims['amr'] ?? []);
        $request->attributes->set('supabase_session_id', $claims['session_id'] ?? null);
    } else {
        // Auth-Server fallback path: no claims available. Default to aal1
        // so downstream policies fail safe (treat as not-MFA-verified).
        $request->attributes->set('supabase_aal', 'aal1');
        $request->attributes->set('supabase_amr', []);
        $request->attributes->set('supabase_session_id', null);
    }

    // Nightwatch falls back to hidden context when no Laravel auth guard is resolved.
    if (class_exists(\Laravel\Nightwatch\Compatibility::class)) {
        \Laravel\Nightwatch\Compatibility::addUserIdToContext($uid);
    }
}
```

- [ ] **Step 4: Run the test — confirm it PASSES**

```bash
composer test -- --filter VerifySupabaseJwtClaimsExposure
```

Expected: both tests pass.

- [ ] **Step 5: Run the existing JWT middleware test suite (regression check)**

```bash
composer test -- --filter VerifySupabaseJwtFallback
```

Expected: existing tests still pass — the change is purely additive.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Middleware/Auth/VerifySupabaseJwt.php tests/Feature/Auth/VerifySupabaseJwtClaimsExposureTest.php
git commit -m "feat(mfa): expose aal, amr, session_id on request attributes

Lays the foundation for AAL-aware middleware and policy checks. The
auth-server fallback path defaults to aal1 / empty amr so downstream
checks fail safe.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 5: `RequireAal2` middleware

**Files:**
- Create: `app/Http/Middleware/Auth/RequireAal2.php`
- Modify: `bootstrap/app.php` — register `require.aal2` alias
- Test: `tests/Feature/Auth/RequireAal2MiddlewareTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Auth/RequireAal2MiddlewareTest.php`:

```php
<?php

use App\Models\Core\Professional\Professional;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    setupProfessionalsTable();

    Route::middleware(['supabase.jwt', 'require.aal2'])
        ->get('/__test/aal2-gate', fn () => response()->json(['ok' => true]));
});

it('returns 401 with mfa_required code when the session is aal1', function () {
    $pro = Professional::factory()->create();

    actingAsProfessional($pro) // default aal1
        ->getJson('/__test/aal2-gate')
        ->assertStatus(401)
        ->assertJson([
            'code' => 'mfa_required',
        ]);
});

it('passes through when the session is aal2', function () {
    $pro = Professional::factory()->create();

    actingAsProfessional($pro, aal2ClaimsWithFreshTotp())
        ->getJson('/__test/aal2-gate')
        ->assertOk()
        ->assertJson(['ok' => true]);
});
```

- [ ] **Step 2: Run the test — confirm it FAILS**

```bash
composer test -- --filter RequireAal2Middleware
```

Expected: route 404 or 500 — `require.aal2` middleware not registered.

- [ ] **Step 3: Create the middleware**

Create `app/Http/Middleware/Auth/RequireAal2.php`:

```php
<?php

namespace App\Http\Middleware\Auth;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects any request whose supabase session is not aal2 (i.e. has not
 * passed at least one MFA factor verification this session).
 *
 * Always layered AFTER VerifySupabaseJwt — depends on the `supabase_aal`
 * request attribute set there.
 *
 * Returns 401 with code='mfa_required' so frontend can trigger a step-up
 * challenge modal and retry the original request.
 */
class RequireAal2
{
    public function handle(Request $request, Closure $next): Response
    {
        $aal = $request->attributes->get('supabase_aal', 'aal1');

        if ($aal !== 'aal2') {
            return response()->json([
                'message' => 'MFA required',
                'code' => 'mfa_required',
            ], 401);
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Register the middleware alias**

In `bootstrap/app.php`, locate the `withMiddleware(function (Middleware $middleware) { ... })` block where existing aliases are registered (look for `'supabase.jwt'` and similar). Add:

```php
$middleware->alias([
    // ... existing aliases ...
    'require.aal2' => \App\Http\Middleware\Auth\RequireAal2::class,
]);
```

If `'require.aal2'` would be a new alias key, just add it to the existing alias array. Do NOT duplicate the existing aliases — locate them and add this one line.

- [ ] **Step 5: Run the test — confirm it PASSES**

```bash
composer test -- --filter RequireAal2Middleware
```

Expected: both tests pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Middleware/Auth/RequireAal2.php bootstrap/app.php tests/Feature/Auth/RequireAal2MiddlewareTest.php
git commit -m "feat(mfa): add require.aal2 middleware

401 with code=mfa_required when session is aal1. Layered after
supabase.jwt — reads supabase_aal request attribute.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 6: `BasePolicy` AAL helpers

**Files:**
- Modify: `app/Policies/BasePolicy.php`
- Test: `tests/Feature/Auth/BasePolicyAalHelpersTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Auth/BasePolicyAalHelpersTest.php`:

```php
<?php

use App\Policies\BasePolicy;
use Illuminate\Auth\Access\Response;

/**
 * Test double exposing the protected helpers as public methods so
 * we can assert their behavior directly without inventing a real policy.
 */
class TestableBasePolicy extends BasePolicy
{
    public function checkAal2(): Response
    {
        return $this->requiresAal2();
    }

    public function checkFreshAal2(int $window): Response
    {
        return $this->requiresFreshAal2($window);
    }
}

it('requiresAal2 allows aal2 sessions', function () {
    request()->attributes->set('supabase_aal', 'aal2');

    expect((new TestableBasePolicy)->checkAal2()->allowed())->toBeTrue();
});

it('requiresAal2 denies aal1 sessions with 401', function () {
    request()->attributes->set('supabase_aal', 'aal1');

    $response = (new TestableBasePolicy)->checkAal2();
    expect($response->allowed())->toBeFalse();
    expect($response->status())->toBe(401);
});

it('requiresFreshAal2 allows when the most recent mfa entry is inside the window', function () {
    request()->attributes->set('supabase_aal', 'aal2');
    request()->attributes->set('supabase_amr', [
        ['method' => 'totp', 'timestamp' => time() - 60],
    ]);

    expect((new TestableBasePolicy)->checkFreshAal2(300)->allowed())->toBeTrue();
});

it('requiresFreshAal2 denies when the mfa entry is outside the window', function () {
    request()->attributes->set('supabase_aal', 'aal2');
    request()->attributes->set('supabase_amr', [
        ['method' => 'totp', 'timestamp' => time() - 1000],
    ]);

    $response = (new TestableBasePolicy)->checkFreshAal2(300);
    expect($response->allowed())->toBeFalse();
    expect($response->status())->toBe(401);
});

it('requiresFreshAal2 reads the most-recent mfa entry, ignoring later non-mfa entries', function () {
    request()->attributes->set('supabase_aal', 'aal2');
    request()->attributes->set('supabase_amr', [
        ['method' => 'token_refresh', 'timestamp' => time() - 5],   // newer, not mfa
        ['method' => 'totp', 'timestamp' => time() - 60],            // mfa, fresh
        ['method' => 'magiclink', 'timestamp' => time() - 120],
    ]);

    expect((new TestableBasePolicy)->checkFreshAal2(300)->allowed())->toBeTrue();
});

it('requiresFreshAal2 denies when amr has no mfa entries at all', function () {
    request()->attributes->set('supabase_aal', 'aal1');
    request()->attributes->set('supabase_amr', [
        ['method' => 'magiclink', 'timestamp' => time() - 10],
    ]);

    expect((new TestableBasePolicy)->checkFreshAal2(300)->allowed())->toBeFalse();
});
```

- [ ] **Step 2: Run the test — confirm it FAILS**

```bash
composer test -- --filter BasePolicyAalHelpers
```

Expected: failure — `requiresAal2` and `requiresFreshAal2` don't exist yet.

- [ ] **Step 3: Add the helpers to `BasePolicy`**

Open `app/Policies/BasePolicy.php`. Locate a sensible insertion point (after the existing helper methods, or near the top before specific action methods). Add:

```php
use Illuminate\Auth\Access\Response;

// ... existing class body ...

/**
 * Allow only sessions at AAL2 (passed at least one MFA factor this session).
 * Use for "this action requires MFA but doesn't need re-verification".
 *
 * Returns 401 (not 403) — frontend interprets 401 + a recognizable message
 * as "trigger step-up challenge".
 */
protected function requiresAal2(): Response
{
    $aal = request()->attributes->get('supabase_aal', 'aal1');

    return $aal === 'aal2'
        ? Response::allow()
        : Response::denyWithStatus(401, 'MFA required for this action');
}

/**
 * "Fresh" AAL2 — was the user's most recent MFA verification inside
 * $maxAgeSeconds? Use for high-risk actions where AAL2 alone is too weak
 * (an attacker on an already-aal2 session could otherwise act freely).
 *
 * AAL stays sticky at aal2 for the life of the session (Supabase doesn't
 * downgrade it on token refresh), so we have to inspect the amr timeline
 * to enforce "verify recently". The amr array is ordered most-recent-first
 * per Supabase docs; we scan for the first mfa-method entry and compare
 * its timestamp to now.
 *
 * @param  int  $maxAgeSeconds  Window. Default in config('sidest.mfa.fresh_window_seconds').
 */
protected function requiresFreshAal2(?int $maxAgeSeconds = null): Response
{
    $maxAgeSeconds ??= (int) config('sidest.mfa.fresh_window_seconds', 300);
    $amr = request()->attributes->get('supabase_amr', []);
    $mfaMethods = ['totp', 'phone', 'webauthn'];

    foreach ($amr as $entry) {
        $method = $entry['method'] ?? null;
        if (in_array($method, $mfaMethods, true)) {
            $age = time() - (int) ($entry['timestamp'] ?? 0);
            return $age <= $maxAgeSeconds
                ? Response::allow()
                : Response::denyWithStatus(401, 'Recent MFA verification required');
        }
    }

    return Response::denyWithStatus(401, 'Recent MFA verification required');
}
```

- [ ] **Step 4: Run the test — confirm it PASSES**

```bash
composer test -- --filter BasePolicyAalHelpers
```

Expected: all 6 tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Policies/BasePolicy.php tests/Feature/Auth/BasePolicyAalHelpersTest.php
git commit -m "feat(mfa): add requiresAal2 and requiresFreshAal2 to BasePolicy

Fresh-MFA helper inspects the amr timeline (ordered most-recent-first
per Supabase docs) because aal stays sticky at aal2 across token
refreshes — only amr.timestamp can answer 'verify within last N
seconds'. Defaults to 300s; the unenroll flow will pass 60s.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 7: Apply `require.aal2` to staff routes (sweep test + wiring)

**Files:**
- Create: `tests/Feature/Security/Aal2RouteCoverageTest.php`
- Modify: `routes/api/staff.php`

- [ ] **Step 1: Write the failing sweep test**

Create `tests/Feature/Security/Aal2RouteCoverageTest.php`:

```php
<?php

use Illuminate\Support\Facades\Route;

/**
 * Sweep test — every staff route must enforce AAL2. Modeled on the
 * existing PolicyCoverageTest pattern (see tests/Feature/Security/).
 *
 * If you add a new staff route and forget the middleware, CI fails here.
 * If a specific staff route legitimately must remain AAL1 (e.g. a
 * "request MFA enrollment" route used pre-enrollment), add it to the
 * AAL2_EXEMPT_PATHS list with a one-line justification comment.
 */

const AAL2_EXEMPT_PATHS = [
    // path => justification
    // 'api/staff/mfa/setup' => 'pre-enrollment endpoint; called from aal1 sessions',
];

it('every staff API route is gated by require.aal2', function () {
    $staffRoutes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/staff/'));

    expect($staffRoutes)->not->toBeEmpty('No staff routes found — adjust the prefix filter');

    $offenders = [];
    foreach ($staffRoutes as $route) {
        $path = $route->uri();
        if (array_key_exists($path, AAL2_EXEMPT_PATHS)) {
            continue;
        }

        $middleware = $route->gatherMiddleware();
        $hasAal2 = collect($middleware)->contains(function ($m) {
            return $m === 'require.aal2'
                || $m === \App\Http\Middleware\Auth\RequireAal2::class;
        });

        if (! $hasAal2) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'Staff routes missing require.aal2: '.implode(', ', $offenders));
});
```

- [ ] **Step 2: Run the test — confirm it FAILS**

```bash
composer test -- --filter Aal2RouteCoverage
```

Expected: failure listing every existing staff route as an offender.

- [ ] **Step 3: Apply the middleware to the staff route group**

Open `routes/api/staff.php`. Locate the top-level route group (the one with `'middleware' => ['supabase.jwt', 'staff', ...]` or similar). Add `'require.aal2'` to the middleware list:

```php
Route::middleware(['supabase.jwt', 'staff', 'require.aal2'])->group(function () {
    // existing routes unchanged
});
```

If the file has multiple top-level groups, apply to each. If it uses a `Route::prefix(...)->middleware([...])` chain, add `'require.aal2'` to the middleware array.

- [ ] **Step 4: Run the test — confirm it PASSES**

```bash
composer test -- --filter Aal2RouteCoverage
```

Expected: pass — all staff routes now show `require.aal2` in `gatherMiddleware()`.

- [ ] **Step 5: Run the full staff test suite to catch regressions**

```bash
composer test -- --filter Staff
```

Expected: existing staff tests pass. **However**: any existing staff test that uses `actingAsProfessional($staff)` without an aal2 override will now receive 401. If that happens, update the affected tests to pass `aal2ClaimsWithFreshTotp()`:

```php
// Before
actingAsProfessional($staff)->getJson('/api/staff/x')->assertOk();
// After
actingAsProfessional($staff, aal2ClaimsWithFreshTotp())->getJson('/api/staff/x')->assertOk();
```

Add a test that confirms a staff user at aal1 is rejected (one new test in an appropriate existing staff test file, or as part of the route coverage test).

- [ ] **Step 6: Commit**

```bash
git add routes/api/staff.php tests/Feature/Security/Aal2RouteCoverageTest.php tests/  # plus any updated staff tests
git commit -m "feat(mfa): require AAL2 on all staff routes

Staff routes carry the largest blast radius — mandatory AAL2 with no
opt-out. Sweep test fails CI when a new staff route lacks the middleware.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 8: `AuthFactorEventRepository` — typed DB access

**Files:**
- Create: `app/Services/Auth/AuthFactorEventRepository.php`
- Test: `tests/Feature/Auth/AuthFactorEventRepositoryTest.php`

> Why a repository: the webhook handler, the unenroll endpoint, and (eventually) staff support tooling all read/write this table. A typed accessor centralizes the column names, makes the brute-force window query reusable, and keeps `DB::table(...)` calls out of controllers.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Auth/AuthFactorEventRepositoryTest.php`:

```php
<?php

use App\Services\Auth\AuthFactorEventRepository;
use Illuminate\Support\Str;

beforeEach(function () {
    setupAuthFactorEventsTable();
});

it('records a factor event with all fields', function () {
    $repo = app(AuthFactorEventRepository::class);
    $userId = (string) Str::uuid();
    $factorId = (string) Str::uuid();

    $id = $repo->record(
        userId: $userId,
        eventType: 'verify_success',
        factorId: $factorId,
        factorType: 'totp',
        sessionId: (string) Str::uuid(),
        ip: '1.2.3.4',
        userAgent: 'Test/1.0',
        metadata: ['source' => 'hook'],
    );

    expect($id)->toBeString();

    $row = \DB::connection('pgsql')->table('core.auth_factor_events')->where('id', $id)->first();
    expect($row->user_id)->toBe($userId);
    expect($row->event_type)->toBe('verify_success');
    expect($row->factor_type)->toBe('totp');
});

it('counts recent failures within the window', function () {
    $repo = app(AuthFactorEventRepository::class);
    $userId = (string) Str::uuid();
    $factorId = (string) Str::uuid();

    // 3 failures in the last minute, plus 1 outside the window
    foreach (range(1, 3) as $_) {
        $repo->record($userId, 'verify_failed', $factorId, 'totp');
    }

    // Outside-window failure — simulate by direct DB insert with old timestamp
    \DB::connection('pgsql')->table('core.auth_factor_events')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $userId,
        'event_type' => 'verify_failed',
        'factor_id' => $factorId,
        'factor_type' => 'totp',
        'metadata' => '{}',
        'created_at' => now()->subMinutes(10)->toIso8601String(),
    ]);

    expect($repo->countRecentFailures($userId, $factorId, windowSeconds: 300))->toBe(3);
});

it('countRecentFailures includes verify_rejected_by_hook events', function () {
    $repo = app(AuthFactorEventRepository::class);
    $userId = (string) Str::uuid();
    $factorId = (string) Str::uuid();

    $repo->record($userId, 'verify_failed', $factorId, 'totp');
    $repo->record($userId, 'verify_rejected_by_hook', $factorId, 'totp');
    $repo->record($userId, 'verify_success', $factorId, 'totp'); // not a failure

    expect($repo->countRecentFailures($userId, $factorId, 300))->toBe(2);
});
```

- [ ] **Step 2: Run the test — confirm it FAILS**

```bash
composer test -- --filter AuthFactorEventRepository
```

Expected: failure — class doesn't exist.

- [ ] **Step 3: Implement the repository**

Create `app/Services/Auth/AuthFactorEventRepository.php`:

```php
<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Typed access to core.auth_factor_events.
 *
 * Single insertion point for factor audit events; single query for the
 * brute-force window check used by the MFA Verification Hook handler.
 * All callers go through this — never DB::table('core.auth_factor_events')
 * directly in controllers/services.
 */
class AuthFactorEventRepository
{
    public const TABLE = 'core.auth_factor_events';

    public const FAILURE_EVENT_TYPES = [
        'verify_failed',
        'verify_rejected_by_hook',
    ];

    /**
     * Insert a new event row. Returns the generated id.
     *
     * @param  array<string, mixed>  $metadata  Arbitrary JSON-serializable extras (geo, device fingerprint, etc.)
     */
    public function record(
        string $userId,
        string $eventType,
        ?string $factorId = null,
        ?string $factorType = null,
        ?string $sessionId = null,
        ?string $ip = null,
        ?string $userAgent = null,
        array $metadata = [],
    ): string {
        $id = (string) Str::uuid();

        DB::connection('pgsql')->table(self::TABLE)->insert([
            'id' => $id,
            'user_id' => $userId,
            'session_id' => $sessionId,
            'event_type' => $eventType,
            'factor_id' => $factorId,
            'factor_type' => $factorType,
            'ip' => $ip,
            'user_agent' => $userAgent,
            'metadata' => json_encode($metadata),
            'created_at' => now()->toIso8601String(),
        ]);

        return $id;
    }

    /**
     * Count failure events for a given (user, factor) inside a rolling
     * window. Used by the MFA Verification Hook to decide whether to
     * reject the current attempt.
     *
     * Hits the partial index auth_factor_events_failed_window_idx in
     * production — ~1ms even at high volume.
     */
    public function countRecentFailures(string $userId, string $factorId, int $windowSeconds): int
    {
        return (int) DB::connection('pgsql')->table(self::TABLE)
            ->where('user_id', $userId)
            ->where('factor_id', $factorId)
            ->whereIn('event_type', self::FAILURE_EVENT_TYPES)
            ->where('created_at', '>=', now()->subSeconds($windowSeconds)->toIso8601String())
            ->count();
    }
}
```

- [ ] **Step 4: Run the test — confirm it PASSES**

```bash
composer test -- --filter AuthFactorEventRepository
```

Expected: all 3 tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Auth/AuthFactorEventRepository.php tests/Feature/Auth/AuthFactorEventRepositoryTest.php
git commit -m "feat(mfa): add AuthFactorEventRepository for typed audit-log access

Centralizes record() and the brute-force countRecentFailures() window
query so callers don't hand-write DB::table queries. Matches the
partial-index column order from the migration.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 9: `SupabaseAuthHookService` — signature verification + decision logic

**Files:**
- Create: `app/Services/Auth/SupabaseAuthHookService.php`
- Test: `tests/Feature/Webhooks/SupabaseAuthHookSignatureTest.php`

> Signature scheme: Supabase signs with [Standard Webhooks](https://www.standardwebhooks.com/). Headers `webhook-id`, `webhook-timestamp`, `webhook-signature`. Signature is base64 HMAC-SHA256 of `{id}.{timestamp}.{rawBody}` using the shared secret, prefixed with `v1,`. Multiple signatures may be present, comma-separated.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Webhooks/SupabaseAuthHookSignatureTest.php`:

```php
<?php

use App\Services\Auth\SupabaseAuthHookService;

beforeEach(function () {
    config(['supabase.auth_hook_secret' => 'whsec_test_secret_at_least_32_bytes_long_xx']);
});

function signStandardWebhookPayload(string $secret, string $id, int $timestamp, string $body): string
{
    $signedContent = "{$id}.{$timestamp}.{$body}";
    $signature = base64_encode(hash_hmac('sha256', $signedContent, $secret, true));
    return "v1,{$signature}";
}

it('accepts a valid Standard Webhooks signature', function () {
    $svc = app(SupabaseAuthHookService::class);
    $body = '{"user_id":"abc","factor_id":"xyz","valid":true}';
    $id = 'msg_test_1';
    $ts = time();
    $secret = 'whsec_test_secret_at_least_32_bytes_long_xx';

    $sig = signStandardWebhookPayload($secret, $id, $ts, $body);

    expect($svc->verifySignature($id, (string) $ts, $sig, $body))->toBeTrue();
});

it('rejects a forged signature', function () {
    $svc = app(SupabaseAuthHookService::class);
    $body = '{"user_id":"abc"}';

    expect($svc->verifySignature('msg_1', (string) time(), 'v1,wrong_signature', $body))->toBeFalse();
});

it('rejects a signature signed with a different secret', function () {
    $svc = app(SupabaseAuthHookService::class);
    $body = '{"user_id":"abc"}';
    $id = 'msg_1';
    $ts = time();

    $sig = signStandardWebhookPayload('different_secret', $id, $ts, $body);

    expect($svc->verifySignature($id, (string) $ts, $sig, $body))->toBeFalse();
});

it('rejects a timestamp outside the tolerance window', function () {
    $svc = app(SupabaseAuthHookService::class);
    $body = '{"user_id":"abc"}';
    $id = 'msg_1';
    $oldTs = time() - 600; // 10 minutes old
    $secret = 'whsec_test_secret_at_least_32_bytes_long_xx';

    $sig = signStandardWebhookPayload($secret, $id, $oldTs, $body);

    expect($svc->verifySignature($id, (string) $oldTs, $sig, $body))->toBeFalse();
});

it('accepts when at least one signature in a multi-signature header matches', function () {
    $svc = app(SupabaseAuthHookService::class);
    $body = '{"user_id":"abc"}';
    $id = 'msg_1';
    $ts = time();
    $secret = 'whsec_test_secret_at_least_32_bytes_long_xx';

    $validSig = signStandardWebhookPayload($secret, $id, $ts, $body);
    $combined = "v1,wrong_signature {$validSig}"; // space-separated per Standard Webhooks

    expect($svc->verifySignature($id, (string) $ts, $combined, $body))->toBeTrue();
});
```

- [ ] **Step 2: Run the test — confirm it FAILS**

```bash
composer test -- --filter SupabaseAuthHookSignature
```

Expected: failure — service doesn't exist.

- [ ] **Step 3: Implement the service**

Create `app/Services/Auth/SupabaseAuthHookService.php`:

```php
<?php

namespace App\Services\Auth;

/**
 * Standard Webhooks signature verification + helpers for Supabase Auth Hooks.
 *
 * Spec: https://www.standardwebhooks.com/
 * Header format:
 *   webhook-id:        unique message id (used in signature input)
 *   webhook-timestamp: unix seconds (used in signature input + tolerance check)
 *   webhook-signature: "v1,<base64-sig> [v1,<rotated-sig>]" — space-separated for rotation
 *
 * Signature input is exactly: "{id}.{timestamp}.{body}" — HMAC-SHA256 with the
 * shared secret, base64-encoded.
 */
class SupabaseAuthHookService
{
    /** Reject signed messages older than this — replay-attack defense. */
    private const TIMESTAMP_TOLERANCE_SECONDS = 300;

    public function verifySignature(string $id, string $timestamp, string $signatureHeader, string $rawBody): bool
    {
        $secret = (string) config('supabase.auth_hook_secret');
        if ($secret === '') {
            // Fail-closed: misconfiguration is a deploy bug, not a runtime question.
            return false;
        }

        // Replay defense: reject ancient timestamps.
        $ts = (int) $timestamp;
        if ($ts <= 0 || abs(time() - $ts) > self::TIMESTAMP_TOLERANCE_SECONDS) {
            return false;
        }

        $signedContent = "{$id}.{$timestamp}.{$rawBody}";
        $expected = base64_encode(hash_hmac('sha256', $signedContent, $secret, true));

        // The header may contain multiple "v1,<sig>" tokens space-separated to
        // support secret rotation. Accept if ANY matches.
        foreach (explode(' ', trim($signatureHeader)) as $candidate) {
            if (! str_starts_with($candidate, 'v1,')) {
                continue;
            }
            $candidateSig = substr($candidate, 3);
            if (hash_equals($expected, $candidateSig)) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Run the test — confirm it PASSES**

```bash
composer test -- --filter SupabaseAuthHookSignature
```

Expected: all 5 tests pass.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Auth/SupabaseAuthHookService.php tests/Feature/Webhooks/SupabaseAuthHookSignatureTest.php
git commit -m "feat(mfa): add SupabaseAuthHookService with Standard Webhooks signing

Verifies webhook-id / webhook-timestamp / webhook-signature using
HMAC-SHA256 per the Standard Webhooks spec. Rejects replays older
than 5 min and supports multi-signature headers for rotation.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 10: `SupabaseAuthHookController` — MFA verification endpoint + brute-force decision

**Files:**
- Create: `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php`
- Modify: `routes/api.php` — register the webhook route
- Test: `tests/Feature/Webhooks/SupabaseAuthHookBruteForceTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Webhooks/SupabaseAuthHookBruteForceTest.php`:

```php
<?php

use App\Services\Auth\AuthFactorEventRepository;
use Illuminate\Support\Str;

beforeEach(function () {
    setupAuthFactorEventsTable();
    config([
        'supabase.auth_hook_secret' => 'whsec_test_secret_at_least_32_bytes_long_xx',
        'sidest.mfa.verify_max_failures' => 5,
        'sidest.mfa.verify_failure_window_seconds' => 300,
    ]);
});

function postSignedHook(array $payload, ?string $overrideBody = null): \Illuminate\Testing\TestResponse
{
    $body = $overrideBody ?? json_encode($payload);
    $id = 'msg_'.Str::uuid();
    $ts = (string) time();
    $secret = config('supabase.auth_hook_secret');
    $sig = 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$ts}.{$body}", $secret, true));

    return test()->withHeaders([
        'webhook-id' => $id,
        'webhook-timestamp' => $ts,
        'webhook-signature' => $sig,
    ])->call('POST', '/api/webhooks/supabase/auth/mfa-verification', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

it('returns 401 for an unsigned request', function () {
    test()
        ->postJson('/api/webhooks/supabase/auth/mfa-verification', ['user_id' => 'abc', 'valid' => true])
        ->assertStatus(401);
});

it('returns continue and records verify_success for a valid signed success', function () {
    $userId = (string) Str::uuid();
    $factorId = (string) Str::uuid();

    postSignedHook([
        'user_id' => $userId,
        'factor_id' => $factorId,
        'factor_type' => 'totp',
        'valid' => true,
    ])->assertOk()->assertJson(['decision' => 'continue']);

    $event = \DB::connection('pgsql')->table('core.auth_factor_events')
        ->where('user_id', $userId)->first();
    expect($event->event_type)->toBe('verify_success');
});

it('returns continue and records verify_failed for the first few failures', function () {
    $userId = (string) Str::uuid();
    $factorId = (string) Str::uuid();

    foreach (range(1, 4) as $i) {
        $response = postSignedHook([
            'user_id' => $userId,
            'factor_id' => $factorId,
            'factor_type' => 'totp',
            'valid' => false,
        ]);
        $response->assertOk()->assertJson(['decision' => 'continue']);
    }

    $count = \DB::connection('pgsql')->table('core.auth_factor_events')
        ->where('user_id', $userId)->where('event_type', 'verify_failed')->count();
    expect($count)->toBe(4);
});

it('returns reject on the 6th failed attempt in the window', function () {
    $userId = (string) Str::uuid();
    $factorId = (string) Str::uuid();
    $repo = app(AuthFactorEventRepository::class);

    // Seed 5 prior failures
    foreach (range(1, 5) as $_) {
        $repo->record($userId, 'verify_failed', $factorId, 'totp');
    }

    postSignedHook([
        'user_id' => $userId,
        'factor_id' => $factorId,
        'factor_type' => 'totp',
        'valid' => false,
    ])
    ->assertOk()
    ->assertJson(['decision' => 'reject'])
    ->assertJsonStructure(['decision', 'message']);

    // The rejection itself is recorded as verify_rejected_by_hook (so
    // future window queries continue to flag the user as in-cooldown).
    $rejection = \DB::connection('pgsql')->table('core.auth_factor_events')
        ->where('user_id', $userId)
        ->where('event_type', 'verify_rejected_by_hook')
        ->first();
    expect($rejection)->not->toBeNull();
});
```

- [ ] **Step 2: Run the test — confirm it FAILS**

```bash
composer test -- --filter SupabaseAuthHookBruteForce
```

Expected: 404 for all calls — route not registered.

- [ ] **Step 3: Implement the controller**

Create `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Auth\AuthFactorEventRepository;
use App\Services\Auth\SupabaseAuthHookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives Supabase Auth Hook callbacks.
 *
 * Currently handles only the MFA Verification Hook — every TOTP/Phone
 * verification attempt is announced to us *before* Supabase promotes
 * the session to aal2. We respond with {decision: "continue"} to allow,
 * or {decision: "reject", message: "..."} to refuse.
 *
 * Brute-force defense: after N failed verifies in the rolling window
 * (configurable in sidest.mfa.*), we reject further attempts and record
 * the rejection so subsequent window queries keep flagging the user as
 * in-cooldown.
 *
 * Signature verification is the FIRST thing this does — unsigned or
 * forged requests get 401 before any DB access. Standard Webhooks spec.
 */
class SupabaseAuthHookController extends Controller
{
    public function __construct(
        private readonly SupabaseAuthHookService $hookService,
        private readonly AuthFactorEventRepository $repo,
    ) {}

    public function mfaVerification(Request $request): JsonResponse
    {
        $id = (string) $request->header('webhook-id', '');
        $timestamp = (string) $request->header('webhook-timestamp', '');
        $signature = (string) $request->header('webhook-signature', '');
        $rawBody = $request->getContent();

        if (! $this->hookService->verifySignature($id, $timestamp, $signature, $rawBody)) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        $payload = $request->json()->all();
        $userId = (string) ($payload['user_id'] ?? '');
        $factorId = (string) ($payload['factor_id'] ?? '');
        $factorType = $payload['factor_type'] ?? null;
        $valid = (bool) ($payload['valid'] ?? false);

        if ($userId === '' || $factorId === '') {
            return response()->json(['message' => 'Malformed payload'], 400);
        }

        $ip = $request->ip();
        $userAgent = (string) $request->userAgent();

        if ($valid) {
            $this->repo->record(
                userId: $userId,
                eventType: 'verify_success',
                factorId: $factorId,
                factorType: $factorType,
                ip: $ip,
                userAgent: $userAgent,
            );
            return response()->json(['decision' => 'continue']);
        }

        // Failed verify path.
        $maxFailures = (int) config('sidest.mfa.verify_max_failures', 5);
        $windowSeconds = (int) config('sidest.mfa.verify_failure_window_seconds', 300);

        $recentFailures = $this->repo->countRecentFailures($userId, $factorId, $windowSeconds);

        if ($recentFailures >= $maxFailures) {
            $this->repo->record(
                userId: $userId,
                eventType: 'verify_rejected_by_hook',
                factorId: $factorId,
                factorType: $factorType,
                ip: $ip,
                userAgent: $userAgent,
                metadata: ['recent_failures' => $recentFailures, 'window_seconds' => $windowSeconds],
            );
            return response()->json([
                'decision' => 'reject',
                'message' => 'Too many failed verification attempts. Try again in '.ceil($windowSeconds / 60).' minutes.',
            ]);
        }

        // Below threshold — record the failure but allow Supabase to
        // continue (so the user just sees "wrong code" and can retry).
        $this->repo->record(
            userId: $userId,
            eventType: 'verify_failed',
            factorId: $factorId,
            factorType: $factorType,
            ip: $ip,
            userAgent: $userAgent,
        );

        return response()->json(['decision' => 'continue']);
    }
}
```

- [ ] **Step 4: Register the route**

In `routes/api.php`, add (in the webhooks section — there is an existing Shopify webhooks pattern to model after):

```php
// Supabase Auth Hooks — signature-gated, unauthenticated.
// Configure URL in Supabase Dashboard → Authentication → Hooks → MFA Verification.
Route::post(
    '/webhooks/supabase/auth/mfa-verification',
    [\App\Http\Controllers\Api\Webhooks\SupabaseAuthHookController::class, 'mfaVerification'],
)->name('webhooks.supabase.auth.mfa-verification');
```

This route is intentionally NOT behind `supabase.jwt` — Supabase signs the body, not a bearer token. Signature verification is the auth.

- [ ] **Step 5: Run the test — confirm it PASSES**

```bash
composer test -- --filter SupabaseAuthHookBruteForce
```

Expected: all 4 tests pass.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php routes/api.php tests/Feature/Webhooks/SupabaseAuthHookBruteForceTest.php
git commit -m "feat(mfa): add MFA Verification Hook endpoint with brute-force defense

Configurable threshold (5 fails / 5 min by default) enforced via the
AuthFactorEventRepository window query. Rejections are themselves
recorded as verify_rejected_by_hook so the cooldown persists across
the window. Signature verification is the only auth gate — no JWT.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 11: Self-service unenroll endpoint with fresh-AAL2 (60s)

**Files:**
- Modify: `app/Services/Auth/SupabaseAdminService.php` — add `unenrollMfaFactor`
- Create: `app/Http/Controllers/Api/Professional/Account/MfaController.php`
- Create: `app/Http/Requests/Account/UnenrollMfaFactorRequest.php`
- Modify: `routes/api/professional.php` (or wherever account routes live) — register the route
- Test: `tests/Feature/Account/UnenrollMfaFactorTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Account/UnenrollMfaFactorTest.php`:

```php
<?php

use App\Models\Core\Professional\Professional;
use App\Services\Auth\SupabaseAdminService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    setupProfessionalsTable();
    setupAuthFactorEventsTable();

    config([
        'supabase.admin.base_url' => 'https://test.supabase.co/auth/v1/admin',
        'supabase.service_role_key' => 'sr_test_key',
        'sidest.mfa.unenroll_fresh_window_seconds' => 60,
    ]);
});

it('rejects unenroll when session is aal1', function () {
    $pro = Professional::factory()->create();

    actingAsProfessional($pro) // aal1
        ->deleteJson('/api/account/mfa/factors/'.Str::uuid())
        ->assertStatus(401)
        ->assertJsonPath('message', fn ($msg) => str_contains((string) $msg, 'MFA'));
});

it('rejects unenroll when most-recent totp is older than 60s', function () {
    $pro = Professional::factory()->create();

    actingAsProfessional($pro, aal2ClaimsWithFreshTotp(90)) // 90s old
        ->deleteJson('/api/account/mfa/factors/'.Str::uuid())
        ->assertStatus(401);
});

it('calls Supabase Admin API and records unenroll event when within 60s', function () {
    Http::fake([
        'test.supabase.co/auth/v1/admin/*' => Http::response(['ok' => true], 200),
    ]);

    $pro = Professional::factory()->create();
    $factorId = (string) Str::uuid();

    actingAsProfessional($pro, aal2ClaimsWithFreshTotp(30)) // 30s old, well inside 60s
        ->deleteJson("/api/account/mfa/factors/{$factorId}")
        ->assertOk();

    Http::assertSent(function ($request) use ($pro, $factorId) {
        return str_contains($request->url(), "/users/{$pro->auth_user_id}/factors/{$factorId}")
            && $request->method() === 'DELETE'
            && $request->hasHeader('Authorization', 'Bearer sr_test_key');
    });

    $event = \DB::connection('pgsql')->table('core.auth_factor_events')
        ->where('user_id', $pro->auth_user_id)
        ->where('event_type', 'unenroll')
        ->first();
    expect($event)->not->toBeNull();
    expect($event->factor_id)->toBe($factorId);
});

it('surfaces Supabase Admin API failure as 502', function () {
    Http::fake([
        'test.supabase.co/auth/v1/admin/*' => Http::response(['error' => 'not found'], 404),
    ]);

    $pro = Professional::factory()->create();

    actingAsProfessional($pro, aal2ClaimsWithFreshTotp(30))
        ->deleteJson('/api/account/mfa/factors/'.Str::uuid())
        ->assertStatus(502);
});
```

- [ ] **Step 2: Run the test — confirm it FAILS**

```bash
composer test -- --filter UnenrollMfaFactor
```

Expected: 404 for all — route not registered, controller doesn't exist.

- [ ] **Step 3: Add `unenrollMfaFactor` to `SupabaseAdminService`**

Open `app/Services/Auth/SupabaseAdminService.php`. Add the method (alongside existing admin methods):

```php
/**
 * Remove an MFA factor from a Supabase user via the Admin API.
 *
 * Endpoint: DELETE /auth/v1/admin/users/{user_id}/factors/{factor_id}
 * Auth: service role key.
 *
 * Throws \RuntimeException on non-2xx response so the controller can
 * map to a 502.
 */
public function unenrollMfaFactor(string $supabaseUserId, string $factorId): void
{
    $baseUrl = rtrim((string) config('supabase.admin.base_url'), '/');
    $serviceRoleKey = (string) config('supabase.service_role_key');

    if ($baseUrl === '' || $serviceRoleKey === '') {
        throw new \RuntimeException('Supabase admin config missing');
    }

    $response = \Illuminate\Support\Facades\Http::timeout(5)
        ->withHeaders([
            'apikey' => $serviceRoleKey,
            'Authorization' => 'Bearer '.$serviceRoleKey,
        ])
        ->delete("{$baseUrl}/users/{$supabaseUserId}/factors/{$factorId}");

    if (! $response->successful()) {
        throw new \RuntimeException(
            "Supabase factor unenroll failed: HTTP {$response->status()} body={$response->body()}"
        );
    }
}
```

- [ ] **Step 4: Create the controller**

Create `app/Http/Controllers/Api/Professional/Account/MfaController.php`:

```php
<?php

namespace App\Http\Controllers\Api\Professional\Account;

use App\Http\Controllers\Controller;
use App\Services\Auth\AuthFactorEventRepository;
use App\Services\Auth\SupabaseAdminService;
use Illuminate\Auth\Access\Response as GateResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Self-service MFA management for the authenticated user.
 *
 * Today: unenroll a single factor. Enrollment / list / verify all live
 * on the frontend via supabase.auth.mfa.*  — we do NOT intermediate
 * those because we never want to handle factor secrets.
 *
 * The unenroll endpoint exists on our backend (not directly via the
 * Supabase JS SDK) so we can enforce a *fresh* AAL2 gate — Supabase
 * only enforces session-level aal2, not "verify within last 60s".
 */
class MfaController extends Controller
{
    public function __construct(
        private readonly SupabaseAdminService $admin,
        private readonly AuthFactorEventRepository $repo,
    ) {}

    public function destroy(Request $request, string $factorId): JsonResponse
    {
        // Inline fresh-AAL2 gate — not in a policy because there's no
        // model to authorize against (the factor lives in Supabase, not
        // our DB). Same logic as BasePolicy::requiresFreshAal2() but
        // applied here with the unenroll-specific window.
        $window = (int) config('sidest.mfa.unenroll_fresh_window_seconds', 60);
        $gate = $this->requiresFreshAal2($request, $window);
        if (! $gate->allowed()) {
            return response()->json([
                'message' => $gate->message() ?: 'Recent MFA verification required',
                'code' => 'mfa_fresh_required',
            ], $gate->status() ?? 401);
        }

        $uid = (string) $request->attributes->get('supabase_uid');
        $sessionId = $request->attributes->get('supabase_session_id');

        try {
            $this->admin->unenrollMfaFactor($uid, $factorId);
        } catch (\RuntimeException $e) {
            Log::warning('MFA unenroll failed against Supabase Admin API', [
                'operation' => __METHOD__,
                'user_id' => $uid,
                'factor_id' => $factorId,
                'reason' => $e->getMessage(),
            ]);
            return response()->json(['message' => 'Could not remove factor'], 502);
        }

        $this->repo->record(
            userId: $uid,
            eventType: 'unenroll',
            factorId: $factorId,
            sessionId: is_string($sessionId) ? $sessionId : null,
            ip: $request->ip(),
            userAgent: (string) $request->userAgent(),
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Inline copy of BasePolicy::requiresFreshAal2 — see comment on
     * destroy() above for why it's not delegated to a policy.
     */
    private function requiresFreshAal2(Request $request, int $maxAgeSeconds): GateResponse
    {
        $amr = $request->attributes->get('supabase_amr', []);
        $mfaMethods = ['totp', 'phone', 'webauthn'];

        foreach ($amr as $entry) {
            $method = $entry['method'] ?? null;
            if (in_array($method, $mfaMethods, true)) {
                $age = time() - (int) ($entry['timestamp'] ?? 0);
                return $age <= $maxAgeSeconds
                    ? GateResponse::allow()
                    : GateResponse::denyWithStatus(401, 'Recent MFA verification required');
            }
        }

        return GateResponse::denyWithStatus(401, 'Recent MFA verification required');
    }
}
```

- [ ] **Step 5: Register the route**

In whichever file owns professional/account routes (`routes/api/professional.php` or similar), add:

```php
Route::middleware(['supabase.jwt', 'current.pro'])->prefix('account/mfa')->group(function () {
    Route::delete('/factors/{factorId}', [
        \App\Http\Controllers\Api\Professional\Account\MfaController::class,
        'destroy',
    ])->name('account.mfa.factors.destroy');
});
```

Note: this route does NOT use `require.aal2` middleware — the controller does its own *fresh*-AAL2 check, which is strictly stricter than `require.aal2`. Layering both would be redundant.

- [ ] **Step 6: Run the test — confirm it PASSES**

```bash
composer test -- --filter UnenrollMfaFactor
```

Expected: all 4 tests pass.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Auth/SupabaseAdminService.php app/Http/Controllers/Api/Professional/Account/MfaController.php routes/api/professional.php tests/Feature/Account/UnenrollMfaFactorTest.php
git commit -m "feat(mfa): add self-service unenroll endpoint with fresh-AAL2 (60s)

Delegates the actual factor removal to Supabase Admin API; our role
is enforcing the tighter 60s fresh-MFA window (Supabase only enforces
session-level aal2). Records an 'unenroll' audit event on success.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 12: Documentation + rollout checklist

**Files:**
- Create: `docs/auth/mfa-foundation.md`
- Modify: `CLAUDE.md` — add a short pointer

- [ ] **Step 1: Write the runbook**

Create `docs/auth/mfa-foundation.md`:

```markdown
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
2. ✅ Confirm `/api/webhooks/supabase/auth/mfa-verification` is reachable on dev:
   ```bash
   curl -i https://dev-api.partna.au/api/webhooks/supabase/auth/mfa-verification
   ```
   Expected: 401 with `Invalid signature` (NOT 404 — the route exists, it's just unsigned).
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

## Tunables (`config/sidest.php` → `mfa`)

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
public function changePayoutBank(Professional $pro, ProfessionalIntegration $i): Response
{
    if ($pro->id !== $i->professional_id) return Response::denyWithStatus(404, 'Not found');
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
```

- [ ] **Step 2: Add a pointer in `CLAUDE.md`**

In `CLAUDE.md`, find a sensible location (perhaps near the "Authorization Pattern" section). Add a single short paragraph:

```markdown
### MFA / AAL2

This codebase reads `aal` and `amr` from Supabase JWTs and exposes them as request attributes (set by `VerifySupabaseJwt`). Staff routes are gated by `require.aal2`. For user-facing routes that should require MFA later, add `$this->requiresFreshAal2()` to the relevant policy method. Full runbook: `docs/auth/mfa-foundation.md`.
```

- [ ] **Step 3: Commit**

```bash
git add docs/auth/mfa-foundation.md CLAUDE.md
git commit -m "docs(mfa): add MFA foundation runbook + CLAUDE.md pointer

Covers rollout sequence (dev → soak → prod), brute-force testing,
lockout support procedure, tunables, and how to add AAL2 to future
user routes.

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>"
```

---

## Task 13: Final verification

- [ ] **Step 1: Full test suite**

```bash
composer test
```

Expected: zero failures. Any pre-existing flaky tests should be tagged + reported separately; this PR adds no new flakiness.

- [ ] **Step 2: Pint formatting**

```bash
php artisan pint
git diff --stat
```

If pint produced changes, commit them as `style: pint`.

- [ ] **Step 3: Diff sanity check**

```bash
git diff development...HEAD --stat
git log development..HEAD --oneline
```

Expected commit count: ~12. No surprise files. No `.env` changes (`.env.example` is OK).

- [ ] **Step 4: Smoke-test the webhook endpoint locally**

```bash
composer dev   # if not already running
```

In another terminal:

```bash
curl -i http://localhost:8000/api/webhooks/supabase/auth/mfa-verification
```

Expected: `HTTP/1.1 401 Unauthorized` with `{"message":"Invalid signature"}`. (NOT 404 — the route is registered.)

- [ ] **Step 5: Self-check against the pre-flight checklist**

- ☐ Dev Supabase project has TOTP MFA enabled?  (Operator step; Josh does this)
- ☐ `SUPABASE_AUTH_HOOK_SECRET` set in Laravel Cloud dev env?  (Operator step)
- ☐ MFA Verification Hook URL configured in Supabase Dashboard? (Operator step — done after PR ships)

- [ ] **Step 6: Open the PR**

```bash
git push -u origin feat/mfa-foundation
gh pr create --title "feat: MFA foundation — AAL2 gating, audit log, hook" --body "$(cat <<'EOF'
## Summary
- Adds `core.auth_factor_events` audit log + `VerifySupabaseJwt` extension to expose `aal`/`amr`/`session_id`
- `require.aal2` middleware applied to all staff routes (sweep test enforces)
- `BasePolicy::requiresAal2()` / `requiresFreshAal2()` helpers — dormant on user routes for now
- Supabase MFA Verification Hook endpoint with 5/5min brute-force defense
- Self-service unenroll endpoint with fresh-MFA (60s) gate
- Operator runbook at `docs/auth/mfa-foundation.md`

## Decisions (from planning session)
- TOTP only at launch; SMS + WebAuthn deferred
- Staff-only mandatory; user routes have foundation but no enforcement yet
- 300s fresh-MFA window default; 60s for unenroll
- Brute-force threshold 5 fails / 5 min

## Test plan
- [ ] `composer test` passes locally
- [ ] After merge: `curl https://dev-api.partna.au/api/webhooks/supabase/auth/mfa-verification` returns 401
- [ ] Enable TOTP in dev Supabase project
- [ ] Configure MFA Verification Hook in dev Supabase project
- [ ] Smoke-test enrollment from frontend test session
- [ ] Verify `core.auth_factor_events` rows appear
- [ ] Trigger 6 failed verifies; confirm 6th is rejected
- [ ] Soak on dev for 1 week before prod rollout

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

- [ ] **Step 7: Final commit on the test plan checklist (if anything else found)**

If the smoke test surfaced a real bug, fix it as a new commit. Do NOT amend prior commits.

---

## Self-review checklist (the implementer should walk through this before opening the PR)

1. **Spec coverage:** Every spec decision has a task — TOTP-only ✓, staff-mandatory ✓, fresh-MFA 300s/60s ✓, brute-force 5/5min ✓, audit retention left intentionally ✓.
2. **Placeholder scan:** No "TBD", "add error handling", or "similar to Task N" left in this file. Every code block is concrete.
3. **Type consistency:** `record()` signature in Task 8 matches the calls in Task 10 and Task 11. `requiresFreshAal2($maxAgeSeconds)` in Task 6 matches the inline copy in Task 11.
4. **What's not here on purpose:** Frontend work (separate repo), prod Supabase config (operator), AAL2 enforcement on user routes (deferred per Decision 2).
