# Inbound Callbacks & Idempotency Semantics Audit — 2026-06-13

**Branch:** development
**Lens:** Inbound callbacks & idempotency semantics — auth/email hook correctness, `IdempotencyKey` middleware, bot-protection observability
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-5`
**Source files audited:**
- `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php`
- `app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php`
- `app/Http/Middleware/Auth/VerifySupabaseAuthHookSignature.php`
- `app/Http/Middleware/Auth/VerifySupabaseEmailHookSignature.php`
- `app/Services/Webhooks/StandardWebhookVerifier.php`
- `app/Services/Email/SupabaseEmailHookSignatureVerifier.php`
- `app/Http/Middleware/IdempotencyKey.php`
- `app/Http/Middleware/VerifyBotToken.php`
- `app/Http/Controllers/Api/Internal/EnvCheckController.php`
- `routes/api.php`

## Progress

- P0 Blockers: 0 of 1 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 0 complete

---

## P0 — Must fix before any real user touches the system

- [ ] **#WHK-1** · P0 — Auth hook dedup anchor not reverted on `repo->record()` failure; rejection silently flips to allow on Supabase's retry
    - **Where:** `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php` — `mfaVerification()`, all three `$this->repo->record()` call sites (lines 73, 92, 108)
    - **Affects:** MFA brute-force protection. When `AuthFactorEventRepository::record()` throws (DB outage, pool exhaustion, constraint violation), the dedup anchor is already in the cache. Supabase retries with the same `webhook-id`; `Cache::add` returns false; the controller returns `['decision' => 'continue']`. On the rejection path (`verify_rejected_by_hook`) this permanently converts a brute-force lockout into "allowed — please proceed." The `verify_success` and `verify_failed` paths lose their audit row but their `continue` responses are semantically correct, so only the rejection path is a security bypass.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap all three `$this->repo->record()` calls in a `try/catch (\Throwable $e)` block.
        - In the catch: call `Cache::forget("supabase:auth-hook:{$id}")` to revert the anchor, then either re-throw the exception (framework returns 500) or explicitly `return response()->json(['error' => 'Internal error'], 500)`.
        - Mirror the pattern already in `SupabaseEmailHookController` (`if ($dedupKey !== null) { Cache::forget($dedupKey); }` in the catch block, followed by a 500 response).
        - Add a test: mock `AuthFactorEventRepository::record()` to throw, assert the response is 500 and `Cache::has("supabase:auth-hook:{$id}")` is false.
    - **Technical:** The controller sets the idempotency anchor atomically via `Cache::add` *before* calling `$this->repo->record()`. If the repository throws, the exception propagates and Laravel returns 500 — correct for the first attempt. But the anchor remains in the cache. On Supabase's mandatory retry, `Cache::add` returns false (key exists) and the controller short-circuits at line 49 with `['decision' => 'continue']`, which Supabase interprets as "allow this authentication attempt." For the brute-force rejection path (`$recentFailures >= $maxFailures`), this inverts the intended `reject` response into an implicit `continue` for the retry, bypassing the lockout. The email hook controller already handles this correctly with `Cache::forget($dedupKey)` inside its catch block before returning 500 — the auth hook should be brought to parity.
    - **Plain English:** Imagine a nightclub bouncer who writes a name on the "banned" list before turning someone away, but the pen runs out of ink mid-sentence. The half-written entry stays on the list. When the banned person comes back (Supabase's retry), the bouncer sees the entry, assumes they already handled it, and waves them through. The fix: if the pen fails, cross out the half-written entry so the person gets properly reviewed next time.
    - **Evidence:**
        ```php
        // Dedup anchor set atomically before any repo work:
        if ($id !== '' && ! Cache::add(
            "supabase:auth-hook:{$id}",
            true,
            (int) config('partna.cache.ttls.webhook_idempotency'),
        )) {
            return response()->json(['decision' => 'continue']); // ← replay response
        }

        // … rejection path — no try/catch, no Cache::forget on failure:
        if ($recentFailures >= $maxFailures) {
            $this->repo->record(          // ← throws on DB outage; anchor remains
                userId: $userId,
                eventType: 'verify_rejected_by_hook',
                …
                metadata: ['recent_failures' => $recentFailures, 'window_seconds' => $windowSeconds],
            );

            return response()->json(['decision' => 'reject', …]);
        }

        // verify_failed path — same missing guard:
        $this->repo->record(              // ← throws on DB outage; anchor remains
            userId: $userId,
            eventType: 'verify_failed',
            …
        );

        // Contrast — email hook correctly reverts:
        // } catch (\Throwable $e) {
        //     if ($dedupKey !== null) { Cache::forget($dedupKey); }
        //     return $this->error('Failed to send email', 500);
        // }
        ```

---

## P2 — Should fix

- [ ] **#WHK-2** · P2 — Auth hook dedup guard is conditional on non-empty `webhook-id`; controller is not independently hardened against a missing header
    - **Where:** `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php` — `mfaVerification()`, line 44
    - **Affects:** Defense-in-depth for MFA event dedup. Under normal operation `StandardWebhookVerifier::verify()` returns false when `webhookId === ''` (the verifier explicitly checks `$webhookId === ''` before any HMAC computation), so the middleware returns 401 and the controller is never reached. The guard in the controller is therefore dead code today. The risk materialises if the signature-middleware logic ever changes, or if a future hook type bypasses the same middleware stack — at that point the controller silently skips all dedup and the repository is written unconditionally on every delivery.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the conditional dedup guard with an unconditional rejection: if `$id === ''`, return `response()->json(['message' => 'Missing webhook-id'], 400)` before any processing.
        - This makes each layer independently correct — the controller does not rely on the middleware having enforced the invariant.
    - **Technical:** `StandardWebhookVerifier::verify()` short-circuits and returns false when `webhookId === ''`, causing `VerifySupabaseAuthHookSignature` to return 401 before the controller executes. The `if ($id !== '' && ...)` guard in the controller is therefore only reachable with a non-empty id under current routing. However, making the controller independently safe costs one line and eliminates a latent class of bugs if the middleware stack is ever modified or reused with a different hook type.
    - **Plain English:** The front door of the venue already checks for an ID — but the coat-check inside the venue only bothers checking IDs "if the person has one." Since you can't get to the coat-check without passing the front door, nothing bad happens today. But if a side entrance is ever added, the coat-check's conditional logic becomes a gap. The fix is to make the coat-check check unconditionally, which costs nothing and makes it safe regardless of how someone arrived.
    - **Evidence:**
        ```php
        $id = (string) $request->header('webhook-id', '');

        // Guard is conditional — if $id is '', dedup is skipped entirely:
        if ($id !== '' && ! Cache::add(
            "supabase:auth-hook:{$id}",
            true,
            (int) config('partna.cache.ttls.webhook_idempotency'),
        )) {
            return response()->json(['decision' => 'continue']);
        }
        // If $id === '', Cache::add is never called; repo->record() runs unconditionally.

        // Gateway guarantee (VerifySupabaseAuthHookSignature + StandardWebhookVerifier):
        // if ($configuredSecret === '' || $webhookId === '' || ...) { return false; }
        // → middleware returns 401 before controller executes — but only under current stack.
        ```

- [ ] **#WHK-3** · P2 — Email hook dedup guard is conditional on non-empty `webhook-id`; same latent gap as WHK-2
    - **Where:** `app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php` — `__invoke()`, lines 72–83
    - **Affects:** Auth email dedup (password reset, magic link, signup confirm, invite). Same structural gap as WHK-2: `StandardWebhookVerifier::verify()` already rejects empty `webhook-id` in the middleware, so the controller is currently unreachable with an empty id. The `$webhookId !== ''` condition makes the dedup optional and leaves the controller dependent on middleware correctness for its safety guarantee.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - If `$webhookId === ''`, return 400 before any payload processing or dedup setup.
        - Mirrors the WHK-2 fix — one line, makes each layer independently correct.
        - Note: the email hook's existing failure-revert logic (`Cache::forget($dedupKey)` on exception) is already correct and must be preserved.
    - **Technical:** Identical gateway guarantee applies: `VerifySupabaseEmailHookSignature` calls `StandardWebhookVerifier::verify()`, which returns false on empty `webhookId`, causing a 401 before the controller runs. The `if ($webhookId !== '')` guard in the controller is dead code under the current middleware stack. The email hook's dedup TTL of 300 s is deliberately set to match `TIMESTAMP_TOLERANCE` — any replay outside this window would fail signature verification anyway, making the cache entry non-load-bearing. That design is sound; only the conditional guard needs hardening.
    - **Plain English:** Same "coat-check that only checks IDs when there is one" problem as WHK-2, for the system that prevents users from receiving duplicate password-reset and magic-link emails. The front door already guarantees an ID is present; the fix makes the coat-check independently guarantee it too, costing nothing.
    - **Evidence:**
        ```php
        $dedupKey = null;
        if ($webhookId !== '') {                          // ← dedup is optional
            $dedupKey = 'supabase:email_hook:seen:'.$webhookId;
            if (! Cache::add($dedupKey, 1, now()->addSeconds(300))) {
                Log::info('supabase.email_hook.duplicate', […]);
                return response()->json(['ok' => true, 'handled' => false, 'duplicate' => true]);
            }
        }
        // When $webhookId === '', $dedupKey stays null; Mail::queue() runs with no dedup guard.
        // Correct failure-revert path preserved:
        // } catch (\Throwable $e) {
        //     if ($dedupKey !== null) { Cache::forget($dedupKey); }
        //     return $this->error('Failed to send email', 500);
        // }
        ```

- [ ] **#WHK-4** · P2 — `IdempotencyKey` middleware fail-open is invisible to Nightwatch; sustained Redis degradation silently removes idempotency from all mutating endpoints
    - **Where:** `app/Http/Middleware/IdempotencyKey.php` — `logFailOpen()` method and all three `catch (Throwable $e)` blocks (cache lookup, lock acquisition, cache store)
    - **Affects:** Operations visibility. When Redis is unreachable, every mutating endpoint silently loses its replay-safe guarantee. The only evidence is `Log::warning` lines — which are breadcrumbs, not alerts. Nightwatch does not trigger on `warning`-level logs; it only surfaces exceptions and detected slow jobs/routes. A multi-hour Redis outage would produce one warning line at onset and then silence.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `logFailOpen()` to use `Log::error` (which Nightwatch surfaces) instead of `Log::warning`, OR wrap the first fail-open event per deploy in a reported exception via a throttled reporter class.
        - Add a per-deploy Redis dedup key (similar to `VerifyBotToken::logFailOpenOnce`) so one Redis outage produces one alert, not a flood.
        - Document the fail-open posture explicitly in the class docblock: "Fail-open is intentional — idempotency degrades gracefully rather than 503-ing all mutating requests during a Redis blip. Alert fires on degradation onset."
    - **Technical:** The architecture contract is explicit: "A failure that needs attention must throw or `$this->fail($e)`; bare `Log::warning` is invisible." The fail-open design itself is correct — 503-ing every mutating request during a Redis blip is worse than losing idempotency for that window. But the degradation onset must be surfaced to Nightwatch so the team can respond before users report duplicate side effects.
    - **Plain English:** The hotel's key-card system correctly falls back to "doors unlock without a card" when the network goes down — real guests shouldn't be locked out. But right now, there's no notification that it's happening. The front desk only finds out when a guest complains about a stranger in their room. The fix: make the "key-card network offline" indicator actually ring the front desk, not just blink in the maintenance closet.
    - **Evidence:**
        ```php
        // Cache lookup fail-open (same pattern at lock-acquire and cache-store):
        try {
            $cached = Cache::get($cacheKey);
        } catch (Throwable $e) {
            $this->logFailOpen($e, 'lookup');  // ← Log::warning, invisible to Nightwatch
            return $next($request);
        }

        private function logFailOpen(Throwable $e, string $stage): void
        {
            Log::warning('Idempotency middleware failing open', [  // ← never triggers alert
                'stage' => $stage,
                'reason' => $e->getMessage(),
                'operation' => __METHOD__,
            ]);
        }
        ```

- [ ] **#WHK-5** · P2 — `VerifyBotToken` circuit-open and provider-unreachable states use `Log::warning`; bot-protection degradation is invisible to Nightwatch
    - **Where:** `app/Http/Middleware/VerifyBotToken.php` — `logFailOpenOnce()` (line 187), `logBreakerUnavailable()` (line 211), and the direct `Log::warning` call in the `CaptchaProviderException` catch block (line 68)
    - **Affects:** Bot-protection observability. When the captcha provider is unreachable, the circuit breaker is open, or the Redis-backed breaker is unavailable, every public write endpoint (subscribe, signup, lead, enquiry, waitlist, auth identifier) proceeds without bot verification. No Nightwatch alert fires. The Redis-backed dedup in `logFailOpenOnce` / `logBreakerUnavailable` is well-designed (one log line per cooldown window), but the log level is `warning` — invisible under the Nightwatch alert contract.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `Log::warning` to `Log::error` in `logFailOpenOnce()`, `logBreakerUnavailable()`, and the `CaptchaProviderException` catch block. The existing Redis-backed dedup already rate-limits the frequency — one `Log::error` per cooldown window is exactly the right cadence for an on-call alert.
        - The `CaptchaProviderException` path (line 68) uses a direct `Log::warning` without the Redis dedup — if it fires repeatedly during a sustained provider outage it would flood at `error` level. Add the same dedup here, or rely on Nightwatch's own dedup to group repeated identical events.
    - **Technical:** Identical observability gap to WHK-4. `Log::warning` is explicitly breadcrumb-only per the Nightwatch alert model; `Log::error` (or an unhandled exception) triggers alerts. The fail-open design posture is correct — real users must not be blocked because a captcha service is down. The fix only changes the log level, not the request-pass-through behavior.
    - **Plain English:** Same silent-alarm problem as the idempotency middleware, but for the bot-detection system. When the captcha service goes dark, the venue's ID scanner gracefully waves everyone through — which is the right call. But nobody on the security team gets a notification that the scanner is offline. By the time spam complaints roll in, hours may have passed. The fix: change "blink the maintenance light" to "call the on-call phone."
    - **Evidence:**
        ```php
        // Direct Log::warning in CaptchaProviderException catch (no Redis dedup):
        } catch (CaptchaProviderException $e) {
            $this->safelyRecord(fn () => $this->breaker->recordFailure($driver));
            Log::warning('bot_protection.fail_open', [          // ← invisible to Nightwatch
                'driver' => $driver, 'reason' => 'provider_error', …
            ]);
            return $this->failOpenOrReject($failOpen, $mode, $next, $request);
        }

        // Redis-deduped path — same Log::warning level:
        private function logFailOpenOnce(…): void
        {
            try {
                $key = "bot_protection:fail_open_logged:{$driver}:{$reason}";
                $count = Redis::incr($key);
                if ($count === 1) {
                    Redis::expire($key, (int) config('partna.bot_protection.circuit_breaker.cooldown_seconds', 300));
                    Log::warning('bot_protection.fail_open', [  // ← invisible to Nightwatch
                        'driver' => $driver, 'reason' => $reason, …
                    ]);
                }
            } catch (Throwable $e) { /* silent */ }
        }
        // logBreakerUnavailable() follows the identical pattern.
        ```

