I now have all the evidence I need. Here is my verified picture before writing:

- **SEC-1 confirmed P0**: `/internal/embedded/connect-account` is registered standalone (only `throttle:10,1`, no `embedded.key`). The controller does its own inline `if ($expected !== '')` check — the old bypass-on-empty pattern. `VerifyEmbeddedApiKey` middleware has the correct fail-closed behavior (`app()->environment(['local','testing'])` guard + `RuntimeException`) but this route can't use that middleware by design. The inline copy is wrong.
- **SEC-2 re-tiered P1**: `VerifyHydrogenApiKey` is now properly fail-closed (confirmed from source). Blast-radius is real but requires key leak. P1.
- **SEC-3 confirmed P1**: DNS-rebinding TOCTOU confirmed from source.
- **SEC-4 confirmed P1**: Unverified token confirmed from source.
- **SEC-6 DROP**: `HydrogenBrandConfigController` is inside `Route::middleware(['hydrogen.key', 'throttle:hydrogen-internal'])` group at `routes/api.php:209–216`. Finding is factually wrong.
- **SEC-5 original DROP**: Rate limiting on `/internal/*` — always-drop category.
- **SEC-7 → renumber SEC-5, P2**: Catch-all regex confirmed.
- **SEC-8 DROP**: Affiliate gallery images are intentionally public storefront content; confidence 0.7, no real security issue.

`★ Insight ─────────────────────────────────────`
The most instructive pattern here is the **dual-implementation divergence** in SEC-1: a correct, fail-closed version exists in `VerifyEmbeddedApiKey` middleware but the one route that can't use that middleware has a hand-rolled copy that silently bypasses on empty config. This is a textbook "copy-paste security" failure — any time you replicate auth logic outside a shared gate, the copies tend to drift over time. The fix is exactly to mirror the middleware's environment guard and RuntimeException path.
`─────────────────────────────────────────────────`

# Security Audit — 2026-05-11

**Branch:** development
**Lens:** Security: auth boundaries, tenant isolation, webhooks, secrets, injection, SSRF, PII exposure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/Internal/EmbeddedConnectController.php
- app/Http/Controllers/Api/Internal/EmbeddedOrderAnalyticsController.php
- app/Http/Controllers/Api/Internal/EmbeddedProductAnalyticsController.php
- app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php
- app/Http/Controllers/Api/Internal/EmbeddedSetupController.php
- app/Http/Controllers/Api/Internal/HydrogenAffiliateController.php
- app/Http/Controllers/Api/Internal/HydrogenAffiliateProductsController.php
- app/Http/Controllers/Api/Internal/HydrogenBrandConfigController.php
- app/Http/Controllers/Api/Internal/HydrogenBrandDesignController.php
- app/Http/Controllers/Api/Internal/HydrogenDeploymentController.php
- app/Http/Controllers/Api/Professional/ShopifyIntegration/ShopifyIntegrationController.php
- app/Http/Middleware/Auth/VerifyHydrogenApiKey.php
- app/Http/Middleware/Auth/VerifyEmbeddedApiKey.php
- app/Http/Middleware/Auth/VerifyShopifySessionToken.php
- routes/api.php

## Progress

- P0 Blockers: 0 of 1 complete
- P1 High: 0 of 3 complete
- P2 Medium: 0 of 1 complete

---

## P0 — Must fix before any real user touches the system

- [ ] **#SEC-1** · P0 — EmbeddedConnectController inline API-key check bypasses auth when env var is empty
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedConnectController.php (connect method, auth block)
    - **Affects:** The `/internal/embedded/connect-account` endpoint — the only route that links a Shopify shop to a Partna brand account. With an empty env var any caller who discovers the endpoint can pair arbitrary shops to brand accounts, or consume another brand's connection code (e.g. if it leaked via logs or a compromised browser session).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the inline `if ($expected !== '')` guard with the same fail-closed logic used by `VerifyEmbeddedApiKey`: allow bypass only in `app()->environment(['local', 'testing'])`, throw `\RuntimeException` otherwise.
        - Add a deploy-time boot assertion in `AppServiceProvider::boot()` that throws if `services.embedded.api_key` is empty in production, matching the pattern already enforced for the Hydrogen key.
    - **Technical:** The `/internal/embedded/connect-account` route is registered outside the `embedded.key` middleware group (`routes/api.php:174`) with only `throttle:10,1`, for a documented reason: the shop hasn't been linked yet so the middleware (which resolves `embedded_professional_id` from the shop) can't run. The controller therefore does its own inline key check — but uses the old bypass-on-empty pattern: `if ($expected !== '') { … }`. If `PARTNA_EMBEDDED_API_KEY` is absent from the deployment environment, the entire block is skipped and the endpoint is completely unauthenticated. `VerifyEmbeddedApiKey` (committed on a recent prior cycle) already solves this correctly: check `app()->environment(['local', 'testing'])` first, throw `\RuntimeException` if the key is missing elsewhere. The inline copy never received that fix. The connection code stored in Redis provides a second factor (30-minute TTL, single-use `Cache::pull`), but without the API key gate an attacker can race any active code or attempt brute-force within the throttle window.
    - **Plain English:** There's a special door for pairing a Shopify store to a Partna account. The main keypad system (shared by the rest of the building) handles this correctly — if there's no key configured, it raises an alarm rather than just leaving the door open. But this particular door has a hand-copied keypad that was written before the alarm feature was added, and nobody updated the copy. If the building manager forgets to set the combination on this door during a deployment, anyone can walk in. The fix is to update the hand-copied keypad to behave the same way as the main system.
    - **Evidence:**
        ```php
        // EmbeddedConnectController::connect() — inline auth check
        $expected = (string) config('services.embedded.api_key');
        if ($expected !== '') {
            $provided = (string) str_replace('Bearer ', '', $request->header('Authorization', ''));
            if ($provided === '' || ! hash_equals($expected, $provided)) {
                return $this->error('Invalid or missing embedded API key.', 403);
            }
        }

        // VerifyEmbeddedApiKey::handle() — correct fail-closed version (reference)
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

## P1 — Fix before pilot launch

- [ ] **#SEC-2** · P1 — HydrogenDeploymentController returns all brands' Oxygen tokens behind a single static key
    - **Where:** app/Http/Controllers/Api/Internal/HydrogenDeploymentController.php (targets method)
    - **Affects:** Every brand with a stored Oxygen deployment token. If the single `HYDROGEN_API_KEY` CI secret leaks (GitHub Actions log exposure, compromised runner, repository secret scan miss), an attacker retrieves all brands' decrypted Oxygen deployment tokens in one request and can push arbitrary code to every brand's Shopify Hydrogen storefront.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add GitHub Actions OIDC verification as a second check alongside the static key for the deployment-targets endpoint: the workflow requests a short-lived OIDC token, the backend verifies it against GitHub's JWKS before returning tokens.
        - As a shorter-term measure, document and enforce a `HYDROGEN_API_KEY` rotation runbook — if it leaks, the blast radius is all brands until the key is rotated.
        - Consider separating the "single brand deploy" path (triggered by the wizard) from the "all brands deploy" path (triggered by a code push) with different scoping — the single-brand path can verify the caller is authorised for that specific brand, reducing the blast radius of a leaked key.
    - **Technical:** `VerifyHydrogenApiKey` is now properly fail-closed (it throws `\RuntimeException` when the key is unconfigured outside local/testing — the prior bypass-on-empty has been fixed). The remaining concern is the blast-radius design: a single bearer secret grants access to a response containing every brand's decrypted `oxygen_deployment_token`. These tokens authorize code deployments to Shopify Oxygen, the CDN-level runtime that serves affiliate storefronts. A leaked CI secret (GitHub Actions secret exposure is a documented real-world threat) maps to a one-request total storefront compromise for all brands. GitHub Actions OIDC (`actions/github-token`) enables cryptographically verifiable, per-workflow-run short-lived tokens that eliminate the static bearer secret entirely.
    - **Plain English:** The deployment pipeline has a master key that, if someone finds it, unlocks every brand's storefront at once. The key is stored in the CI system's secret vault, which is well-protected — but secrets stored in CI systems have leaked before (misconfigured logs, third-party actions). The fix is to replace the permanent master key with a temporary visitor pass that GitHub generates fresh for each deployment run, which can't be reused after the deployment finishes.
    - **Evidence:**
        ```php
        public function targets(Request $request): JsonResponse
        {
            $query = BrandStoreSettings::query()
                ->whereNotNull('oxygen_deployment_token');

            if ($professionalId = $request->query('professional_id')) {
                $query->where('professional_id', $professionalId);
            }

            $settings = $query->get(['professional_id', 'oxygen_deployment_token', 'oxygen_storefront_id']);

            $targets = $settings->map(function (BrandStoreSettings $row) {
                // ...
                return [
                    // Decrypted by the encrypted cast — never stored in plain text
                    'oxygen_deployment_token' => $row->oxygen_deployment_token,
                    'oxygen_storefront_id' => $row->oxygen_storefront_id,
                ];
            })->values();

            return $this->success($targets);
        }
        ```

- [ ] **#SEC-3** · P1 — resolveShop SSRF guard has DNS-rebinding TOCTOU window
    - **Where:** app/Http/Controllers/Api/Professional/ShopifyIntegration/ShopifyIntegrationController.php (isPrivateHost, discoverShopifyHandle methods)
    - **Affects:** Authenticated brand professionals and staff using the `/shopify/resolve-shop` endpoint. An attacker with a valid Partna account and control over a DNS record can probe internal infrastructure (including the AWS metadata endpoint at 169.254.169.254 and RFC1918 services) during the window between the IP validation check and the HTTP request.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - After resolving the hostname in `isPrivateHost()`, pass the pre-validated IP directly to Guzzle via `CURLOPT_RESOLVE` so the connection uses the same IP that was checked: `Http::withOptions(['curl' => [CURLOPT_RESOLVE => ["{$host}:443:{$resolvedIp}"]]])`.
        - Alternatively, call `gethostbynamel()` once inside `discoverShopifyHandle()` and validate the IP immediately before the HTTP call — no separate `isPrivateHost()` call.
        - Return the pre-resolved IP from `isPrivateHost()` and thread it through to the Guzzle call to eliminate the second DNS lookup entirely.
    - **Technical:** `isPrivateHost()` calls `gethostbynamel()` and validates every returned IP against `FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE`. `discoverShopifyHandle()` then calls `Http::get("https://{$host}/")` which triggers a second, independent DNS resolution. A DNS record with a TTL of 0 (or TTL ≤ the time between the two calls) can return a public IP during the check and a private IP (e.g., `169.254.169.254`) during Guzzle's resolution. The `allow_redirects => false` option correctly blocks redirect-based SSRF but does not close the rebinding window — the second DNS call is not redirect-based. The fix is to ensure a single DNS resolution serves both the validation and the HTTP connection.
    - **Plain English:** The code checks a destination address in an address book before sending a package — but then lets the delivery driver look up the address again from a potentially different phone book. Between checking and delivering, a clever attacker can swap what the phone book says. The fix is to write the verified address directly on the package so the driver uses that address and ignores any other lookup.
    - **Evidence:**
        ```php
        // isPrivateHost() — DNS resolved here for the safety check
        $ips = gethostbynamel($host);
        // ...
        foreach ($ips as $ip) {
            if ($this->ipIsBlocked($ip)) {
                return true;
            }
        }
        return false;

        // discoverShopifyHandle() — separate Http call triggers a second DNS resolution
        $url = "https://{$host}/";
        $response = Http::timeout(6)
            ->connectTimeout(4)
            ->withOptions(['allow_redirects' => false])
            ->get($url);
        ```

- [ ] **#SEC-4** · P1 — provisionShopifyIntegration stores an OAuth access token supplied by the client without server-side verification
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedSetupController.php (provisionShopifyIntegration method)
    - **Affects:** Brands completing the embedded setup wizard. A buggy embedded app, or a token issued for shop A being replayed against a session authenticated as shop B, causes the backend to store a mismatched access token — breaking catalog sync, webhook registration, and every Shopify Admin API job that follows.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Before storing the token, make one lightweight Shopify Admin API call (e.g., `GET /admin/api/{version}/shop.json`) using the provided token. Verify the response is HTTP 200 and that `shop.myshopify_domain` matches the `shopDomain` derived from the `X-Shopify-Shop` header.
        - Return a clear 422 if the verification call fails or the domain mismatches, so the embedded app can prompt the brand to restart OAuth rather than silently failing downstream jobs.
    - **Technical:** The embedded app completes Shopify OAuth and sends the resulting `access_token` directly in the request body. The `VerifyEmbeddedApiKey` middleware correctly resolves `embedded_professional_id` from the `X-Shopify-Shop` header, but neither the middleware nor the controller validates that the submitted token was issued for the shop in the header. Both values originate from the untrusted client. A cross-shop token replay (token for `shop-a.myshopify.com` submitted to a session resolved for `shop-b.myshopify.com`) passes all current checks and stores a token that will silently fail every downstream Shopify API call. This is a vendor-callback trust-boundary failure (category 11): the backend trusts the client to have performed OAuth correctly rather than verifying it.
    - **Plain English:** The setup wizard says "I went to Shopify and got this key for your store." The backend says "thanks, I'll store that" without ever testing whether the key actually opens that store's lock. If the wizard has a bug, or if someone cleverly sends the key for the wrong store, the backend stores a broken key and the setup silently fails. The fix is a single test — try the key in the lock before storing it.
    - **Evidence:**
        ```php
        $data = $request->validate([
            'access_token' => ['required', 'string', 'max:512'],
            'shop_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'scopes' => ['sometimes', 'nullable', 'string', 'max:4096'],
        ]);
        // ...token stored directly without any Shopify API verification:
        $integration = ProfessionalIntegration::updateOrCreate(
            [
                'professional_id' => $professionalId,
                'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY,
            ],
            [
                'external_account_id' => $shopDomain,
                'access_token' => $data['access_token'],
                'last_catalog_sync_error' => null,
                'provider_metadata' => $metadata,
            ],
        );
        ```

---

## P2 — Should fix

- [ ] **#SEC-5** · P2 — resolveShop catch-all regex matches myshopify.com strings anywhere in scraped HTML
    - **Where:** app/Http/Controllers/Api/Professional/ShopifyIntegration/ShopifyIntegrationController.php (discoverShopifyHandle method, patterns array)
    - **Affects:** Authenticated brand professionals using the `/shopify/resolve-shop` endpoint with a custom-domain store. A storefront that mentions another brand's `.myshopify.com` domain in user-generated content (blog post, product description, review, meta tag) can cause the resolver to return the wrong canonical domain, potentially leading a brand to initiate OAuth against the wrong shop.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the third catch-all pattern `'/([a-z0-9][a-z0-9-]*\.myshopify\.com)/i'` or tighten it to require surrounding quote delimiters: `'/["\']([a-z0-9][a-z0-9-]*\.myshopify\.com)["\']/i'`.
        - The two structured patterns above it (`Shopify.shop = "..."` and `"shop":"..."`) are sufficient for well-formed Shopify themes and carry far lower false-positive risk.
    - **Technical:** The first two patterns require the match to appear in a specific Shopify JavaScript context (`Shopify.shop = "..."`) or a JSON key (`"shop":"..."`), which are both controlled by Shopify's theme rendering engine. The third pattern `'/([a-z0-9][a-z0-9-]*\.myshopify\.com)/i'` matches any occurrence anywhere in the response body — including user-generated content, embedded reviews, competitor mentions, or HTML comments. Since `resolveShop` is the entry point for the OAuth flow, returning the wrong shop domain redirects the brand's OAuth initiation to a shop they don't own. The brand would then receive an "access denied" from Shopify, but not before Partna has logged and potentially cached the wrong domain. The fix has zero cost to legitimate cases: the two structured patterns already cover well-formed Shopify themes.
    - **Plain English:** The code tries to find a store's official name by reading its website. The first two methods look in the right places — the official store registry embedded in the page by Shopify. The third method just searches the entire page for anything that looks like a Shopify store name, including someone mentioning a competitor's store in a product review. The fix is to stop using the third method, since the first two are already sufficient.
    - **Evidence:**
        ```php
        $patterns = [
            '/Shopify\.shop\s*=\s*["\']([a-z0-9][a-z0-9-]*\.myshopify\.com)["\']/i',
            '/["\']shop["\']\s*:\s*["\']([a-z0-9][a-z0-9-]*\.myshopify\.com)["\']/i',
            '/([a-z0-9][a-z0-9-]*\.myshopify\.com)/i',   // ← catch-all: matches anywhere in body
        ];
        ```
