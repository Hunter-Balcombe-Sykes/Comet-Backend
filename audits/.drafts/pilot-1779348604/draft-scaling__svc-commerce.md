- [ ] **#CACHE-1** · P1 — `BrandCatalogService::fetchCommissionOverridesForProducts` uncached on order-webhook hot path
    - **Where:** app/Services/Store/BrandCatalogService.php:324-362
    - **Affects:** Every Shopify order webhook processed by `ProcessShopifyOrderWebhookJob` — at scale, 30 brands × ~50 affiliates × ~100 orders/affiliate/year = ~150K unnecessary Shopify Admin API calls/year, each costing ~200ms latency on the webhook processing thread.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the method body in `$this->cacheLock->rememberLocked(CacheKeyGenerator::brandCommissionOverrides($integration->id, $productGidHash), 60, fn () => ...)` so one cold miss fans out to one Shopify call per unique product-GID set, not one per webhook.
        - Bust the key from `setProductMetafields()` and `deleteProductMetafield()` (the write paths that change `commission_override` and `affiliate_discount_pct`) so the cache is push-invalidated, not TTL-stale.
    - **Technical:** The docblock states "Used by ProcessShopifyOrderWebhookJob to resolve commission rates server-side instead of trusting buyer-set cart line attributes." Every order webhook calls this method, which internally calls `$this->graphql(...)` against the Shopify Admin API with no local cache layer. The `BrandCatalogService` already has a `CacheLockService` dependency injected and uses `rememberLocked` on every other Shopify-read path (`fetchBrandCatalog`, `resolveCollectionGid`). This method is the sole exception. The canonical replacement is live query + `rememberLocked` + jitter + push-invalidate — exactly the pattern the other methods in this class already follow. At 30 brands with 100 orders/affiliate/year, the uncached cost is 150K round-trips/year at ~200ms each, or ~8.3 hours of cumulative latency just from this one uncached path. Per-webhook latency adds ~200–400ms to commission resolution, delaying the entire order-processing pipeline (Stripe charge creation, notification dispatch, affiliate earnings update).
    - **Plain English:** Every time a customer buys something through an affiliate's link, the system calls Shopify to ask "what's the commission rate on this product?" — even if it asked the same question 2 seconds ago. It's like calling your supplier to confirm a price every time you sell a single item, instead of writing it on a sticky note and re-checking once a minute. The fix is to keep a short-lived local note (60-second cache) and tear it up whenever the brand changes the commission rate on that product.
    - **Evidence:**
        ```php
        public function fetchCommissionOverridesForProducts(ProfessionalIntegration $integration, array $productGids): array
        {
            $productGids = array_values(array_unique(array_filter($productGids)));
            if (empty($productGids)) {
                return [];
            }

            $metadata = is_array($integration->provider_metadata) ? $integration->provider_metadata : [];
            $shopDomain = trim((string) Arr::get($metadata, 'shop_domain', ''));
            $accessToken = trim((string) $integration->access_token);

            if ($shopDomain === '' || $accessToken === '') {
                return array_fill_keys($productGids, null);
            }

            try {
                $response = $this->graphql(
                    $shopDomain,
                    $accessToken,
                    self::COMMISSION_OVERRIDES_QUERY,
                    ['ids' => $productGids]
                );
            } catch (\Throwable $e) {
                Log::warning('Failed to fetch commission overrides.', [
                    'integration_id' => (string) $integration->id,
                    'error' => $e->getMessage(),
                ]);

                return array_fill_keys($productGids, null);
            }
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#CACHE-2** · P2 — `BrandCatalogService::fetchCollectionProducts` uncached on affiliate catalog read path
    - **Where:** app/Services/Store/BrandCatalogService.php:421-457
    - **Affects:** Every affiliate catalog page load that renders the favourites filter — `AffiliateProductCatalogService::fetchCollectionGids()` calls this per-catalog-request with no cache. At 30 brands × ~50 affiliates checking their catalog once daily, ~547K extra Shopify API calls/year.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `do...while` loop body in `$this->cacheLock->rememberLocked(CacheKeyGenerator::brandCollectionProducts($integration->professional_id, $collectionGid), 300, fn () => ...)`.
        - Add push-invalidation in the collection-mutation methods (`addProductsToCollection`, `removeProductsFromCollection`) that bust the key so the catalog doesn't go stale after a brand edits the collection.
    - **Technical:** `fetchCollectionProducts` paginates through Shopify's GraphQL API with `first: 50` per page and no local cache. Every sibling read method in `BrandCatalogService` (`fetchBrandCatalog`, `resolveCollectionGid`) uses `CacheLockService::rememberLocked`; this method is the sole collection read that doesn't. It's called from `AffiliateProductCatalogService::fetchCollectionGids()` which feeds the favourites filter on the affiliate catalog page — a dashboard UI that reloads on every navigation. The canonical replacement mirrors the existing pattern: `rememberLocked` with int TTL for jitter, push-invalidate on collection-mutation writes. At scale with 1,500 affiliates checking their catalog once daily, this saves 547K Shopify round-trips/year.
    - **Plain English:** Imagine every time a salesperson opens their product binder, they call the warehouse to ask "which products are in the 'favourites' bin?" instead of looking at a printed list that was updated 5 minutes ago. The warehouse answers the same question identically for everyone, over and over. The fix is to print the list once every 5 minutes and hand copies to everyone, tossing the old copies whenever the brand shuffles the bin contents.
    - **Evidence:**
        ```php
        public function fetchCollectionProducts(ProfessionalIntegration $integration, string $collectionGid): array
        {
            $resolved = $this->resolveCredentials($integration);
            $products = [];
            $cursor = null;

            do {
                $variables = ['id' => $collectionGid, 'first' => self::PRODUCTS_PER_PAGE];
                if ($cursor !== null) {
                    $variables['after'] = $cursor;
                }

                $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::COLLECTION_PRODUCTS, $variables);
                // ... parse edges, accumulate $products, advance $cursor ...
            } while ($hasNextPage && $cursor !== null);

            return $products;
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CACHE-3** · P2 — `BrandCatalogService::fetchProductCustomPhotosMetafield` manual-cache race condition
    - **Where:** app/Services/Store/BrandCatalogService.php:366-395
    - **Affects:** Concurrent requests for the same uncached product's `custom_photos_enabled` metafield — multiple identical Shopify Admin API calls fire instead of one. Called from `CustomPhotoPermissionService::isAllowed()` on the affiliate storefront render path.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the manual `Cache::get` + `Cache::put` pattern with `$this->cacheLock->rememberLocked($cacheKey, $ttl, fn () => ...)` — eliminates the check-then-put window where multiple workers all observe a miss and all call Shopify.
        - Use the int-TTL form (not `now()->addSeconds()`) so `CacheLockService::writeWithJitter` applies ±20% jitter and prevents synchronized expiry.
    - **Technical:** The method does its own caching via `Cache::get($key)` → null-check → `Cache::put($key, $sentinel, $ttl)` without any lock or single-flight coordination. In `CacheLockService::rememberLocked`, the `Cache::add` lock ensures only one worker calls the Shopify API on a cold miss; all concurrent workers wait on the lock or serve stale. The manual pattern here has no such guard — on a cold cache after deploy or eviction, N concurrent storefront renders produce N Shopify GraphQL calls. The rest of `BrandCatalogService` already uses `CacheLockService::rememberLocked` via the injected `$cacheLock` dependency; this method is the lone hand-rolled exception. TTL uses `now()->addSeconds()` (DateTimeInterface) which `CacheLockService::writeWithJitter` explicitly skips — so even with a switch to `rememberLocked`, the TTL must be passed as a plain int or jitter won't engage.
    - **Plain English:** This is like three roommates all noticing the fridge is empty at the same time and each driving to the grocery store separately, buying the same milk, and coming home — instead of one person going while the others wait. The code checks "is the answer in my notebook?" and if not, goes to Shopify to get it. But three requests can all see the empty notebook simultaneously and all make the trip. The fix is to install a simple "one person goes, everyone else waits" rule that the rest of the codebase already uses.
    - **Evidence:**
        ```php
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return match ($cached) {
                'true' => true,
                'false' => false,
                default => null,
            };
        }

        $resolved = $this->resolveCredentials($integration);

        try {
            $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::PRODUCT_CUSTOM_PHOTOS_QUERY, [
                'productId' => $productGid,
            ]);
        } catch (\Throwable $e) {
            // ...
            Cache::put($cacheKey, 'unset', now()->addSeconds((int) config('partna.cache.ttls.product_custom_photos')));
            return null;
        }
        // ...
        Cache::put($cacheKey, $bool ? 'true' : 'false', now()->addSeconds((int) config('partna.cache.ttls.product_custom_photos')));
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#CACHE-4** · P2 — `AffiliateProjectionsService::build` uncached multi-query projections
    - **Where:** app/Services/Analytics/AffiliateProjectionsService.php:32-69
    - **Affects:** Every affiliate dashboard projections load — 5+ SQL queries per uncached call with no single-flight lock, no TTL, and no push-invalidation. At 1,500 affiliates checking dashboards, ~7,500 queries/day minimum.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `build()` body in `CacheLockService::rememberLocked` with the same versioned-key pattern used by `AnalyticsService` (a `projectionsSummaryVersion` counter bumped on every order write that affects the rollup).
        - Or, if order-write push-invalidation is infeasible, use a short int-TTL (60–120s) with jitter — projections tolerate minute-level staleness.
    - **Technical:** The sibling `AnalyticsService` in the same namespace wraps both `forAffiliate()` and `forBrand()` in `$this->cacheLock->rememberLocked` with a versioned cache key that auto-rotates on every analytics event ingest. `AffiliateProjectionsService` has no such wrapper — every call executes `fetchPerCurrencyAggregates`, `fetchPriorWindowAggregates`, `fetchYtdAggregates`, `fetchBestMonthPerCurrency`, and `resolveDataHistoryDays` (5 queries) against `commerce.brand_affiliate_rollup`. While the rollup table is trigger-maintained (correct per the rebuild), the service still re-queries it on every dashboard load. At the scaling target of 1,500 affiliates checking dashboards once or twice daily, this is ~7.5K–15K redundant SQL queries/day against a table whose contents change only when a new order arrives. The canonical replacement is live query + `rememberLocked` — exactly what `AnalyticsService` already does.
    - **Plain English:** This is like recalculating a student's GPA from scratch every time they check their report card, instead of computing it once and sticking the result on the fridge. The data (grades) changes rarely, but the math runs fresh every single time. The fix is to compute it once, save the result with a "good for 60 seconds" sticker, and tear up the sticker whenever a new grade comes in. The grade-book system next door (`AnalyticsService`) already does this; the projections page just hasn't been upgraded yet.
    - **Evidence:**
        ```php
        public function build(Professional $professional, ?int $windowDaysOverride = null): array
        {
            $tz = $professional->timezone ?: 'UTC';
            $now = CarbonImmutable::now($tz);

            $dataHistoryDays = $this->resolveDataHistoryDays($professional->id, $now);
            $windowDays = $windowDaysOverride !== null
                ? $this->validateOverride($windowDaysOverride, $dataHistoryDays)
                : $this->selectWindowDays($dataHistoryDays);
            // ...
            $perCurrency = $this->fetchPerCurrencyAggregates(
                $professional->id,
                $windowFrom->toDateString(),
                $windowTo->toDateString(),
                $windowDays
            );
            $priorByCurrency = $this->fetchPriorWindowAggregates(
                $professional->id,
                $priorWindowFrom->toDateString(),
                $priorWindowTo->toDateString()
            )->keyBy('currency_code');
            $ytdByCurrency = $this->fetchYtdAggregates($professional->id, $yearStart)->keyBy('currency_code');
            $bestMonthByCurrency = $this->fetchBestMonthPerCurrency($professional->id, $yearStart)->keyBy('currency_code');
            // ... no CacheLockService, no Cache::remember, no version key
        }
        ```
    - `[DRAFT, confidence: 0.80]`
