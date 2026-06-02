I now have what I need. Let me compile the findings:

- **BOOT-1**: `bootstrap/cache/.gitignore` contains `*` — all files there are gitignored. Standard Laravel deploy pattern. Confidence 0.65 and not a real production risk. **Drop.**
- **MAIL-1**: `SendAccountDeletionRequestMailJob` uses `Str::random(64)` token with SHA-256 hash + 24h expiry + timing-safe comparison. `cancelUrl` is a navigation-only link, auth required to act. Secure implementation. Confidence 0.55. **Drop.**
- **SING-1**: `InAppEnquiryNotificationAdapter` is fully stateless — only a `readonly NotificationPublisher` dependency, all per-dispatch data passed as method parameters. No request-scoped mutable state possible. Confidence 0.50. **Drop.**
- **EXC-1**: Evidence verified verbatim. The `else` branch in the renderer catches any `HttpException` that doesn't match the specific 404/423/403 branches and suppresses the message in production. P2 tier correct.
- **BOOT-2**: Evidence verified verbatim. Five production boot guards exist; no `APP_DEBUG` guard. P3 tier correct.

`★ Insight ─────────────────────────────────────`
- The three-layer exception handler (typed → policy-specific → generic) creates an implicit contract: only exceptions that match a named branch preserve their message in production. The `HttpStatusCodeInterface` pattern exists specifically to bridge this gap for domain errors — the inconsistency in EXC-1 is the natural pressure point where that pattern hasn't yet been fully applied.
- `bootstrap/cache/services.php` being included as "source material" is a common DeepSeek hallucination trigger. The `.gitignore` in that directory (`*`) is the authoritative signal that the file is ephemeral — always check before accepting a finding about a cached/generated file.
- The `prependToPriorityList(ThrottleRequests, VerifySupabaseJwt)` + `prependToPriorityList(ThrottleRequests, IdempotencyKey)` pattern produces a deterministic order `JWT → Idempotency → Throttle` because each insertion prepends relative to the anchor, and the first insertion is already ahead of the anchor when the second fires.
`─────────────────────────────────────────────────`

---

# Bootstrap / Providers / Mail Layer Audit — 2026-05-31

**Branch:** development
**Lens:** Bootstrap and providers, global middleware order bugs, exception render leakage, route-model-binding misuse, Laravel 12 bootstrap drift, dangerous singletons, service-provider boot bugs, mail-send layer correctness, mail XSS, unsigned mail links, PII in emails
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- bootstrap/app.php
- bootstrap/cache/packages.php
- bootstrap/cache/services.php
- bootstrap/providers.php
- app/Exceptions/Auth/JwksUnavailableException.php
- app/Exceptions/Gdpr/DataExportInProgressException.php
- app/Exceptions/Gdpr/NoRecipientEmailException.php
- app/Exceptions/Streaming/KickRateLimitException.php
- app/Http/Middleware/AddETagHeaders.php
- app/Http/Middleware/AddPublicCacheHeaders.php
- app/Http/Middleware/Auth/EnsurePartnaAdmin.php
- app/Http/Middleware/Auth/EnsurePartnaStaff.php
- app/Http/Middleware/Auth/RequireAal2.php
- app/Http/Middleware/Auth/RequireEmailVerified.php
- app/Http/Middleware/Auth/VerifySupabaseEmailHookSignature.php
- app/Http/Middleware/Auth/VerifySupabaseJwt.php
- app/Http/Middleware/Context/EnforcePendingDeletionReadOnly.php
- app/Http/Middleware/Context/LoadCurrentUser.php
- app/Http/Middleware/FeatureGate.php
- app/Http/Middleware/IdempotencyKey.php
- app/Http/Middleware/Logging/LogLeadRateLimits.php
- app/Http/Middleware/Logging/RecordStaffAuditEntry.php
- app/Http/Middleware/Moderation/PerTargetReportThrottle.php
- app/Http/Middleware/SecureHeaders.php
- app/Http/Middleware/VerifyBotToken.php
- app/Providers/AppServiceProvider.php
- app/Providers/BotProtectionServiceProvider.php
- app/Providers/DatabaseServiceProvider.php
- app/Providers/EventServiceProvider.php
- app/Mail/Auth/* (6 files)
- app/Mail/BaseTransactionalMail.php
- app/Mail/Branding/EmailBrand.php
- app/Mail/Branding/EmailBrandDefaults.php
- app/Mail/Branding/EmailPalette.php
- app/Mail/Branding/ProEmailBrandResolver.php
- app/Mail/EnquiryConfirmationMail.php
- app/Mail/FeedbackSubmittedMail.php
- app/Mail/Gdpr/UserDataExportMail.php
- app/Mail/HandleAliasExpiringMail.php
- app/Mail/Notifications/* (7 files)
- app/Mail/SiteEnquiryNotification.php
- app/Mail/StaffBroadcastMail.php
- app/Mail/SubscriptionConfirmationMail.php
- resources/views/emails/** (15 files)
- app/Services/Email/SupabaseEmailHookSignatureVerifier.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **#EXC-1** · P2 — Generic exception fallback suppresses `abort(4xx, message)` messages in production
    - **Where:** bootstrap/app.php:117–131 (the `else` branch of the exception renderer)
    - **Affects:** Any API code path that calls `abort(4xx, 'meaningful message')` directly — the caller's message is replaced with `"An error occurred"` in production, while policy-denial messages (same status codes, different exception class) pass through intact.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extend the generic `else` branch to check whether the exception is a `HttpException` with status < 500 and has a non-empty message; if so, pass the message through rather than substituting `"An error occurred"`.
        - Alternatively, promote any such call site to use the `HttpStatusCodeInterface` domain-exception pattern that already exists in the codebase (`KickRateLimitException`, `DataExportInProgressException`) — this is the architecturally cleaner fix and makes the contract explicit.
        - Do not change the ≥500 path — internal error messages must remain hidden in production.
    - **Technical:** The renderer handles `AccessDeniedHttpException` (403 from policies) explicitly and preserves `$e->getMessage()`. However a plain `abort(403, 'reason')` throws a base `Symfony\Component\HttpKernel\Exception\HttpException(403)`, which is NOT an `AccessDeniedHttpException` and is NOT caught by the named 404/423 branch. It falls to the `else` block where `config('app.debug')` gates the message — returning `"An error occurred"` with status 403 in production. The same mismatch applies to any other 4xx status code not explicitly named (401, 400, 409, 410…). CI today blocks inline `abort()` calls in controllers, which limits exposure, but the inconsistency is latent and will be hit by any future code that uses `abort()` on an API route before the `HttpStatusCodeInterface` pattern is applied.
    - **Plain English:** Imagine two locked doors at the same office. One door (policy checks) shows a helpful note: "Sorry, staff only — try the main entrance." The other door (direct abort calls) just flashes a generic "Error" with no explanation. Both doors are locked for the same reason, but one leaves you confused and the other explains why. Any time the app explicitly tries to send a meaningful message with an error code, that message is silently swapped for a useless one before it reaches the user in production.
    - **Evidence:**
        ```php
        // bootstrap/app.php — generic fallback branch
        // Generic error handling
        else {
            $statusCode = 500;
            if ($e instanceof HttpException) {
                $statusCode = $e->getStatusCode();
            }

            // Log full exception for debugging even in production
            if ($statusCode >= 500) {
                \Illuminate\Support\Facades\Log::error('API Error', [
                    'exception' => $e,
                    'status' => $statusCode,
                ]);
            }

            // Don't expose internal errors in production
            $message = config('app.debug')
                ? $e->getMessage()
                : 'An error occurred';

            $response = response()->json([
                'message' => $message,
            ], $statusCode);
        }
        ```

---

## P3 — Nice to have

- [ ] **#BOOT-2** · P3 — `AppServiceProvider` boot guards cover six misconfigurations but not `APP_DEBUG=true`
    - **Where:** app/Providers/AppServiceProvider.php:94–140 (the `boot()` production-guard block)
    - **Affects:** A production deploy where `APP_DEBUG` is accidentally left `true` — the generic exception handler in `bootstrap/app.php` gates full exception messages on `config('app.debug')`, so every uncaught exception leaks its raw message, file path, and status to API consumers until the misconfiguration is noticed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a guard after the existing block: `if (app()->isProduction() && config('app.debug')) { throw new \RuntimeException('APP_DEBUG must be false in production.'); }`
        - This mirrors the pattern of the five existing guards and fails the deploy fast rather than silently leaking for hours.
    - **Technical:** `AppServiceProvider::boot()` already enforces six production invariants (throttle enabled, public domain set, JWKS fail-closed, JWT issuer/audience present, email hook secret set, Nightwatch token present). The exception renderer in `bootstrap/app.php` gates the full exception message on `config('app.debug')` inside the generic `else` branch. If `APP_DEBUG=true` ships to production, every unhandled exception — including 5xx crashes — exposes raw Laravel exception text and internal paths to API consumers. Adding the seventh guard is a one-liner consistent with the established idiom.
    - **Plain English:** The app already refuses to start if six settings are misconfigured — like a pilot running a six-item pre-flight checklist. There's one item missing from that checklist: "is debug mode off?" If debug mode is accidentally left on, every server error leaks internal details to anyone who triggers one. Adding the check takes five minutes and makes the checklist complete.
    - **Evidence:**
        ```php
        // app/Providers/AppServiceProvider.php — boot() — five guards exist, no APP_DEBUG guard
        if (app()->isProduction() && ! (bool) config('partna.throttle.enabled', true)) {
            throw new \RuntimeException('PARTNA_THROTTLE_ENABLED must not be false in production.');
        }
        // ... four more analogous guards for JWKS, JWT issuer/audience, email hook secret, Nightwatch ...
        // No guard for: if (app()->isProduction() && config('app.debug')) { throw ... }
        ```
