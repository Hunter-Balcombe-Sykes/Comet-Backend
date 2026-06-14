
<!-- ═══ LENS: webhook-idempotency | CHUNK: callbacks ═══ -->

- [ ] **#WHK-1** · P0 — Auth hook silently flips rejection to acceptance when repo->record() fails, defeating Supabase's retry mechanism
    - **Where:** app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php — `mfaVerification()` method, `$this->repo->record(...)` calls on the rejection and failure paths
    - **Affects:** MFA brute-force protection — when `AuthFactorEventRepository::record()` throws (DB outage, constraint violation), the dedup anchor is not reverted, Supabase retries, hits the anchor, and returns `decision: continue` (200) instead of the correct `decision: reject`. The attacker gets a free pass on the retry.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `$this->repo->record()` calls in a try/catch that reverts the dedup anchor (`Cache::forget`) on failure and re-throws so the framework returns 500.
        - Alternatively, restructure so the dedup anchor is only persisted AFTER `repo->record()` succeeds — but that would open a narrower race; reverting on failure is simpler and matches the email hook's pattern.
    - **Technical:** The controller sets `Cache::add("supabase:auth-hook:{$id}", true, ttl)` as an atomic idempotency gate, then calls `$this->repo->record()` synchronously in the controller. No try/catch guards the repository call. If it throws, the exception propagates to the framework → 500, but the cache anchor remains. Supabase retries with the same `webhook-id` → `Cache::add` returns false → the controller returns `['decision' => 'continue']` (200). Supabase stops retrying. The MFA rejection (or failure record) is permanently lost. On the rejection path this flips a `reject` decision into `continue`, temporarily defeating brute-force protection. The email hook already does this correctly with `Cache::forget($dedupKey)` before returning 500.
    - **Plain English:** Imagine a bouncer at a club who stamps hands to track entry. If the bouncer faints while writing down a rejection in the logbook, their stamp is still on the person's hand. When the person comes back (the retry), the stamp says "already processed — go ahead." The person who should have been rejected walks right in. The fix: if the logbook write fails, wipe the stamp off so the bouncer can re-evaluate on the next attempt.
    - **Evidence:**
        ```php
        // Dedup anchor set (lines 63-70 in mfaVerification)
        if ($id !== '' && ! Cache::add(
            "supabase:auth-hook:{$id}",
            true,
            (int) config('partna.cache.ttls.webhook_idempotency'),
        )) {
            return response()->json(['decision' => 'continue']);
        }

        // … later, on the rejection path — no try/catch around repo->record():
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
                'message' => 'Too many failed verification attempts…',
            ]);
        }
        // Same pattern on the verify_failed path — no try/catch, no Cache::forget.
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#WHK-2** · P1 — Auth hook skips idempotency entirely when `webhook-id` header is empty or missing
    - **Where:** app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php — `mfaVerification()`, lines 60-70
    - **Affects:** Auth-factor event integrity — any delivery that arrives without a `webhook-id` header (misconfigured proxy stripping headers, future Supabase hook types, an attacker replaying a captured request) bypasses dedup entirely and is processed fresh each time.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Reject deliveries with a missing or empty `webhook-id` header (return 400) — the Standard Webhooks spec requires this header, and Supabase always sends it.
        - Alternatively, make the anchor unconditional: derive a fallback id from `hash('sha256', $rawBody)` when the header is missing, so dedup still works. Rejection is simpler and aligns with the spec.
    - **Technical:** The guard `if ($id !== '' && ! Cache::add(...))` makes the entire idempotency gate conditional on a non-empty `webhook-id`. A delivery with an empty header falls through to `$this->repo->record()` with no dedup check at all. Under Standard Webhooks, `webhook-id` is a required header — Supabase always includes it — so this is defense-in-depth, not a currently-exploitable gap under normal operation. But it leaves the door open: a future hook type that omits the header, a middleware that strips it, or an attacker replaying a captured request that was stripped of headers would all bypass dedup silently.
    - **Plain English:** The duplicate-detection system only activates if the incoming package has an ID sticker on it. Supabase always puts a sticker on, so in normal operation this works fine. But if a package ever arrives without one — due to a configuration mistake, a network appliance stripping headers, or a future change — the system processes it as brand-new every time. A bouncer who only checks IDs "if present" will let the same person through twice if they take their ID out of their wallet. The fix: refuse entry to anyone without an ID.
    - **Evidence:**
        ```php
        $id = (string) $request->header('webhook-id', '');

        // WEBHOOK-3: dedup retried hook deliveries.
        if ($id !== '' && ! Cache::add(
            "supabase:auth-hook:{$id}",
            true,
            (int) config('partna.cache.ttls.webhook_idempotency'),
        )) {
            return response()->json(['decision' => 'continue']);
        }
        // If $id === '', the entire dedup block is skipped — repo->record() runs unconditionally.
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#WHK-3** · P1 — Email hook skips idempotency entirely when `webhook-id` header is empty or missing
    - **Where:** app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php — `__invoke()`, lines 79-86
    - **Affects:** Auth email delivery — a retried or replayed delivery without a `webhook-id` header would queue a duplicate Mailable, sending the user the same auth email twice.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Reject deliveries with a missing or empty `webhook-id` header (return 400).
        - Mirrors WHK-2 — same fix, same reasoning.
    - **Technical:** Identical pattern to the auth hook: `$dedupKey` is only set and `Cache::add` only called when `$webhookId !== ''`. An empty header bypasses dedup. The email hook has the correct failure-reversal pattern (`Cache::forget` on exception → 500), so the risk is narrower — it only matters if the header is absent, not on a mid-processing failure. But duplicate auth emails (password reset, magic link, signup confirmation) degrade trust and can confuse users who think their account is being attacked.
    - **Plain English:** Same ID-sticker problem as the auth hook, but for email. If Supabase retries a "send password reset email" delivery and the ID sticker has fallen off, the user gets two reset emails. The second one doesn't work (the token is the same), but it looks like a phishing attempt and generates support tickets.
    - **Evidence:**
        ```php
        $dedupKey = null;
        if ($webhookId !== '') {
            $dedupKey = 'supabase:email_hook:seen:'.$webhookId;
            if (! Cache::add($dedupKey, 1, now()->addSeconds(300))) {
                Log::info('supabase.email_hook.duplicate', […]);
                return response()->json(['ok' => true, 'handled' => false, 'duplicate' => true]);
            }
        }
        // When $webhookId === '', $dedupKey stays null — Mail::queue() runs with no dedup guard.
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#WHK-4** · P2 — IdempotencyKey middleware fail-open is invisible to Nightwatch alerts
    - **Where:** app/Http/Middleware/IdempotencyKey.php — `logFailOpen()` method and all `catch (Throwable $e)` blocks
    - **Affects:** Operations visibility — when Redis is unreachable, every mutating request silently loses idempotency protection, but no alert fires. The team only discovers this when a user reports a duplicate side effect.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Escalate fail-open events to Nightwatch by throwing a dedicated exception (e.g. `IdempotencyDegradedException`) from a throttled reporter, or use `Log::error` (which Nightwatch DOES alert on) with a per-deploy dedup key so one Redis outage produces one alert, not a flood.
        - Document the fail-open posture explicitly in the class docblock so future maintainers know it's intentional.
    - **Technical:** The middleware catches `Throwable` on cache lookups, lock acquisition, and cache stores, logs via `Log::warning`, and proceeds without idempotency. The architecture doc states "A failure that needs attention must throw or `$this->fail($e)`; `Log::warning` alone is invisible." Nightwatch does not alert on warning-level logs — it only surfaces exceptions and errors. A sustained Redis outage would degrade every mutating endpoint silently, with the only evidence being breadcrumb log lines nobody is watching. The middleware's fail-open design is sound (503-ing all mutating requests during a Redis blip is worse than losing idempotency), but the degradation must be surfaced.
    - **Plain English:** This is like a hotel's key-card system that falls back to "doors unlock without a card" when the battery dies. That's the right call — guests shouldn't be locked out. But right now, the front desk doesn't get a notification that it's happening. The fix: wire up the "battery low" light to actually ring the front desk, not just blink in the maintenance closet.
    - **Evidence:**
        ```php
        // Three fail-open sites, all using Log::warning — invisible to Nightwatch:
        try {
            $cached = Cache::get($cacheKey);
        } catch (Throwable $e) {
            $this->logFailOpen($e, 'lookup');
            return $next($request);
        }

        // … and in logFailOpen():
        private function logFailOpen(Throwable $e, string $stage): void
        {
            Log::warning('Idempotency middleware failing open', [
                'stage' => $stage,
                'reason' => $e->getMessage(),
                'operation' => __METHOD__,
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#WHK-5** · P2 — VerifyBotToken fail-open and circuit-breaker-unavailable are invisible to Nightwatch alerts
    - **Where:** app/Http/Middleware/VerifyBotToken.php — `logFailOpenOnce()` and `logBreakerUnavailable()` methods
    - **Affects:** Bot protection observability — when the captcha provider is unreachable or the circuit breaker is open, every public write endpoint (subscribe, signup, lead, enquiry, waitlist, report) proceeds without bot verification. No alert fires; the team only discovers the gap if spam volumes spike.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Same pattern as WHK-4: replace `Log::warning` with a throttled `Log::error` or a dedicated reported exception so Nightwatch surfaces the degradation.
        - The Redis-based dedup (`Redis::incr` + `Redis::expire`) already prevents log flooding — one alert per cooldown window per driver is the right cadence.
    - **Technical:** `logFailOpenOnce` and `logBreakerUnavailable` use Redis-backed dedup to log once per cooldown window, then call `Log::warning`. The dedup is well-designed, but `Log::warning` does not trigger Nightwatch alerts per the architecture's observability contract. A captcha provider outage that lasts hours would log exactly one warning-level line and then go silent — every subsequent request would fail-open with zero visibility. The `fail_open` mode itself is correct (a captcha outage shouldn't block genuine users), but the circuit-open state must page the on-call engineer.
    - **Plain English:** Same "silent alarm" problem as the idempotency middleware, but for bot protection. When the captcha service goes down, the doors stay open — which is the right call for real users. But right now, nobody knows the captcha is down until the spam complaints roll in. The fix: make the "captcha unavailable" light actually notify someone.
    - **Evidence:**
        ```php
        private function logFailOpenOnce(string $driver, string $action, Request $request, string $reason): void
        {
            try {
                $key = "bot_protection:fail_open_logged:{$driver}:{$reason}";
                $count = Redis::incr($key);
                if ($count === 1) {
                    Redis::expire($key, (int) config('partna.bot_protection.circuit_breaker.cooldown_seconds', 300));
                    Log::warning('bot_protection.fail_open', [  // ← invisible to Nightwatch
                        'driver' => $driver, 'reason' => $reason, 'action' => $action,
                        'route' => $request->path(), …
                    ]);
                }
            } catch (Throwable $e) {
                // Silent — observability failure must not break the request.
            }
        }
        // logBreakerUnavailable follows the same pattern with Log::warning.
        ```
    - `[DRAFT, confidence: 0.9]`
