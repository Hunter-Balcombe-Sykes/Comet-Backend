`★ Insight ─────────────────────────────────────`
Key findings from verification:
1. `routes/web.php` has exactly ONE route: `/p/{UUID}.svg` (QR code endpoint). This scope is narrower than DeepSeek implied — it's not a "full web stack", just one SVG endpoint.
2. `AddPublicCacheHeaders::mergeVary()` is a **private** instance method — DeepSeek's proposed fix for SEC-2 references a method that cannot be called from the exception handler. The fix needs rewriting.
3. SEC-3 (HTTP→HTTPS redirect) is a literal always-drop-category finding: "Missing HTTPS — Partna is HTTPS-only at the infrastructure level." Dropped.
`─────────────────────────────────────────────────`

# Security Header & HSTS Posture Audit — 2026-05-31

**Branch:** development
**Lens:** Security header gaps, HTTPS enforcement, frame/CSP/HSTS posture, header coverage on public site responses
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- bootstrap/app.php
- app/Http/Middleware/SecureHeaders.php
- app/Http/Middleware/AddPublicCacheHeaders.php
- app/Http/Middleware/AddETagHeaders.php
- routes/web.php
- app/Http/Controllers/Api/PublicSite/QrCodeController.php
- app/Providers/AppServiceProvider.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **#SEC-1** · P2 — Web-route exception responses bypass `SecureHeaders` — the QR-code 404/500 ships un-headered HTML
    - **Where:** bootstrap/app.php:88-91 (exception render gate); routes/web.php:6-8 (the only web route); app/Http/Controllers/Api/PublicSite/QrCodeController.php:25-27 (the abort path)
    - **Affects:** Any visitor to `/p/{UUID}.svg` whose request produces an error response. The controller calls `abort(404)` for missing professionals. The HTML error page that Laravel renders carries no `X-Frame-Options`, `X-Content-Type-Options`, `Referrer-Policy`, `CSP`, or `HSTS` header. Normal (200 SVG) responses are correctly headered because `SecureHeaders` is global middleware and runs post-`$next()` on the happy path; only exception responses are missed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In the `withExceptions` render closure in `bootstrap/app.php`, remove or restructure the `if (! $request->is('api/*')) { return null; }` early return so `SecureHeaders::apply()` is called for non-API error responses as well.
        - The cleanest path: build a minimal error response for non-API exceptions (even a bare-bones HTML or plain-text body is fine) and pass it through `SecureHeaders::apply($response, $request)` before returning, rather than returning `null` and letting Laravel render un-headered HTML.
        - Alternatively, restrict `routes/web.php` to prefix the QR code route under `api/` and adjust the URL accordingly — then it falls under the existing guard naturally.
    - **Technical:** `SecureHeaders` is registered as global middleware (`$middleware->append(SecureHeaders::class)`), so it correctly headers every successful response, including the QR SVG. The gap is exclusive to exception responses. When a controller throws (or calls `abort()`), the middleware chain unwinds and the code after `$next($request)` in each middleware never executes. Laravel's exception kernel catches the exception and calls the `withExceptions` render closure. For `api/*` routes, that closure constructs a JSON response and explicitly calls `SecureHeaders::apply($response, $request)`. For all other routes — today, just `/p/{UUID}.svg` — it returns `null`, delegating to Laravel's default HTML exception renderer, which emits an un-headered response. The `SecureHeaders.php` docblock already calls out the two intended call sites (middleware handle + exception closure); the non-API branch is simply missing.
    - **Plain English:** Your security setup is like a bodyguard who checks everyone at the front door. They reliably check every visitor on the way in. But when a visitor triggers a "not found" alarm on the QR-code page, the alarm page itself goes out the side door without being checked — no "don't put this in a frame" sticker, no browser-protection labels. It's one page, one scenario, but it's a gap in an otherwise complete system. The fix is a five-line addition to tell the alarm handler to always apply the same checks before it sends anything back.
    - **Evidence:**
        ```php
        // bootstrap/app.php — the gate that skips SecureHeaders for non-API routes
        $exceptions->render(function (Throwable $e, Request $request) {
            // Only handle API routes
            if (! $request->is('api/*')) {
                return null;  // Laravel's default renderer — no SecureHeaders called
            }
            // ...
            if ($response !== null) {
                SecureHeaders::apply($response, $request);  // ONLY reached for api/*
            }
            return $response;
        });
        ```
        ```php
        // routes/web.php — the only non-API route; abort(404) triggers the un-headered path
        Route::get('/p/{professionalId}.svg', [QrCodeController::class, 'svg'])
            ->where('professionalId', '[0-9a-fA-F-]{36}')
            ->middleware('throttle:public-site');
        ```
        ```php
        // QrCodeController.php:25-27 — abort(404) on missing professional
        if (! $professional || ! $professional->partna_url) {
            abort(404);
        }
        ```

- [ ] **#SEC-2** · P2 — Exception-path API responses carry no `Cache-Control` — browsers may heuristically cache 404s and other errors
    - **Where:** bootstrap/app.php:87-158 (entire exception render closure); app/Http/Middleware/AddPublicCacheHeaders.php:32-68 (post-`$next()` logic that never runs on exception paths)
    - **Affects:** API consumers and frontend clients. When an API controller throws, `AddPublicCacheHeaders::handle()` calls `$next($request)` which throws — the middleware's post-response `Cache-Control` logic never executes. The exception handler constructs fresh JSON responses but only calls `SecureHeaders::apply()`, never setting `Cache-Control`. RFC 7234 allows browsers to heuristically cache responses with no explicit `Cache-Control` directive, using the `Last-Modified` delta (typically 10% of age). A 404 from a freshly-created resource, a 403 from a state that has since changed, or a 422 validation failure could be replayed from browser cache on retry. Authenticated responses are particularly affected: when `AddPublicCacheHeaders` does not run, the `private, no-store` guard it would normally set for `Authorization`-bearing requests is absent from error responses.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Inside the `withExceptions` render closure, immediately before the `SecureHeaders::apply($response, $request)` call, add `$response->headers->set('Cache-Control', 'private, no-store, max-age=0');` and `$response->headers->set('Pragma', 'no-cache');`. This applies to every branch that builds a `$response`.
        - Do not use `AddPublicCacheHeaders::mergeVary()` for this — it is a `private` instance method and cannot be called from the exception closure.
        - After the fix, verify that public cacheable paths (none currently in the exception handler since those return 200s normally) are not inadvertently marked `no-store`. The exception handler only produces 4xx/5xx responses, so `private, no-store` is universally correct here.
    - **Technical:** `AddPublicCacheHeaders` is registered only in the `api` middleware group. During normal request flow it runs post-`$next()` and correctly sets `Cache-Control: private, no-store` for authenticated requests and `Cache-Control: public, max-age=900` for allow-listed public paths. When any middleware or controller throws, the post-`$next()` code in every middleware in the chain is skipped — the exception surfaces directly to Laravel's kernel, which calls the `withExceptions` render callback. That callback constructs responses via `response()->json(...)` which, like all fresh Symfony responses, carry no `Cache-Control` header by default. The `SecureHeaders::apply()` call that follows sets XFO, CSP, HSTS, and nosniff — but does not touch `Cache-Control`, which is outside `SecureHeaders`' remit. The DeepSeek draft proposed calling `AddPublicCacheHeaders::mergeVary()` as part of the fix; this method is declared `private` and cannot be invoked outside that class. The correct fix is a direct `$response->headers->set()` call inside the exception closure.
    - **Plain English:** When your app encounters an error — "not found," "validation failed," "access denied" — it sends back a response, but that response has no "please don't cache this" label on it. Web browsers have a rule: if a server doesn't say anything about caching, they're allowed to hold onto responses for a little while. So if a user's API call gets a "not found" error, their browser might cache that error and show it again even after the problem is fixed — like a Post-it note stuck to the front door that says "closed" even after you've reopened. The fix is a single line that stamps every error response with "do not cache this."
    - **Evidence:**
        ```php
        // bootstrap/app.php — the generic error block; no Cache-Control is set in any branch
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

        // Only SecureHeaders::apply() is called — Cache-Control is never set
        if ($response !== null) {
            SecureHeaders::apply($response, $request);
        }
        return $response;
        ```
        ```php
        // AddPublicCacheHeaders.php:32-40 — the no-store guard that protects authenticated
        // responses during normal flow, but never runs when a controller throws
        if ($request->headers->has('Authorization')) {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $this->mergeVary($response, ['Authorization', 'Cookie', 'Accept-Encoding']);
            return $response;
        }
        ```
