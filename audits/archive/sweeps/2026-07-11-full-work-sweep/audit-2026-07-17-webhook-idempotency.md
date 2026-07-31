# Inbound Callbacks & Idempotency Semantics Audit — 2026-07-17

**Branch:** audit/full-work-sweep-2026-07-17
**Lens:** Inbound callbacks & idempotency semantics — Supabase auth/email hooks, `bot.token`-gated internal endpoints, and the client-supplied `IdempotencyKey` middleware, measured against the Partna gold-standard callback pattern (HMAC-before-parse, persisted idempotency anchors, 200-only-on-success, no domain mutations outside a job).
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php`
- `app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php`
- `app/Http/Controllers/Api/Internal/EnvCheckController.php`
- `app/Http/Controllers/Api/Internal/CspReportController.php`
- `app/Http/Middleware/Auth/VerifySupabaseHookSignature.php`
- `app/Services/Webhooks/StandardWebhookVerifier.php`
- `app/Http/Middleware/IdempotencyKey.php`
- `app/Http/Middleware/VerifyBotToken.php`
- `app/Services/Auth/AuthFactorEventRepository.php`
- `app/Services/Notifications/SupabaseEmailEventService.php`
- `app/Services/User/AccountDeletionService.php`
- `app/Mail/BaseTransactionalMail.php`, `app/Mail/Auth/EmailConfirmMail.php`
- `routes/api.php`, `routes/api/user.php`, `bootstrap/app.php`
- `routes/api/platforms.php` (original scan scope — no callback surface present)

**Note on scope:** the DeepSeek scan for this chunk was configured with `--scope routes/api/platforms.php`, which contains none of this lens's target surface (no hook controllers, no `IdempotencyKey`/`VerifyBotToken`/`StandardWebhookVerifier`). That "no findings" draft is accurate for the scope it was given, but the scope itself was a pipeline misconfiguration — `routes/api/platforms.php` holds only authenticated dashboard integration routes. Per the adjudicator's mandate to read source against the lens and add missed findings, this audit instead directly reads the Group A–E files the lens actually targets (`SupabaseAuthHookController`, `SupabaseEmailHookController`, `VerifySupabaseHookSignature`, `StandardWebhookVerifier`, `IdempotencyKey`, `VerifyBotToken`, `routes/api.php`).

**Overall finding:** this surface is unusually well-hardened already — in-code annotations (`WHK-1`…`WHK-5`, `OBS-1`, `OBS-4`, `PRIV-1`, `LIFE-2`, `CCH-1`, `SCALE-1/2`) and matching Pest coverage (`SupabaseAuthHookBruteForceTest`, `SupabaseAuthHookSignatureTest`, `SupabaseEmailHookTest`, `IdempotencyKeyMiddlewareTest`, `VerifyBotTokenTest`) show this exact lens has already been through at least one hardening pass. HMAC-before-parse, `hash_equals`, timestamp tolerance, atomic `Cache::add` anchors, anchor-reversal-on-failure, 500-on-dispatch-failure, and stable Message-ID mail dedup are all correctly implemented and verified against source. Two narrower gaps survived review.

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **#WHK-1** · P2 — MFA auth-hook idempotency anchor is Redis-only; no DB-level uniqueness backstop on the audit trail
    - **Where:** `app/Services/Auth/AuthFactorEventRepository.php:30-56`, `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php:53-59`
    - **Affects:** `audit.auth_factor_events` rows and the MFA brute-force counter (`countRecentFailures`) for any user going through TOTP/phone/webauthn verification during a Redis blip.
    - **Effort:** M (~2-4h) — new nullable `webhook_id` column + unique index migration, thread the id through `record()`, switch the insert to `INSERT … ON CONFLICT (webhook_id) DO NOTHING`.
    - **What to do:**
        - Add a `webhook_id TEXT` column (nullable, unique partial index `WHERE webhook_id IS NOT NULL`) to `audit.auth_factor_events` in a new `supabase/migrations/` file.
        - Pass `$id` from the controller into `AuthFactorEventRepository::record()` and switch the insert to `DB::connection('pgsql')->table(...)->insertOrIgnore([...])` (or raw `INSERT … ON CONFLICT (webhook_id) DO NOTHING`) keyed on `webhook_id`.
        - Leave the existing `Cache::add` anchor as the fast-path gate (it's correct and atomic) — the DB constraint is a second line of defense for when Redis loses the key.
    - **Technical:** the only thing preventing a redelivered `webhook-id` from double-recording a factor event is `Cache::add("supabase:auth-hook:{$id}", ...)` with a 24h TTL (`config('partna.cache.ttls.webhook_idempotency')`, default 86400s). That anchor lives purely in Redis. If Redis restarts, fails over, or evicts the key under `maxmemory` pressure between the original delivery and a Supabase retry, `Cache::add` succeeds again on the retry and `AuthFactorEventRepository::record()` — which has no `webhook_id` column and no uniqueness constraint at all — inserts a second row for the same real-world event. For a `verify_failed`/`verify_rejected_by_hook` pair this inflates `countRecentFailures()` by one, which can flip a legitimate 4th failure into a false 5th and trigger a premature MFA lockout. The email hook (`SupabaseEmailEvent::updateOrCreate(['webhook_id' => $webhookId], ...)`) already has a DB-persisted anchor for its forensic trail; the auth hook's audit table has no equivalent column, so it can't backstop a lost cache key the way the email hook's trail table structurally could.
    - **Plain English:** every MFA verification attempt gets a receipt stamped with a delivery ID so we never count the same attempt twice. That receipt currently lives only in a fast, temporary scratchpad (Redis) — if that scratchpad gets wiped during routine server maintenance right as Supabase resends a message, we lose track and might count one real failed login attempt as two. In rare cases that could lock a legitimate user out of their account a login attempt early. The fix is to also write the delivery ID into the permanent record, so even if the scratchpad is wiped, we can still tell "already saw this one" from the permanent copy.
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
        ```
        ```php
        if (! Cache::add(
            "supabase:auth-hook:{$id}",
            true,
            (int) config('partna.cache.ttls.webhook_idempotency'),
        )) {
            return response()->json(['decision' => 'continue']);
        }
        ```

- [ ] **#WHK-2** · P2 — `IdempotencyKey` is opt-in on `/me/deletion/*`; the double-submit race it's meant to close has no server-side enforcement and no test for the header-omitted path
    - **Where:** `app/Http/Middleware/IdempotencyKey.php:44-47`, `routes/api/user.php:54-59`, `app/Services/User/AccountDeletionService.php:48-113` (`request()`)
    - **Affects:** `POST /api/me/deletion/request` — a browser double-tap, refresh, or client retry without the `Idempotency-Key` header sends two deletion-confirmation emails and writes two `UserDeletionAuditEntry` rows for one user action.
    - **Effort:** M (~2-4h) — add a `lockForUpdate`-style guard to `AccountDeletionService::request()` matching the pattern already used in `confirm()`, plus a test for the missing-header path.
    - **What to do:**
        - Give `AccountDeletionService::request()` the same re-read-under-`lockForUpdate` guard `confirm()` already uses (lines 211-215) so a concurrent double-submit is closed at the domain layer regardless of whether the client sent an idempotency key.
        - Add a Pest test exercising `POST /me/deletion/request` twice concurrently (or back-to-back with the header omitted) asserting only one `SendAccountDeletionRequestMailJob` dispatch and one audit row — mirroring the existing `#P2-43` coverage that only tests the key-present path.
    - **Technical:** the `idempotent` middleware is intentionally opt-in system-wide — `if (! is_string($key) || $key === '') { return $next($request); }` — which is correct for the general contract (client sends the header, server replays on retry). But the route comment on `me/deletion` frames the middleware as *the* fix for a specific double-submit race (`#P2-43`), and unlike `confirm()` (which independently re-reads the row under `lockForUpdate` and no-ops the losing concurrent caller — see the comment at `AccountDeletionService.php:211-214`), `request()` has no equivalent domain-layer guard: it unconditionally does `$professional->update([...])` + `SendAccountDeletionRequestMailJob::dispatch(...)` + `logAuditEvent(...)` inside a transaction, gated only by the controller's `status === 'pending_deletion'` check — which `request()` itself never flips (only `confirm()` does), so two concurrent `request()` calls both pass that check and both fire. If the `Idempotency-Key` header is ever omitted (frontend bug, non-browser client, a retry path that doesn't reuse the header), the race this middleware exists to close reopens silently with zero enforcement and zero test coverage of that scenario.
    - **Plain English:** clicking "delete my account" twice quickly (a slow network, a nervous double-click) is supposed to be safe because of a receipt-based dedup system — but that system only works if the app remembers to attach the receipt number to the request. Right now nothing on the server double-checks that the receipt was actually attached, and the underlying "delete" action itself has no independent safety check to fall back on. If the receipt ever goes missing for any reason, the user could get two deletion-confirmation emails and the compliance log gets a duplicate entry — confusing, not catastrophic, but avoidable with a proper backstop.
    - **Evidence:**
        ```php
                // Account Deletion — self-service lifecycle.
                // `idempotent` middleware closes the concurrent-double-submit race that
                // would otherwise let a browser refresh or mobile double-tap persist
                // duplicate audit rows and queue duplicate confirmation mails (#P2-43).
                // Frontend must send a per-action `Idempotency-Key: <uuid-v4>` header.
                Route::prefix('me/deletion')->middleware('idempotent')->group(function () {
        ```
        ```php
                $key = $request->header('Idempotency-Key');
                if (! is_string($key) || $key === '') {
                    return $next($request);
                }
        ```
        ```php
            DB::connection('pgsql')->transaction(function () use ($professional, $tokenHash, $confirmationUrl, $request) {
                $professional->update([
                    'deletion_token_hash' => $tokenHash,
                    'deletion_requested_at' => now(),
                    'deletion_mail_sent_at' => null,
                ]);

                SendAccountDeletionRequestMailJob::dispatch(
                    $professional->id,
                    $confirmationUrl,
                    $tokenHash,
                );

                $this->logAuditEvent($professional, UserDeletionAuditEntry::EVENT_REQUESTED, $request);
            });
        ```

## Suggested Bundled Sessions

None — the two surviving findings touch unrelated files and subsystems (auth-hook audit trail vs. account-deletion lifecycle) with no shared root cause.

## Standalone — do NOT bundle

- **#WHK-1 — MFA auth-hook idempotency anchor is Redis-only** · requires a Supabase migration (new column + unique index on `audit.auth_factor_events`).
- **#WHK-2 — `IdempotencyKey` opt-in on `/me/deletion/*`** · touches the account-deletion authorization/lifecycle flow (policy-gated, GDPR-adjacent audit trail) and warrants isolated plan + sign-off.
