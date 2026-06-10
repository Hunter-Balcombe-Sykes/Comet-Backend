Now I have everything I need. The `HandlesShopifyWebhook` trait is the canonical dedup pattern — the uninstall controller isn't using it and doesn't replicate its dedup logic either. SEC-5 (shop domain drift) is definitively invalid — `shopify_shop_domain` is a PostgreSQL `GENERATED ALWAYS AS` computed column that mirrors `lower(trim(provider_metadata ->> 'shop_domain'))` automatically; there can be no drift. That finding is dropped.

`★ Insight ─────────────────────────────────────`
- PostgreSQL `GENERATED ALWAYS AS ... STORED` columns update atomically with their owning row — they can never drift from their source expression. DeepSeek's SEC-5 assumed app-layer sync was required, missing that the DB layer already enforces it.
- The `HandlesShopifyWebhook` trait uses `Cache::add` (atomic NX) rather than `Cache::has` + `Cache::put` — the two-step version would expose dedup state to unauthenticated callers (webhook ID enumeration). The uninstall controller predates the trait and misses both this security property and the dedup itself.
- The reinstall-overwrite risk in SEC-2 is worse than duplicate job dispatch: a Shopify webhook retry arriving after a brand reinstalls will null out the *new* access token and flip brand_status back to Disconnected, locking the brand out of their own dashboard.
`─────────────────────────────────────────────────`

# Security Audit — 2026-05-15

**Branch:** development
**Lens:** Security: auth boundaries, tenant isolation, webhooks, secrets, injection, SSRF, PII exposure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/Internal/EmbeddedConnectController.php
- app/Http/Controllers/Api/Internal/EmbeddedSetupController.php
- app/Http/Controllers/Api/Internal/EmbeddedOrderAnalyticsController.php
- app/Http/Controllers/Api/Internal/EmbeddedProductAnalyticsController.php
- app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php
- app/Http/Middleware/Auth/VerifyShopifySessionToken.php
- app/Http/Requests/Api/Internal/Embedded/*.php
- app/Services/Shopify/ShopifyShopResolver.php
- app/Http/Controllers/Api/Webhooks/ShopifyAppUninstalledWebhookController.php
- app/Http/Controllers/Concerns/HandlesShopifyWebhook.php
- bootstrap/app.php
- supabase/migrations/20260403000000_v2_baseline.sql (schema verification)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#SEC-1** · P1 — `EmbeddedProductSettingsController::update()` has no Form Request; unvalidated `value` corrupts Shopify metafield types silently
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php:132–164
    - **Affects:** Brands using the embedded Shopify admin extension to configure per-product commission rates. A non-numeric string sent for `commission_override` is stored as `single_line_text_field` instead of `number_decimal`; `parseFloat()` on retrieval returns `null`, silently clearing the override and falling back to the brand-wide default rate for all affiliates on that product.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `app/Http/Requests/Api/Internal/Embedded/UpdateProductSettingsRequest.php` extending `BaseFormRequest`.
        - Add `product_gid` rule: `required|string|regex:/^gid:\/\/shopify\/Product\/\d+$/`.
        - Add `field` rule: `required|string|in:active,commission_override,affiliate_discount_pct,custom_photos_enabled,add_to_favourites,add_to_default,disabled_variant_gids` — moves the allowlist out of the `match` and into the validation layer where it surfaces as 422 rather than a caught exception whose message is returned verbatim to the client.
        - Add `value` rule: conditional on `field` — `numeric|min:0|max:100` when `commission_override` or `affiliate_discount_pct`; `boolean` when `active`, `custom_photos_enabled`, `add_to_favourites`, `add_to_default`; `array` when `disabled_variant_gids`.
        - Change `update(Request $request)` signature to `update(UpdateProductSettingsRequest $request)`.
    - **Technical:** Every other embedded mutation endpoint has a dedicated Form Request (`SaveIdentityRequest`, `UpdateSettingRequest`, `SaveDeploymentTokenRequest`, etc.). This endpoint reads `$value = $request->input('value')` with no validation. The `saveMetafield()` helper infers the Shopify metafield type from PHP's runtime type of `$value` — but since HTTP input is always a string, `is_bool()` and `is_numeric()` behave differently than expected: `"true"` passes `is_bool()` as false (it's a string), `"1"` passes `is_numeric()` as true, but `"abc"` falls through to the `default` branch and gets typed as `single_line_text_field`. The commission rate then persists as the wrong type in Shopify's metafield store. When the embedded app reads it back through `parseFloat()`, the value appears `null`, causing an invisible rollback to the default commission rate. No error surfaces anywhere in the stack.
    - **Plain English:** Every other field in the embedded setup wizard has a bouncer checking that the data matches what's expected before it enters the system. This one endpoint — the one that controls per-product commission rates — skips the bouncer entirely. If anyone (or a buggy client) sends a word instead of a number for the commission rate, the system politely accepts it, stores it in the wrong format, and then quietly forgets the override ever existed. Affiliates start earning the global default rate instead, with no error message shown anywhere.
    - **Evidence:**
        ```php
        $value = $request->input('value');

        if ($productGid === '' || $field === '') {
            return $this->error('product_gid and field are required.', 422);
        }
        ```
        ```php
        $typedValue = match (true) {
            is_bool($value) => json_encode($value),
            is_numeric($value) => (string) $value,
            is_null($value) => '',
            default => (string) $value,
        };

        $type = match (true) {
            is_bool($value) => 'boolean',
            is_numeric($value) => 'number_decimal',
            default => 'single_line_text_field',
        };
        ```

- [ ] **#SEC-2** · P1 — `ShopifyAppUninstalledWebhookController` has no webhook-ID deduplication; a Shopify retry arriving after brand reinstall nulls the new access token and locks the brand out
    - **Where:** app/Http/Controllers/Api/Webhooks/ShopifyAppUninstalledWebhookController.php:22–91
    - **Affects:** Any brand that uninstalls and reinstalls the Partna Shopify app within Shopify's 48-hour retry window. The retry delivers the uninstall event after the reinstall, wiping the new access token to `null` and setting `brand_status` back to `Disconnected` — locking the brand out of the embedded setup wizard with no recovery path except ops intervention.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Read `X-Shopify-Webhook-Id` at the top of `__invoke()`, immediately after HMAC validation passes.
        - Call `Cache::add("shopify:webhook:uninstall:{$webhookId}", true, config('partna.cache.ttls.webhook_idempotency'))` — return `$this->success(['received' => true])` if it returns `false` (duplicate).
        - Place this AFTER the HMAC check (never expose dedup state without a valid signature — the `HandlesShopifyWebhook` trait documents this exact ordering requirement).
        - If `X-Shopify-Webhook-Id` is absent (Shopify omits it only on manual test deliveries), fall through to normal processing — do not skip dedup silently.
        - As a secondary hardening: before executing the `integration->update()` + `BrandProfile::update()` mutations, check whether `$metadata['disconnected_at']` is already set; if it is, return 200 immediately — this makes the handler idempotent even when the cache TTL has expired.
    - **Technical:** Every other Shopify webhook controller in the codebase uses `HandlesShopifyWebhook` (which runs `Cache::add` atomically after HMAC) or an explicit dedup block. `ShopifyAppUninstalledWebhookController` was written against `ValidatesShopifyWebhookHmac` only and never adopted the trait. Shopify retries undelivered webhooks with exponential backoff for up to 19 attempts over 48 hours. The inline mutations in this handler are not idempotent when a reinstall occurs between deliveries: `$integration->update(['access_token' => null, ...])` revokes the new token even though it was never connected to the original uninstall event, and `BrandProfile::where(...)->update(['brand_status' => BrandStatus::Disconnected, 'setup_complete' => false])` overrides the reinstall's status sync. The job dispatch (`PurgeAffiliateProductSelectionsJob`) is idempotent (DELETE WHERE) and tolerable on double-dispatch, but the integration mutations are the real hazard. The `HandlesShopifyWebhook` trait cannot be dropped in directly because the uninstall handler performs inline DB mutations rather than delegating to a job, but the Cache::add dedup pattern is a one-file change.
    - **Plain English:** Shopify has a habit of ringing the doorbell twice if it doesn't hear back quickly. For most doors in this codebase, the second ring is silently ignored. But the uninstall door has no memory — every knock triggers the full teardown again. If a brand uninstalls Monday and reinstalls Tuesday, and Shopify's second delivery arrives Wednesday, the system treats Wednesday's knock as a fresh uninstall: it erases the access key the brand just set up and marks them as disconnected. The brand opens the app to a "you need to reconnect" screen even though they just reconnected. The fix is simple: write down the first knock's ID and ignore any knock with the same ID.
    - **Evidence:**
        ```php
        public function __invoke(Request $request): JsonResponse
        {
            $rawBody = (string) $request->getContent();
            $signature = (string) $request->header('X-Shopify-Hmac-SHA256', '');
            $shopDomain = strtolower(trim((string) $request->header('X-Shopify-Shop-Domain', '')));

            if (! $this->isValidShopifyHmac($rawBody, $signature)) {
                // ...
            }

            $integration = ProfessionalIntegration::query()
                ->where('shopify_shop_domain', $shopDomain)
                ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
                ->first();

            // ... metadata mutations, BrandProfile::update(), PurgeAffiliateProductSelectionsJob::dispatch() ...

            PurgeAffiliateProductSelectionsJob::dispatch((string) $integration->professional_id);

            return $this->success(['received' => true]);
        }
        // No X-Shopify-Webhook-Id read anywhere in this method.
        ```
        Contrast with `HandlesShopifyWebhook` (used by all other Shopify webhook controllers):
        ```php
        $webhookId = (string) $request->header('X-Shopify-Webhook-Id', '');
        // 1. HMAC first
        if (! $this->isValidShopifyHmac($rawBody, $signature)) { ... }
        // 2. Atomic cache claim — never exposed without valid signature
        if ($webhookId !== '') {
            $cacheKey = "{$this->dedupCachePrefix()}:{$webhookId}";
            if (! Cache::add($cacheKey, true, (int) config('partna.cache.ttls.webhook_idempotency'))) {
                return $this->success(['received' => true, 'duplicate' => true]);
            }
        }
        ```

---

## P2 — Should fix

- [ ] **#SEC-3** · P2 — Embedded controllers resolve tenant from request attributes and scope queries inline; no Policy gate on any embedded mutation endpoint
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedSetupController.php (all 12 methods), app/Http/Controllers/Api/Internal/EmbeddedOrderAnalyticsController.php:show(), app/Http/Controllers/Api/Internal/EmbeddedProductAnalyticsController.php:show(), app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php:show() and update()
    - **Affects:** All brand operations from the embedded Shopify admin surface. The current auth is cryptographically correct — `embedded_professional_id` is bound to the JWT signature and cannot be forged. The risk is architectural: if a future developer adds an embedded endpoint that accidentally reads `professional_id` from a body or query parameter instead of request attributes, there is no Policy gate to catch the tenant-isolation failure before it ships.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Introduce a `currentEmbeddedProfessional(Request $request): Professional` concern (mirrors the existing `LoadCurrentProfessional` pattern for the Supabase JWT path) that reads `embedded_professional_id` from request attributes only and throws if absent — encapsulating the source-of-truth in one place.
        - For write operations that act on a known resource (`ProfessionalIntegration`, `BrandStoreSettings`, `BrandProfile`), add a Policy gate via `$this->authorizeForUser($pro, 'update', $resource)` after loading the resource. Policies for `ProfessionalIntegration` and `BrandStoreSettings` don't yet exist — create them extending `BasePolicy`.
        - Register new policies in `AppServiceProvider::boot()` via `Gate::policy()` so they appear in the `PolicyCoverageTest` sweep.
        - Read operations (analytics) are lower priority: the inline `->where('brand_professional_id', $professionalId)` scoping is functionally correct; add Policy gates only if the model is also returned by a non-embedded route that has policy coverage.
    - **Technical:** The Partna authorization doctrine mandates "Authorization through Policies, never inline." The `embedded_professional_id` attribute is set by `VerifyShopifySessionToken` after full JWT signature verification, so the current code cannot be exploited as-is. However, CI's `PolicyCoverageTest` only sweeps `app/Models/` against `AppServiceProvider` registrations — it has no coverage of whether *controllers* actually call `authorizeForUser`. The CLAUDE.md's CI guard catches `BrandAccessService` capability calls and inline `abort_unless` checks but not missing `authorizeForUser` calls. An embedded controller added without a policy gate would pass CI silently. Centralizing tenant resolution via a dedicated concern (analogous to `LoadCurrentProfessional`) is the higher-leverage first step — it makes the source of `$professionalId` auditable in one file rather than scattered across 12+ methods.
    - **Plain English:** Every room in the building is correctly locked — only the right person's keycard opens each door. But the lock mechanism was custom-wired for each room separately, rather than going through the central security panel. The central panel is what lets the security team audit every door at once and catch a new door installed without proper wiring. Right now, someone adding a new embedded endpoint could forget to copy the wiring, and the security audit wouldn't flag it until a human manually reviewed the code.
    - **Evidence:**
        ```php
        // EmbeddedSetupController::saveIdentity() — representative of 12+ methods
        $professionalId = (string) $request->attributes->get('embedded_professional_id');
        $professional = Professional::findOrFail($professionalId);
        // No $this->authorizeForUser($professional, 'update', $resource) follows
        ```
        ```php
        // EmbeddedOrderAnalyticsController::show()
        $professionalId = (string) $request->attributes->get('embedded_professional_id');
        $order = Order::query()
            ->with(['items', 'affiliateProfessional:id,display_name,handle'])
            ->where('brand_professional_id', $professionalId)
            ->where('shopify_order_id', $orderId)
            ->first();
        // No Policy gate; tenant scoping is an inline WHERE clause
        ```

- [ ] **#SEC-4** · P2 — Exception renderer sets `Access-Control-Allow-Origin: *` on all API error responses; error bodies readable cross-origin by any page
    - **Where:** bootstrap/app.php:108–113
    - **Affects:** Any browser-based client that receives an API error response. Under the current Supabase JWT + Shopify session-token auth (no cookies), this is low-risk. If a cookie-based auth path is added in future (staff dashboard SSO, OAuth callback), `*` on error responses could allow malicious third-party pages to read error bodies. In `app.debug=true` mode, error bodies include exception messages which may reference internal identifiers or paths.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `'*'` with a reflection of the request's `Origin` header, gated against `config('cors.allowed_origins')`.
        - If the request `Origin` is absent or not in the allow-list, omit the header entirely rather than falling back to `*`. Browsers treat a missing `Access-Control-Allow-Origin` as same-origin-only, which is the correct default for error responses.
        - Alternatively, call `app(HandleCors::class)->handle($request, fn() => $response)` on the already-built response to re-run the configured CORS middleware on the error path, eliminating the need to duplicate the allow-list logic.
    - **Technical:** The guard was added because Laravel Cloud's proxy strips CORS headers from some error responses, leaving the browser unable to read the error body. The fix (`'*'`) is broader than necessary: CORS `*` is incompatible with `credentials: include` (browsers reject it) but compatible with plain `fetch()` with no credentials, meaning any origin can read error responses including any exception messages surfaced via `app.debug`. The correct fix re-applies the project's configured allow-list (`config/cors.php`) rather than opening to all origins. The HandleCors middleware approach is preferable because it keeps the allow-list in one canonical location and avoids the allow-list drifting between `cors.php` and the exception handler.
    - **Plain English:** When something goes wrong with an API request, the error message gets a sticky note that says "any website in the world may read this." Today that's fine because our API uses keycards (JWT tokens) rather than browser cookies, so only our own app is making these requests anyway. But if we ever add a staff login that uses cookies, that sticky note could let a malicious website read our error messages. The fix is to write our own website's name on the sticky note instead of "any website."
    - **Evidence:**
        ```php
        // bootstrap/app.php — exception renderer fallback
        if ($response !== null
            && ! $response->headers->has('Access-Control-Allow-Origin')
        ) {
            $response->headers->set('Access-Control-Allow-Origin', '*');
        }
        ```

---

## P3 — Nice to have

- [ ] **#SEC-5** · P3 — `checkStorefrontStatus()` makes an outbound HTTP request using a subdomain value sourced from the database without validating it cannot resolve to an internal network address
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedSetupController.php:361–393
    - **Affects:** No user-visible impact under the current architecture. Defense-in-depth gap only: the URL is always `{site.subdomain}.partna.au` and Partna controls that zone, so real-world SSRF through this path requires DNS poisoning of Partna's own domain.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a Guzzle `on_stats` callback (or a custom Laravel HTTP middleware) that checks the resolved IP against RFC1918 + link-local ranges (`10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16`) before the connection is established and throws if matched.
        - Alternatively, assert that the URL's host ends with `.partna.au` (or the configured `partna.public_domain`) before issuing the request.
    - **Technical:** `$url = 'https://'.$subdomain.'.'.config('partna.public_domain', 'partna.au')` — the host is always `{subdomain}.partna.au`. The subdomain comes from `Site::where('professional_id', ...).first()`, set at brand creation and not user-controlled in this request path. The attack surface is theoretical: a database-level write to a brand's subdomain record could redirect the health check to an internal IP, but that write already represents full DB compromise. Adding an IP-range check costs nothing and eliminates the gadget entirely.
    - **Plain English:** The system regularly phones home to check whether a brand's storefront is alive. It always dials a `.partna.au` number from our own phone book, so it can't accidentally call an internal office extension. If someone ever changed the phone book entry (which would require breaking into the database), they could redirect the health check to an internal system. Adding a quick check that the number resolves to a real public IP before dialing costs almost nothing and closes the theoretical risk permanently.
    - **Evidence:**
        ```php
        private function checkStorefrontStatus(string $subdomain): string
        {
            $url = 'https://'.$subdomain.'.'.config('partna.public_domain', 'partna.au');
            try {
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'timeout' => 5,
                    'connect_timeout' => 3,
                ])->get($url);
                // ...
            }
        }
        ```
