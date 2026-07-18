# Inbound Callbacks & Idempotency Semantics Audit — 2026-07-08

**Branch:** development
**Lens:** Inbound callbacks & idempotency semantics — Supabase auth/email hooks, `bot.token`-gated internal endpoints, and the client-supplied `IdempotencyKey` middleware against the Partna gold-standard callback pattern (HMAC-before-parse, persisted idempotency anchor, 200-only-on-success, no domain mutation outside the job).
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php
- app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php
- app/Http/Middleware/Auth/VerifySupabaseHookSignature.php
- app/Services/Webhooks/StandardWebhookVerifier.php
- app/Services/Auth/AuthFactorEventRepository.php
- app/Services/Notifications/SupabaseEmailEventService.php
- app/Http/Middleware/IdempotencyKey.php
- app/Http/Middleware/VerifyBotToken.php
- app/Http/Controllers/Api/Internal/EnvCheckController.php
- app/Http/Controllers/Api/Internal/CspReportController.php
- routes/api.php, bootstrap/app.php
- supabase/migrations/20260526000000_baseline_standalone_user.sql
- supabase/migrations/20260625000000_create_supabase_email_events.sql

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **#WHK-1** · P2 — Auth-hook `AuthFactorEventRepository::record()` has no durable idempotency anchor, unlike the email hook's already-shipped pattern
    - **Where:** app/Services/Auth/AuthFactorEventRepository.php:30-56; app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php:53-59
    - **Affects:** `core.auth_factor_events` audit trail — a Redis eviction/restart during the retry window can produce a duplicate row, inflating the brute-force failure counter used by `countRecentFailures()`.
    - **Effort:** M (~2–4h) — includes a Supabase migration, so this is a schema change (see Standalone below).
    - **What to do:**
        - Add a `webhook_id text` column (nullable, for historical rows) plus `CONSTRAINT auth_factor_events_webhook_id_unique UNIQUE (webhook_id)` to `core.auth_factor_events` in a new `supabase/migrations/` file.
        - Thread `webhookId` through `SupabaseAuthHookController::mfaVerification()` into `AuthFactorEventRepository::record()`.
        - Use `DB::connection('pgsql')->table(...)->insertOrIgnore(...)` (or raw `INSERT … ON CONFLICT (webhook_id) DO NOTHING`) instead of a plain `insert()`.
        - Mirror the precedent already shipped for the sibling hook: `supabase/migrations/20260625000000_create_supabase_email_events.sql` adds `UNIQUE(webhook_id)` on `core.supabase_email_events` specifically as "a durable backstop to the 300s Redis cache" — the same reasoning applies here.
    - **Technical:** The `Cache::add("supabase:auth-hook:{$id}", ...)` dedup anchor is atomic and correct, and its TTL (`partna.cache.ttls.webhook_idempotency`, default 86400s) vastly exceeds the only window in which a legitimate retry can reach the controller at all — `StandardWebhookVerifier::TIMESTAMP_TOLERANCE` (300s) rejects any delivery whose `webhook-timestamp` is more than 5 minutes stale, so the practical replay window is bounded well inside the cache TTL. The real (narrower) gap is durability: if the Redis key backing the anchor is evicted or Redis restarts within that 300s window, a genuine retry re-acquires the anchor and `record()` inserts a second row with no unique constraint to catch it. The codebase already established the fix for this exact class of problem on the email hook (`core.supabase_email_events` with `UNIQUE(webhook_id)`, upserted via `SupabaseEmailEvent::updateOrCreate()`); the auth hook's `core.auth_factor_events` table was never given the same backstop. Because the failure mode requires both an in-window retry *and* a concurrent cache-layer outage, this is hardening rather than a scenario that fires today — hence P2, not P1.
    - **Plain English:** There are two doors that record MFA login attempts, and both use a "we already saw this" stamp to avoid double-counting. One of them (the email side) also keeps a permanent ledger as a backup in case the stamp gets lost. The other (the login-attempt side) only has the stamp — if it gets wiped out at exactly the wrong moment, a retried login check could get logged twice, unfairly nudging someone closer to being locked out. The fix is to give the login-attempt side the same permanent-ledger backup the email side already has.
    - **Evidence:**
        ```php
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
                ...
        ```

- [ ] **#WHK-2** · P2 — MFA auth-hook dedup fast-path always replays `{decision: continue}`, discarding a lost `reject`
    - **Where:** app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php:53-59
    - **Affects:** MFA brute-force protection — an attacker at the failed-attempt threshold whose `reject` response is lost in transit gets one extra un-counted evaluation on Supabase's retry.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Cache the original outcome alongside the anchor, e.g. `Cache::put("supabase:auth-hook:{$id}", ['decision' => $decision, 'message' => $message ?? null], $ttl)` instead of a bare `true`.
        - On the dedup-hit branch, read the cached payload back and return the recorded `decision`/`message` instead of the hardcoded `continue`.
        - Keep the cached payload small (decision + message only) to stay within the existing Redis memory budget for this key.
    - **Technical:** `Cache::add` correctly gates the fast path atomically, but on a cache hit (line 58) the controller unconditionally returns `{'decision': 'continue'}` regardless of what the first delivery actually decided. If the first delivery computed `reject` (brute-force threshold hit) and the response never reached Supabase (network drop after our 200), Supabase's mandatory retry hits the dedup gate and gets `continue` — one extra permissive evaluation beyond the configured threshold. The failure counter itself (`auth_factor_events` rows) is unaffected since it was written on the first delivery, so this is bounded to exactly one retry per threshold crossing, not an open bypass — hence P2 rather than P1/P0.
    - **Plain English:** The system stamps each login-security check so a repeat delivery isn't processed twice. But right now the stamp doesn't remember what the original answer was — it always replays "go ahead," even if the original answer was "stop, you've tried too many times." If the network drops the original "stop" message at exactly the wrong instant, the retry lets one extra attempt through. The fix is to have the stamp remember and replay the actual answer, not a fixed "go ahead."
    - **Evidence:**
        ```php
        if (! Cache::add(
            "supabase:auth-hook:{$id}",
            true,
            (int) config('partna.cache.ttls.webhook_idempotency'),
        )) {
            return response()->json(['decision' => 'continue']);
        }
        ```

- [ ] **#WHK-3** · P2 — `VerifyBotToken`'s Redis-outage fallback silently drops both the log breadcrumb and the Nightwatch report, instead of throttling them
    - **Where:** app/Http/Middleware/VerifyBotToken.php:223-238, 251-265
    - **Affects:** On-call visibility during a bot-protection Redis outage — exactly when captcha enforcement is failing open (public signup/enquiry/lead/waitlist endpoints become wide open to bots), no signal reaches the team.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a non-Redis-dependent fallback throttle (e.g. a per-process static counter) inside `firstHitInWindow`'s `catch` block so a Redis outage doesn't disable the throttle mechanism that the alerting itself depends on.
        - Ensure the fallback still allows at least one `Log::warning` + `report()` per process/deploy for `breaker_unavailable`, rather than the current unconditional suppression.
        - Update the docblock on `firstHitInWindow` to state explicitly what happens to observability when Redis itself is down.
    - **Technical:** `firstHitInWindow` wraps its `Redis::eval` in try/catch and returns `false` on any `Throwable`. Its only caller, `throttledFailReport`, is gated `if (! $this->firstHitInWindow($dedupKey)) { return; }` — i.e. a `false` return causes an **early return before `Log::warning` or `report()` ever run**. Both the `circuit_open` and `breaker_unavailable` branches route through `throttledFailReport`. During a genuine Redis outage, `$this->breaker->isOpen($driver)` itself throws (landing in the `breaker_unavailable` catch), and the `firstHitInWindow` call inside `throttledFailReport` fails for the same reason — so the *entire* observability path (log breadcrumb and Nightwatch page) goes silent for the duration of the outage, precisely when bot protection is failing open and needs visibility most. This is the inverse of an alert flood: it's a total blackout. (The sibling `CaptchaProviderException` branch avoids this — it logs unconditionally and only throttles `report()` — so the fix is to bring `breaker_unavailable`/`circuit_open` in line with that existing pattern plus a Redis-independent throttle fallback.)
    - **Plain English:** There's a rule that says "don't spam the on-call pager — only page once every few minutes for the same problem." That rule itself is stored in the same system (Redis) that's failing. So when Redis goes down, the "don't spam" rule can't be checked — and the code's answer to "I can't check" is "then don't page at all," for as long as the outage lasts. That's backwards: exactly when something needs attention most (the security check is silently letting bots through), nobody gets told. The fix is to keep a simple backup counter that doesn't depend on the failing system, so at least one page still goes out.
    - **Evidence:**
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

- [ ] **#WHK-4** · P2 — Webhook signature-failure and misconfiguration events are logged at `warning` only, invisible to Nightwatch alerting
    - **Where:** app/Http/Middleware/Auth/VerifySupabaseHookSignature.php:35-43, 58-68
    - **Affects:** Security operations — a sustained signature-forgery attempt, or a missing/rotated Supabase secret that silently drops every hook delivery (auth-factor events and auth emails both stop working), produces only searchable log lines with zero Nightwatch alert.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a throttled `report()` alongside the existing `Log::warning` on both the `signature_failed` and `misconfigured` branches, reusing the `firstHitInWindow`-style dedup pattern already established in `VerifyBotToken` and `IdempotencyKey::logFailOpen`.
        - Throttle to one `report()` per cooldown window (e.g. 300s) so a burst of forged-signature attempts doesn't flood Nightwatch.
        - Prioritize the `misconfigured` (503) branch — a missing secret in production means 100% of hook deliveries silently fail, which is the more severe of the two.
    - **Technical:** Partna's observability doctrine is that Nightwatch alerts only on exceptions/`report()`, never on `Log::warning` alone. Both failure branches in `VerifySupabaseHookSignature::handle()` — `signature_failed` (401) and `misconfigured` (503, missing secret) — call `Log::warning` and return a response without ever calling `report()` or throwing. A sustained forgery attempt or a deploy that drops the Supabase hook secret both produce a stream of failed responses that are fully correct HTTP-wise but generate zero pages. `VerifyBotToken::throttledFailReport` and `IdempotencyKey::logFailOpen` already establish the throttled-`report()` pattern elsewhere in this same middleware layer; this middleware was not brought in line with it.
    - **Plain English:** When someone tries to forge a webhook signature — or when a deploy accidentally loses the shared secret and every legitimate delivery starts failing — the system correctly slams the door, but only whispers about it in a log file nobody is actively watching. If this happens overnight, the team finds out hours later by searching logs instead of getting paged. The fix is to also ring the alarm, but only once every few minutes so a burst of attempts doesn't become a flood of pages.
    - **Evidence:**
        ```php
        $secret = (string) config($configKey, '');
        if ($secret === '') {
            Log::warning("{$logPrefix}.misconfigured", ['reason' => 'secret_missing']);

            return response()->json([
                'error' => 'hook_not_configured',
                'message' => "{$label} hook is not configured.",
            ], 503);
        }
        ```
        ```php
        if (! $valid) {
            Log::warning("{$logPrefix}.signature_failed", [
                'webhook_id' => $webhookId,
                'webhook_timestamp' => $webhookTimestamp,
            ]);

            return response()->json([
                'error' => 'invalid_signature',
                'message' => 'Invalid webhook signature.',
            ], 401);
        }
        ```

## P3 — Nice to have

- [ ] **#WHK-5** · P3 — Email-hook dedup TTL is hardcoded at 300s instead of reading the same config key as the auth hook
    - **Where:** app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php:81-82
    - **Affects:** Operations — tuning the webhook dedup window requires a code change and deploy on the email hook, while the auth hook picks it up from config alone.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `now()->addSeconds(300)` with `now()->addSeconds((int) config('partna.cache.ttls.webhook_idempotency', 300))`.
        - Confirm the config default (currently 86400s) doesn't silently change email-hook dedup behavior — either keep the literal 300s as a per-call override or accept the longer TTL (harmless per the WHK-1 analysis above, since it's bounded by the 300s signature-timestamp tolerance in practice regardless).
    - **Technical:** The auth hook reads its dedup TTL from `config('partna.cache.ttls.webhook_idempotency')`; the email hook hardcodes `now()->addSeconds(300)`. The 300s value happens to match `StandardWebhookVerifier::TIMESTAMP_TOLERANCE`, which is the real ceiling on any legitimate replay regardless of TTL — so this is a hygiene/consistency gap, not a functional bug. An operator who adjusts the config key expecting to affect both hook surfaces will only affect the auth hook.
    - **Plain English:** Two similar doors use the same kind of "already handled" stamp, but one door's timer is controlled by a dial on the wall (a config setting) and the other is welded at exactly 5 minutes. If someone wants to retune the timing, turning the dial only changes one door. Nothing breaks today because of it, but it's a trap waiting for the next person who touches this.
    - **Evidence:**
        ```php
        $dedupKey = 'supabase:email_hook:seen:'.$webhookId;
        if (! Cache::add($dedupKey, 1, now()->addSeconds(300))) {
        ```

## Suggested Bundled Sessions

- **Bundle 1 — Webhook dedup-anchor behavior fixes:** #WHK-2, #WHK-5
    - **Why grouped:** Both touch the two Supabase hook controllers' `Cache::add`-based dedup anchors (decision fidelity on the auth side, TTL config-consistency on the email side) — same subsystem, same session can cover both files.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

- **Bundle 2 — Inbound-callback observability (throttled Nightwatch coverage):** #WHK-3, #WHK-4
    - **Why grouped:** Both add the same throttled-`report()` pattern (already established in `IdempotencyKey::logFailOpen`) to a middleware that currently only `Log::warning`s on a failure path — one in `VerifyBotToken`, one in `VerifySupabaseHookSignature`. Same fix shape, same review checklist.
    - **Model:** Plan: Opus · Implement: Sonnet · Review: Sonnet.

## Standalone — do NOT bundle

- **#WHK-1 — Auth-hook DB-level idempotency anchor** · standalone: requires a `supabase/migrations/` schema change (new column + unique constraint on `core.auth_factor_events`) plus a repository method signature change — DB migrations always run alone with their own plan + sign-off.
