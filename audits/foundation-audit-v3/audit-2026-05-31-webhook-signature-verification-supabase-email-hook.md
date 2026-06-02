`★ Insight ─────────────────────────────────────`
**Architecture note worth flagging before adjudicating WEBHK-1:** The `SupabaseAuthHookController` does call `verifySignature()` as its literal first line — DeepSeek's P0 hypothesis was correct about "it might check internally" and the check IS present and fail-closed. This is the difference between "unsecured endpoint" (P0) and "secured but architecturally inconsistent" (P2). Verification at the controller level is fragile for future growth; middleware makes the gate visible and automatic for any new action methods added to the same controller. Both patterns secure the current request flow; only one secures the inevitable next request flow.
`─────────────────────────────────────────────────`

# Webhook & Auth-Hook Security Audit — 2026-05-31

**Branch:** development
**Lens:** Webhook signature verification, Supabase email-hook auth, third-party webhook replay risk, internal-API auth weakness, moderation webhook auth
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `routes/api.php`
- `app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php`
- `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php`
- `app/Http/Middleware/Auth/VerifySupabaseEmailHookSignature.php`
- `app/Services/Auth/SupabaseAuthHookService.php`
- `app/Services/Email/SupabaseEmailHookSignatureVerifier.php`
- `app/Services/Auth/AuthFactorEventRepository.php`
- `app/Services/Auth/SupabaseAdminService.php`
- `app/Services/Auth/TokenRevocationService.php`
- `app/Http/Controllers/Api/Internal/EnvCheckController.php`
- `app/Http/Controllers/Api/Internal/CspReportController.php`
- `app/Http/Middleware/Auth/VerifySupabaseJwt.php`
- `routes/api/user.php`, `routes/api/staff.php`, `routes/api/publicSite.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **#WEBHK-1** · P2 — Auth hook signature verification lives in the controller, not middleware
    - **Where:** `routes/api.php:24-27` + `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php:42-44`
    - **Affects:** Architectural durability of the MFA brute-force protection hook. Any future developer adding a second action method to `SupabaseAuthHookController` won't automatically get signature verification — unlike the email hook which enforces it at the route level and can't be bypassed by new methods.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Create a `VerifySupabaseAuthHookSignature` middleware (or register `supabase.auth-hook` alias) that mirrors `VerifySupabaseEmailHookSignature`: extracts headers, calls `SupabaseAuthHookService::verifySignature()`, returns `503` when the secret is unconfigured and `401` (with logging — see WEBHK-2) on mismatch.
        - Attach `->middleware('supabase.auth-hook')` to the MFA verification route in `routes/api.php`.
        - Remove the inline `$this->hookService->verifySignature()` call from the controller method body (it becomes redundant once middleware enforces it).
    - **Technical:** `SupabaseAuthHookController::mfaVerification()` does call `verifySignature()` as its first statement (line 42) and the verification is fail-closed — misconfigurations return `false`, not `true`. So the _current_ route is secure. The problem is architectural: the email hook gate is on the _route_ (enforced by `supabase.email-hook` middleware before dispatch), while the auth hook gate is on the _method body_ (only enforced because the one developer who wrote it remembered to add it). If a second action method is ever added to this controller — `enrollmentAuditHook()`, for example — it inherits no verification. Middleware makes the gate load-bearing at the framework level rather than dependent on developer memory.
    - **Plain English:** Think of signature verification as the ID check at the door of a club. The email hook has a bouncer standing permanently at the door — anyone who tries to get in without the right ID gets turned away before they even reach the bar. The auth hook's ID check currently happens at the bar itself: today's bartender knows to ask, but if a second bartender starts work tomorrow, there's no bouncer to remind them. Moving the check to the door makes it impossible to forget.
    - **Evidence:**
        ```php
        // routes/api.php — email hook has middleware gate; auth hook does not
        Route::post('/internal/email-hooks/supabase', SupabaseEmailHookController::class)
            ->middleware('supabase.email-hook');          // ← enforced at route level

        Route::post(
            '/webhooks/supabase/auth/mfa-verification',
            [SupabaseAuthHookController::class, 'mfaVerification'],
        )->name('webhooks.supabase.auth.mfa-verification');  // ← no middleware

        // SupabaseAuthHookController.php:42-44 — verification in method body
        if (! $this->hookService->verifySignature($id, $timestamp, $signature, $rawBody)) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }
        ```

- [ ] **#WEBHK-2** · P2 — Auth hook signature failures produce no log output
    - **Where:** `app/Services/Auth/SupabaseAuthHookService.php:24-44` + `app/Http/Controllers/Api/Webhooks/SupabaseAuthHookController.php:42-44`
    - **Affects:** Operations visibility during a misconfiguration or active probe. When `verifySignature()` returns `false`, the controller returns `401` with no log record. There is no way to distinguish "secret was never set," "clock skew exceeded tolerance," and "attacker is replaying captured headers."
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - If WEBHK-1 is fixed first (middleware path), add structured logging to the new `VerifySupabaseAuthHookSignature` middleware — same pattern as `VerifySupabaseEmailHookSignature`: `Log::warning('supabase.auth_hook.misconfigured', ...)` when secret is blank (return 503), `Log::warning('supabase.auth_hook.signature_failed', ['webhook_id' => ..., 'webhook_timestamp' => ...])` on mismatch (return 401).
        - If fixing standalone (without WEBHK-1): add equivalent `Log::warning()` calls directly in `SupabaseAuthHookController::mfaVerification()` before the 401 return, and a separate `Log::warning` in `verifySignature()` when the secret is empty. Include `webhook_id` and `webhook_timestamp` as context keys — these are the correlation identifiers Supabase support will request in any incident.
        - Add a `Log::warning('supabase.auth_hook.misconfigured', ['reason' => 'secret_missing'])` path to `SupabaseAuthHookService::verifySignature()` for the empty-secret case, mirroring the 503 + log behavior of the email hook middleware.
    - **Technical:** `VerifySupabaseEmailHookSignature` logs `supabase.email_hook.misconfigured` on empty secret (returning 503 so an unconfigured deploy is immediately visible) and `supabase.email_hook.signature_failed` with `webhook_id` and `webhook_timestamp` on mismatch. `SupabaseAuthHookService::verifySignature()` returns `false` from three distinct branches — missing secret, timestamp out of tolerance, and signature mismatch — with no log on any of them, and the controller adds no log before its `401` response. In a production incident where the hook starts silently failing (e.g. after a secret rotation that only updated one of the two Supabase hook entries), the only observable signal is a 401 in the access log. No Nightwatch breadcrumb, no `webhook_id` for correlation, no way to distinguish misconfiguration from attack.
    - **Plain English:** When someone hands you a fake ID at the door, you want the doorman to write it in the incident log — when it happened, what the ID looked like, and where the person was standing. Right now the auth hook door just silently turns people away with no record. The email hook door keeps a detailed log. During an incident (like someone trying to guess their way past MFA, or a config error after a secret rotation), that missing log is the difference between a 5-minute fix and a multi-hour investigation.
    - **Evidence:**
        ```php
        // SupabaseAuthHookService.php — all three failure branches silent
        $secret = (string) config('supabase.auth_hook_secret');
        if ($secret === '') {
            return false;  // no log — compare email hook: Log::warning('supabase.email_hook.misconfigured')
        }

        $ts = (int) $timestamp;
        if ($ts <= 0 || abs(time() - $ts) > self::TIMESTAMP_TOLERANCE_SECONDS) {
            return false;  // no log — can't distinguish clock-skew from stale replay
        }

        // ... HMAC comparison ...
        return false;  // no log — can't distinguish wrong secret from tampered payload

        // VerifySupabaseEmailHookSignature.php — contrast: structured log before 401
        Log::warning('supabase.email_hook.signature_failed', [
            'webhook_id'        => $webhookId,
            'webhook_timestamp' => $webhookTimestamp,
        ]);
        ```

---

## P3 — Nice to have

- [ ] **#WEBHK-3** · P3 — Two Standard Webhooks implementations diverge in edge-case handling and secret format support
    - **Where:** `app/Services/Email/SupabaseEmailHookSignatureVerifier.php:36-56` vs `app/Services/Auth/SupabaseAuthHookService.php:24-52`
    - **Affects:** Maintainability and long-term correctness. A security patch to one implementation (e.g. improved timestamp parsing, a new secret format Supabase introduces) must be manually replicated in the other.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract a single `StandardWebhookVerifier` service (or promote `SupabaseEmailHookSignatureVerifier` to a shared class) with unified secret-format handling, empty-header rejection, and timestamp validation.
        - Update `VerifySupabaseEmailHookSignature` and `SupabaseAuthHookService` (or its future middleware replacement per WEBHK-1) to both delegate to the shared verifier.
        - Keep per-hook config keys separate — they already are (`services.supabase.email_hook_secret` vs `supabase.auth_hook_secret`).
    - **Technical:** The two implementations share the same Standard Webhooks spec (HMAC-SHA256, `{id}.{timestamp}.{body}`, `v1,`-prefixed signatures) but differ in three edge cases: (1) `SupabaseEmailHookSignatureVerifier` rejects empty `$webhookId`, `$webhookTimestamp`, and `$webhookSignatureHeader` at entry; the auth hook service does not, permitting an empty `$id` to flow silently into the signed-content string. (2) The email verifier validates `$webhookTimestamp` with `ctype_digit()`; the auth hook casts to `(int)` and checks `<= 0` — different failure modes for a malformed timestamp string. (3) Most materially, `SupabaseEmailHookSignatureVerifier` includes a `decodeSecret()` method that handles Supabase's `v1,whsec_<base64>` secret format (stripping the prefix and base64-decoding to raw bytes before the HMAC); the auth hook service uses the config value verbatim as the HMAC key. If `SUPABASE_AUTH_HOOK_SECRET` is ever set in `v1,whsec_<base64>` format, the HMAC key would be the encoded string rather than the decoded bytes and all legitimate Supabase deliveries would fail signature verification silently.
    - **Plain English:** You have two identical padlocks on two different doors, made to the same blueprint but assembled by different contractors. They both work today. But when a security recall comes out for that padlock model, you have to track down both installations and fix them separately — and if one contractor used a slightly different key-cutting technique, the recall fix might not transfer cleanly. Better to have one padlock manufacturer so one fix covers both doors.
    - **Evidence:**
        ```php
        // SupabaseEmailHookSignatureVerifier.php — entry guard rejects empty headers;
        // decodes v1,whsec_<base64> secret before HMAC
        public function verify(...): bool {
            if ($configuredSecret === '' || $webhookId === '' || $webhookTimestamp === '' || $webhookSignatureHeader === '') {
                return false;  // explicit empty-header rejection
            }
            // ...
            $secretBytes = $this->decodeSecret($configuredSecret);  // handles v1,whsec_<base64>
            $expectedSignature = base64_encode(hash_hmac('sha256', $signedPayload, $secretBytes, true));
        }

        // SupabaseAuthHookService.php — no empty-header guard; uses secret verbatim
        public function verifySignature(string $id, string $timestamp, ...): bool {
            $secret = (string) config('supabase.auth_hook_secret');
            // $id may be '' — silently included in signed content as ".{timestamp}.{body}"
            $signedContent = "{$id}.{$timestamp}.{$rawBody}";
            $expected = base64_encode(hash_hmac('sha256', $signedContent, $secret, true));
            // $secret used as-is — no v1,whsec_ decoding
        }
        ```
