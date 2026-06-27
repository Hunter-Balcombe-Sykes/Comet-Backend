`★ Insight ─────────────────────────────────────`
The key architectural tension here is that Shopify embedded admin extensions genuinely *cannot* bundle a server secret (the API key would be readable by any merchant in DevTools), so the app has correctly migrated the UI extension routes to Shopify session tokens — but the older setup-wizard routes still carry the shared-key pattern. SEC-1 documents exactly that boundary mismatch. SEC-2 is a second form of the same class of bug: inline key validation that doesn't mirror the fail-closed behavior the dedicated middleware was written to enforce.
`─────────────────────────────────────────────────`

# Security: auth boundaries, tenant isolation, webhooks, secrets, injection, SSRF, PII exposure — 2026-05-11

**Branch:** development
**Lens:** Security: auth boundaries, tenant isolation, webhooks, secrets, injection, SSRF, PII exposure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Middleware/Auth/VerifyEmbeddedApiKey.php
- app/Http/Middleware/Auth/VerifyShopifySessionToken.php
- app/Http/Middleware/Auth/VerifyHydrogenApiKey.php
- app/Http/Middleware/Auth/VerifySupabaseJwt.php
- app/Http/Middleware/Auth/EnsurePartnaAdmin.php
- app/Http/Middleware/Auth/EnsurePartnaStaff.php
- app/Policies/*.php (all 12 policy files)
- app/Providers/AppServiceProvider.php
- app/Http/Controllers/Concerns/*.php
- app/Http/Controllers/Api/Shopify/ShopifyAppOAuthController.php
- app/Http/Controllers/Api/Webhooks/ShopifyOrderWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyGdprWebhookController.php
- app/Http/Controllers/Api/Webhooks/StripeWebhookController.php
- app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php
- app/Http/Controllers/Api/Internal/EmbeddedConnectController.php
- app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php
- app/Http/Controllers/Api/PublicSite/PublicDocumentDownloadController.php
- tests/Feature/Security/PolicyCoverageTest.php
- config/cors.php
- routes/api.php

## Progress

- P0 Blockers: 0 of 1 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 0 complete

---

## P0 — Must fix before any real user touches the system

- [ ] **#SEC-1** · P0 — Setup-wizard embedded routes resolve tenant from client-supplied `X-Shopify-Shop` header with no cryptographic binding to the API key
    - **Where:** app/Http/Middleware/Auth/VerifyEmbeddedApiKey.php:43–58 + routes/api.php:178–193
    - **Affects:** All routes in the `embedded.key` middleware group: `/internal/embedded/deploy`, `/deployment-token`, `/domain/setup`, `/domain/provision-txt`, `/brand-settings`, and eight others. An attacker who extracts `PARTNA_EMBEDDED_API_KEY` from the frontend bundle can forge `X-Shopify-Shop: victim.myshopify.com` and deploy arbitrary storefront changes or alter domain configuration for any connected brand.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Migrate all `embedded.key` setup-wizard routes (lines 178–193 of `routes/api.php`) to the `shopify.session` middleware group (already implemented for the UI extension routes at lines 199–206). The session-token path (`VerifyShopifySessionToken`) binds the shop identity to a Shopify-signed JWT whose `dest` claim cannot be forged independently of the token.
        - The `EmbeddedSetupController` actions need to read the tenant from `$request->attributes->get('embedded_professional_id')` (already the attribute the session-token middleware sets), so no controller changes should be needed.
        - After migration, `VerifyEmbeddedApiKey` is only required for `POST /internal/embedded/connect-account` (pre-association bootstrap), which has its own inline validation. Confirm it can be removed from `bootstrap/app.php` once no route group references `embedded.key`.
    - **Technical:** `VerifyEmbeddedApiKey` performs `hash_equals` on a single shared secret (`PARTNA_EMBEDDED_API_KEY`) then reads the shop domain from a client-controlled `X-Shopify-Shop` request header. There is no cryptographic link between the API key and the header value. Because the setup wizard runs in the Shopify admin iframe, the API key must be present in the JavaScript bundle (the middleware comment for the session-token group explicitly says "extensions can't ship the embedded API key" as the rationale for the different auth path — confirming the key is bundled with the setup wizard). Any merchant opening developer tools can extract the key and forge requests with `X-Shopify-Shop: any-victim.myshopify.com`, gaining full access to the `deployNow` and `saveDeploymentToken` endpoints which can rewrite another brand's Hydrogen storefront. The `VerifyShopifySessionToken` middleware one file away solves this: the JWT `dest` claim is HS256-signed with the app's API secret, so the shop identity cannot be forged without knowing the Shopify app secret (a server-side secret, not bundled).
    - **Plain English:** Imagine a hotel that gives every guest the same master keycard and then asks them which room they want when they swipe it. A guest can just say "penthouse suite" and walk in. The `X-Shopify-Shop` header is that verbal room request — completely separate from the key. Shopify's session tokens are like a keycard where the room assignment is laser-etched in by Shopify and can't be changed by the guest.
    - **Evidence:**
        ```php
        // VerifyEmbeddedApiKey.php
        $provided = (string) str_replace('Bearer ', '', $request->header('Authorization', ''));

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json(['message' => 'Invalid or missing embedded API key.'], 403);
        }

        $shopDomain = strtolower(trim((string) $request->header('X-Shopify-Shop', '')));

        if ($shopDomain === '') {
            return response()->json(['message' => 'Missing X-Shopify-Shop header.'], 400);
        }

        $professionalId = $this->resolver->resolveProfessionalId($shopDomain);
        ```
        ```php
        // routes/api.php:178-193 — routes protected by embedded.key (VerifyEmbeddedApiKey)
        Route::middleware(['embedded.key', 'throttle:60,1'])->prefix('internal/embedded')->group(function () {
            Route::get('/brand-profile', [EmbeddedSetupController::class, 'brandProfile']);
            Route::post('/deployment-token', [EmbeddedSetupController::class, 'saveDeploymentToken']);
            Route::post('/deploy', [EmbeddedSetupController::class, 'deployNow']);
            Route::post('/domain/setup', [EmbeddedSetupController::class, 'setupDomain']);
            // ... 10 additional routes
        });
        ```

---

## P1 — Fix before pilot launch

- [ ] **#SEC-2** · P1 — `EmbeddedConnectController` inline API-key check has a bypass-on-empty path that the dedicated middleware was explicitly written to close
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedConnectController.php:27–34
    - **Affects:** `POST /internal/embedded/connect-account`. If `PARTNA_EMBEDDED_API_KEY` is absent or empty in the production environment (misconfigured deploy), this endpoint accepts any request — the key check is entirely skipped. The endpoint links a Shopify shop domain to a Partna professional account.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the inline `if ($expected !== '')` guard with the same fail-closed logic used in `VerifyEmbeddedApiKey`: if `$expected === ''` and the app is not in `local` or `testing` environment, throw a `RuntimeException` to produce a 500 rather than silently accepting the request.
        - Alternatively, extract a shared method or base-class helper (`assertKeyConfigured()`) so the inline check and the middleware share one implementation and cannot diverge again. The misleading comment ("same check as VerifyEmbeddedApiKey middleware") would then be accurate.
    - **Technical:** `VerifyEmbeddedApiKey::handle()` protects against empty-env deploys with an explicit environment check and a `RuntimeException` that produces a 500 (fail-closed). The inline check in `EmbeddedConnectController` uses `if ($expected !== '')` — when the config value is empty the entire block is skipped and every request proceeds to the connection-code lookup. If `PARTNA_EMBEDDED_API_KEY` is missing from a production deploy, the endpoint accepts any caller who supplies a valid one-time connection code. Codes are Redis-backed with a 30-minute TTL and are consumed on first use (`Cache::pull`), which limits blast radius; however, the auth gate is entirely absent, which violates the security invariant the middleware comment documents.
    - **Plain English:** The main security guard for this door was built with a fail-safe: if the lock isn't configured, it slams shut and throws an alarm. But a side door was built later with the same intent but a different mechanism — if the lock isn't configured, it just leaves the door open instead. The fix is to make the side door behave the same way as the main door.
    - **Evidence:**
        ```php
        // EmbeddedConnectController.php — "same check as VerifyEmbeddedApiKey middleware" (comment is incorrect)
        $expected = (string) config('services.embedded.api_key');
        if ($expected !== '') {
            $provided = (string) str_replace('Bearer ', '', $request->header('Authorization', ''));
            if ($provided === '' || ! hash_equals($expected, $provided)) {
                return $this->error('Invalid or missing embedded API key.', 403);
            }
        }
        // If $expected === '' (empty env), execution falls through — no auth check.
        ```
        ```php
        // VerifyEmbeddedApiKey.php — the correct pattern this controller claims to mirror
        if ($expected === '') {
            if (app()->environment(['local', 'testing'])) {
                return $next($request);
            }
            throw new \RuntimeException(
                'services.embedded.api_key is not configured — refusing to fall through to bypass outside local/testing.'
            );
        }
        ```

---

## P2 — Should fix

- [ ] **#SEC-3** · P2 — `VerifyShopifySessionToken` validates `exp`/`aud`/`dest` but has no JTI replay gate
    - **Where:** app/Http/Middleware/Auth/VerifyShopifySessionToken.php:48–73
    - **Affects:** Routes in the `shopify.session` middleware group: `/internal/embedded/product-settings`, `/internal/embedded/orders/{id}`, `/internal/embedded/products/{id}/analytics`. A session token intercepted from network logs, a browser extension, or a misconfigured proxy can be replayed within its ~60-second validity window.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - After `JWT::decode` succeeds, extract `$claims['jti']` and check it against a short-lived Redis deny-set: `Cache::add("partna:jti:{$jti}", 1, 120)` — returns `false` on duplicate, in which case return 401.
        - TTL of 120 seconds (token lifetime + clock skew leeway) is sufficient; the key auto-expires.
        - If Shopify tokens do not always include `jti`, add a `nbf`-to-`exp` window check as a fallback to at least bound the replay window explicitly.
    - **Technical:** `Firebase\JWT\JWT::decode` validates `exp`, `nbf`, and signature automatically. The middleware additionally verifies `aud` via `hash_equals`. However, Shopify session tokens include a `jti` claim for replay detection, and the middleware never reads it. Without a server-side record of used `jti` values, a captured token can be replayed by anyone within its remaining lifetime. The attack window is narrow (~60 seconds) but real for sensitive mutations like PATCH `/internal/embedded/product-settings` (modifies product metafields directly on Shopify). Redis DB 0 is already in use for similar short-lived idempotency keys.
    - **Plain English:** A Shopify session token is like a single-use entry wristband with a 60-second expiry. The door scanner correctly checks that the wristband hasn't expired, but it doesn't mark the wristband as used after the first scan. If someone photographs the wristband QR code, they can use it again within the same 60-second window. JTI tracking is the equivalent of the scanner marking wristbands as used on first scan.
    - **Evidence:**
        ```php
        try {
            $claims = (array) JWT::decode($token, new Key($secret, 'HS256'));
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Invalid session token.'], 401);
        }

        // Audience must match this app's client ID — guards against tokens
        // issued for a different Shopify app being replayed against ours.
        $aud = (string) ($claims['aud'] ?? '');
        if (! hash_equals($expectedAud, $aud)) {
            return response()->json(['message' => 'Session token audience mismatch.'], 401);
        }

        // Pull the shop domain out of the `dest` claim and normalise.
        // Shape: https://{shop}.myshopify.com (no path).
        $dest = (string) ($claims['dest'] ?? '');
        $shopDomain = strtolower(parse_url($dest, PHP_URL_HOST) ?? '');
        ```

- [ ] **#SEC-4** · P2 — `X-Site-Subdomain` header accepted without CDN-origin validation enables cross-brand analytics pollution
    - **Where:** app/Http/Controllers/Concerns/ResolvesSubdomainFromHost.php:16–19
    - **Affects:** All public analytics ingestion endpoints (pageviews, clicks, lead submissions) that call `resolveSiteSubdomain()`. An attacker can inject analytics events — including lead submissions — attributed to any brand's site by setting `X-Site-Subdomain: victim-brand` in their request.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the `X-Site-Subdomain` header path entirely if no CDN in the production configuration is known to set it. Rely solely on the host-based subdomain extraction (`resolveSubdomainFromHost`) and query parameter fallback, both of which are harder to forge.
        - If a CDN does set this header, add a trusted-proxy IP allow-list check before accepting it: only accept `X-Site-Subdomain` when `$request->ip()` is in the configured CDN IP ranges (Cloudflare publishes theirs; the app already trusts `CF-IPCountry` from the same header family in `DetectsClientInfo`).
        - Note: `ResolvesSiteFromRequest::resolveSiteFromData()` does cross-check `site_id` against `subdomain` when both are present — but public analytics payloads commonly omit `site_id`, falling through to the subdomain-only path.
    - **Technical:** `resolveSiteSubdomain()` consults the `X-Site-Subdomain` header before all other resolution strategies. This is a custom header set by client-side JavaScript (via the frontend's fetch calls) and not a browser-enforced header — it is trivially spoofable by direct HTTP requests. The host-based resolution (`resolveSubdomainFromHost`) validates against `config('partna.public_domain')`, providing structural trust; the header path provides none. Lead submissions that land under the wrong brand's site create support noise and could distort conversion metrics used to calculate affiliate commissions.
    - **Plain English:** Analytics data is attributed to a brand by reading a label the request itself includes. Any tool that can make HTTP requests (curl, Postman, a script) can put any brand's name on that label and submit fake visits or lead forms. The host-based approach is harder to fake because it relies on the DNS name of the server the request actually arrived at, not a field the caller writes themselves.
    - **Evidence:**
        ```php
        protected function resolveSiteSubdomain(Request $request): ?string
        {
            $fromHeader = trim((string) $request->header('X-Site-Subdomain', ''));
            if ($fromHeader !== '') {
                return strtolower($fromHeader);
            }

            foreach (['subdomain', 'slug'] as $key) {
                $fromQuery = trim((string) $request->query($key, ''));
                if ($fromQuery !== '') {
                    return strtolower($fromQuery);
                }
                $fromInput = trim((string) $request->input($key, ''));
                if ($fromInput !== '') {
                    return strtolower($fromInput);
                }
            }

            $fromHost = $this->resolveSubdomainFromHost($request);

            return $fromHost ? strtolower($fromHost) : null;
        }
        ```
