
<!-- ═══ SUB-CHUNK: s1 (app/Http/Controllers/Api/Professional/Brand app/Http/Controllers/Api/Professional/SiteManagement) ═══ -->

- No findings identified in the provided scope.

<!-- ═══ SUB-CHUNK: s2 (app/Http/Controllers/Api/Professional/Analytics app/Http/Controllers/Api/Professional/Store) ═══ -->

- [ ] **#CACHE-1** · P2 — Triplicate brand-partner-link query inside a single cache-miss closure
    - **Where:** app/Http/Controllers/Api/Professional/Analytics/BrandCommerceAnalyticsController.php (in `queryPageViewsByBucket`, `querySiteVisitTotals`, `queryCartEventCounts`)
    - **Affects:** Brand dashboard overview — three independent helper methods each re-fetch the same `brand.brand_partner_links` rows on every cold-cache request.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Hoist the `brand.brand_partner_links` pluck into the `overview()` closure and pass the resulting `$affiliateIds` array into each helper.
        - If the array is empty, short-circuit before calling the helpers so they skip their main queries entirely.
    - **Technical:** Inside `BrandCommerceAnalyticsController::overview()`, the `rememberLocked` callback calls `queryPageViewsByBucket()`, `querySiteVisitTotals()`, and `queryCartEventCounts()`. Each method independently runs `DB::table('brand.brand_partner_links')->where('brand_professional_id', ...)->pluck('affiliate_professional_id')`. At 30 brands × 50 affiliates, this is three identical ~50-row queries instead of one — a minor write-amplification on the read path that compounds with every dashboard cold start. The result set is deterministic within the closure's lifetime and should be computed once.
    - **Plain English:** Imagine three different staff members each walking to the filing cabinet to pull the exact same list of affiliate IDs, one after another, instead of one person making a copy and handing it to the other two. Wastes a few seconds on every dashboard load.
    - **Evidence:**
        ```php
        // queryPageViewsByBucket, lines ~408-413
        $affiliateIds = DB::table('brand.brand_partner_links')
            ->where('brand_professional_id', $brandProfessionalId)
            ->whereNull('deleted_at')
            ->pluck('affiliate_professional_id')
            ->toArray();
        ```
        ```php
        // querySiteVisitTotals, lines ~433-438 — identical block
        $affiliateIds = DB::table('brand.brand_partner_links')
            ->where('brand_professional_id', $brandProfessionalId)
            ->whereNull('deleted_at')
            ->pluck('affiliate_professional_id')
            ->toArray();
        ```
        ```php
        // queryCartEventCounts, lines ~453-458 — identical block
        $affiliateIds = DB::table('brand.brand_partner_links')
            ->where('brand_professional_id', $brandProfessionalId)
            ->whereNull('deleted_at')
            ->pluck('affiliate_professional_id')
            ->toArray();
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CACHE-2** · P2 — Synchronous Shopify API fan-out in `resetToDefaults` across multiple brands
    - **Where:** app/Http/Controllers/Api/Professional/Store/AffiliateProductController.php:279-296
    - **Affects:** Affiliates linked to multiple brands triggering "reset to defaults" — each brand's re-seed is a synchronous external API call serialised in a `foreach`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Dispatch a dedicated `ResetAffiliateDefaultSelectionsJob` per brand-professional-id instead of calling `seedDefaultSelections` on the request thread.
        - Add a short rate-limit so a double-click can't enqueue duplicate resets.
    - **Technical:** When no `brand_professional_id` is passed, `resetToDefaults` fetches all linked brand IDs and iterates them with `$this->catalogService->seedDefaultSelections(...)`. `seedDefaultSelections` reaches out to Shopify (both read and write GraphQL calls), and each brand iteration blocks the loop. At 30 brands × 1–3 linked brands per affiliate, this is 1–3 synchronous Shopify round-trips holding the HTTP request open. A transient Shopify slowdown would push the response past a reasonable timeout and tie up a PHP-FPM worker. The canonical replacement is a chunked/batched fan-out: queue one job per brand and let Horizon process them concurrently.
    - **Plain English:** When an affiliate clicks "reset to defaults," the server makes a phone call to Shopify for every brand they're linked to, one after another, while the affiliate stares at a spinner. If Shopify takes 3 seconds per call and they're linked to 3 brands, that's a 9-second wait.
    - **Evidence:**
        ```php
        $brandIds = DB::table('brand.brand_partner_links')
            ->where('affiliate_professional_id', $pro->id)
            ->whereNull('deleted_at')
            ->pluck('brand_professional_id');

        foreach ($brandIds as $brandId) {
            try {
                $this->catalogService->seedDefaultSelections($pro, (string) $brandId, clearExisting: true);
            } catch (\Throwable $e) {
                report($e);
                Log::warning('Failed to reset default selections for brand', [...]);
            }
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CACHE-3** · P3 — Deprecated `shopSummary` method carries duplicate cache-key logic and live-query infrastructure
    - **Where:** app/Http/Controllers/Api/Professional/Analytics/ProfessionalAnalyticsController.php:259-403
    - **Affects:** Maintenance burden — any change to `CacheKeyGenerator::analyticsSummary()` or the commerce aggregate schema must be mirrored in this dead path.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Audit for remaining in-flight callers (route definition, frontend, mobile).
        - Remove the method and its route once no callers remain.
    - **Technical:** The method is annotated `@deprecated Data is now folded into summary()` and duplicates the caching pattern from `summary()`: it builds its own `$cacheKey` as a raw string (`'analytics:shop:'.$professional->id.':'...`) instead of using `CacheKeyGenerator`, re-implements date-range parsing, and runs independent live queries against `commerce.orders` and `analytics.*` tables. Every future schema or cache-key convention change creates a risk of drift between the two code paths. Dead code also shows up in code search results and confuses new contributors.
    - **Plain English:** This is like keeping an old, disconnected checkout counter behind the new one. It still plugs in, still has a cash register, but nobody uses it — yet every time the store remodels, someone has to remember to dust it. It should just be taken out.
    - **Evidence:**
        ```php
        /**
         * Shop analytics funnel for the authenticated professional (as affiliate).
         *
         * @deprecated Data is now folded into summary() — kept temporarily for in-flight callers.
         */
        public function shopSummary(Request $request): JsonResponse
        {
            // ...
            $cacheKey = 'analytics:shop:'.$professional->id.':'.$from->format('YmdH').':'.$to->format('YmdH').':'
                .($useHourlyBuckets ? 'hour' : 'day').":v{$summaryVersion}";
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#CACHE-4** · P3 — `BrandStoreSettingsController::show()` issues four uncached DB queries on every settings-page load
    - **Where:** app/Http/Controllers/Api/Professional/Store/BrandStoreSettingsController.php:30-64
    - **Affects:** Brand dashboard settings tab — every open of the Store Settings page hits `brand_store_settings`, `core.professionals` (via `$pro->loadMissing('site')`), `professional_integrations` (via `resolveBrandIntegration`), and `brand_profiles` on the request thread.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `show()` response in `CacheLockService::rememberLocked` with a 60s TTL (±20% jitter, SWR).
        - Invalidate the key from `update()` (and `deploy()`) so writes surface immediately.
    - **Technical:** `show()` reads `BrandStoreSettings`, loads the site relationship, resolves the Shopify integration (which touches `ProfessionalIntegration`), and fetches `BrandProfile` — all uncached. This is a low-traffic settings page (one user per brand, opened occasionally), so the absolute DB load is negligible at 30 brands. The value of adding a cache here is consistency: every other analytics/overview controller in the same directory already uses `rememberLocked`, and leaving this one uncached trains contributors that "settings endpoints don't need caching," which becomes a problem if a future settings endpoint becomes hot.
    - **Plain English:** The settings page runs four database lookups every time a brand opens it. At 30 brands this is completely harmless — it's like taking four steps to reach a light switch. The fix is just to add a sticky note so the pattern matches the rest of the house and nobody accidentally copies the "no cache" habit onto a page that actually gets heavy traffic.
    - **Evidence:**
        ```php
        public function show(Request $request): JsonResponse
        {
            $pro = $this->currentProfessional($request);
            $storeSettings = BrandStoreSettings::where('professional_id', $pro->id)->first();   // query 1
            $pro->loadMissing('site');                                                          // query 2 (relationship)
            // ...
            $resolved = $this->catalogService->resolveBrandIntegration($pro);                   // query 3 (ProfessionalIntegration)
            // ...
            $brandProfile = BrandProfile::where('professional_id', $pro->id)->first();          // query 4
            // ...
            return $this->success(new BrandStoreSettingsResource([...]));
        }
        ```
    - `[DRAFT, confidence: 0.8]`
