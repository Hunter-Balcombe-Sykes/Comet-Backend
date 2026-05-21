- [ ] **#CCH-1** · P1 — Hydrogen storefront config endpoint has zero caching on a hot read path
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicShopifyStorefrontController.php:45-100
    - **Affects:** Every Hydrogen storefront initial render across all brands. Each page load runs 2+ DB queries for the same rarely-changing credentials (shop domain, storefront token, collection handle, brand status).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Inject `CacheLockService` and wrap the integration resolution + payload assembly in `rememberLocked` with a 60s TTL (matching `HydrogenBrandConfigController`).
        - On write paths that mutate the returned data (storefront token creation, brand status transitions), call `Cache::forget` on the matching key.
    - **Technical:** This is a public, unauthenticated endpoint called by Hydrogen at runtime — every storefront visitor triggers it. The controller has a job-dedup `Cache::put` for storefront token creation (line ~98) which proves the endpoint is called frequently enough to need dedup, yet the read itself has no caching layer. Compare `HydrogenBrandConfigController::show` which resolves a nearly identical integration query and caches the full payload for 60s via `CacheLockService::rememberLocked` — this endpoint should follow the same pattern.
    - **Plain English:** Imagine every customer walking into your store has to wait while the cashier looks up the store's own address in a filing cabinet. That's what this endpoint does — it re-queries the database for credentials that change once a month, on every single page load, for every single storefront visitor. The fix is to put that lookup result on a sticky note at the register (cache it) and only re-check the filing cabinet when the credentials actually change.
    - **Evidence:**
        ```php
        public function storefrontConfig(Request $request): JsonResponse
        {
            // ...validation...

            // Resolve integration: shop_domain takes precedence over brand_slug
            $integration = ! empty($validated['shop_domain'])
                ? $this->resolveByShopDomain($validated['shop_domain'])
                : $this->resolveByBrandSlug($validated['brand_slug']);

            if (! $integration) {
                return $this->error('Not found.', 404);
            }

            $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
            $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
            $storefrontToken = trim((string) ($integration->storefront_token ?? ''));

            // ... no caching layer — every call queries DB ...
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CCH-2** · P2 — Affiliate services endpoint has no caching layer
    - **Where:** app/Http/Controllers/Api/Internal/HydrogenAffiliateController.php:150-193
    - **Affects:** Hydrogen lazy-fetch calls for the Services & Pricing section. Each call re-resolves the brand integration, re-validates the affiliate, and rebuilds services data from scratch.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the services resolution in `CacheLockService::rememberLocked` with a key from `CacheKeyGenerator`, matching the 60s TTL pattern used by the parent `show()` endpoint.
        - Invalidate in `SiteCacheService` on `SiteSettings` writes that affect services or section blocks.
    - **Technical:** The `show()` method already caches the full affiliate payload (including services) at 60s via `CacheLockService`. This standalone `services()` endpoint — described in the docblock as "Standalone endpoint kept for back-compat / lazy fetches" — performs the same 3 gate queries + `buildServicesData()` inline with no caching. If Hydrogen calls this endpoint independently (lazy-loaded section), it produces a stampede on cold cache with the same DB cost as the full payload, defeating the purpose of the `show()` cache.
    - **Plain English:** The main affiliate page already has a smart caching system that remembers the answer for 60 seconds. But there's a second doorbell for just the "services" section that has no memory at all — it re-does all the same ID checks and database lookups from scratch every time. If Hydrogen pulls services separately after the main page loads, the cache on the main page doesn't help. The fix is to put the same 60-second memory on this doorbell too.
    - **Evidence:**
        ```php
        public function services(Request $request): JsonResponse
        {
            // ...validation...

            $integration = ProfessionalIntegration::query()
                ->where('shopify_shop_domain', $shopDomain)
                ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
                ->first();

            // ...affiliate lookup, link check, site query...

            return $this->success($this->resolver->buildServicesData($site, $affiliate->id));
        }
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **#CCH-3** · P2 — Site publish toggle does not invalidate the public site payload cache
    - **Where:** Write: app/Http/Controllers/Api/PublicSite/SiteVisibilityController.php:18-25 — Read: app/Http/Controllers/Api/PublicSite/PublicSiteController.php:20-24
    - **Affects:** Professionals who unpublish their site — visitors continue seeing the cached published payload until the TTL expires. Conversely, professionals who publish a previously-unpublished site may wait for the next cache refresh before it becomes visible.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - After `$site->save()` in `SiteVisibilityController::update()`, call `SiteCacheService::forgetPublicSitePayload($site->subdomain)` (or the equivalent invalidation helper).
        - Ensure the invalidation forgets both the primary key and the `:stale` companion so the stale-while-revalidate window doesn't serve the old published state.
    - **Technical:** `PublicSiteController::show()` reads from `SiteCacheService::getPublicSitePayload()` which is the gold-standard cached path. The write path in `SiteVisibilityController::update()` toggles `site->is_published` (via `$site->published`) but never calls any cache invalidation. This is a textbook TTL-only invalidation gap — the cached payload carries the old published state and will serve it until the TTL expires naturally. For a site being unpublished, this means the public payload remains accessible for the full cache window; for a site being published, the 404 response remains cached.
    - **Plain English:** There's a light switch that controls whether a professional's public page is visible or hidden. The public page has a cached "photo" of itself that it shows visitors to avoid rebuilding it every time. When someone flips the switch to "hidden," the system updates the database but doesn't tear down the old photo — so visitors keep seeing the old "visible" photo until it naturally fades. The fix is to shred the old photo at the same moment the switch is flipped.
    - **Evidence:**
        ```php
        // Write path — SiteVisibilityController.php:18-25
        public function update(UpdateVisibilityRequest $request): JsonResponse
        {
            $professional = $request->attributes->get('professional');
            // ...
            $site = Site::query()
                ->where('professional_id', $professional->id)
                ->firstOrFail();

            $site->published = (bool) $request->validated('published');
            $site->save();
            // ← NO cache invalidation here

            return $this->success([
                'site' => $site->fresh(),
            ]);
        }
        ```
        ```php
        // Read path — PublicSiteController.php:20-24
        $payload = $this->siteCache->getPublicSitePayload($subdomain);
        if ($payload) {
            return $this->success($this->liveStatus->injectIntoPayload($payload));
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CCH-4** · P2 — Shopify errors inside a cached closure pollute the product settings cache with incorrect defaults
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php:138-167 (call site) and 251-339 (fetchProductMetafields swallowing errors)
    - **Affects:** Embedded product settings UI for brands. During a Shopify Admin API outage, the cached payload silently defaults `active` to `true` and all collection memberships to `false`, and that incorrect data survives for 5 minutes (primary TTL) + up to 50 minutes (stale window).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `fetchProductMetafields()`, let `\Throwable` bubble out of the `rememberLocked` closure instead of returning `$empty`. `CacheLockService::rememberLocked` will not cache the result when the closure throws — the next request retries the fetch.
        - In `isInCollection()`, apply the same treatment: log the error but let the exception propagate so the closure fails rather than caching `false`.
    - **Technical:** `buildSettingsPayload()` is the closure passed to `CacheLockService::rememberLocked`. It calls `fetchProductMetafields()` which catches all `\Throwable` and returns `['metafields' => [], 'variants' => []]`. The closure then happily assembles a payload with `'active' => $metafields['active'] ?? true` — defaulting to `true` because the metafields array is empty. This payload IS cached by `writeWithJitter` inside `rememberLocked`. The gold-standard rule (category 10) is that closures inside `Cache::remember`/`rememberLocked` must not swallow errors — the exception must bubble so bad data never enters the cache. `resolveActive()` in `EmbeddedProductAnalyticsController` already follows this rule (logs warning, returns null only for `ShopifyClientException`, uses `rememberLockedNullable`). This closure does the opposite.
    - **Plain English:** Think of the cache as a whiteboard where you write down answers so you don't have to recalculate them. When Shopify is down and you can't get the real answer, this code writes "active: yes, in collections: no" on the whiteboard anyway — because those are the defaults when no data comes back. For the next 5–50 minutes, every settings page load reads that wrong answer from the whiteboard, even after Shopify recovers. The fix is: when you can't get the real answer, don't write anything on the whiteboard. Just throw up your hands and let the next person try again.
    - **Evidence:**
        ```php
        // fetchProductMetafields — catches ALL Throwable and returns empty
        } catch (\Throwable $e) {
            Log::error('Shopify Admin API exception fetching product metafields.', [...]);
            return $empty;  // $empty = ['metafields' => [], 'variants' => []]
        }
        ```
        ```php
        // buildSettingsPayload — uses empty metafields, defaults active to true
        $result = $this->fetchProductMetafields($integration, $productId);
        $metafields = $result['metafields'];
        // ...
        return [
            'active' => $metafields['active'] ?? true,  // ← defaults TRUE on error
            // ...
        ];
        ```
        ```php
        // This payload IS cached inside rememberLocked
        $payload = $this->cacheLock->rememberLocked(
            $cacheKey,
            300,
            fn () => $this->buildSettingsPayload($integration, $productGid, $productId, $professionalId),
        );
        ```
    - `[DRAFT, confidence: 0.80]`
