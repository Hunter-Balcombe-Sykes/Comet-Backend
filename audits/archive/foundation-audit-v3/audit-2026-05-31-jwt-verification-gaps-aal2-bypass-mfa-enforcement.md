`★ Insight ─────────────────────────────────────`
- JWT-1's root cause is an architectural inconsistency: the developer correctly isolated `setSupabaseContext` (tracking) in an inner try/catch so Redis failures don't block auth — but the *revocation* Redis call immediately above it has no such isolation, creating an asymmetric safety net within the same function.
- `requiresFreshAal2()` already exists and is used (BasePolicy + MfaController), so JWT-2's proposed fix is partially implemented — the finding just needs updating to reference the existing hook rather than proposing a net-new mechanism.
- The `jwks_fail_closed` production boot guard in `AppServiceProvider` is the key fact that downgrade JWT-4 from P2 to P3: the fallback path is provably unreachable in production, making it dead-code polish rather than a ship-quality fix.
`─────────────────────────────────────────────────`

# JWT Verification & MFA Enforcement Audit — 2026-05-31

**Branch:** development
**Lens:** JWT verification gaps, AAL2 bypass, MFA enforcement holes, claim trust, token replay, aal/amr attribute handling, fresh-AAL2 policy gaps
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Middleware/Auth/VerifySupabaseJwt.php
- app/Http/Middleware/Auth/RequireAal2.php
- app/Http/Middleware/Auth/RequireEmailVerified.php
- app/Http/Middleware/Auth/EnsurePartnaStaff.php
- app/Http/Middleware/Auth/EnsurePartnaAdmin.php
- app/Http/Middleware/Auth/VerifySupabaseEmailHookSignature.php
- app/Services/Auth/TokenRevocationService.php
- app/Services/Auth/SupabaseAdminService.php
- app/Services/Auth/SupabaseAuthHookService.php
- app/Services/Auth/AuthFactorEventRepository.php
- app/Exceptions/Auth/JwksUnavailableException.php
- app/Policies/BasePolicy.php
- config/supabase.php
- app/Providers/AppServiceProvider.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#JWT-1** · P1 — Redis exception during revocation check is swallowed by the JWKS outer catch, returning 401 "Invalid token" for valid sessions
    - **Where:** app/Http/Middleware/Auth/VerifySupabaseJwt.php:83–88 (revocation check inside unguarded outer try), 105–129 (outer catch)
    - **Affects:** Every authenticated user when Redis is unavailable. A valid, cryptographically-sound token gets rejected with 401 "Invalid token", indistinguishable from a genuinely bad token. The log message says "JWT JWKS verification failed" — factually wrong, masking a Redis outage from incident responders. Users are locked out until Redis recovers; client apps may prompt re-authentication, which also fails for the same reason.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap `$this->revocation->isRevoked($sessionId)` in its own `try/catch(\Throwable)`, mirroring the pattern already applied to `setSupabaseContext` directly below it.
        - Choose a fail strategy: **fail-open** (log a distinct warning, proceed as if not revoked — the token is cryptographically valid; revocation is a best-effort oracle) or **fail-closed with 503** (log and return 503 so clients retry without discarding their token). Fail-open is recommended here because the token's validity is independently proven by signature; the revocation oracle being down is an infrastructure blip, not token fraud.
        - Use a distinct log channel key (`'kind' => 'revocation_check'`) so Nightwatch can separate Redis outages from JWKS outages.
    - **Technical:** The `handle()` method wraps both `verifyWithJwks()` and the subsequent `isRevoked()` Redis call in a single outer `try` block. `setSupabaseContext()` — which also writes to Redis via `trackForUser()` — was correctly given its own inner `try/catch` with a descriptive log and fail-open behaviour (this is already in the code). The `isRevoked()` call immediately above it has no such isolation. A `RedisException` from `EXISTS` propagates to the outer catch, which logs "JWT JWKS verification failed" and, because `$e` is not a `JwksUnavailableException`, returns a plain 401. This is the exact asymmetry the inner `trackingEx` catch was written to prevent — it just wasn't applied to the revocation call.
    - **Plain English:** Imagine a bouncer who checks a "banned list" before letting you in. If the banned-list computer crashes, the bouncer currently says "your ID is fake" and turns you away — instead of "our computer is down, sorry, come back in a minute." Meanwhile, the logs say the ID scanner is broken, not the banned-list computer, making it hard for the team to figure out what's actually wrong. The fix is a simple "if the banned-list computer is unreachable, let the person in and flag it separately" rule — the ID itself was already verified as genuine before the bouncer even reached the banned list.
    - **Evidence:**
        ```php
        // outer try — no inner guard around isRevoked()
        $sessionId = isset($claims['session_id']) ? (string) $claims['session_id'] : '';
        if ($sessionId !== '' && $this->revocation->isRevoked($sessionId)) {   // ← RedisException escapes here
            return response()->json([
                'message' => 'Session was terminated. Please log in again.',
                'code' => 'session_revoked',
            ], 401);
        }

        try {
            $this->setSupabaseContext($request, $uid, $claims);  // ← identical Redis risk, already guarded
        } catch (\Throwable $trackingEx) {
            Log::warning('Session tracking failed after successful JWT verification', [
                // ...
                'kind' => 'session_tracking',
            ]);
        }

        return $next($request);
        } catch (\Throwable $e) {
            Log::warning('JWT JWKS verification failed, falling back to auth server', [  // ← wrong diagnosis
                // ...
            ]);
            if (config('supabase.jwks_fail_closed', true)) {
                if ($e instanceof JwksUnavailableException) {
                    return response()->json(['message' => 'Service unavailable'], 503);
                }
                return response()->json(['message' => 'Invalid token'], 401);  // ← wrong response for Redis outage
            }
        ```

---

## P2 — Should fix

- [ ] **#JWT-2** · P2 — Revocation is silently skipped for tokens that omit the `session_id` claim
    - **Where:** app/Http/Middleware/Auth/VerifySupabaseJwt.php:82–88 (main path) and analogous block in the fallback path (~line 153)
    - **Affects:** Any session whose JWT lacks a `session_id` — e.g., a token type Supabase introduces in a future version, a machine/service token, or edge-case tokens from older project configurations. An admin-revoked session with such a token would continue to be accepted until natural expiry (up to 1 hour for access tokens).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `Log::warning` (with `'kind' => 'missing_session_id'`) whenever `$sessionId === ''` so you know if this case ever triggers in practice. It has no operational cost and gives you the data to decide the next step.
        - Once you've confirmed via logs whether this case is ever hit, tighten the guard: if Supabase always emits `session_id` on signed-in tokens (which current evidence suggests), reject tokens without it as structurally incomplete (`return response()->json(['message' => 'Token missing session_id'], 401)`). That converts a silent bypass into an explicit, auditable rejection.
        - Apply the same change to the fallback path's `$fallbackSessionId` check.
    - **Technical:** The guard `$sessionId !== '' && $this->revocation->isRevoked($sessionId)` silently skips the blocklist check when `session_id` is absent. `TokenRevocationService::isRevoked()` also returns `false` early for an empty string, so neither layer catches the gap. Supabase's signed-in access tokens consistently include `session_id`, but the code makes no assertion about this — a future token kind or a non-interactive service token that omits the claim would receive a clean pass through the revocation gate. The fix costs nearly nothing and eliminates a silent, untested code path.
    - **Plain English:** The bouncer has a list of banned visitor ID numbers. If a visitor's ID card has a blank ID field, the bouncer's rulebook says "only check the list if there's an ID number" — so a blank card is waved through without checking. Currently no one with a blank card is showing up, but if they ever do, they bypass the banned list entirely. The fix is to log each time someone arrives with a blank card, and then decide whether to require an ID number or turn them away at the door.
    - **Evidence:**
        ```php
        $sessionId = isset($claims['session_id']) ? (string) $claims['session_id'] : '';
        if ($sessionId !== '' && $this->revocation->isRevoked($sessionId)) {
            return response()->json([
                'message' => 'Session was terminated. Please log in again.',
                'code' => 'session_revoked',
            ], 401);
        }
        ```

- [ ] **#JWT-3** · P2 — `RequireAal2` middleware checks only session-level AAL; sensitive staff operations have no `amr`-based freshness window
    - **Where:** app/Http/Middleware/Auth/RequireAal2.php:22–29; app/Policies/BasePolicy.php:69–93
    - **Affects:** Staff routes protected by `require.aal2`. A staff member who passed MFA days or weeks ago holds a refresh token that continues minting `aal2` access tokens. Anyone holding that refresh token — whether the legitimate user on a new device, or an attacker who obtained it — passes the AAL2 gate indefinitely without re-verifying a second factor.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - `BasePolicy::requiresFreshAal2()` already exists in `app/Policies/BasePolicy.php` and is already wired into `UserSelfPolicy` (MFA enrollment) and `MfaController` (factor management). The mechanism is complete.
        - Identify the highest-risk operations within staff routes — role changes, user impersonation, account deletion, MFA factor removal — and add `$aal2Check = $this->requiresFreshAal2(); if ($aal2Check->denied()) return $aal2Check;` to those policy methods (or inline in controllers where there is no policy, following the existing `MfaController` pattern).
        - The `RequireAal2` middleware (session-level gate) is correct as-is for general staff access; `requiresFreshAal2()` is the layer above it for individual high-stakes actions, not a replacement.
        - Tune the freshness window via `config('partna.mfa.fresh_window_seconds')` (default 300s) or pass an explicit value per action.
    - **Technical:** Supabase sets `aal` once per session when a second factor is verified and never downgrades it on token refresh. A refresh token with `aal2` can mint new hour-long access tokens for up to ~30 days. `RequireAal2` trusts the sticky `aal` claim, so it provides session hygiene but not action-level freshness. The `amr` claim is an ordered list of authentication events with Unix timestamps; `requiresFreshAal2()` already scans it for the most recent MFA method timestamp and compares against `now()`. The gap is that this call is not yet applied to the sensitive operations inside staff routes.
    - **Plain English:** Your office's two-factor lock grants a "cleared" badge once you pass it. The badge says "cleared" forever — it never expires. Anyone who picks up that badge three weeks later can walk into the server room because the badge still shows "cleared." The system already has the technology to check "when was the last time *this person* actually typed their second-factor code?" — it's just not turned on for the sensitive rooms yet. Turning it on for the handful of actions that really matter (deleting accounts, changing roles) is a one-afternoon job using existing code.
    - **Evidence:**
        ```php
        // RequireAal2 — session-level check only, no freshness:
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

        // BasePolicy::requiresFreshAal2() — already implemented, not yet applied to staff actions:
        protected function requiresFreshAal2(?int $maxAgeSeconds = null): Response
        {
            $maxAgeSeconds ??= (int) config('partna.mfa.fresh_window_seconds', 300);
            $amr = request()->attributes->get('supabase_amr', []);
            $mfaMethods = ['totp', 'phone', 'webauthn'];

            $mostRecentMfaTs = null;
            foreach ($amr as $entry) {
                $method = $entry['method'] ?? null;
                if (in_array($method, $mfaMethods, true)) {
                    $ts = (int) ($entry['timestamp'] ?? 0);
                    if ($mostRecentMfaTs === null || $ts > $mostRecentMfaTs) {
                        $mostRecentMfaTs = $ts;
                    }
                }
            }

            if ($mostRecentMfaTs === null) {
                return Response::denyWithStatus(401, 'Recent MFA verification required');
            }

            return (time() - $mostRecentMfaTs) <= $maxAgeSeconds
                ? Response::allow()
                : Response::denyWithStatus(401, 'Recent MFA verification required');
        }
        ```

---

## P3 — Nice to have

- [ ] **#JWT-4** · P3 — Auth-Server fallback path returns 401 for network/upstream errors, making infrastructure outages indistinguishable from bad tokens
    - **Where:** app/Http/Middleware/Auth/VerifySupabaseJwt.php (fallback catch block, ~line 165)
    - **Affects:** Developers and non-production environments where `SUPABASE_JWKS_FAIL_CLOSED=false` is set. Not reachable in production: `AppServiceProvider::boot()` throws at startup if `jwks_fail_closed` is false in a production environment (confirmed at line 145–147). The impact is limited to local/staging debug sessions using the legacy fallback path.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In the fallback catch block, inspect the exception type before returning. For `\Illuminate\Http\Client\ConnectionException` or similar network errors, return 503 with a distinct message ("Auth service unavailable") so developers don't mistake a transient Supabase outage for a bad token.
        - Add a distinct log key (`'kind' => 'auth_server_fallback_failed'`) to separate this from JWKS failures in Nightwatch.
    - **Technical:** The fallback path (used only when `jwks_fail_closed = false`) calls `verifyWithAuthServer()`, which uses `Http::get()`. A connection timeout or 5xx from Supabase Auth throws a `\Throwable` that falls into the catch block and returns `response()->json(['message' => 'Invalid token'], 401)`. The JWKS path correctly distinguishes infrastructure failures (`JwksUnavailableException` → 503) from token failures (→ 401); the fallback path does not. Since `AppServiceProvider::boot()` prevents this path from running in production, the fix is polish for development and future maintainers rather than a live correctness gap.
    - **Plain English:** There's a backup ID-check system that's disabled in the real building (it's been locked out for security). When developers test it locally, if the backup server goes offline, the front desk says "your ID is invalid" instead of "the system is down." This makes debugging confusing — but since it can't happen in the real building, fixing it is about making the developer experience less frustrating, not about protecting real visitors.
    - **Evidence:**
        ```php
        try {
            $uid = $this->verifyWithAuthServer($token);
            if (! $uid) {
                return response()->json(['message' => 'Invalid token'], 401);
            }
            // …
            return $next($request);
        } catch (\Throwable $e2) {
            Log::warning('JWT verification failed', [
                'request_id' => $requestId,
                'operation' => __METHOD__,
                'reason' => $e2->getMessage(),
                'ip' => $request->ip(),
            ]);

            return response()->json(['message' => 'Invalid token'], 401);
        }
        ```

`★ Insight ─────────────────────────────────────`
- The asymmetric try/catch pattern here (inner guard for tracking, no guard for revocation) is a common drift pattern in security middleware: a subsequent engineer added the tracking call and correctly isolated it, but didn't look one block up at the structurally identical Redis call that predated it. A code review checklist item — "does every Redis call in auth middleware have a failure strategy?" — would have caught this.
- The `requiresFreshAal2` / `RequireAal2` two-tier model is an excellent design: session-level AAL2 at the middleware layer (cheap, applies broadly) and amr-timestamp freshness at the policy layer (only for high-stakes individual actions). It just needs consistent application to staff-route sensitive actions.
`─────────────────────────────────────────────────`
