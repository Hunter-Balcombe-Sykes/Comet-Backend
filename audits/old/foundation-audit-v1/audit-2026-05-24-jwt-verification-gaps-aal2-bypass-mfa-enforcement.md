`★ Insight ─────────────────────────────────────`
JWT-3 is a false positive: `config('supabase.admin.base_url')` has a default of `{SUPABASE_URL}/auth/v1/admin` (line 38 of `config/supabase.php`), so the appended `/users/{id}/factors/{id}` produces the correct full endpoint. DeepSeek saw two different config keys and assumed divergence without checking the config file's default expression. This is a classic cross-file invariant verification miss.
`─────────────────────────────────────────────────`

# Auth/MFA Audit — 2026-05-24

**Branch:** development
**Lens:** JWT verification gaps, AAL2 bypass, MFA enforcement holes, claim trust, token replay
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Middleware/Auth/VerifySupabaseJwt.php
- app/Http/Middleware/Auth/RequireAal2.php
- app/Http/Middleware/Auth/RequireEmailVerified.php
- app/Http/Middleware/Auth/EnsurePartnaStaff.php
- app/Http/Middleware/Auth/EnsurePartnaAdmin.php
- app/Http/Middleware/Auth/VerifySupabaseEmailHookSignature.php
- app/Services/Auth/SupabaseAdminService.php
- app/Services/Auth/SupabaseAuthHookService.php
- app/Services/Auth/AuthFactorEventRepository.php
- app/Exceptions/Auth/JwksUnavailableException.php
- config/supabase.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete

---

## P2 — Should fix

- [ ] **#JWT-1** · P2 — `RequireAal2` trusts JWT `aal` claim; stolen `aal2` token bypasses MFA gate for its full lifetime
    - **Where:** app/Http/Middleware/Auth/RequireAal2.php:26-33
    - **Affects:** All routes gated by `require.aal2` (currently staff routes). A leaked `aal2` JWT grants MFA-protected access until natural token expiry (~1h Supabase default) even after an admin revokes the session in the Supabase dashboard.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Accept this as a known JWT statelessness trade-off for now, and document the decision explicitly in the `RequireAal2` docblock (e.g. "session liveness is not checked — relies on short token TTL as the revocation window").
        - When/if an incident demands tighter guarantees: add an optional `supabase_session_id`-based liveness check cached in Redis per session (TTL 60s), keyed as `jwt:session-alive:{session_id}`. Call `GET /auth/v1/admin/users/{uid}/sessions` and filter for the session ID. On a cache miss, call Supabase; on hit, use the cached result. Return the same `401 mfa_required` body on revoked sessions.
        - Add a config flag (`SUPABASE_AAL2_LIVENESS_CHECK=true`) so it can be toggled without a deploy and off-loaded to a separate service if latency becomes a concern.
    - **Technical:** `RequireAal2` reads `supabase_aal` which `VerifySupabaseJwt::setSupabaseContext()` sets from `$claims['aal']` — a claim baked into the JWT at issuance. JWTs are signed snapshots; Supabase can revoke the underlying session via the dashboard or admin API, but this middleware never consults the live session state. The `supabase_session_id` attribute is already extracted on `$request->attributes` (line 182 of `VerifySupabaseJwt.php`) — the infrastructure to do the liveness check is in place, only the check itself is missing. At Supabase's default 1-hour token TTL the practical attack window is bounded, which is why this is P2 (hardening) rather than P1.
    - **Plain English:** Think of the `aal2` claim in a JWT like a "VIP" stamp on a wristband at a venue. Once you're stamped in, the door staff just glances at your wrist — they don't call back to the front desk to confirm your VIP status is still valid. If the front desk later revokes your VIP status, you still get in until the night ends (token expires). For now that's an acceptable tradeoff, but the right long-term fix is a quick radio check with the front desk, cached for a minute so it doesn't slow down every person walking through the door.
    - **Evidence:**
        ```php
        // app/Http/Middleware/Auth/RequireAal2.php:24-33
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
        ```
        ```php
        // app/Http/Middleware/Auth/VerifySupabaseJwt.php:178-183
        if ($claims !== null) {
            $request->attributes->set('supabase_claims', $claims);
            $request->attributes->set('supabase_aal', $claims['aal'] ?? 'aal1');
            $request->attributes->set('supabase_amr', $claims['amr'] ?? []);
            $request->attributes->set('supabase_session_id', $claims['session_id'] ?? null);
        }
        ```

- [ ] **#JWT-2** · P2 — Logout is cosmetic — any stolen JWT remains valid on all routes until natural expiry
    - **Where:** app/Http/Middleware/Auth/VerifySupabaseJwt.php (full `handle` flow)
    - **Affects:** All authenticated routes. A token obtained by an attacker (XSS, device theft, log leakage) continues to authenticate requests even after the legitimate user signs out or an admin forcibly terminates the session in Supabase. This is the general case of which JWT-1 is a specific subset.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Document the accepted tradeoff in `VerifySupabaseJwt`'s class docblock: "Session revocation relies on short token TTL; no per-request liveness check is performed."
        - If tighter revocation is required in future: implement a Redis-backed short-lived blocklist. On Supabase logout webhook (or admin action), write `jwt:revoked:{session_id}` with TTL equal to the token's remaining lifetime. `VerifySupabaseJwt` checks this key after signature verification — a hit returns 401 immediately. This avoids an upstream HTTP call on every request.
        - Alternatively, reduce Supabase JWT TTL to 15 minutes (configurable per project under Auth → Settings) to shrink the attack window without any code change.
    - **Technical:** `verifyWithJwks()` (and the auth-server fallback) return as soon as the cryptographic signature and standard claims (`exp`, `iss`, `aud`) pass. The `supabase_session_id` extracted at line 182 is never used for a liveness check anywhere in the middleware stack. Supabase's session API (`GET /auth/v1/admin/users/{uid}/sessions`) can confirm whether a session ID is still active, but calling it on every request introduces an upstream dependency and ~100ms latency hit. The blocklist approach is lower-latency and doesn't add a hard dependency on Supabase Auth availability per request.
    - **Plain English:** When a user clicks "log out," the app tells Supabase to end the session — but it doesn't void the physical key (the JWT) they were holding. Anyone who grabbed a copy of that key before logout can still use it to open doors until it expires on its own schedule. The fix is either to shorten how long keys stay valid (a setting change) or to keep a short "voided keys" list at the door that the bouncer checks before letting anyone in.
    - **Evidence:**
        ```php
        // app/Http/Middleware/Auth/VerifySupabaseJwt.php — setSupabaseContext()
        $request->attributes->set('supabase_session_id', $claims['session_id'] ?? null);
        // ^^^ extracted but never read by any downstream middleware, policy, or controller
        // to confirm the session is still alive in Supabase
        ```
        ```php
        // verifyWithJwks() returns after cryptographic + claims checks only:
        $decoded = JWT::decode($jwt, $key);
        // No subsequent call to Supabase session endpoint before returning $claims
        return json_decode(json_encode($decoded), true) ?: [];
        ```

The JWT-3 draft finding (`unenrollMfaFactor` URL divergence) was dropped: `config('supabase.admin.base_url')` defaults to `rtrim(env('SUPABASE_URL'), '/').'/auth/v1/admin'` (verified in `config/supabase.php:38`), so the path appended in `unenrollMfaFactor` produces the correct full endpoint. The JWT-4 draft finding (process-global `JWT::$leeway`) was dropped: confidence 0.65 is below threshold and the risk is purely theoretical under the current PHP-FPM runtime with no Octane.
