# Inbound Callbacks & Idempotency Semantics Audit — 2026-07-11

**Branch:** HEAD
**Lens:** Inbound callbacks & idempotency semantics — Supabase auth/email hooks, `bot.token`-gated internal endpoints, and the client-supplied `IdempotencyKey` middleware, measured against the Standard Webhooks gold standard (HMAC-before-parse, atomic idempotency anchors, no silent-200-on-failure, out-of-order tolerance).
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php
- app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php
- app/Http/Controllers/Api/Internal/EnvCheckController.php
- app/Http/Middleware/Auth/VerifySupabaseHookSignature.php
- app/Services/Webhooks/StandardWebhookVerifier.php
- app/Services/Auth/AuthFactorEventRepository.php
- app/Services/Notifications/SupabaseEmailEventService.php
- app/Http/Middleware/IdempotencyKey.php
- app/Http/Middleware/VerifyBotToken.php
- app/Http/Middleware/Logging/LogLeadRateLimits.php
- app/Http/Controllers/Api/User/Account/UserAccountDeletionController.php
- app/Services/User/AccountDeletionService.php
- routes/api.php
- routes/api/user.php
- app/Providers/AppServiceProvider.php (rate limiters)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

- [ ] **#WHK-1** · P1 — `Idempotency-Key` is opt-in, not enforced, on account-deletion routes — omitting the header silently drops the double-submit protection it was built for
    - **Where:** app/Http/Middleware/IdempotencyKey.php:44-47, routes/api/user.php:54-65, app/Services/User/AccountDeletionService.php:71-97
    - **Affects:** Any user whose client (mobile double-tap before the header is attached, a curl/API test, a future frontend regression, a retry library that drops custom headers) calls `POST /me/deletion/request` without `Idempotency-Key` — two concurrent calls both pass, both queue a deletion-confirmation email and both write an audit row.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a route-level "idempotency required" mode (e.g. `idempotent:required` middleware variant, or a small pre-flight check) that returns 400 when the header is missing on routes documented as requiring it, instead of silently falling through to `$next($request)`.
        - As a defense-in-depth backstop independent of client compliance, make `AccountDeletionService::request()` itself idempotent at the DB layer (e.g. `lockForUpdate()` on the user row, or a short-TTL `Cache::add()` keyed by user id) so a raced double-submit can't queue two confirmation emails regardless of header presence.
    - **Technical:** `IdempotencyKey::handle()` treats a missing/blank `Idempotency-Key` header as "not opted in" and calls `$next($request)` unconditionally (lines 44-47) — there is no route-level flag or Form Request rule anywhere in the codebase that requires the header on `/me/deletion/*`, despite the route comment stating the middleware "closes the concurrent-double-submit race that would otherwise let a browser refresh or mobile double-tap persist duplicate audit rows and queue duplicate confirmation mails (#P2-43)" and "Frontend must send a per-action `Idempotency-Key: <uuid-v4>` header." `AccountDeletionService::request()` provides no independent anchor: it only checks `$professional->status === 'pending_deletion'` in the controller (a status that `request()` itself never sets — only `confirm()` does), so two concurrent `/request` calls without the header both pass the controller check, both enter the `DB::connection('pgsql')->transaction()` block, and both dispatch `SendAccountDeletionRequestMailJob` and write an audit row. The middleware's protection is real but is entirely a function of client cooperation, which is exactly the property #P2-43 was meant to eliminate.
    - **Plain English:** There's a safety mechanism meant to stop a user's account-deletion request from being processed twice if they double-tap the button or their app retries the request. But that safety mechanism only works if the app remembers to attach a special "don't repeat this" tag to the request — and nothing on the server checks that the tag is actually there. If the tag is ever missing (a bug, a different client, a flaky network retry), the server happily processes the same deletion request twice: two confirmation emails go out and two records get logged, even though the safeguard was specifically built to prevent that.
    - **Evidence:**
        ```php
        $key = $request->header('Idempotency-Key');
        if (! is_string($key) || $key === '') {
            return $next($request);
        }
        ```
        ```php
        // `idempotent` middleware closes the concurrent-double-submit race that
        // would otherwise let a browser refresh or mobile double-tap persist
        // duplicate audit rows and queue duplicate confirmation mails (#P2-43).
        // Frontend must send a per-action `Idempotency-Key: <uuid-v4>` header.
        Route::prefix('me/deletion')->middleware('idempotent')->group(function () {
            Route::post('/request', [UserAccountDeletionController::class, 'request'])
                ->middleware('throttle:3,60');
        ```

## P2 — Should fix

- [ ] **#WHK-2** · P2 — `idempotent` middleware runs after `throttle:authenticated` on the account-deletion route group, so lock-contended 409s consume rate-limit budget
    - **Where:** routes/api/user.php:41-65, app/Http/Middleware/IdempotencyKey.php:95-102
    - **Affects:** Authenticated users hitting `/me/deletion/*` with concurrent identical `Idempotency-Key` requests — the losing request gets a 409 but has already been counted against the per-user `authenticated` rate limit.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Reorder the group so `idempotent` sits ahead of `throttle:authenticated` for this route group (either apply it on the outer group with a no-op guard for GET routes, or split the inner group's middleware stack).
    - **Technical:** Laravel merges nested route-group middleware outer-first, so the effective stack for `/me/deletion/*` is `user.api` → `EnforcePendingDeletionReadOnly` → `throttle:authenticated` → `idempotent` → (route-specific `throttle:3,60` on `/request`, which therefore runs *after* `idempotent` and is unaffected). The lens's documented contract places `idempotent` before `ThrottleRequests` precisely so a lock-contended 409 costs zero rate-limit budget; here it costs one hit against the outer `authenticated` limiter (`RateLimiter::for('authenticated', ...)` in `AppServiceProvider.php`, 300 req/min per user). At that budget a client would need to sustain roughly 300 concurrent identical retries in one minute before self-locking, so this is hardening rather than a live-today failure mode — but it's a real, fixable ordering violation of the documented contract, and the fix is a one-line group reorder.
    - **Plain English:** Before checking whether a request is a duplicate, the server first counts it against how many requests that user is allowed to make per minute. So a burst of identical retries (say, from a flaky connection) uses up part of the user's request allowance even though the server ultimately rejects them as duplicates. The per-minute allowance here is generous enough that this wouldn't realistically lock someone out today, but the order is backwards from how it's documented to work, and it's cheap to fix.
    - **Evidence:**
        ```php
        Route::middleware(['user.api', EnforcePendingDeletionReadOnly::class, 'throttle:authenticated'])
            ->group(function () {
                ...
                Route::prefix('me/deletion')->middleware('idempotent')->group(function () {
                    Route::post('/request', [UserAccountDeletionController::class, 'request'])
                        ->middleware('throttle:3,60');
                    Route::post('/confirm', [UserAccountDeletionController::class, 'confirm']);
                    Route::post('/cancel', [UserAccountDeletionController::class, 'cancel'])
                        ->withoutMiddleware([EnforcePendingDeletionReadOnly::class]);
                });
        ```
        ```php
        if (! $acquired) {
            // Another request with the same key is mid-flight. Tell the client
            // to retry shortly — they should hit the cache fast-path next time.
            return response()->json([
                'message' => 'Request with the same Idempotency-Key is already in progress.',
                'code' => 'idempotency_locked',
            ], 409, ['Retry-After' => '1']);
        }
        ```

- [ ] **#WHK-3** · P2 — `VerifyBotToken`'s circuit-breaker fail-open alerting self-masks during the exact Redis outage it's meant to escalate
    - **Where:** app/Http/Middleware/VerifyBotToken.php:223-265 (`firstHitInWindow`, `throttledFailReport`)
    - **Affects:** Operators monitoring bot-protection health during a Redis outage; all `bot.token`-gated public write endpoints (subscribe, signup, lead, enquiry, waitlist, login-identifier) silently lose CAPTCHA enforcement with zero alert.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `throttledFailReport`, add a fallback path when `firstHitInWindow` fails due to a Redis exception (not just "already logged this window") so `report()` still fires — mirror the pattern already used in `LogLeadRateLimits::terminate()` and `IdempotencyKey::logFailOpen()`, both of which fall through to an unconditional `report($e)` when the throttling lock itself is unreachable.
        - Keep `Log::warning` unconditional on this path (as the `provider_error` branch already does) so the breadcrumb survives even when the Nightwatch page is throttled.
    - **Technical:** `throttledFailReport` gates *both* `Log::warning` and `report()` behind `firstHitInWindow()`, which wraps its Redis `INCR`/`EXPIRE` call in a try/catch that returns `false` on any `Throwable` (line 235-237). When Redis is down, `firstHitInWindow` returns `false`, `throttledFailReport` returns immediately, and neither the log nor the Nightwatch report fires — for both the `circuit_open` path and the `breaker_unavailable` path (the latter is reached specifically because a Redis-backed `CircuitBreaker::isOpen()` call just threw, meaning the *same* Redis outage will almost certainly make the subsequent `firstHitInWindow` call fail too). This is the opposite of the established codebase pattern in `LogLeadRateLimits::terminate()` and `IdempotencyKey::logFailOpen()`, both of which catch a lock-acquisition failure and fall through to an unconditional `report($e)` specifically so a sustained outage can't suppress its own alert. A circuit-open state is a security-relevant posture change (CAPTCHA enforcement is bypassed for every gated public endpoint); losing observability of it during the outage that likely caused the breaker to trip is a self-masking failure.
    - **Plain English:** When the bot-protection system trips its circuit breaker and starts letting requests through without a CAPTCHA check, it's supposed to send an alert so the team knows. But the alert mechanism itself relies on the same Redis server that's probably the reason the breaker tripped in the first place. If Redis goes down, the breaker opens, captcha checks get skipped for everyone, and nobody gets paged — because the paging system also needs Redis and quietly gives up. It's a fire alarm wired to the same circuit as the fire.
    - **Evidence:**
        ```php
        private function throttledFailReport(string $dedupKey, string $logEvent, array $context, ?string $reportMessage = null): void
        {
            if (! $this->firstHitInWindow($dedupKey)) {
                return;
            }

            try {
                Log::warning($logEvent, $context);
                if ($reportMessage !== null) {
                    report(new \RuntimeException($reportMessage));
                }
            } catch (Throwable $e) {
                // Observability must never break a request — a fail-open decision is already made.
            }
        }
        ```
        ```php
        private function firstHitInWindow(string $dedupKey): bool
        {
            try {
                ...
                return (int) $count === 1;
            } catch (Throwable $e) {
                return false;
            }
        }
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Account-deletion idempotency hardening:** #WHK-1, #WHK-2
    - **Why grouped:** same route group (`/me/deletion/*`), same middleware file, same underlying subsystem — fixing enforcement and reordering can land in one pass over `routes/api/user.php` + `IdempotencyKey.php` + `AccountDeletionService.php`.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#WHK-3 — Bot-protection fail-open observability self-masks:** touches a distinct file (`VerifyBotToken.php`) with no shared subsystem with Bundle 1; small, self-contained fix — runs alone so its Nightwatch-visibility behavior can be verified in isolation (simulate a Redis outage, confirm `report()` fires).
