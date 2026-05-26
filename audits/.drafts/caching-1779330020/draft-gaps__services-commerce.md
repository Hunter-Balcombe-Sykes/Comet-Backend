- [ ] **#CCG-1** · P2 — Uncached Shopify Admin API calls for favourites collection products on hot affiliate catalog path
    - **Where:** app/Services/Store/BrandCatalogService.php — `fetchCollectionProducts()` (no cache wrapper)
    - **Affects:** Every affiliate catalog request (`/api/affiliate/products`) that tags products with the `in_favourites` flag; multiple synchronous Shopify GraphQL calls per request.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the call in `AffiliateProductCatalogService::fetchCollectionGids()` with `CacheLockService::rememberLocked` using a key from `CacheKeyGenerator` keyed on brand ID + collection handle (e.g. `brand_favourites_gids:{brandId}`).
        - Set a short TTL (60–120s) and bust on any product add/remove from the favourites collection via the existing `BrandCatalogService` write methods.
    - **Technical:** `fetchCollectionProducts()` paginates through every product in a Shopify collection via Admin API GraphQL (50 per page). For a brand with 200 favourites, that's 4 synchronous ~200ms vendor calls per affiliate catalog load. The resolved GID list is identical for every affiliate of the same brand — perfect `rememberLocked` candidate. The collection GID itself is already cached via `resolveCollectionGid()`, but the product membership list underneath is recomputed from Shopify on every request.
    - **Plain English:** Every time an affiliate opens their catalog, we call Shopify's servers to ask "which products are in the brand's favourites collection?" — and we make that same call for every affiliate of the same brand, over and over. It's like calling the warehouse to ask for the same inventory list every time a different sales rep walks in the door. Cache it once and share it.
    - **Evidence:**
        ```php
        // BrandCatalogService::fetchCollectionProducts() — no cache, paginates Shopify Admin API
        private function fetchCollectionProducts(ProfessionalIntegration $integration, string $collectionGid): array
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
                // ... pagination loop
            } while ($hasNextPage && $cursor !== null);

            return $products;
        }
        ```
        ```php
        // AffiliateProductCatalogService::fetchCollectionGids() — calls the uncached method
        private function fetchCollectionGids(ProfessionalIntegration $integration, string $metadataKey): array
        {
            // ...
            $products = $this->brandCatalogService->fetchCollectionProducts($integration, $collectionGid);
            return array_map(fn (array $p) => $p['gid'] ?? '', $products);
        }
        ```
        ```php
        // Called from getCatalogWithSelections() — the hot affiliate catalog endpoint
        $favouritesGids = $this->fetchCollectionGids($integration, 'favourites_collection_handle');
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CCG-2** · P3 — Repeated BrandPartnerLink query within a single affiliate catalog request
    - **Where:** app/Services/Store/AffiliateProductCatalogService.php — `resolveAffiliateBrandIntegration()` (line ~203) + `getCatalogWithSelections()` (line ~290)
    - **Affects:** Affiliate catalog endpoint; one redundant database query per request.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Have `resolveAffiliateBrandIntegration()` return the `BrandPartnerLink` (or its `custom_photos_enabled` value) alongside the existing `brand_professional_id` and `integration`.
        - Consume that pre-fetched value in `getCatalogWithSelections()` instead of re-querying.
    - **Technical:** `resolveAffiliateBrandIntegration()` queries `BrandPartnerLink` to resolve the brand ID and validate the connection. Later in the same request, `getCatalogWithSelections()` queries the same `BrandPartnerLink` row again solely to read `custom_photos_enabled`. This is a textbook request-scoped memoisation gap — the first query's result is discarded and re-fetched 50 lines later. Fix is a pure in-process optimisation: return the link (or the boolean) from the resolver, no Redis needed.
    - **Plain English:** The code asks the database "which brand is this affiliate connected to?" and gets back a full answer card. Then 50 lines later, it throws away that card and asks the database again for the same information to check one extra checkbox on it. Keep the card and read the checkbox from it.
    - **Evidence:**
        ```php
        // First query — in resolveAffiliateBrandIntegration()
        $link = BrandPartnerLink::query()
            ->where('affiliate_professional_id', $affiliate->id)
            ->first();
        // ... returns brand_professional_id and integration, discards $link
        ```
        ```php
        // Second query — later in getCatalogWithSelections(), same request
        $link = BrandPartnerLink::query()
            ->where('affiliate_professional_id', $affiliate->id)
            ->where('brand_professional_id', $brandId)
            ->first();
        $customPhotosEnabled = (bool) ($link?->custom_photos_enabled ?? false);
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#CCG-3** · P2 — Uncached multi-aggregate analytics read on affiliate projections dashboard
    - **Where:** app/Services/Analytics/AffiliateProjectionsService.php — `build()` (no `CacheLockService::rememberLocked` wrapper)
    - **Affects:** Affiliate dashboard projections widget (run-rate, momentum, YTD, best month, year-end forecast) — 5 aggregate queries per request with zero caching.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap `build()` in `CacheLockService::rememberLocked` with a key from `CacheKeyGenerator` (e.g. `affiliate_projections:{professionalId}`) and a moderate TTL (120–300s).
        - Bust the key on any order ingest that touches `commerce.brand_affiliate_rollup` for this affiliate, or accept the TTL staleness (projections are inherently lagging indicators).
    - **Technical:** `build()` fires 5 separate aggregate queries per call: a windowed SUM with jsonb_agg and COUNT DISTINCT FILTER, a prior-window SUM, a YTD SUM, a window-function best-month query with DISTINCT ON and date_trunc, and a history-days lookup. These run against the rollup table which is indexed, but the combination of jsonb_agg, window functions, and DISTINCT ON per request adds up at the scale target (10K affiliates × periodic dashboard checks). The result set is per-affiliate and changes only when new rollup rows land — a 2–5 minute cache is both safe and effective.
    - **Plain English:** Every time an affiliate opens their earnings projections dashboard, we run five separate calculator operations against the database — even if they refresh 30 seconds later and nothing has changed. It's like redoing your taxes from scratch every time you check your bank balance. Cache the result for a few minutes and only recalculate when new money actually arrives.
    - **Evidence:**
        ```php
        public function build(Professional $professional, ?int $windowDaysOverride = null): array
        {
            // ... resolve window bounds ...

            $perCurrency = $this->fetchPerCurrencyAggregates(       // Query 1: SUM + jsonb_agg + COUNT DISTINCT FILTER
                $professional->id, $windowFrom, $windowTo, $windowDays
            );
            $priorByCurrency = $this->fetchPriorWindowAggregates(   // Query 2: SUM
                $professional->id, $priorWindowFrom, $priorWindowTo
            )->keyBy('currency_code');
            $ytdByCurrency = $this->fetchYtdAggregates(             // Query 3: SUM
                $professional->id, $yearStart
            )->keyBy('currency_code');
            $bestMonthByCurrency = $this->fetchBestMonthPerCurrency( // Query 4: window function + DISTINCT ON + date_trunc
                $professional->id, $yearStart
            )->keyBy('currency_code');
            // ... resolveDataHistoryDays also fires Query 5 ...
            // No Cache:: or CacheLockService anywhere in the method.
        }
        ```
    - `[DRAFT, confidence: 0.8]`
