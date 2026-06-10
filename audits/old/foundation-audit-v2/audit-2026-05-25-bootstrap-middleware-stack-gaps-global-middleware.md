`★ Insight ─────────────────────────────────────`
- **Laravel's `prepareException()` runs before render callbacks** — this is a non-obvious architectural quirk where exception type-checking in `withExceptions()->render()` receives an already-transformed exception, making naive `instanceof ModelNotFoundException` checks permanently dead.
- **FQCN middleware application vs. aliases** — deliberately placing middleware outside a named group (like `professional.api`) is the correct pattern when individual routes need `withoutMiddleware()` exclusions; aliases are for convenience, not correctness.
- **Idempotency cache namespacing** — using a per-deploy version prefix protects against response shape drift across deploys, but only works if `APP_VERSION` is actually set; an undocumented env var that silently falls back is a deployment trap.
`─────────────────────────────────────────────────`

# Bootstrap / Middleware Audit — 2026-05-25

**Branch:** development
**Lens:** bootstrap middleware stack gaps, global middleware order bugs, exception render leakage, route model binding misuse, Laravel 12 bootstrap config drift
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- bootstrap/app.php
- bootstrap/cache/packages.php
- bootstrap/cache/services.php
- bootstrap/providers.php
- app/Exceptions/Auth/JwksUnavailableException.php
- app/Exceptions/Gdpr/DataExportInProgressException.php
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
- app/Http/Middleware/Context/LoadCurrentProfessional.php
- app/Http/Middleware/FeatureGate.php
- app/Http/Middleware/IdempotencyKey.php
- app/Http/Middleware/Logging/LogLeadRateLimits.php
- app/Http/Middleware/Logging/RecordStaffAuditEntry.php
- app/Http/Middleware/SecureHeaders.php
- app/Http/Middleware/VerifyTurnstileCaptcha.php
- routes/api/professional.php (verified via Grep)
- .env.example (verified via Grep — `APP_VERSION` absent)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **#BOOT-1** · P2 — `ModelNotFoundException` render branch is dead; route-model-binding misses return `"Endpoint not found"`
    - **Where:** bootstrap/app.php:78-83
    - **Affects:** Every API caller whose request hits a route where a model binding fails (e.g. `GET /api/sites/{site}` with a non-existent UUID). They receive `"Endpoint not found"` instead of `"Resource not found"`, which conflates a missing resource with a missing route. Every client-side error logger and support ticket that surfaces this message becomes harder to triage.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the dead `elseif ($e instanceof ModelNotFoundException)` branch — it can never match because `prepareException()` converts it to `NotFoundHttpException` before render callbacks fire.
        - In the surviving `NotFoundHttpException` handler, distinguish the two cases: `if ($e->getPrevious() instanceof ModelNotFoundException)` return `"Resource not found"` (the binding resolved to no row); otherwise return `"Endpoint not found"` (the route itself doesn't exist).
    - **Technical:** Laravel's `Handler::render()` calls `mapException()` (which invokes `prepareException()`) on the raw exception before iterating the render callbacks registered in `withExceptions()`. `prepareException()` converts `ModelNotFoundException` → `NotFoundHttpException`. By the time the `render` closure runs, the exception is already a `NotFoundHttpException`; the `$e instanceof ModelNotFoundException` check below it is permanently unreachable. The original `ModelNotFoundException` survives as `$e->getPrevious()`, so the correct fix is a `getPrevious()` type-check inside the `NotFoundHttpException` branch. Verified via Grep: `tests/Feature/Security/PolicyExceptionHandlerTest.php` asserts `"Resource not found"` at line 41, but that test exercises the policy-denial `HttpException(404)` branch — not the `ModelNotFoundException` path — so the dead branch is untested and the wrong message ships undetected.
    - **Plain English:** The code has two labeled chutes for 404 errors — one labeled "item doesn't exist" and one labeled "page doesn't exist." Behind the scenes, the framework merges both into the same chute before the labeling logic even runs. So everything, whether a missing item or a missing page, exits through the "page doesn't exist" chute and tells the user "this page doesn't exist." The label on the other chute is there, the paint is dry, but no package ever lands in it. The fix is to read the package's original label (still attached as a sticky note on the merged package) and send it to the right destination.
    - **Evidence:**
        ```php
        // Dead — never matches because prepareException() already converted this
        // ModelNotFoundException into a NotFoundHttpException by this point.
        elseif ($e instanceof ModelNotFoundException) {
            $response = response()->json([
                'message' => 'Resource not found',
            ], 404);
        }

        // Route not found (404)
        elseif ($e instanceof NotFoundHttpException) {
            $response = response()->json([
                'message' => 'Endpoint not found',   // <— model-not-found hits THIS
            ], 404);
        }
        ```

---

## P3 — Nice to have

- [ ] **#BOOT-2** · P3 — `APP_VERSION` is undocumented; idempotency cache defaults to `'v0'` across all deploys when unset
    - **Where:** app/Http/Middleware/IdempotencyKey.php:161-167
    - **Affects:** Any client using `Idempotency-Key` headers across a deploy boundary where `APP_VERSION` is not set. If the JSON shape of a response changes in a new deploy and the same idempotency key is reused shortly after deploy, the stale cached body from before-deploy is replayed verbatim. The shared `v0` prefix means the two deploys cannot be distinguished in Redis.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `APP_VERSION=` to `.env.example` with a comment explaining it should be set to the git commit SHA (or semver tag) in CI/CD so each deploy gets a distinct cache namespace.
        - In Laravel Cloud deploy config, inject `APP_VERSION` from the commit SHA (e.g. `$GITHUB_SHA`) at deploy time.
        - Optionally, add a boot-time assertion in `AppServiceProvider` that warns (`Log::warning`) if `APP_VERSION` is empty and `APP_ENV=production`, so the gap surfaces in Nightwatch before it causes a silent replay problem.
    - **Technical:** `IdempotencyKey::appVersion()` returns `config('app.version', '')`, falling back to the hard-coded string `'v0'` when the key is blank. The `v` field in the cache payload (currently `1`) is a middleware-schema version guard, not a deploy guard — it only protects against shape changes in the middleware's own cache envelope, not changes to the JSON bodies controllers emit. `APP_VERSION` is absent from `.env.example` (verified via Grep), so it is undocumented and likely unset in most environments. Added in commit `a04aa6a8` (feat(http): B27 Idempotency-Key middleware); no follow-up commit sets the env var.
    - **Plain English:** The idempotency system stamps each cached response with a "version tag" so it knows not to replay answers from an old version of the app after a new version deploys. But the tag is only useful if each deployment gets a unique value stamped into it — right now, if nobody configures the version number, every deploy gets the same tag (`v0`), which is like issuing every employee the same ID badge. A retry after a code update could silently hand back the old answer instead of asking the new code. The fix is to wire the deploy process to automatically set the version number to the git commit hash.
    - **Evidence:**
        ```php
        private function appVersion(): string
        {
            $version = (string) config('app.version', '');

            return $version !== '' ? $version : 'v0';  // <— all deploys without APP_VERSION share this
        }
        ```
