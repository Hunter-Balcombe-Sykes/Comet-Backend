All evidence verified. Let me now compile the complete adjudication:

**Key adjudication decisions:**
- **RATE-1** (P1 → P2): Downgraded. Zero throttle on `/internal/env-check` is a real gap, but exploiting it requires endpoint discovery + sustained flood — not "ships bad behavior in known scenarios." The always-drop rule for `/internal/*` doesn't apply here because this endpoint has zero middleware and is publicly reachable.
- **RATE-2** (P2 kept): Confirmed — 7 of 8 IP-keyed limiters use bare `$request->ip()`; only `public-profile` uses `CF-Connecting-IP`. Fix recommendation refined: follow the existing `public-profile` pattern rather than the complex CIDR approach.
- **RATE-3** (P3 kept): Verified — subdomain falls to `'unknown'` on path-based routes; per-IP limit still applies.
- **RATE-4** (P3 kept): Verified — 120/min tuning preference on already-throttled endpoint.
- **RATE-5** (new, P2): DeepSeek missed this — `captcha` middleware is on path-based lead routes in `api.php` (lines 113, 116) but absent from the equivalent domain-based routes in `publicSite.php` (lines 31, 35). Confirmed by Grep. When CAPTCHA goes live, the primary subdomain traffic path bypasses bot protection entirely.

`★ Insight ─────────────────────────────────────`
- The `trustProxies(at: '*')` pattern is safe when Cloudflare is the _only_ path to origin — but it creates a defense-in-depth gap: the single layer of Cloudflare IP restriction becomes a single point of failure for all per-IP rate limits simultaneously. The `CF-Connecting-IP` header is set _by_ Cloudflare and cannot be forged by clients, making it a strictly stronger key than `$request->ip()` in this architecture.
- Middleware asymmetry between route files (domain-based vs path-based) is a common source of missed security controls in Laravel multi-route architectures. The captcha gap here is a textbook example: the feature was added to one entry point but the equivalent sibling route in a different file was overlooked.
`─────────────────────────────────────────────────`

# Rate Limiting, Throttle Bypass & CORS Audit — 2026-05-25

**Branch:** development
**Lens:** Rate limiting coverage on public + auth routes, throttle bypass, CORS misconfig
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- routes/api.php
- routes/api/professional.php
- routes/api/publicSite.php
- routes/api/staff.php
- app/Providers/AppServiceProvider.php
- app/Http/Middleware/VerifyTurnstileCaptcha.php
- app/Http/Middleware/Auth/VerifySupabaseJwt.php
- app/Http/Middleware/SecureHeaders.php
- bootstrap/app.php
- config/cors.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **#RATE-2** · P2 — `trustProxies('*')` + bare `$request->ip()` keys on 7 of 8 IP-keyed rate limiters
    - **Where:** app/Providers/AppServiceProvider.php:233–419 (limiter definitions); bootstrap/app.php:54 (trust config)
    - **Affects:** All public rate limits keyed by `$request->ip()`: `health-check`, `public-site`, `analytics`, `analytics-click`, `leads` (per-IP bucket), `waitlist`, `webhooks`. An actor who bypasses Cloudflare and reaches the origin directly (misconfigured security group, IPv6 omission, infrastructure pivot) controls `X-Forwarded-For` and can rotate through arbitrary IPs, evading all seven per-IP caps.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace `$request->ip()` with `$request->header('CF-Connecting-IP') ?? $request->ip()` in every IP-keyed limiter except `public-profile` (which already does this correctly).
        - Do not change `trustProxies(at: '*')` — removing it would collapse all Cloudflare traffic onto a single IP bucket and break rate limiting entirely. The header-key fix is the right layer.
        - Verify at the infrastructure level (Laravel Cloud / Cloudflare) that the origin only accepts inbound HTTP/S from Cloudflare IP ranges.
    - **Technical:** `trustProxies(at: '*')` is correct for a Cloudflare-only deployment and is intentional. The gap is that `CF-Connecting-IP` — a header Cloudflare always sets and clients can never forge — is used as the rate-limit key in `public-profile` (explicitly, with a comment explaining why) but bare `$request->ip()` is used everywhere else. Under `trustProxies('*')`, `$request->ip()` resolves `X-Forwarded-For`, which an attacker controlling a direct connection to the origin can set arbitrarily. `CF-Connecting-IP` is immune to this because Cloudflare injects it on its own edge, after the client's connection, and strips any client-supplied value. Commit `3e7e72e9` addressed CORS, but did not normalise the rate-limit key inconsistency that `public-profile` already identified in its own comment.
    - **Plain English:** Every rate limit that tracks "too many requests from this address" is doing so by trusting the address the client claims to be from. Cloudflare normally acts as the middleman and stamps the real address on every request — but if someone finds the back door directly to the server, they can lie about their address and reset their counter at will. One rate limiter (for individual profiles) already uses the tamper-proof Cloudflare stamp instead of the self-reported address. The rest need the same fix.
    - **Evidence:**
        ```php
        // bootstrap/app.php:54 — trusts all proxies (correct for Cloudflare-only)
        $middleware->trustProxies(at: '*');

        // public-profile (AppServiceProvider.php:248) — uses tamper-proof CF header:
        $key = $request->header('CF-Connecting-IP') ?? $request->ip();

        // All other IP-keyed limiters use bare $request->ip(), e.g.:
        RateLimiter::for('public-site', function (Request $request) use ($throttleEnabled) {
            return Limit::perMinute(60)->by($request->ip());
        });
        RateLimiter::for('analytics', function (Request $request) use ($throttleEnabled) {
            return Limit::perMinute(120)->by($request->ip());
        });
        RateLimiter::for('webhooks', function (Request $request) use ($throttleEnabled) {
            return Limit::perMinute(200)->by($request->ip());
        });
        ```

- [ ] **#RATE-5** · P2 — `captcha` middleware missing on domain-based lead routes; path-based equivalents have it
    - **Where:** routes/api/publicSite.php:31, 35 (missing); routes/api.php:113, 116 (present)
    - **Affects:** Bot protection on `POST /{subdomain}.partna.au/public/customers` and `POST /{subdomain}.partna.au/public/enquiry`. These are the **primary** lead-submission routes served to all visitors arriving via a user's custom subdomain. When `PARTNA_CAPTCHA_ENABLED` is set to `true`, the path-based fallback routes get Turnstile verification but the high-traffic subdomain routes do not, leaving the main attack surface unguarded.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `'captcha'` to the domain-based customer and enquiry route middleware stacks in `routes/api/publicSite.php`.
        - Add the same for the `/subscribe` endpoint if CAPTCHA coverage is intended there too — currently neither path has it.
    - **Technical:** `VerifyTurnstileCaptcha` is currently disabled by default (`config('partna.features.captcha', false)`), so there is no live-traffic impact today. However commit `0361257b` (chore: enable Turnstile captcha for local stack) indicates captcha is being readied for production rollout. When the flag is flipped, bot submissions via `handle.partna.au/public/customers` will bypass the gate entirely because the domain-based routes in `publicSite.php` never received the `captcha` middleware that was added to the path-based equivalents in `api.php`. Both route groups invoke the same controller (`PublicCustomerLeadController::store`, `PublicEnquiryController::submit`), so the fix is a one-line change per route.
    - **Plain English:** Your contact form has two front doors. One goes through the custom web address people see (`yourname.partna.au/public/customers`); the other is a backup path used when that subdomain routing isn't available. Someone recently added a robot check to the backup door but forgot to add it to the main door. Right now this doesn't matter because the robot check is switched off. But the moment you turn it on, bots can still walk straight through the main door.
    - **Evidence:**
        ```php
        // routes/api/publicSite.php — domain-based routes, captcha absent:
        Route::post('/customers', [PublicCustomerLeadController::class, 'store'])
            ->middleware(['lead.log', 'throttle:leads']);      // ← no 'captcha'

        Route::post('/enquiry', [PublicEnquiryController::class, 'submit'])
            ->middleware(['lead.log', 'throttle:leads']);      // ← no 'captcha'

        // routes/api.php — path-based equivalents, captcha present:
        Route::post('/public/customers', [PublicCustomerLeadController::class, 'store'])
            ->middleware(['lead.log', 'throttle:leads', 'captcha']);

        Route::post('/public/enquiry', [PublicEnquiryController::class, 'submit'])
            ->middleware(['lead.log', 'throttle:leads', 'captcha']);
        ```

- [ ] **#RATE-1** · P2 — `/internal/env-check` is the only public HTTP endpoint with zero middleware
    - **Where:** routes/api.php:119
    - **Affects:** Worker availability. Every GET to this route — valid or not — bootstraps the full Laravel framework and hits the database-backed `EnvCheckService`. Unlike every other route in the application, there is no throttle middleware to reject excess requests before the controller runs. An attacker who finds this endpoint can sustain a flood of garbage requests, each consuming a PHP-FPM worker slot and framework bootstrap cycle.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `->middleware('throttle:health-check')` to the route, or define a dedicated named limiter (e.g. `throttle:10,1`) in `configureRateLimiting()`.
        - The endpoint is intentionally independent of JWT auth — a shared-secret header inside `EnvCheckController` validates callers. Throttle middleware does not interfere with this design and does not require JWT middleware to be present.
    - **Technical:** `EnvCheckController` already fails-closed for two cases: (1) `INTERNAL_ENV_CHECK_TOKEN` unset → 503; (2) token missing/mismatched → 403. Both failure paths still require the framework to bootstrap (routing, service container, config loading, `EnvCheckService` resolution) before the controller fires. Every other route in `routes/api.php` — including `/ping` and `/health` — carries at minimum `throttle:health-check`. The env-check route is the sole exception. At pilot scale the risk is low, but the fix is a single `->middleware('throttle:health-check')` call that aligns it with every peer endpoint.
    - **Plain English:** Your diagnostic health-check page has a key lock on it (a secret code that must be provided), but no limit on how many times someone can try the wrong code before being turned away. Every wrong-code attempt still opens the door partway — it makes the server do some work before saying "no." Every other similar page has a "maximum ten tries per minute" rule. This one doesn't, making it the easiest page to hammer without consequence.
    - **Evidence:**
        ```php
        // routes/api.php:119 — zero middleware; compare with every peer:
        Route::get('/internal/env-check', EnvCheckController::class);

        // Every other endpoint has at minimum throttle:health-check, e.g.:
        Route::get('/ping', fn () => response()->json(['pong' => true]))
            ->middleware('throttle:health-check');
        Route::get('/health', fn () => response()->json(['ok' => true]))
            ->middleware('throttle:health-check');
        Route::get('/ready', [HealthController::class, 'check'])
            ->middleware('throttle:health-check');
        ```

## P3 — Nice to have

- [ ] **#RATE-4** · P3 — CSP violation report endpoint throttle (120/min) allows sustained log flooding
    - **Where:** routes/api.php:126
    - **Affects:** Log volume, Nightwatch ingest cost, and signal-to-noise ratio. A single misconfigured page or a deliberate CSP-report flood can sustain 120 POST requests per minute indefinitely without hitting the limit.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Tighten to `throttle:30,1` (30 per minute). This still absorbs a full site-wide CSP breakage during a 30-second bad deploy while capping sustained noise.
    - **Technical:** CSP violation reports are generated by browsers whenever a page's `Content-Security-Policy` is breached. In a correctly configured application they should be near-zero. A spike signals either a deployment error or an injection attempt. At 120/min, a polling page with a single inline-script violation can generate 2 reports/second indefinitely, which fills logs and dilutes the signal of a genuine attack. The existing comment says "throttled hard" but 120/min is the most permissive throttle in the codebase by absolute value. Tightening to 30/min still absorbs burst events (e.g. 50 browser tabs reloading simultaneously during a bad deploy) while cutting sustained noise by 75%.
    - **Plain English:** Your CSP report endpoint is the smoke detector for policy violations. Right now it's set to allow 2 beeps per second indefinitely before it stops listening. That's loud enough to drown out real alerts in the logs. Turning it down to one beep every two seconds still catches everything important but cuts through the noise faster.
    - **Evidence:**
        ```php
        // routes/api.php:126
        // CSP violation report sink. Browsers POST here when a page breaks the policy
        // set by SecureHeaders. Unauthenticated (browsers send without credentials);
        // throttled hard so a single misconfigured page cannot flood logs.
        Route::post('/internal/csp-report', CspReportController::class)
            ->middleware('throttle:120,1');
        ```

- [ ] **#RATE-3** · P3 — `leads` per-subdomain limit collapses to a shared `'unknown'` bucket for path-based routes
    - **Where:** app/Providers/AppServiceProvider.php:296–315 (leads limiter); routes/api.php:113–116 (path-based routes)
    - **Affects:** Per-tenant abuse isolation on `POST /public/customers` and `POST /public/enquiry` when accessed via the path-based fallback. All tenants' path-based traffic shares one 100/min bucket instead of being isolated per subdomain.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - In the `leads` limiter, resolve the subdomain from `$request->header('X-Site-Subdomain')` as a fallback before defaulting to `'unknown'`: `$subdomain = $request->route('subdomain') ?? $request->header('X-Site-Subdomain') ?? 'unknown';`
        - The `X-Site-Subdomain` header is the established mechanism for path-based frontend routing in this codebase (see the `site-by-slug` route comment in `api.php`).
    - **Technical:** The `leads` limiter returns two `Limit` objects: a per-IP cap (3/min) and a per-subdomain cap (100/min). The per-subdomain key is populated from `$request->route('subdomain')`, which resolves the `{subdomain}` route parameter on domain-based routes in `publicSite.php` but returns `null` on the flat-path routes in `api.php` (which have no such parameter). The fallback `'unknown'` means all path-based lead submissions — regardless of which tenant they target — share a single 100/min bucket. The per-IP limit of 3/min still provides effective individual rate limiting; this is a correctness gap, not a security hole, but a coordinated multi-IP campaign against path-based endpoints could exceed the intended per-tenant ceiling.
    - **Plain English:** The contact form has two ways to enforce "no more than 100 submissions per minute per business." One way works via the subdomain address (`name.partna.au`); the other is a backup path. On the backup path, the system can't figure out which business is being targeted, so it lumps all businesses together into one shared bucket. Individual-IP limits still work fine. This just means the "per-business" cap isn't enforced on the backup path.
    - **Evidence:**
        ```php
        // AppServiceProvider.php — limiter reads {subdomain} route param
        // which is only defined on domain-based routes in publicSite.php:
        RateLimiter::for('leads', function (Request $request) use ($throttleEnabled) {
            $subdomain = $request->route('subdomain') ?? 'unknown';
            return [
                Limit::perMinute(3)->by($request->ip())->response(...),
                Limit::perMinute(100)->by($subdomain)->response(...),  // ← 'unknown' for path routes
            ];
        });

        // routes/api.php — path-based routes with no {subdomain} parameter:
        Route::post('/public/customers', [PublicCustomerLeadController::class, 'store'])
            ->middleware(['lead.log', 'throttle:leads', 'captcha']);
        Route::post('/public/enquiry', [PublicEnquiryController::class, 'submit'])
            ->middleware(['lead.log', 'throttle:leads', 'captcha']);
        ```

`★ Insight ─────────────────────────────────────`
- The `public-profile` limiter serves as a working template for the correct pattern in this codebase — `CF-Connecting-IP ?? $request->ip()` with an explanatory comment. When fixing RATE-2, using that limiter as the canonical example makes the PR diff self-documenting.
- RATE-5 (captcha asymmetry) is a category of bug worth watching for as the codebase grows: whenever you have two route files serving the same controllers with overlapping paths (domain-based in `publicSite.php` vs path-based in `api.php`), any new middleware must be applied to both. A simple grep for the controller class name across route files before closing a PR catches this.
`─────────────────────────────────────────────────`
