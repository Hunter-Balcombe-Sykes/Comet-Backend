I now have all the evidence needed. Let me write the final adjudicated audit.

`★ Insight ─────────────────────────────────────`
The most critical missed finding (P0) is in the Shopify OAuth callback: Shopify's `shop.json` returns a *contact email* field that is freely editable by any store owner without verification. Partna auto-connects this email to an existing professional account, creating a zero-authentication account-takeover path. This is a classic trust-confusion bug — the OAuth flow proves "this is a real Shopify store," but not "this store belongs to the Partna account that owns that email."
`─────────────────────────────────────────────────`

# Security Audit — 2026-05-11

**Branch:** development
**Lens:** Security: auth boundaries, tenant isolation, webhooks, secrets, injection, SSRF, PII exposure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Middleware/Auth/VerifyHydrogenApiKey.php
- app/Http/Middleware/Auth/VerifyShopifySessionToken.php
- app/Http/Middleware/Auth/VerifyEmbeddedApiKey.php
- app/Http/Controllers/Api/Shopify/ShopifyAppOAuthController.php
- app/Http/Controllers/Api/Webhooks/ShopifyOrderWebhookController.php
- app/Http/Controllers/Api/Webhooks/StripeWebhookController.php
- app/Http/Controllers/Concerns/ValidatesShopifyWebhookHmac.php
- app/Http/Controllers/Concerns/NormalizesShopDomain.php
- app/Http/Controllers/Api/Internal/EmbeddedSetupController.php
- app/Http/Controllers/Api/Internal/EmbeddedConnectController.php
- app/Services/Shopify/Client/ShopifyAdminClient.php
- app/Services/Shopify/ShopifyTeardownService.php
- app/Services/Shopify/BrandSignupService.php
- app/Services/Shopify/BrandDesignImporter.php
- app/Services/Shopify/ShopifySetupTokenService.php
- app/Services/Shopify/ShopifyDataResyncService.php
- app/Services/Cloudflare/CloudflareDnsService.php
- app/Services/Cloudflare/CloudflareKvService.php
- app/Services/Hydrogen/HydrogenDeploymentService.php
- app/Services/Auth/SupabaseAdminService.php
- app/Providers/AppServiceProvider.php
- routes/api.php
- config/cors.php

## Progress

- P0 Blockers: 0 of 1 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 1 complete

---

## P0 — Must fix before any real user touches the system

- [ ] **#SEC-1** · P0 — Shopify OAuth email match enables zero-authentication takeover of any existing brand's integration
    - **Where:** app/Http/Controllers/Api/Shopify/ShopifyAppOAuthController.php:151–166 (Path B); app/Services/Shopify/BrandSignupService.php:handleExistingBrandConnect
    - **Affects:** Every existing Partna brand account. An attacker can overwrite any brand's Shopify integration (including their access token, shop domain, webhook registrations) by creating a free Shopify store, setting its "contact email" to the target brand's Partna email, then triggering the OAuth install flow — no password, no confirmation, no authentication required.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Remove the email-based auto-match path entirely (Path B). Require the user to be authenticated via Supabase JWT when associating a new Shopify shop with an existing account.
        - On the OAuth callback: if no matching integration exists for the shop domain (Path A), store a setup token (as Path C already does) and redirect the user to the dashboard to log in and explicitly authorize the connection.
        - Alternatively, if maintaining a "first install from Shopify admin" UX is required, issue a short-lived one-time verification link to the store's email address and require the brand to click it before associating the account — this adds one-way proof that the Partna account owner also controls that email inbox.
        - Audit the `handleExistingBrandConnect` callers to ensure no other code path can connect a shop to a professional without explicit authorization.
    - **Technical:** `$shopEmail` is read from Shopify's `shop.json` response via `Arr::get($shopData, 'email')`. This is the store's *contact email* field in Shopify admin, which any merchant can set to any string without email verification. Partna then calls `Professional::whereRaw('lower(primary_email) = ?', [$shopEmail])` and if a match is found, calls `handleExistingBrandConnect` which executes `ProfessionalIntegration::updateOrCreate([...], ['access_token' => $accessToken, 'provider_metadata' => [...])`. This overwrites the victim brand's existing Shopify integration with the attacker's shop credentials. The OAuth HMAC and state/nonce checks are both correct; they prove "this is a real Shopify OAuth flow" but offer no assurance that the shop belongs to the matched Partna account. `VerifyHydrogenApiKey`, `VerifyShopifySessionToken`, and `VerifyEmbeddedApiKey` are all uninvolved — this bypass operates on the unauthenticated OAuth callback.
    - **Plain English:** When someone installs the Partna Shopify app, Partna checks "does our system already know this store's email?" and if so, automatically links the store to that account — without asking for a password or any confirmation. Shopify doesn't lock down what email a merchant puts on their store. So a bad actor can create a free Shopify store, type any brand's email into the store settings, click "Install Partna," and silently take over that brand's account connection. It's like a hotel automatically handing your room key to anyone who claims to share your last name — without checking ID. The fix is to require the brand to log in and confirm the connection themselves.
    - **Evidence:**
        ```php
        $shopEmail = strtolower(trim((string) Arr::get($shopData, 'email', '')));
        // ...
        // Path B: Existing account — shop email matches a Professional's primary_email
        if ($shopEmail !== '') {
            $existingProfessional = Professional::whereRaw('lower(primary_email) = ?', [$shopEmail])->first();

            if ($existingProfessional) {
                $result = $this->brandSignup->handleExistingBrandConnect(
                    $existingProfessional, $shop, $accessToken, $shopData, $scopes
                );
                // ...
                return redirect()->away($basePath);
            }
        }
        ```

---

## P1 — Fix before pilot launch

- [ ] **#SEC-2** · P1 — VerifyEmbeddedApiKey resolves tenant identity from an unsigned HTTP header; any API-key holder can impersonate any brand
    - **Where:** app/Http/Middleware/Auth/VerifyEmbeddedApiKey.php:46–64; routes/api.php:178–193 (embedded.key group)
    - **Affects:** All brands using the embedded setup wizard. The `embedded.key` middleware group protects high-impact routes: saving brand identity and details, updating settings, provisioning deployment tokens, triggering Hydrogen deploys, and setting up Cloudflare DNS. A party that obtains the `PARTNA_EMBEDDED_API_KEY` can access any brand's wizard endpoints by setting `X-Shopify-Shop` to any shop domain.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - The `shopify.session` middleware (which already exists and is used for UI extension routes on lines 199–206 of routes/api.php) extracts the shop domain from the cryptographically-signed JWT `dest` claim — this is the correct pattern. Migrate the setup wizard routes to `shopify.session` if the embedded app can obtain an App Bridge session token.
        - If the server-side wizard flow genuinely cannot produce a session token (e.g., non-browser worker context), validate that the `X-Shopify-Shop` domain matches an existing integration row that is already associated with the embedded API key's intended shop, and add a per-brand scoped key or connection-code as a second factor.
        - Document the key rotation procedure. A compromised `PARTNA_EMBEDDED_API_KEY` must be treated as a full multi-tenant compromise requiring immediate rotation.
    - **Technical:** `VerifyEmbeddedApiKey::handle` authenticates the *client application* (correct — `hash_equals` on the API key), then resolves the tenant from the separate `X-Shopify-Shop` request header: `$shopDomain = strtolower(trim((string) $request->header('X-Shopify-Shop', '')))`. The tenant (professional ID) flows from this header through `ShopifyShopResolver::resolveProfessionalId()` and is written to `request->attributes->set('embedded_professional_id', $professionalId)`. `EmbeddedSetupController` then reads this attribute with no further policy gate: `$professionalId = (string) $request->attributes->get('embedded_professional_id')`. Per the Partna authorization doctrine: "the resolution must be cryptographically tied to the token, not a separate field." `VerifyShopifySessionToken` (used for UI extension routes) satisfies this by extracting the shop from the JWT's `dest` claim; `VerifyEmbeddedApiKey` does not.
    - **Plain English:** The setup wizard uses a single shared password (the embedded API key) to let the Sidest app connect to any brand's account. The way it knows *which* brand is through a separate field in the request that any caller can freely set. It's like a master key that opens any room in a hotel, with the room number written in pencil on the key — you can erase the pencil and write any other room number. If that master key is ever copied or leaked, every brand's wizard settings are accessible.
    - **Evidence:**
        ```php
        // VerifyEmbeddedApiKey.php
        $shopDomain = strtolower(trim((string) $request->header('X-Shopify-Shop', '')));

        if ($shopDomain === '') {
            return response()->json(['message' => 'Missing X-Shopify-Shop header.'], 400);
        }

        $professionalId = $this->resolver->resolveProfessionalId($shopDomain);
        // ...
        $request->attributes->set('embedded_professional_id', $professionalId);
        ```

- [ ] **#SEC-3** · P1 — ShopifyAdminClient builds API URLs from `$shopDomain` with no domain validation; two call sites skip `.myshopify.com` format check before passing to the client
    - **Where:** app/Services/Shopify/Client/ShopifyAdminClient.php:126–127 (rest), 272–273 (post/graphql); app/Services/Shopify/ShopifyTeardownService.php:82–90; app/Services/Shopify/BrandSignupService.php:revokeStorefrontToken (~line 107–115)
    - **Affects:** The teardown flow (dashboard "Disconnect" button) and the reinstall storefront-token revocation path. A corrupted or injected `provider_metadata.shop_domain` value — set via a compromised DB, a staff-level write, or the SEC-1 bypass — would cause the Shopify client to make HTTP requests to an arbitrary host, including internal metadata endpoints (169.254.169.254).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a domain validation guard at the top of both `graphql()` and `rest()` in `ShopifyAdminClient`: assert `$shopDomain` matches `/^[a-z0-9][a-z0-9\-]*\.myshopify\.com$/` and throw a typed `ShopifyTransportException` (or new `InvalidShopDomainException`) before any HTTP call is made.
        - This matches the validation already performed in `BrandDesignImporter::import` and `ShopifyDataResyncService::resync` — centralise it in the client so every call site is protected regardless of whether the caller validates.
    - **Technical:** `rest()` constructs `$url = "https://{$shopDomain}{$path}"` and `post()` constructs `"https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json"` with no format check on `$shopDomain`. `ShopifyTeardownService::teardownForIntegration` reads the domain directly from `provider_metadata` with `trim((string) Arr::get($metadata, 'shop_domain', ''))` and only checks for empty string before calling the client. `BrandSignupService::revokeStorefrontToken` does the same. In contrast, `BrandDesignImporter::import` and `ShopifyDataResyncService::resync` both perform `preg_match('/^[a-z0-9\-]+\.myshopify\.com$/', $shopDomain)` before using the client — the validation is inconsistently caller-side. Making it the client's responsibility eliminates the entire class of SSRF risk from this service regardless of future callers.
    - **Plain English:** The Shopify API client is like a taxi that will drive to any address given to it, no questions asked. Most passengers (callers) double-check the address before getting in, but two don't. If the address on file for a store ever gets corrupted to something like "the company's private network" or "169.254.169.254" (a special internal address that cloud servers use to access sensitive configuration), the taxi will drive there. The fix is to teach the taxi driver to reject any address that isn't a real Shopify store URL.
    - **Evidence:**
        ```php
        // ShopifyAdminClient.php — rest()
        $url = "https://{$shopDomain}{$path}";

        // ShopifyTeardownService.php — no .myshopify.com check before calling client
        $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
        $accessToken = trim((string) $integration->access_token);
        // ...
        if ($shopDomain === '' || $accessToken === '') {
            $summary['errors'][] = 'missing_credentials';
            return $summary;
        }
        // (domain is immediately passed to $this->client->rest() calls below)
        ```

---

## P2 — Should fix

- [ ] **#SEC-4** · P2 — SupabaseAdminService logs raw user email on Supabase account creation failure
    - **Where:** app/Services/Auth/SupabaseAdminService.php:96–103
    - **Affects:** Any user whose Supabase account creation fails during signup. Their email address is persisted in Laravel logs, Nightwatch, and any centralized log aggregator — where retention is typically months, not seconds.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `'email' => $email` in the error log context with a one-way keyed hash: `'email_hash' => hash_hmac('sha256', $email, config('app.key'))`. This remains correlatable to a support ticket without storing the raw address.
        - Review other log call sites in the auth and onboarding flow for the same pattern.
    - **Technical:** `Log::error('Supabase admin: failed to create user', ['email' => $email, ...])` emits the raw email to the configured log channel on any non-422/409 failure. In production this streams to Nightwatch and potentially to a third-party log aggregator where it persists far beyond the ephemeral request. Under GDPR / Privacy Act (AU), storing email addresses in log infrastructure without user consent and without a defined retention policy is a reportable data handling incident. The 422/409 paths that return an existing user's data do not log the email — this inconsistency confirms the log line was added for debugging and never reviewed for PII.
    - **Plain English:** When a user signs up and something goes wrong on Supabase's side, we write their email address into our permanent error logs. It's like a customer-service rep noting a customer's email on a sticky note and dropping it in a shared filing cabinet accessible to anyone in the office — and never taking it out. We should write a scrambled fingerprint instead: something we can match to a support ticket without ever storing the actual address.
    - **Evidence:**
        ```php
        Log::error('Supabase admin: failed to create user', [
            'email' => $email,
            'status' => $response->status(),
            'error_code' => $response->json('code'),
            'error_msg' => $response->json('msg'),
        ]);
        ```

- [ ] **#SEC-5** · P2 — ShopifyAdminClient::rest() path parameter is unenforced despite docblock contract
    - **Where:** app/Services/Shopify/Client/ShopifyAdminClient.php:110–127
    - **Affects:** Any future caller that passes a user-influenced `$path` value. All current callers use hardcoded `/admin/` paths, so there is no immediate exploitation — but the client is the safety boundary for every path that flows through it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a guard at the start of `rest()`: `if (!str_starts_with($path, '/admin/')) { throw new \InvalidArgumentException("rest() path must start with /admin/: {$path}"); }`. This enforces the documented contract in code rather than a comment.
        - Also reject paths containing `..` or null bytes as a secondary guard.
    - **Technical:** The docblock states `@param string $path must start with /admin/...` but `rest()` immediately constructs `$url = "https://{$shopDomain}{$path}"` without any check. Combined with the missing domain validation in SEC-3, a caller with attacker-influenced input on both dimensions could direct the client to any URL. Current callers (teardown, reinstall revocation, resync, design import) all use hardcoded `/admin/api/{$version}/...` paths — the risk is prospective, not current. Enforcing the contract in code prevents it from being a future SSRF amplifier when new callers are added under time pressure.
    - **Plain English:** There's a sign on the REST client's door that says "only admin paths allowed" but there's no actual check — the sign is just a comment. Right now every caller respects it, but nothing stops a future developer from accidentally (or unknowingly) passing a different path. The fix adds a real doorman who checks the path before letting the request through.
    - **Evidence:**
        ```php
        /**
         * @param  string  $path  must start with `/admin/...`
         * ...
         */
        public function rest(
            string $method,
            string $shopDomain,
            string $accessToken,
            string $path,
            // ...
        ): Response {
            $timeout = $timeoutSeconds ?? (int) config('services.shopify.throttle.default_timeout', 20);
            $maxRetries = (int) config('services.shopify.throttle.max_inprocess_retries', 3);
            $url = "https://{$shopDomain}{$path}";
        ```

- [ ] **#SEC-6** · P2 — CloudflareDnsService logs full Cloudflare API response body on every error
    - **Where:** app/Services/Cloudflare/CloudflareDnsService.php — `ensureCname` (~line 82–88), `upsertCname` (~lines 115–121, 130–136), `upsertTxt` (~lines 160–164, 175–180), `deleteRecord` (~line 199–204)
    - **Affects:** Any failed Cloudflare DNS API call. Cloudflare error responses can include zone identifiers, record details, and internal diagnostic fields that should not live in a shared log store.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `'body' => $response->body()` with `'cf_errors' => $response->json('errors', [])` in all error log calls. Cloudflare structures errors as `[{"code": N, "message": "..."}]` — logging only `errors` captures the actionable diagnostic without the full response.
        - Apply the same fix to `deleteRecord`'s warning log.
    - **Technical:** Every `Log::error` and `Log::warning` branch in `CloudflareDnsService` includes the raw `$response->body()`. Cloudflare's DNS API responses can include zone-scoped identifiers and internal diagnostic metadata beyond the top-level `errors` array. These persist in Nightwatch/log aggregators. The structured `errors` field from `$response->json('errors', [])` contains everything needed for debugging and is safe to log.
    - **Plain English:** When a DNS operation fails, the service files Cloudflare's entire reply into our permanent logs — like photocopying a sensitive document and putting it in a public archive. Cloudflare's reply contains the specific error code and message we actually need for debugging, but it may also contain other details about our DNS zone configuration. We should save only the error code and message, not the entire reply.
    - **Evidence:**
        ```php
        if (! $response->successful()) {
            Log::error('CloudflareDnsService: failed to create CNAME record.', [
                'name' => $name,
                'target' => $target,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return null;
        }
        ```

- [ ] **#SEC-7** · P2 — HydrogenDeploymentService logs full GitHub API response body on non-2xx
    - **Where:** app/Services/Hydrogen/HydrogenDeploymentService.php:42–46
    - **Affects:** Any failed GitHub Actions workflow dispatch for a Hydrogen deployment. GitHub error responses can include repository metadata, workflow configuration references, and rate-limit details.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `'body' => $response->body()` with `'github_message' => $response->json('message')` — the GitHub API consistently puts the human-readable error in `message`, which is all that's needed for alerting.
    - **Technical:** `Log::warning('HydrogenDeployment: GitHub API returned non-2xx.', ['body' => $response->body()])` writes the raw response body to the log. GitHub API errors can contain repository-internal detail such as workflow file paths, branch names, and repository URLs. While not directly a secret leak, these are operational details that should not be in a general-purpose log store accessible to all Nightwatch users. The GitHub PAT itself is not returned in error responses, so there is no token leak risk — but the principle of minimal log payload applies.
    - **Plain English:** When a Hydrogen deployment fails to trigger, we write GitHub's full error message — including details about our deployment pipeline and repository — into our permanent logs. We only need the one-line error description to diagnose the problem. Filing the entire document is unnecessary and puts internal pipeline details somewhere they don't need to be.
    - **Evidence:**
        ```php
        } else {
            Log::warning('HydrogenDeployment: GitHub API returned non-2xx.', [
                'professional_id' => $professionalId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }
        ```

---

## P3 — Nice to have

- [ ] **#SEC-8** · P3 — ShopifySetupTokenService stores `shop_email` in plaintext Redis cache while `access_token` is encrypted
    - **Where:** app/Services/Shopify/ShopifySetupTokenService.php:22–30
    - **Affects:** Any Shopify install in flight. The merchant's contact email is readable in plaintext on Redis DB 0 (the shared cache store) for up to 60 minutes. The same cache store is accessible via Horizon dashboard and Redis CLI to any developer or ops person with Redis access.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap `$shopEmail` with `encrypt()` to match the treatment of `$accessToken`. The `decryptPayload` method already calls `decrypt($data['access_token'])` — add `$data['shop_email'] = decrypt($data['shop_email'])` alongside it.
        - Note: `shop_data` contains the full Shopify `shop.json` payload which also includes the email and other merchant details. Consider encrypting the full `shop_data` array or omitting `shop_email` entirely (it can be re-derived from `shop_data['email']` after decryption).
    - **Technical:** `Cache::put(self::CACHE_PREFIX.$token, ['access_token' => encrypt($accessToken), 'shop_email' => $shopEmail, ...])` — the access token is encrypted with Laravel's symmetric encryption (AES-256-GCM), correctly, but `shop_email` is stored as plaintext. Redis DB 0 is the general cache store shared with other application cache entries. A `SCAN` + `HGETALL` on the cache database during the 60-minute TTL window would expose the email. The `$token` is 64 hex characters of `random_bytes(32)` so brute-forcing the key is not feasible; the risk is internal access (Horizon dashboard, `redis-cli`, cache dump during an incident).
    - **Plain English:** The setup token service carefully locks the Shopify access token in an encrypted safe before storing it, but leaves the merchant's email sitting on the shelf next to the safe in plain sight. Anyone who can look at the Redis cache — an engineer using the Horizon dashboard or connecting to Redis directly — can read the email during that 60-minute window. Since we're already encrypting the more-sensitive access token, adding the same one-line encryption call for the email is trivial.
    - **Evidence:**
        ```php
        Cache::put(self::CACHE_PREFIX.$token, [
            'shop_domain' => $shopDomain,
            'access_token' => encrypt($accessToken),
            'shop_data' => $shopData,
            'scopes' => $scopes,
            'shop_email' => $shopEmail,
            'created_at' => now()->toIso8601String(),
        ], now()->addMinutes(self::TTL_MINUTES));
        ```

`★ Insight ─────────────────────────────────────`
Three patterns to watch going forward: (1) Email-as-implicit-identity — Shopify's shop email is merchant-configurable data, not a cryptographic proof of ownership; never use it to auto-connect accounts without additional verification. (2) Client-level vs caller-level validation — `ShopifyAdminClient` is called from ~8 different sites; defense that lives only in some callers will eventually be bypassed by the next caller written under time pressure. (3) The `VerifyShopifySessionToken` vs `VerifyEmbeddedApiKey` split illustrates the design tension: session tokens are short-lived and shop-bound by cryptography; shared API keys are long-lived and tenant-unbound — the latter should always be a last resort for server-to-server flows where session tokens genuinely aren't available.
`─────────────────────────────────────────────────`
