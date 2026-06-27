`★ Insight ─────────────────────────────────────`
- No `TRUSTED_PROXIES` env var exists yet — the fix for HDR-1 should introduce one via `config/partna.php` with a boot-time assertion, matching the existing pattern of production guard-rails in `AppServiceProvider::boot()`.
- The `no-store` gap in HDR-2 is confirmed: the only two `no-store` writes in the entire middleware layer live in `AddPublicCacheHeaders`, which never runs on the exception handler's synthesised `Response` object.
`─────────────────────────────────────────────────`

# Security Headers Audit — 2026-05-25

**Branch:** development
**Lens:** security header gaps, HTTPS enforcement, frame/CSP/HSTS posture
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- bootstrap/app.php
- app/Http/Middleware/SecureHeaders.php
- app/Http/Middleware/AddPublicCacheHeaders.php
- app/Http/Middleware/AddETagHeaders.php
- app/Http/Middleware/IdempotencyKey.php
- app/Http/Middleware/Auth/VerifySupabaseJwt.php
- app/Providers/AppServiceProvider.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **HDR-1** · P2 — `trustProxies(at: '*')` trusts forwarded headers from any IP, not just Cloudflare
    - **Where:** bootstrap/app.php:43
    - **Affects:** Every inbound request — IP resolution for rate-limiting buckets, `$request->ip()` values in audit logs, HTTPS detection, `X-Forwarded-Host`-based URL generation. An attacker reaching the origin server directly (exposed LoadBalancer, leaked origin IP, misconfigured firewall) can spoof all of these.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `TRUSTED_PROXIES` env var to `.env.example` and `config/partna.php` (under `security.trusted_proxies`), defaulting to the string `"CLOUDFLARE"` as a sentinel.
        - In `bootstrap/app.php`, resolve the trusted list at boot: when the config value is `"CLOUDFLARE"` read the ranges from a maintained constants file or from the config directly; otherwise use the configured value verbatim.
        - Add an `AppServiceProvider::boot()` assertion (matching the existing pattern for `PARTNA_THROTTLE_ENABLED`, `SUPABASE_JWKS_FAIL_CLOSED`, etc.) that refuses to boot in production if the resolved list is still `'*'`.
        - Document the Cloudflare IPs in `config/partna.php` as a named constant array — Cloudflare publishes stable machine-readable lists at `https://www.cloudflare.com/ips-v4` and `/ips-v6`; copy them in and schedule a quarterly review comment.
    - **Technical:** Laravel's `TrustProxies` middleware uses the `at` parameter to allowlist which upstream IPs may set `X-Forwarded-*` headers. `'*'` means every IP qualifies — a single path to the origin bypasses the restriction entirely. The comment in the source correctly states the architectural intent ("exclusively behind Cloudflare"), but intent is not enforcement. Cloudflare's IP ranges change rarely (~2–3 times per year) and are published in a machine-readable format. The existing boot-time assertion pattern in `AppServiceProvider::boot()` (used for six other production-critical config values) is the right place to enforce the constraint, not comments.
    - **Plain English:** Right now the app is told "trust anyone who claims they came through the Cloudflare gateway." That works fine as long as no one can reach the server directly — but if they ever find a side door (a public IP someone forgot to restrict), they can put on a fake Cloudflare badge and walk right in. All the security checks based on where a request comes from become unreliable. The fix is to give the bouncer a specific list of Cloudflare's actual IP addresses instead of "anyone who says so."
    - **Evidence:**
        ```php
        // bootstrap/app.php:43
        // Trust all proxy IPs — the app is exclusively behind Cloudflare, so every
        // inbound connection is from a Cloudflare edge node. Without this, $request->ip()
        // returns the Cloudflare edge IP and all rate-limit keys collapse to the same value.
        $middleware->trustProxies(at: '*');
        ```

- [ ] **HDR-2** · P2 — Exception-handler error responses ship without `Cache-Control: private, no-store`
    - **Where:** bootstrap/app.php:120–175 (exception render closure); app/Http/Middleware/SecureHeaders.php:38–52 (`apply()`)
    - **Affects:** All authenticated API error responses (401, 403, 404 from policy denials, 422 validation, 423 pending-deletion, 429, 5xx). Without an explicit `no-store` directive, browser private caches and some corporate proxy configurations apply heuristic caching to these responses.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `SecureHeaders::apply()`, after the existing `$set` closure is defined, add a Cache-Control block: when `$request->headers->has('Authorization')`, call `$response->headers->set('Cache-Control', 'private, no-store, max-age=0')` and `$response->headers->set('Pragma', 'no-cache')` (use `set` rather than `$set` — the idempotency guard would prevent overwriting a previously-set public value, but no-store should always win on authenticated paths).
        - This fixes both call sites (middleware + exception handler) in one change, since both invoke `SecureHeaders::apply()`.
    - **Technical:** In the normal request flow, `AddPublicCacheHeaders` detects the `Authorization` header and stamps `Cache-Control: private, no-store, max-age=0` plus `Pragma: no-cache` on the response. But when a controller throws an exception, the `withExceptions` render closure in `bootstrap/app.php` constructs a brand-new `response()->json(...)` object and only calls `SecureHeaders::apply()` on it. `AddPublicCacheHeaders::handle()` never runs on this replacement response — it was already part of the original middleware chain that has now been discarded. `SecureHeaders::apply()` sets XFO, CSP, HSTS, and the other headers, but not Cache-Control. RFC 7234 §3.2 prevents *shared* caches from storing responses to requests containing an Authorization header, so CDN/proxy storage is not the primary concern here. The gap is browser private caches, which RFC 7234 does not constrain in the same way, and non-standards-compliant intermediaries.
    - **Plain English:** When a logged-in user hits an error (wrong URL, accessing something they don't own, validation failure), the system sends back an error message but forgets to include the instruction "don't save this." Your browser usually knows not to cache private data when you're logged in — but only if the server says so explicitly. Some corporate networks and shared computers rely on that explicit instruction. Normal successful responses already include it; error responses on the authentication failure path are missing it because they follow a different code path.
    - **Evidence:**
        ```php
        // bootstrap/app.php — exception handler calls SecureHeaders::apply() but not AddPublicCacheHeaders
        if ($response !== null) {
            SecureHeaders::apply($response, $request);
        }
        ```
        ```php
        // app/Http/Middleware/AddPublicCacheHeaders.php — no-store only set here, never reached on exception path
        if ($request->headers->has('Authorization')) {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $this->mergeVary($response, ['Authorization', 'Cookie', 'Accept-Encoding']);
            return $response;
        }
        ```
        ```php
        // app/Http/Middleware/SecureHeaders.php — apply() sets security headers but never Cache-Control
        $set('X-Frame-Options', 'DENY');
        $set('X-Content-Type-Options', 'nosniff');
        $set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        ```

---

## P3 — Nice to have

- [ ] **HDR-3** · P3 — `Permissions-Policy` covers only three directives
    - **Where:** app/Http/Middleware/SecureHeaders.php:51
    - **Affects:** All API consumers — the header governs which browser features the requesting origin may use. Currently only camera, microphone, and geolocation are restricted.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extend the directive to include at minimum `interest-cohort=()` (disables FLoC), `payment=()`, `display-capture=()`, and `fullscreen=()`.
        - Consider adding `accelerometer=()`, `autoplay=()`, `clipboard-write=()`, and `usb=()` for a comprehensive lock-down appropriate to a JSON API.
    - **Technical:** `Permissions-Policy` (formerly `Feature-Policy`) restricts which browser APIs the document's origin may invoke. For a JSON API that serves no HTML, the header still propagates to the client origin making `fetch()` calls and constrains what that origin can do. The current three-directive set is a correct start but leaves over a dozen standardised feature tokens unrestricted. `interest-cohort=()` specifically disables Google's deprecated FLoC cohort assignment. All additions are zero-cost and non-breaking — they restrict APIs this backend never needs.
    - **Plain English:** This header is a "not allowed" sign on the building entrance. Currently it lists three items. The building has no need for any browser hardware at all — adding more items to the sign costs nothing and removes ambiguity about what's permitted. It's the difference between "no cameras, no microphones, no GPS" and "none of the above, plus no payment pop-ups, no screen recording."
    - **Evidence:**
        ```php
        $set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        ```

- [ ] **HDR-4** · P3 — HSTS `max-age` is hardcoded, preventing per-environment tuning
    - **Where:** app/Http/Middleware/SecureHeaders.php:85
    - **Affects:** All HTTPS clients in non-local/non-testing environments. The one-year duration is baked into source with no configuration escape hatch for certificate migrations or staging setups that share the production domain suffix.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `security.hsts_max_age` to `config/partna.php` defaulting to `31536000`.
        - In `SecureHeaders::apply()`, replace the hardcoded value with `config('partna.security.hsts_max_age', 31536000)`.
        - Allow `0` as a valid value (effectively disables HSTS) for staging environments where the domain suffix overlaps production. Document that `preload` is deliberately omitted from the current header value.
    - **Technical:** The HSTS header currently emits `max-age=31536000; includeSubDomains` on every non-local/non-testing response. Changing the duration requires a code edit and deploy. More importantly, `includeSubDomains` means any environment serving this code under a subdomain of the production domain will broadcast a one-year HTTPS requirement to browsers for the full domain tree. Making the value config-driven follows the existing pattern used for every other tuneable in `config/partna.php` and gives operators a safety valve without weakening the production posture. The `preload` flag is correctly absent — its omission is documented in the source and should stay that way until a deliberate submission decision is made.
    - **Plain English:** The system tells every browser "only connect to us over HTTPS for the next full year, no exceptions." That's the right policy for production. But the duration is soldered into the code — if the team ever needs to shorten it temporarily (during a domain migration or while testing a staging environment that shares the production web address), they'd have to edit source code and push a new release rather than flipping a configuration switch. Making it a setting is like moving the timer dial to the outside of the box.
    - **Evidence:**
        ```php
        // HSTS without `preload` — keeping the door reversible. Adding `preload`
        // is a one-way commitment (browsers bake the entry in for months after
        // submission); revisit only when prod is verified HTTPS-only forever.
        $set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        ```
