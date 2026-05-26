`★ Insight ─────────────────────────────────────`
This audit covers only the session-management + JWT-middleware surface (four files). DeepSeek drafted 8 findings across 8 lenses; several overlap conceptually. Key adjudication decisions: CACHE-1 is dropped because the source code explicitly documents the SADD-on-every-request as intentional design ("idempotent and cheap"); SEC-2 (plaintext IP/UA) is downgraded to P3 because the data is user-owned and only returned to the authenticated user; SEC-3 (no throttle on logout routes) stays at P2 because these are authenticated user-facing endpoints, not internal routes covered by the always-drop rule.
`─────────────────────────────────────────────────`

# Session Management & JWT Middleware Audit — 2026-05-24

**Branch:** development
**Lens:** Bundle 'core' audit across 8 focused themes: security/policy (SEC-*), lifecycle correctness (LIFE-*), scaling antipatterns (CACHE-*), database/queue scaling — N+1/throughput (SCALE-*), schema/RLS correctness (SCHEMA-*), caching gold-standard adherence (CCH-*), webhook idempotency & delivery (WHK-*), and transaction-boundary correctness (TXN-*)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Auth/TokenRevocationService.php
- app/Http/Controllers/Api/Professional/Account/SessionController.php
- app/Http/Middleware/Auth/VerifySupabaseJwt.php
- tests/Feature/Auth/TokenRevocationServiceTest.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: **3 of 3 complete** (resolved 2026-05-25)
- P3 Low: **4 of 4 complete** (resolved 2026-05-25)

**Resolution commits** (on `development` branch):
- `bcfbf053` — SEC-3 + LIFE-2 (TokenRevocationService: PII minimisation + atomic first-seen)
- `5467a117` — SEC-2 (per-user throttle on session-management writes)
- `6a59590e` — SEC-1 + LIFE-1 (auth-server fallback revocation gate + Redis tracking-failure isolation)
- `e83afc78` — LIFE-3 + CCH-1 (APCu observability + cache_locks-backed JWKS throttle)

---

## P2 — Should fix

- [x] **#SEC-1** · P2 — Auth-server fallback path skips session revocation check and omits session-ID context
    - **Where:** app/Http/Middleware/Auth/VerifySupabaseJwt.php — fallback try/catch block (~line 170)
    - **Affects:** Any deployment where `SUPABASE_JWKS_FAIL_CLOSED=false` is set — revoked sessions re-authenticate successfully through the fallback path, and `supabase_session_id` is always null for any downstream middleware that reads it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In the `verifyWithAuthServer` success branch, call `$this->extractJwtPayloadClaims($token)` (already exists on the class) to recover the payload, then run `$this->revocation->isRevoked($sessionId)` before proceeding.
        - Pass the extracted claims to `$this->setSupabaseContext($request, $uid, $claims)` so `supabase_session_id`, `supabase_exp`, and `supabase_aal` are populated identically to the JWKS path.
    - **Technical:** The JWKS-verified path runs `isRevoked()` and fully populates request attributes via `setSupabaseContext($request, $uid, $claims)`. The auth-server fallback calls `setSupabaseContext($request, $uid)` with no claims — meaning `supabase_session_id` is always `null`, the revocation blocklist is never consulted, and `supabase_aal` defaults to `aal1`. The `extractJwtPayloadClaims` helper (used earlier in `verifyWithAuthServer` itself for issuer/audience validation) already has the decoded payload available; the session_id is simply never acted upon. The fallback is gated by `config('supabase.jwks_fail_closed', true)` defaulting true, so production exposure requires a deliberate misconfiguration — but defense-in-depth demands both paths enforce the same revocation contract.
    - **Plain English:** Think of a building with a main entrance where a guard checks a blocklist — anyone who's been kicked out is turned away. There's also a side door used only during emergencies, but it has no guard at all. The side door is supposed to stay dead-bolted in production, but if someone ever props it open, every person on the blocklist walks straight through. The fix stations the same guard at both doors.
    - **Evidence:**
        ```php
        // JWKS path — revocation check present, full context set
        $sessionId = isset($claims['session_id']) ? (string) $claims['session_id'] : '';
        if ($sessionId !== '' && $this->revocation->isRevoked($sessionId)) {
            return response()->json([
                'message' => 'Session was terminated. Please log in again.',
                'code' => 'session_revoked',
            ], 401);
        }
        $this->setSupabaseContext($request, $uid, $claims);
        return $next($request);
        ```
        ```php
        // Auth-server fallback — no revocation check, no claims forwarded
        $uid = $this->verifyWithAuthServer($token);
        if (! $uid) {
            return response()->json(['message' => 'Invalid token'], 401);
        }
        $this->setSupabaseContext($request, $uid); // ← no claims → session_id never checked
        return $next($request);
        ```

- [x] **#SEC-2** · P2 — Session-management endpoints carry no throttle middleware, enabling Redis write amplification
    - **Where:** app/Http/Controllers/Api/Professional/Account/SessionController.php — `logout`, `logoutOthers`, `destroy` methods
    - **Affects:** Authenticated users — a buggy client in a redirect loop, or an attacker holding a valid token, can issue unlimited revocation writes (`SETEX`, `SADD`, `SREM`, `DEL`) against the Redis revocation key space, which is consulted on every authenticated request site-wide.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply a per-user rate limiter (keyed on `supabase_uid`, not IP) to the `logout`, `logoutOthers`, and `destroy` routes — `throttle:10,1` is a reasonable starting point.
        - Register a named rate limiter in `AppServiceProvider` or `RouteServiceProvider` so the limit is configurable without code changes: `RateLimiter::for('session-writes', fn ($r) => Limit::perMinute(10)->by($r->attributes->get('supabase_uid')))`.
    - **Technical:** All three mutating endpoints write to Redis on every call: `revoke()` calls `SETEX`, `revokeAllForUser()` calls N `SETEX` + N `SREM`, and `destroy()` calls one each. None carry throttle middleware. The revocation blocklist is the single piece of server-side state consulted by `isRevoked()` on every authenticated request — write flooding degrades that path for all users, not just the one sending the requests. Per-user keying (on `supabase_uid`) is important because multiple users may share an IP (embedded app context, corporate NAT), making IP-keyed throttling ineffective.
    - **Plain English:** The "sign out" buttons in the app have no speed limit. A bug that fires logout in a loop — or someone deliberately hammering the endpoint — will write to the system's "who's allowed in" checklist thousands of times per second. That checklist is consulted on every single page load for every user on the platform, so slowing it down with writes slows down everyone's experience. A simple rate limiter — no more than ten sign-out actions per minute per person — prevents this with a few lines of code.
    - **Evidence:**
        ```php
        class SessionController extends Controller
        {
            public function __construct(private readonly TokenRevocationService $revocation) {}

            /** POST /api/sessions/logout */
            public function logout(Request $request): JsonResponse { ... }

            /** POST /api/sessions/logout-others */
            public function logoutOthers(Request $request): JsonResponse { ... }

            /** DELETE /api/sessions/{sessionId} */
            public function destroy(Request $request, string $sessionId): JsonResponse { ... }
        }
        ```

- [x] **#LIFE-1** · P2 — Redis failure inside `setSupabaseContext` is logged as "JWT JWKS verification failed," masking the real root cause
    - **Where:** app/Http/Middleware/Auth/VerifySupabaseJwt.php — outer try/catch in `handle()` (~line 123)
    - **Affects:** On-call engineers during a Redis outage — every authenticated request logs the wrong failure mode, Nightwatch alert grouping points at JWKS infrastructure instead of Redis, and MTTR extends because the real signal is buried.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `$this->setSupabaseContext(...)` outside the JWKS try/catch (call it after `return $next($request)` is no longer an option, so instead wrap the `setSupabaseContext` call in its own inner try/catch with a distinct log: `Log::warning('Session tracking failed after successful JWT verification', [...])`.
        - Ensure a Redis failure during session tracking does not trigger the JWKS fallback path — the token is valid; only the side-effect write failed. In that case, set the context without tracking and continue to `$next($request)`.
    - **Technical:** `setSupabaseContext()` calls `$this->revocation->trackForUser()`, which issues `Redis::sadd` and `Redis::expire`. If Redis is unavailable, this throws a `\Throwable` that is caught by the outer catch block and logged as `'JWT JWKS verification failed, falling back to auth server'`. The JWKS check itself succeeded — the token is cryptographically valid — but a transient Redis blip causes valid requests to be mis-logged as JWKS failures and, depending on `jwks_fail_closed`, may trigger the auth-server fallback unnecessarily. At scale, a 5-minute Redis outage produces thousands of misleading log entries.
    - **Plain English:** When you swipe a valid hotel keycard, two things happen: the lock verifies your card, then a logbook records your entry. If the logbook breaks, the system currently reports "invalid keycard" — even though your card worked fine. Engineers troubleshoot the card reader for an hour before realising the logbook was the problem. Separating the two steps into separate error messages means the alert immediately points at the right component.
    - **Evidence:**
        ```php
        try {
            $claims = $this->verifyWithJwks($token);
            // ... claims validation ...
            $this->setSupabaseContext($request, $uid, $claims); // Redis write happens here
            return $next($request);
        } catch (\Throwable $e) {
            Log::warning('JWT JWKS verification failed, falling back to auth server', [
                // ← misleading: Redis failure lands here with the same message
                'reason' => $e->getMessage(),
            ]);
            // fallback path...
        }
        ```
        ```php
        // Inside setSupabaseContext → trackForUser → Redis:
        Redis::sadd($setKey, $sessionId);
        Redis::expire($setKey, self::MAX_LIFETIME_SECONDS);
        ```

---

## P3 — Nice to have

- [x] **#SEC-3** · P3 — Session IP and user-agent written and returned in plaintext with no at-rest protection
    - **Where:** app/Services/Auth/TokenRevocationService.php:79-87 (`trackForUser`), app/Http/Controllers/Api/Professional/Account/SessionController.php — `index` response
    - **Affects:** All authenticated users — their login IP history and raw user-agent strings are stored plaintext in Redis for up to 30 days and returned verbatim in API responses.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Introduce a `SessionResource` class that reformats the raw data before returning it — exposing browser family (parsed from UA) rather than the full UA string, and optionally GeoIP city rather than the raw IP.
        - If raw IP/UA must be retained for security-audit purposes, note the retention period in the privacy policy.
        - Consider encrypting values at rest using Laravel's `Encrypter` if compliance requirements demand it.
    - **Technical:** `trackForUser` writes `ip` and `user_agent` into a Redis hash via `hmset` with no encryption. `listSessionsForUser` reads them back via `hgetall` and `SessionController::index` passes them directly into the JSON response. Each user sees only their own sessions (the UID is resolved from a verified JWT), so there is no cross-user data leak. The risk is incidental exposure: Redis command logs captured by monitoring, a misconfigured Redis ACL, or a future bug that widens the query scope. The current implementation is common and functional; this is a privacy-hygiene hardening item rather than an active vulnerability.
    - **Plain English:** Your login history — including the exact IP address you used each time — sits in a database drawer for 30 days and gets read back to you whenever you open the "active sessions" page. Nobody else can see it; it's your drawer. But the drawer has no lock, and if anyone ever peeked inside (a logging tool, a monitoring system), they'd see home addresses written in plain ink. The fix is to either blur the address (show city instead of street number) or put a lock on the drawer.
    - **Evidence:**
        ```php
        Redis::hmset($metaKey, [
            'user_id' => $userId,
            'created_at' => (string) time(),
            'ip' => (string) ($metadata['ip'] ?? ''),
            'user_agent' => (string) ($metadata['user_agent'] ?? ''),
        ]);
        ```
        ```php
        return response()->json(['sessions' => $sessions]); // ip + user_agent returned verbatim
        ```

- [x] **#LIFE-2** · P3 — Read-then-write race on session first-seen metadata
    - **Where:** app/Services/Auth/TokenRevocationService.php:72-84 (`trackForUser`)
    - **Affects:** Active Sessions UI — shows wrong "first signed in from" IP if two concurrent requests for a brand-new session race. Cosmetic only; no security or functional impact.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `Redis::exists($metaKey)` guard + `Redis::hmset` with a single Lua script (or `hsetnx` on a sentinel field such as `_init`) so the check-and-set is atomic.
        - Alternatively, document the race explicitly in the code comment and accept it — at realistic device-count concurrency, simultaneous first requests for the same brand-new session_id are extraordinarily rare.
    - **Technical:** `trackForUser` checks `!Redis::exists($metaKey)` then calls `Redis::hmset`. These are two separate Redis commands with no transaction boundary. A second PHP-FPM worker processing a concurrent request for the same brand-new session can observe `exists` returning false and overwrite the first `hmset` with different metadata (different IP). The canonical fix is a Lua script or Redis `MULTI`/`WATCH` to make the check-and-set atomic. The impact is negligible — simultaneous first-request pairs for the same session are extraordinary at current scale.
    - **Plain English:** Imagine two hotel front desks checking in the same guest simultaneously. Both check "has room 302 signed in yet?" and both see an empty form, so both fill it in — but with different handwriting. One entry overwrites the other, recording the wrong arrival time. Nobody loses their room; it's just a smudge in the guestbook.
    - **Evidence:**
        ```php
        $metaKey = self::SESSION_META_PREFIX.$sessionId;
        if (! Redis::exists($metaKey)) {
            Redis::hmset($metaKey, [
                'user_id' => $userId,
                'created_at' => (string) time(),
                'ip' => (string) ($metadata['ip'] ?? ''),
                'user_agent' => (string) ($metadata['user_agent'] ?? ''),
            ]);
            Redis::expire($metaKey, self::MAX_LIFETIME_SECONDS);
        }
        ```

- [x] **#LIFE-3** · P3 — Silent `@` suppression on `apcu_store` hides APCu capacity failures
    - **Where:** app/Http/Middleware/Auth/VerifySupabaseJwt.php — `apcuStore` method
    - **Affects:** All authenticated requests when APCu fills up or misconfigures — p99 latency silently degrades by 150–300ms per request as every request re-runs `JWK::parseKeySet()`. Nightwatch sees latency regressions but has no root-cause signal.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the `@` error suppressor and capture the return value of `apcu_store`.
        - When it returns `false`, emit a throttled `Log::warning('APCu store failed for JWKS key — cold-path will run on every request', ['kid' => $kid])` (throttled to once per 5 minutes using the same `Cache::add` pattern already present in `jwksOutage`).
    - **Technical:** `@apcu_store(...)` silently discards any failure — APCu full, APCu disabled mid-request, or a version bug. When APCu is unavailable, every JWKS resolution falls through to the cold `parseKeySet()` path, a 150–300ms ES256 key parse, on every authenticated request. The `resolveSigningKey` method has no fallback metric or log to surface this degradation, so Nightwatch detects a latency regression but cannot attribute it. The `@` suppressor is the only thing preventing `apcu_store` from surfacing its own failure.
    - **Plain English:** The middleware keeps a speed-cheat-sheet so it doesn't have to re-read the full access-control rulebook on every request. If the cheat-sheet storage breaks, the code silently ignores the failure and re-reads the whole rulebook every time — making every login 10x slower with zero indication of why. Removing the silence operator means the system at least leaves a note saying "the cheat-sheet drawer is jammed."
    - **Evidence:**
        ```php
        private function apcuStore(string $key, mixed $value): void
        {
            if (! function_exists('apcu_store') || ! ini_get('apc.enabled')) {
                return;
            }

            @apcu_store($key, $value, self::APCU_TTL_SECONDS);
        }
        ```

- [x] **#CCH-1** · P3 — JWKS outage throttle key uses the default cache store instead of the locks store
    - **Where:** app/Http/Middleware/Auth/VerifySupabaseJwt.php — `jwksOutage` method
    - **Affects:** JWKS outage Nightwatch reporting — a `Cache::flush()` on the data store resets the throttle and allows a flood of duplicate exception reports during an ongoing outage.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::add('jwt:jwks-failure-reported', true, 60)` with `Cache::store('cache_locks')->add('jwt:jwks-failure-reported', true, 60)`.
    - **Technical:** The `jwt:jwks-failure-reported` key acts as a one-per-minute lock preventing repeated `report($outage)` calls during a sustained JWKS outage. Using the default Redis cache store means any scheduled `Cache::flush()` or operator-initiated cache clear on the data store (DB 0) resets the throttle. The project already maintains a separate `cache_locks` Redis connection (DB 0 with lock-key isolation, or a separate DB — confirm in `config/database.php`) for lock-like keys that must survive data flushes. This throttle key should live there.
    - **Plain English:** The system has a rule: "don't send more than one alarm per minute for the same outage." That rule is written on a sticky note on the same desk as the app's general scratch-pad. If someone sweeps the desk clean (a cache flush), the sticky note disappears and the alarm fires again and again. Moving the rule to a dedicated, protected notebook — separate from the scratch-pad — means a desk-sweep can't accidentally cancel it.
    - **Evidence:**
        ```php
        private function jwksOutage(\Throwable $cause): JwksUnavailableException
        {
            $outage = new JwksUnavailableException($cause);

            if (Cache::add('jwt:jwks-failure-reported', true, 60)) {
                report($outage);
            }

            return $outage;
        }
        ```

`★ Insight ─────────────────────────────────────`
Three patterns worth noting in this codebase: (1) The `extractJwtPayloadClaims` method is already called inside `verifyWithAuthServer` for issuer/audience validation — meaning SEC-1's fix is nearly free, the session_id is already decoded, just not forwarded. (2) The `jwksOutage` throttle pattern (`Cache::add` as a one-shot lock) is a clean idiom that appears elsewhere in Laravel; the only gap is the wrong store. (3) LIFE-1 illustrates a classic "too-wide try/catch" problem — when a success path and its side-effects share one catch block, any infrastructure failure in the side-effect gets mis-classified as a primary failure. Keeping success-path side-effects in their own nested try/catch is the canonical fix.
`─────────────────────────────────────────────────`
