- [ ] **CACHE-1** · P2 — `BrandCatalogService::fetchProductCustomPhotosMetafield` uses raw `Cache::get`/`Cache::put` without single-flight lock, risking cold-cache stampede
    - **Where:** app/Services/Store/BrandCatalogService.php (method `fetchProductCustomPhotosMetafield`)
    - **Affects:** Affiliates viewing product detail; brand team members toggling per-product custom-photos overrides.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the raw `Cache::get` + `Cache::put` with `$this->cacheLock->rememberLocked($cacheKey, $ttl, fn() => ...)`.
        - Use int TTL (not `now()->addSeconds(...)`) so `writeWithJitter` applies ±20% jitter.
    - **Technical:** Two concurrent requests for the same product's `custom_photos_enabled` metafield both observe a cache miss and both fire Shopify Admin API calls before the first one writes back. The `CacheLockService::rememberLocked` lock prevents this by holding a single-flight mutex so only one worker incurs the Shopify round-trip. The existing pattern is already used by `fetchBrandCatalog` and `fetchActiveCatalog` in sibling services — this method simply never adopted it.
    - **Plain English:** Imagine two affiliates open the same product page at the same moment. Both check the "custom photos allowed?" flag, find the cache empty, and both phone Shopify to ask. One answer would suffice, but we make two calls. The fix tells the second request to wait for the first answer instead of dialling Shopify itself.
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
        // ... fetch from Shopify, then:
        Cache::put($cacheKey, 'unset', now()->addSeconds(...));
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **CACHE-2** · P3 — `BrandCatalogService::fetchProductCustomPhotosMetafield` uses `now()->addSeconds()` DateTimeInterface TTL, bypassing jitter and causing synchronised fleet-wide expiry
    - **Where:** app/Services/Store/BrandCatalogService.php (method `fetchProductCustomPhotosMetafield`, two `Cache::put` calls)
    - **Affects:** Every Horizon worker that serves a cache miss for the same product simultaneously after TTL expiry.
    - **Effort:** S (~0.5h) — resolved automatically when switching to `rememberLocked` with int TTL.
    - **What to do:**
        - Pass an int TTL to `rememberLocked` (e.g. `(int) config('partna.cache.ttls.product_custom_photos')`).
        - Remove the three sentinel branches (`'true'`, `'false'`, `'unset'`) — `rememberLockedNullable` or a boolean return from `rememberLocked` eliminates the sentinel workaround.
    - **Technical:** `CacheLockService::writeWithJitter` only applies jitter when the TTL is an integer. `now()->addSeconds()` produces a `Carbon` instance (DateTimeInterface), which the write path stores as an absolute expiry timestamp — every worker computes the same absolute timestamp, so all caches expire in lockstep. At expiry, a thundering herd of Shopify API calls follows for every product whose custom-photos flag is cold.
    - **Plain English:** Every cached flag is stamped with the exact same expiration time — like a parking meter that expires at the same moment for every car on the block. When the meter runs out, everyone rushes the pay station at once instead of staggering their return.
    - **Evidence:**
        ```php
        Cache::put($cacheKey, $bool ? 'true' : 'false', now()->addSeconds((int) config('partna.cache.ttls.product_custom_photos')));
        // ...
        Cache::put($cacheKey, 'unset', now()->addSeconds((int) config('partna.cache.ttls.product_custom_photos')));
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **CACHE-3** · P2 — `AffiliateProductCatalogService::getCatalogWithSelections` calls uncached `BrandCatalogService::fetchCollectionProducts` on every catalog view, causing redundant Shopify API pagination
    - **Where:** app/Services/Store/AffiliateProductCatalogService.php (method `getCatalogWithSelections` → `fetchCollectionGids` → `fetchCollectionProducts`)
    - **Affects:** Every affiliate viewing their catalog; the favourites collection membership is re-fetched from Shopify on each page load.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap the `fetchCollectionGids('favourites_collection_handle')` call in `CacheLockService::rememberLocked` with a short TTL (e.g. 120s) and int TTL for jitter.
        - Push-invalidate the favourites cache key from `BrandCatalogService::addProductsToCollection` and `removeProductsFromCollection` when the brand modifies collection membership.
        - Or fold favourites-GID resolution into the existing `fetchActiveCatalog` cached payload (appended as a `favourites_gids` key) so no extra API round-trip is needed.
    - **Technical:** `fetchCollectionProducts` paginates through the Shopify collection via `ShopifyAdminClient::graphql()` on every call. While `resolveCollectionGid` is cached, the subsequent product enumeration is not. At 30 brands × 50 affiliates, if each affiliate views their catalog daily, that's 1,500 unnecessary Shopify pagination sequences per day just for favourites — all returning the same answer. The canonical replacement is a live query fronted by `rememberLocked` with push invalidation on write.
    - **Plain English:** Every time an affiliate opens their product catalog, we call Shopify and ask "what's in the Favourites collection?" — even if the answer hasn't changed since ten seconds ago. The fix caches that answer locally for a couple of minutes so 50 affiliates hitting refresh don't all phone Shopify for the same list.
    - **Evidence:**
        ```php
        // In getCatalogWithSelections:
        $favouritesGids = $this->fetchCollectionGids($integration, 'favourites_collection_handle');

        // In fetchCollectionGids:
        $products = $this->brandCatalogService->fetchCollectionProducts($integration, $collectionGid);

        // fetchCollectionProducts — no caching layer:
        public function fetchCollectionProducts(ProfessionalIntegration $integration, string $collectionGid): array
        {
            // ... paginated Shopify API calls, no Cache::remember / rememberLocked
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **CACHE-4** · P1 — `AffiliateProductCatalogService::queryAdminCatalog` bypasses `ShopifyAdminClient`, making direct HTTP calls without token-bucket throttling or cost tracking
    - **Where:** app/Services/Store/AffiliateProductCatalogService.php (method `queryAdminCatalog`, lines using `Http::timeout(20)->...->post()`)
    - **Affects:** Cold-cache affiliate catalog loads — the call skips Shopify rate-limit budget pre-acquisition and proper THROTTLED retry.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Inject `ShopifyAdminClient` into `AffiliateProductCatalogService` and replace the `Http::` post call with `$this->shopifyClient->graphql(...)`.
        - Delete the manual HTTP construction (`Http::timeout(20)->acceptJson()->withHeaders([...])->post(...)`) and the manual error/logging branches — `ShopifyAdminClient` already handles throttled retry, cost reconciliation, and typed exception throwing.
    - **Technical:** Every other Shopify Admin API call site in the codebase (`BrandCatalogService`, `ShopifyTeardownService`, `ShopifyDataResyncService`) routes through `ShopifyAdminClient::graphql()`, which pre-acquires from the Redis token bucket, reconciles bucket state from Shopify's `throttleStatus` response, and throws `ShopifyThrottledException` on THROTTLED so the queue's `backoff()` can retry with delay. `queryAdminCatalog` does none of this — it fires `Http::post` directly, so two brands hitting cold cache simultaneously can exhaust the Shopify budget with no local gate, and a THROTTLED response is logged-and-broken instead of retried.
    - **Plain English:** The rest of the app uses a smart throttling system that paces calls to Shopify like a traffic light — it knows the speed limit and queues cars that would exceed it. This one method ignores the traffic light, floors it onto the highway, and if it gets pulled over (rate-limited), it just gives up instead of waiting its turn. The fix wires it into the same traffic-light system everyone else uses.
    - **Evidence:**
        ```php
        // queryAdminCatalog — direct Http::post, no ShopifyAdminClient:
        $response = Http::timeout(20)
            ->acceptJson()
            ->withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
            ])
            ->post($url, [
                'query' => $query,
                'variables' => $variables,
            ]);

        // Compare: BrandCatalogService::queryAdminCatalog uses the client:
        $response = $this->graphql($shopDomain, $accessToken, self::PRODUCTS_WITH_METAFIELDS, $variables);
        // ... which delegates to $this->client->graphql() (ShopifyAdminClient)
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **CACHE-5** · P3 — `AffiliateProductCatalogService::seedDefaultSelections` creates individual `AffiliateProductSelection` rows in a `foreach` loop instead of batch-inserting
    - **Where:** app/Services/Store/AffiliateProductCatalogService.php (method `seedDefaultSelections`, `foreach ($defaultGids as $gid)` loop)
    - **Affects:** Affiliates during brand-connection onboarding; N individual INSERTs where N = default-collection product count (potentially 50–500).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Collect new selections into an array, then use `AffiliateProductSelection::insert($batch)` for a single multi-row INSERT.
        - Pre-compute `$maxSort + 1` offsets within the batch rather than incrementing per iteration after the fact.
    - **Technical:** Each `AffiliateProductSelection::create()` fires a separate INSERT statement with its own transaction. For a default collection of 100 products, that's 100 round-trips to PostgreSQL. A single `insert([...])` call with a prepared array reduces this to one round-trip. This isn't on a hot webhook path (it runs once per affiliate-brand connection), but at the target scale of 1,500 connections, the cumulative DB load from per-row inserts adds unnecessary write pressure on `pgsql`.
    - **Plain English:** When an affiliate connects to a brand, we add their default product picks one at a time — like hand-delivering 100 letters instead of putting them all in one envelope. The fix bundles them into a single delivery.
    - **Evidence:**
        ```php
        foreach ($defaultGids as $gid) {
            if (in_array($gid, $existingGids, true)) {
                continue;
            }
            $maxSort++;
            AffiliateProductSelection::create([
                'affiliate_professional_id' => $affiliate->id,
                'brand_professional_id' => $brandProfessionalId,
                'shopify_product_gid' => $gid,
                'sort_order' => $maxSort,
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.7]`
