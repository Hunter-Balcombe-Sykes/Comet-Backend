`★ Insight ─────────────────────────────────────`
Key patterns I'm verifying before adjudicating:
- `KickRateLimitException` is caught locally in `LiveStatusPoller.php` — never propagates to the global handler
- Both `DataExportInProgressException` and `Gdpr\NoRecipientEmailException` are caught inline in their respective controllers — also never reach bootstrap renderer
- The non-GDPR `NoRecipientEmailException` has zero import references — confirmed dead code
- `current.pro` is applied consistently at the professional route group level, not ad-hoc per route — so BOOT-3's "developer forgets" scenario is structurally mitigated today
`─────────────────────────────────────────────────`

# Bootstrap / Middleware Stack Audit — 2026-05-24

**Branch:** development
**Lens:** bootstrap middleware stack gaps, global middleware order bugs, exception render leakage, route model binding misuse, Laravel 12 bootstrap config drift
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- bootstrap/app.php
- app/Exceptions/Auth/JwksUnavailableException.php
- app/Exceptions/Gdpr/DataExportInProgressException.php
- app/Exceptions/Gdpr/NoRecipientEmailException.php
- app/Exceptions/NoRecipientEmailException.php
- app/Exceptions/Streaming/KickRateLimitException.php
- bootstrap/cache/packages.php
- bootstrap/cache/services.php
- bootstrap/providers.php
- routes/api/professional.php (verified via Grep)
- app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffDataExportController.php (verified via Grep)
- app/Http/Controllers/Api/Professional/Account/ProfessionalDataExportController.php (verified via Grep)
- app/Services/Streaming/LiveStatusPoller.php (verified via Grep)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **#BOOT-1** · P2 — Domain exception safety net missing: unhandled domain exceptions produce opaque 500s
    - **Where:** bootstrap/app.php (exception renderer `else` branch); `app/Exceptions/Streaming/KickRateLimitException.php`; `app/Exceptions/Gdpr/DataExportInProgressException.php`
    - **Affects:** Any future call site that throws a domain exception without a local try/catch — today's exceptions are caught inline in their controllers, but the global renderer has no net. `KickRateLimitException` in particular carries a `$retryAfter` value that is silently discarded if the exception ever escapes its catch block; callers would retry immediately instead of backing off.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Introduce a lightweight interface (e.g. `HttpStatusCodeInterface` with `getHttpStatusCode(): int` and optionally `getHttpHeaders(): array`) that domain exceptions can implement.
        - Have `KickRateLimitException` implement it, returning 429 with a `Retry-After` header; `DataExportInProgressException` returning 409.
        - In the `else` branch of the exception renderer, check `$e instanceof HttpStatusCodeInterface` before falling through to the generic 500 path.
    - **Technical:** Currently `KickRateLimitException` is caught in `LiveStatusPoller.php` and both GDPR exceptions are caught inline in `StaffDataExportController` and `ProfessionalDataExportController` — none reach the global renderer today. But the renderer has no structural guarantee. The `else` block only checks `$e instanceof HttpException` (Symfony); a plain `RuntimeException` subclass always exits as 500. Adding the interface costs ~15 lines and makes every future domain exception opt-in to correct HTTP semantics without requiring duplicate try/catch at every call site.
    - **Plain English:** Right now every unusual error that isn't on the framework's known list gets stamped "server crashed" — even a polite "please slow down" message from an external service. The fix is to let custom error types declare what HTTP status they should produce, so the system can translate them correctly even if a developer forgets to catch them locally.
    - **Evidence:**
        ```php
        // bootstrap/app.php — generic else branch
        else {
            $statusCode = 500;
            if ($e instanceof HttpException) {
                $statusCode = $e->getStatusCode();
            }
            $message = config('app.debug')
                ? $e->getMessage()
                : 'An error occurred';
            $response = response()->json([
                'message' => $message,
            ], $statusCode);
        }

        // app/Exceptions/Streaming/KickRateLimitException.php
        class KickRateLimitException extends RuntimeException
        {
            public function __construct(
                public readonly ?int $retryAfter = null
            ) {
                parent::__construct('Kick API rate limit exceeded.');
            }
        }
        ```

- [ ] **#BOOT-2** · P2 — `current.pro` middleware not appended to the `api` group — relies on every route group remembering to include it
    - **Where:** bootstrap/app.php (middleware alias block; no `appendToGroup('api', ...)` for `LoadCurrentProfessional`)
    - **Affects:** Any new authenticated route file or route group that omits `current.pro` — `$request->attributes->get('professional')` returns null, controller calls to `$this->currentProfessional($request)` receive null, and authorization calls become no-ops or NPEs. Currently mitigated because `routes/api/professional.php` applies `current.pro` at its outer group; the risk is structural, not yet exploited.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Create an `auth.api` middleware group: `['supabase.jwt', 'require.email_verified', 'current.pro']` and apply it to every authenticated route group in place of the current ad-hoc list.
        - Alternatively, append `LoadCurrentProfessional::class` to the `api` group (after `VerifySupabaseJwt` via `prependToPriorityList`) and remove the explicit `current.pro` from route files — this simplifies every future route file to just `middleware('supabase.jwt')`.
        - Add a test that asserts `$request->attributes->get('professional')` is non-null on any route with `supabase.jwt` applied, to catch future omissions.
    - **Technical:** The Partna Authorization Doctrine requires the resolved actor at `$request->attributes->get('professional')`. `LoadCurrentProfessional` sets that attribute by hydrating the User from the Supabase UID set by `VerifySupabaseJwt`. The current setup has `VerifySupabaseJwt` in the priority list but `LoadCurrentProfessional` is only an alias — it must be explicitly named in every route group. With one route file today this is manageable; as the API grows (staff routes, internal routes, new professional sub-domains) the omission risk compounds. The `prependToPriorityList` call in the file already shows the team is aware of ordering hazards; the same discipline should apply to `LoadCurrentProfessional`.
    - **Plain English:** The front-door badge scanner (`VerifySupabaseJwt`) is wired into every entrance automatically, but the system that prints your name tag (`LoadCurrentProfessional`) has to be manually switched on for each corridor. Every new corridor built without flipping that switch leaves downstream "check your badge" gates reading a blank. Making it automatic removes a class of human error entirely.
    - **Evidence:**
        ```php
        // bootstrap/app.php — current.pro aliased, never appended to a group
        $middleware->alias([
            'supabase.jwt' => VerifySupabaseJwt::class,
            'require.email_verified' => RequireEmailVerified::class,
            'current.pro' => LoadCurrentProfessional::class,  // alias only
            'staff' => EnsurePartnaStaff::class,
            // ...
        ]);

        // routes/api/professional.php — must remember to include it manually
        Route::middleware(['supabase.jwt', 'require.email_verified', 'current.pro', ...])
        ```

---

## P3 — Nice to have

- [ ] **#BOOT-3** · P3 — Dead `NoRecipientEmailException` class in root namespace after strip refactor
    - **Where:** app/Exceptions/NoRecipientEmailException.php
    - **Affects:** Developers tracing exception handling — two classes with near-identical names create false signal about which to throw.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Delete `app/Exceptions/NoRecipientEmailException.php` (the root-namespace version). It is not imported anywhere in the codebase.
        - The GDPR variant (`app/Exceptions/Gdpr/NoRecipientEmailException.php`) is the live one — used in `DataExportService`, `ProfessionalDataExportController`, and `StaffDataExportController`.
        - The strip commits (`strip(task-8)`) renamed `Professional` → `User` throughout but left this orphan behind.
    - **Technical:** Grep confirms zero usages of `App\Exceptions\NoRecipientEmailException` (the root-namespace, `string $professionalId` variant). The `Gdpr\NoRecipientEmailException` is imported and thrown in three files. The dead class is a red herring for anyone writing exception handlers or searching for throw sites. Its constructor still references `'Professional %s'` — the pre-rename terminology — making it a stale artefact of the strip.
    - **Plain English:** After renovating a building, there are two light switches on opposite walls labelled identically, but only one is wired. The unwired one confuses every electrician who works there. Removing it takes five seconds and ends the confusion permanently.
    - **Evidence:**
        ```php
        // app/Exceptions/NoRecipientEmailException.php — zero import references found
        class NoRecipientEmailException extends RuntimeException
        {
            public function __construct(string $professionalId)
            {
                parent::__construct(sprintf('Professional %s has no recipient email on file.', $professionalId));
            }
        }

        // app/Exceptions/Gdpr/NoRecipientEmailException.php — the live version
        class NoRecipientEmailException extends RuntimeException
        {
            public function __construct()
            {
                parent::__construct('No valid recipient email on file.');
            }
        }
        ```

- [ ] **#BOOT-4** · P3 — Manual CORS injection in exception renderer is fragile against new exception branches
    - **Where:** bootstrap/app.php (exception renderer, final `if ($response !== null && ! $response->headers->has(...))` block)
    - **Affects:** Future maintainers adding new exception branches — a new early-return that bypasses the tail block leaves error responses without CORS headers, turning a handled error into an opaque network failure in the browser.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the CORS injection into a named closure or helper that wraps every `$response = response()->json(...)` assignment, making it structurally impossible to skip.
        - Alternatively, add a comment block at the top of the renderer explaining that the CORS guard **must** remain the final operation and that new branches must not early-return before it.
        - Long-term: investigate whether the Laravel Cloud proxy behaviour can be addressed via `config/cors.php` `exposed_headers` / `supports_credentials` settings rather than manual injection.
    - **Technical:** The comment in the source accurately explains the root cause: `HandleCors` middleware adds CORS headers during normal pipeline flow, but exception responses can exit before that middleware fires, and Laravel Cloud's proxy compounds this by stripping CORS on some error responses. The fix is correct and minimal — the structural risk is that the guard lives at the end of a growing `if/elseif/else` chain. Each new branch is a chance to accidentally place an early `return $response` before the CORS assignment.
    - **Plain English:** There's a sticky note at the end of a checklist saying "unlock the side door." The checklist works perfectly today, but every time someone adds a new step in the middle, they might finish early and forget the sticky note. The fix is to make "unlock the side door" part of the step template, not an afterthought at the bottom.
    - **Evidence:**
        ```php
        // bootstrap/app.php — CORS guard at the tail of the render closure
        // Ensure CORS headers are present on all API error responses.
        // HandleCors middleware adds these during normal flow, but when
        // an exception propagates past it the rendered response skips
        // the CORS header injection. Laravel Cloud's proxy also strips
        // CORS headers on some error responses. This guard ensures the
        // browser can always read the error body.
        if ($response !== null
            && ! $response->headers->has('Access-Control-Allow-Origin')
        ) {
            $response->headers->set('Access-Control-Allow-Origin', '*');
        }
        ```

`★ Insight ─────────────────────────────────────`
Three adjudication decisions worth noting for this codebase:
- DeepSeek's BOOT-1 described currently-caught exceptions as "unhandled" — Grep revealed all three are caught inline today. The finding survives as P2 hardening (safety net for future throw sites) but the misleading "Affects" needed rewriting.
- DeepSeek's BOOT-3 (middleware grouping) was re-tiered from P2 to P2 with revised framing — the actual risk is structural/future-facing since the one existing route file applies `current.pro` correctly; the real gap is no enforcement mechanism as route files multiply.
- The BOOT-2 (duplicate exception) survived as P3 but with narrowed scope: confirmed via Grep that the root-namespace variant has zero import references, making deletion unambiguous rather than "trace usages first."
`─────────────────────────────────────────────────`

The audit is complete — four findings (0 P0, 0 P1, 2 P2, 2 P3) with all evidence verified against source files.
