- [ ] **SCALE-1** · P1 — Catalog queries bypass Shopify’s rate‑limit / cost‑budget enforcement  
    - **Where:** app/Services/Store/AffiliateProductCatalogService.php:845 (within `queryAdminCatalog`)  
    - **Affects:** Every affiliate catalog load (brand‑active catalog + fallback) — at 3 K orders/day and dozens of affiliates per brand, raw calls can drain the Shopify bucket and starve all brands.  
    - **Effort:** M (~2–4h)  
    - **What to do:**  
        - Replace the raw `Http::post(…)` with `$client->graphql(…)` (the same `ShopifyAdminClient` already used everywhere else in `BrandCatalogService`).  
        - Let the client’s `preAcquireBudget` / `reconcileFromResponse` / throttle‑retry loop govern the call so the bucket stays healthy.  
    - **Technical:** `queryAdminCatalog` builds its own URL, headers, and timeout and calls Shopify Admin directly without going through the `ShopifyAdminClient` that every other GraphQL call in the codebase uses. That client maintains per‑shop token‑bucket state, logs cost metrics, and honours the THROTTLED‑retry contract. Skipping it means 200 brands’ catalog reads can burst 429 errors with no local defence.  
    - **Plain English:** Imagine every room in a hotel has its own thermostat, but one room has a space heater plugged directly into the mains without the central energy‑budget system. That room can draw so much power that the whole floor trips the breaker. The catalog query is that space heater — it should respect the same thermostat as every other Shopify call.  
    - **Evidence:**  
        ```php
        $response = Http::timeout(20)
            ->acceptJson()
            ->withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
            ])
            ->post($url, [
                'query' => $query,
                'variables' => $variables,
            ]);
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **SCALE-2** · P1 — Collection‑product lists fetched without caching, causing repeat Shopify API pagination per request  
    - **Where:** app/Services/Store/BrandCatalogService.php:504‑530 (`fetchCollectionProducts`)  
    - **Affects:** Every affiliate catalogue request that includes collection‑based data (favourites, default collections) — triggers fresh paginated GraphQL calls that can consume Shopify budget at scale.  
    - **Effort:** M (~2–4h)  
    - **What to do:**  
        - Wrap `fetchCollectionProducts` (or the call site in `AffiliateProductCatalogService::fetchCollectionGids`) with a short‑TTL cache keyed on `(brand_id, collection_gid)`.  
        - Bust that cache on any metafield or product‑visibility change (the surrounding code already handles busting for the main catalog).  
    - **Technical:** `fetchCollectionProducts` paginates through every product in a collection with repeated GraphQL calls (`do … while` loop). The upstream `resolveCollectionGid` is cached, but the actual product list is not. At the target scale, many affiliates hitting `/affiliate/catalog` concurrently will re‑fetch the same collection GIDs repeatedly, multiplying Shopify API load and risking bucket exhaustion.  
    - **Plain English:** Every time a customer walks into the store, we send someone to the warehouse to count all the items on a specific shelf — even though the shelf hasn’t changed since the last customer. A simple memo on the wall (“favourites for brand X are these 20 items”) would stop all those trips.  
    - **Evidence:**  
        ```php
        do {
            $variables = ['id' => $collectionGid, 'first' => self::PRODUCTS_PER_PAGE];
            if ($cursor !== null) {
                $variables['after'] = $cursor;
            }

            $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::COLLECTION_PRODUCTS, $variables);
            // … edges parsed …
        } while ($hasNextPage && $cursor !== null);
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **SCALE-3** · P1 — Favourite‑collection GIDs fetched without caching, adding a second uncached Shopify round‑trip per request  
    - **Where:** app/Services/Store/AffiliateProductCatalogService.php:340‑360 (`fetchCollectionGids`)  
    - **Affects:** The same affiliate‑catalogue surface as SCALE‑2 — the “favourites” membership used for filtering and affiliate selections.  
    - **Effort:** M (~2–4h)  
    - **What to do:**  
        - Cache the return value of `fetchCollectionGids` for a short TTL (e.g. 5 min), keyed on `(brand_id, metadata_key)`, and invalidate when the underlying collection changes.  
    - **Technical:** `fetchCollectionGids` calls `resolveCollectionGid` (cached) and then `fetchCollectionProducts` (uncached, see SCALE‑2). Since it is invoked inside `getCatalogWithSelections` (hot path), each affiliate load fires a fresh collection‑products pagination just to retrieve GIDs that rarely change.  
    - **Plain English:** Same warehouse‑shelf problem, for a different shelf. The “favourites” list is read every time a shopper looks at the catalog, even though the list of favourite products essentially never changes minute‑to‑minute.  
    - **Evidence:**  
        ```php
        private function fetchCollectionGids(ProfessionalIntegration $integration, string $metadataKey): array
        {
            // …
            $collectionGid = $this->brandCatalogService->resolveCollectionGid($integration, $handle);
            if (! $collectionGid) {
                return [];
            }
            $products = $this->brandCatalogService->fetchCollectionProducts($integration, $collectionGid);
            return array_map(fn (array $p) => $p['gid'] ?? '', $products);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **SCALE-4** · P2 — Image‑variant job dispatched to the default queue, risking head‑of‑line blocking  
    - **Where:** app/Services/Media/BrandDesignMediaService.php:292‑297 (`dispatchVariantJob`)  
    - **Affects:** Logo and placeholder uploads; CPU‑intensive image processing can congest the same queue that processes Shopify orders, webhooks, and payouts.  
    - **Effort:** S (~0.5–1h)  
    - **What to do:**  
        - Add `->onQueue('media')` to the `ProcessImageVariantsJob::dispatch` call (and the video equivalent if it exists).  
        - Configure `config/horizon.php` with a dedicated `media` supervisor whose capacity doesn’t starve the `default` queue under burst upload traffic.  
    - **Technical:** Only `dispatch` is called without a queue hint, so Laravel places it on the `default` connection’s default queue. At the scale target a brand re‑uploading a logo during a peak checkout window would delay commission‑webhook processing — a classic noisy‑neighbour scenario at the queue level.  
    - **Plain English:** Instead of having a separate lane for heavy trucks (image processing) and fast cars (order processing), we put both in the same lane. A single slow truck can cause a traffic jam for every car behind it.  
    - **Evidence:**  
        ```php
        ProcessImageVariantsJob::dispatch(
            originalPath: $originalPath,
            imageId: $imageId,
            basePath: $basePath,
            siteId: $siteId,
        );
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **SCALE-5** · P2 — Per‑window brand analytics use potentially large `WHERE IN` with a full set of affiliate IDs  
    - **Where:** app/Services/Analytics/AnalyticsService.php:274‑282 (`brandWindowedViews`) and similar methods  
    - **Affects:** Brand‑side analytics dashboard; at 200 brands × 50 affiliates = 10 K distinct affiliate IDs the `IN` clause can grow long, slow query planning, and consume memory on the cache‑rebuild boundary.  
    - **Effort:** M (~2–4h)  
    - **What to do:**  
        - Replace `pluck` + `whereIn` with a sub‑select or JOIN that pushes the filter into the query executor.  
        - If the list remains, apply a chunk‑based `whereIn` with `array_chunk($affiliateIds, 500)` to bound the IN clause length.  
    - **Technical:** The current code materialises the full affiliate list with `pluck` (no limit) then passes it to `whereIn`. While the results are cached, a cold rebuild for a brand with 500 affiliates sends a SQL statement containing a 500‑element IN list, which can hit PostgreSQL’s per‑statement parameter limit and cause the planner to degrade to a sequential scan.  
    - **Plain English:** When we need to sum sales for a big retailer, we first write down the names of every single cashier (up to thousands) and then hand that long list to the database — when we could just tell the database “only look at rows for this store” and let it do the work.  
    - **Evidence:**  
        ```php
        $affiliateIds = DB::table('commerce.brand_affiliate_rollup')
            ->where('brand_professional_id', $brandId)
            ->distinct()
            ->pluck('affiliate_professional_id');
        // later …
        $query = DB::table('analytics.site_visits')->whereIn('professional_id', $affiliateIds);
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **SCALE-6** · P2 — Shopify cost‑tracker learns query‑cost ratios globally, not per shop  
    - **Where:** app/Services/Shopify/Client/ShopifyCostTracker.php:22‑24 (`key` method)  
    - **Affects:** Pre‑acquisition budget estimates for every GraphQL call; a cost ratio learned from a shop with a small catalog may under‑reserve for a shop with a huge catalog, causing avoidable THROTTLED retries.  
    - **Effort:** S (~0.5–1h)  
    - **What to do:**  
        - Include `$shopDomain` in the Redis key, e.g. `shopify:cost:{$shopDomain}:{$queryHash}`.  
        - Continue to expire keys after inactivity to avoid unbounded key growth.  
    - **Technical:** The `estimate` method uses `Redis::lrange` on a key that contains only the query hash, so samples from all shops are pooled. Shopify’s actual cost for a query like `products(first: 50)` varies with the number of variants and metafields present; a shop with 1 000 variants per product will burn more points than one with 1 variant, but the global average will obscure that, causing the local bucket to under‑budget and hit THROTTLED more often.  
    - **Plain English:** We’re measuring fuel consumption by averaging across every type of car — a tiny hatchback and a heavy truck look the same on paper, so we sometimes give the truck too little fuel and it stalls. Better to track each car (each shop) separately.  
    - **Evidence:**  
        ```php
        private function key(string $queryHash): string
        {
            return "shopify:cost:{$queryHash}";
        }
        ```
    - `[DRAFT, confidence: 0.8]`
