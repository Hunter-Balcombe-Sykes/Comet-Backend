`★ Insight ─────────────────────────────────────`
Three adjudication-critical discoveries before writing the final audit:
1. **DeepSeek missed the most urgent P1s**: `EmbeddedSetupController::overview()` and `EmbeddedOrderAnalyticsController` still read `CommissionMovement WHERE status IN ('pending','approved')`, but the model's own docblock confirms all accrual rows were deleted by the Phase 4 migration (`20260506500000_drop_legacy_aggregates.sql`). These controllers now silently return zeroed/empty data.
2. **Phantom fix methods**: DeepSeek's CACHE-6 fix references `CacheKeyGenerator::embeddedSetupOverview()` which doesn't exist. CACHE-2's fix references `SiteCacheService::forgetBrandConfig()` which also doesn't exist. Both need to be added.
3. **Caching CACHE-8 (overview) is blocked by CACHE-3 (wrong table)** — merging them avoids prescribing caching on a broken data path.
`─────────────────────────────────────────────────`

# Caching & Write Amplification Audit — 2026-05-11

**Branch:** development
**Lens:** Scaling antipatterns: write amplification, rebuild-on-write, weak caching
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/Internal/HydrogenAffiliateController.php
- app/Http/Controllers/Api/Internal/HydrogenAffiliateProductsController.php
- app/Http/Controllers/Api/Internal/HydrogenBrandConfigController.php
- app/Http/Controllers/Api/Internal/HydrogenBrandDesignController.php
- app/Http/Controllers/Api/Internal/EmbeddedSetupController.php
- app/Http/Controllers/Api/Internal/EmbeddedProductAnalyticsController.php
- app/Http/Controllers/Api/Internal/EmbeddedOrderAnalyticsController.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffAnalyticsController.php
- app/Http/Controllers/Api/Professional/Analytics/AffiliateCommerceAnalyticsController.php
- app/Http/Controllers/Api/Professional/Analytics/BrandCommerceAnalyticsController.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/SiteCacheService.php
- app/Models/Retail/CommissionMovement.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 4 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **#CACHE-1** · P1 — HydrogenAffiliateController assembles affiliate site payload from 13+ DB queries per request with zero server-side caching
    - **Where:** app/Http/Controllers/Api/Internal/HydrogenAffiliateController.php:44–82 (show method)
    - **Affects:** Every public storefront page view (end-customers browsing affiliate pages). At 30 brands × 50 affiliates × modest page traffic, this single endpoint dominates read load — no caching layer absorbs any repeat calls.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `CacheKeyGenerator::hydrogenAffiliate(string $brandProfessionalId, string $affiliateHandle): string` method to `CacheKeyGenerator` (pattern: `"hydrogen:affiliate:v1:{$brandId}:{$handle}"`).
        - Wrap the entire payload assembly in `CacheLockService::rememberLocked($key, 60, fn() => [...])` inside `show()`. Inject `CacheLockService` via the constructor.
        - Add push-invalidation to every write path that can mutate the affiliate payload: `SiteObserver` / `BlockObserver` / `SiteMediaObserver` should call a new `SiteCacheService::forgetHydrogenAffiliate(string $siteId)` method that calculates and forgets the affected key.
        - Keep the `Cache-Control: no-store` response header unchanged for now (see CACHE-9 for a follow-on CDN optimisation).
    - **Technical:** `show()` resolves `ProfessionalIntegration`, `Professional`, `BrandPartnerLink`, `Site`, all section `Blocks` (keyed by `block_type`), gallery `SiteMedia` with `mediaVariants` eager-load, content images, links, bio credentials/experience, document, newsletter settings, services with category, and booking settings — all fresh on every request. Affiliate site data changes only on explicit dashboard edits; a 60 s `rememberLocked` window with push-invalidation would absorb effectively all reads with zero correctness impact. The canonical pattern is established in `BrandCommerceAnalyticsController` and `AffiliateCommerceAnalyticsController` — both use `CacheLockService::rememberLocked` with TTL + SWR. The corresponding `CacheKeyGenerator` keys (`brandCommerceAnalytics`, `affiliateCommerceAnalytics`) show the versioned naming convention to follow.
    - **Plain English:** Every time a customer opens an affiliate's storefront page, the server opens the equivalent of 13 filing cabinets — photo gallery, bio, links, services, booking — even if nothing changed since the last visit. Taking a 60-second snapshot and sharing it across visitors until something actually changes would eliminate almost all of this work with no visible impact on the affiliate's page.
    - **Evidence:**
        ```php
        return $this->success([
            'affiliate_id' => (string) $affiliate->id,
            'name' => $affiliate->display_name,
            'slug' => $affiliate->handle,
            'gallery' => $this->getAffiliateGallery($site, $sections),
            'content_images' => $this->getAffiliateContent($site),
            'links' => $this->getAffiliateLinks($site, $booking),
            'bio' => $this->getAffiliateBio($affiliate, $sections),
            'document' => $this->getAffiliateDocument($site),
            'newsletter' => $this->getAffiliateNewsletter($sections),
            'services' => $this->getAffiliateServices($site, $affiliate->id, $sections),
            'booking' => $booking,
            'shop' => $this->sectionEnvelope($sections, 'shop', fn () => null),
        ])->header('Cache-Control', 'no-store');
        ```

- [ ] **#CACHE-2** · P1 — HydrogenBrandConfigController assembles brand config from 5+ DB queries per request with zero server-side caching
    - **Where:** app/Http/Controllers/Api/Internal/HydrogenBrandConfigController.php:27–70 (show method)
    - **Affects:** Every Hydrogen storefront initial render — this config endpoint is fetched on first load for every visitor to every brand's store. 30 brands × all their page traffic.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CacheKeyGenerator::hydrogenBrandConfig(string $shopDomain): string` (pattern: `"hydrogen:brand-config:v1:{$shopDomain}"`).
        - Inject `CacheLockService` and wrap the `show()` response body in `rememberLocked($key, 60, ...)`.
        - Add a `SiteCacheService::forgetHydrogenBrandConfig(string $professionalId)` method (parallel to the existing `forgetBrandDesign`) and call it from `BrandStoreSettings` observer and the Shopify integration write paths.
    - **Technical:** `show()` queries `ProfessionalIntegration` (by `shopify_shop_domain`), `Professional`, `Site`, `BrandStoreSettings`, and `SiteMedia` (fallback gallery) on every request. This payload changes only when a brand edits store settings or an integration is reprovisioned — both rare events. `HydrogenBrandDesignController` already has a 5 s cache for the design sub-payload using `Cache::memo()`, but the full config envelope has no cache at all. Note: the proposed `SiteCacheService::forgetBrandConfig` method referenced in the DeepSeek draft does not exist yet and must be added alongside the cache key.
    - **Plain English:** Every storefront visitor triggers a fresh database read for the brand's name, logo token, commission rate, and collection handles — information that changes perhaps once a month. A 60-second server-side copy shared across all visitors would make the storefront feel faster and cost far less in database work.
    - **Evidence:**
        ```php
        $integration = ProfessionalIntegration::query()
            ->where('shopify_shop_domain', $shopDomain)
            ->where('provider', ProfessionalIntegration::PROVIDER_SHOPIFY)
            ->first();
        // ... resolves professional, metadata, site, storeSettings, design, media ...
        return $this->success([
            'brand_professional_id' => (string) $professional->id,
            'brand_name' => $professional->display_name,
            'brand_handle' => $professional->handle,
            'shop_domain' => $shopDomain,
            'storefront_access_token' => $integration->storefront_token,
            'default_commission_rate' => $storeSettings ? (float) $storeSettings->default_commission_rate : 15.0,
            // ... collection handles, design tokens, fallback_gallery ...
        ]);
        ```

- [ ] **#CACHE-3** · P1 — EmbeddedSetupController::overview() reads CommissionMovement for accrual data that no longer exists post-Phase-4, silently returning zero metrics on the brand's embedded dashboard
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedSetupController.php:316–371 (overview method)
    - **Affects:** Every brand using the embedded Shopify app. The overview panel shows `total_commission_cents`, `commission_30d_cents`, and `revenue_30d_cents` — all of which will be zero even when real attributed commissions exist, because the underlying table no longer stores accrual-type rows.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace all `CommissionMovement` queries in `overview()` with live queries against `commerce.orders` using the same pattern as `BrandCommerceAnalyticsController::buildCommissionSummary()`: `SUM(commission_cents)` where `status NOT IN (Order::EXCLUDED_FROM_AGGREGATES)`.
        - For "pending" (unpaid) commission: `commerce.orders WHERE brand_professional_id = $id AND status NOT IN (excluded) AND payout_id IS NULL`.
        - For `recent_sales`, switch to a `commerce.orders JOIN core.professionals` query (affiliate name from `affiliate_professional_id`).
        - **While you're here:** wrap the rewritten payload in `CacheLockService::rememberLocked` keyed by `"embedded:setup:overview:{$professionalId}"` (add this key to `CacheKeyGenerator`) with 60 s TTL + SWR; bust via `AnalyticsCacheService::invalidateAnalytics()` on every commerce write. The `EmbeddedSetupController` constructor already injects `CacheLockService $cacheLock`, so no new wiring is needed.
    - **Technical:** The `CommissionMovement` model's docblock (lines 11–19) explicitly confirms that all accrual and reversal rows were deleted by migration `20260506500000_drop_legacy_aggregates.sql`. The table's scope is now narrowed to `entry_type IN ('payout','clawback','adjustment')` — neither has `status = 'pending'` or `status = 'approved'` in the accrual sense. Querying `WHERE status IN ('pending', 'approved')` therefore returns empty results, causing all three commission metrics to show zero. `BrandCommerceAnalyticsController` already demonstrates the correct live-query pattern against `commerce.orders`; `EmbeddedSetupController` predates Phase 4 and was not updated. Note: the `CacheKeyGenerator::embeddedSetupOverview()` method referenced in the DeepSeek draft does not exist and must be added when implementing the cache wrapper.
    - **Plain English:** During Phase 4, the database was reorganised so that "commissions owed" are tracked directly on orders instead of in a separate tally table. The embedded brand dashboard wasn't updated to look in the new place — so right now it reads from the old, empty location and shows zero in every commission field, even when the brand has affiliates actively generating sales. The fix is to redirect those queries to the correct table.
    - **Evidence:**
        ```php
        // CommissionMovement model docblock (app/Models/Retail/CommissionMovement.php:11-19):
        // Money-movement ledger. Scope is enforced at the DB level post-Phase-4 (accrual and
        // reversal rows were deleted by 20260506500000_drop_legacy_aggregates.sql; the table
        // itself was renamed by 20260506600000_rename_ledger_to_movements.sql):
        //   - entry_type='payout'     — payout settled
        //   - entry_type='clawback'   — post-payout reversal
        //   - entry_type='adjustment' — manual support correction

        // EmbeddedSetupController::overview() (lines 322–355):
        $commissionQuery = CommissionMovement::where('brand_professional_id', $professionalId)
            ->whereIn('status', ['pending', 'approved']);

        $totalCommissionCents = (int) $commissionQuery->sum('amount_cents');
        // ... commission30dCents, revenue30dCents, recentSales — all from CommissionMovement
        ```

- [ ] **#CACHE-4** · P1 — EmbeddedOrderAnalyticsController reads CommissionMovement for per-order line-item breakdown that no longer exists post-Phase-4, always returning `has_affiliate: false`
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedOrderAnalyticsController.php:47–57 (show method)
    - **Affects:** Every brand using the "affiliate-order-block" Shopify admin UI extension. The per-order commission breakdown — affiliate attribution, line-item commission rates, status summary — returns empty for all orders because the accrual entries that backed it were deleted in Phase 4.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Rewrite `show()` to query `commerce.order_items JOIN commerce.orders` for line-item breakdown, parallel to the pattern already used in `EmbeddedProductAnalyticsController::build()` (lines 67–116 of that file).
        - Affiliate attribution comes from `commerce.orders.affiliate_professional_id` — join `core.professionals` for the display name.
        - Commission rate, `line_total_cents`, and `commission_cents` per line item come from `commerce.order_items` columns (already mirrored from `line_items` JSONB by the trigger).
        - Status summary maps `commerce.orders.status` to the display states rather than per-entry statuses.
    - **Technical:** The `CommissionMovement` model confirms all per-order accrual rows were deleted by Phase 4's `20260506500000_drop_legacy_aggregates.sql`. The query `WHERE shopify_order_id = $orderId` returns zero rows for every non-refunded order, so the `$entries->isEmpty()` branch fires unconditionally and returns `has_affiliate: false`. The controller is listed in the lens prompt's out-of-scope clause as "already on the new live-query path" but the source contradicts this — only `EmbeddedProductAnalyticsController` (which uses `commerce.order_items`) was migrated; `EmbeddedOrderAnalyticsController` was not. `EmbeddedProductAnalyticsController::build()` is the direct model to follow.
    - **Plain English:** When a brand clicks on an order in Shopify to see which affiliate drove it and what commission was earned per item, the app looks in the old (now empty) tally table and always reports "no affiliate." The fix is to look in the updated order tracking system where this information actually lives now.
    - **Evidence:**
        ```php
        $entries = CommissionMovement::with('affiliateProfessional:id,display_name')
            ->where('brand_professional_id', $professionalId)
            ->where('shopify_order_id', $orderId)
            ->orderBy('id')
            ->get();

        if ($entries->isEmpty()) {
            return $this->success([
                'order_id' => $orderId,
                'has_affiliate' => false,
                // ...
            ]);
        }
        ```

---

## P2 — Should fix

- [ ] **#CACHE-5** · P2 — HydrogenBrandDesignController uses bare `Cache::memo()->remember()` without single-flight lock, exposing a stampede on cold cache
    - **Where:** app/Http/Controllers/Api/Internal/HydrogenBrandDesignController.php:53–57 (show method)
    - **Affects:** Hydrogen storefront design payload — fetched on every page load across all visitors to every brand's storefront. On a cold cache (post-deploy, Redis eviction), concurrent visitors all execute `buildDesignPayload()` simultaneously.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::memo()->remember($cacheKey, self::CACHE_TTL_SECONDS, ...)` with `$this->cacheLock->rememberLocked($cacheKey, self::CACHE_TTL_SECONDS, ...)`. Inject `CacheLockService` via the constructor (parallel to `BrandDesignMediaService` already injected there).
        - The existing 5 s TTL is appropriate for rapid design-change propagation; keep it. No jitter change needed at 5 s — synchronized expiry across brands is acceptable at this short interval.
    - **Technical:** `Cache::memo()` provides a process-local in-memory cache per request but no Redis-level single-flight lock. On cache miss (post-deploy, Redis restart, or eviction), N concurrent Hydrogen loader requests for the same brand all execute `buildDesignPayload()` — which queries `Professional`, `Site`, `ProfessionalIntegration`, `SiteMedia`, and calls `BrandDesignMediaService::listDesignMedia()` — simultaneously until one populates the key. `CacheLockService::rememberLocked` acquires a Redis lock; waiters serve stale data via SWR if available or wait up to `blockSeconds` before the first caller populates. The SWR window is the main benefit at a 5 s TTL. The existing `SiteCacheService::forgetBrandDesign(string $siteId)` already provides push-invalidation via the correct cache key.
    - **Plain English:** When the design cache for a brand's storefront expires (which happens every 5 seconds by design), the next several visitors who arrive in that 5-second window all trigger separate database fetches instead of one person doing the work and everyone else waiting for the result. A simple locking pattern ensures only one fetch happens at a time.
    - **Evidence:**
        ```php
        private const CACHE_TTL_SECONDS = 5;

        $payload = Cache::memo()->remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn () => $this->buildDesignPayload($professional, $site)
        );
        ```

- [ ] **#CACHE-6** · P2 — EmbeddedProductAnalyticsController uses `Cache::memo()->remember()` without single-flight lock or push-invalidation on commerce writes
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedProductAnalyticsController.php:43–47 (show method)
    - **Affects:** Shopify admin product analytics block — shown to brand team members on the Shopify product detail page. Stale data for up to 5 minutes after a sale; stampede risk when multiple team members open the product page simultaneously post-cache-eviction.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::memo()->remember($cacheKey, now()->addMinutes(5), ...)` with `$this->cacheLock->rememberLocked($cacheKey, 300, ...)`. Inject `CacheLockService` via the constructor.
        - Add push-invalidation: call `Cache::forget($cacheKey)` from the commerce write path (the Shopify orders webhook handler / `AnalyticsCacheService`) when a new order for that product arrives. The key pattern `"embedded:product-analytics:{$professionalId}:{$productId}"` is already well-formed for single-key forget.
    - **Technical:** The `build()` method joins `commerce.order_items + commerce.orders + core.professionals`, aggregates variant-level sales in PHP, and performs a full Shopify Admin API catalog fetch via `BrandCatalogService::fetchBrandCatalog()`. Both operations are expensive; the 5-minute TTL was chosen to limit re-runs, but `Cache::memo()` has no single-flight lock. Multiple Shopify admin tabs opening after a cache eviction all execute the full `build()` concurrently. The existing key structure is correctly namespaced per brand + product so a targeted forget on webhook receipt is straightforward.
    - **Plain English:** When multiple team members open the same product page in Shopify at the same moment after the cache expires, they each independently trigger the same heavy calculation instead of one person fetching and sharing the result. A lock ensures only one fetch runs at a time; a push notification clears the cache instantly when a real sale arrives.
    - **Evidence:**
        ```php
        $cacheKey = "embedded:product-analytics:{$professionalId}:{$productId}";

        return Cache::memo()->remember($cacheKey, now()->addMinutes(5), function () use ($professionalId, $productId) {
            return $this->success($this->build($professionalId, $productId));
        });
        ```

- [ ] **#CACHE-7** · P2 — HydrogenAffiliateProductsController has no server-side caching for per-affiliate product selections and custom photos
    - **Where:** app/Http/Controllers/Api/Internal/HydrogenAffiliateProductsController.php:35–78 (show method)
    - **Affects:** Hydrogen storefront product listings — fetched after initial page load for every affiliate page view. 30 brands × 50 affiliates × daily traffic hits this cold on every request.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CacheKeyGenerator::hydrogenAffiliateProducts(string $affiliateId): string` (pattern: `"hydrogen:affiliate-products:v1:{$affiliateId}"`).
        - Inject `CacheLockService` and wrap `show()`'s payload assembly in `rememberLocked($key, 60, ...)`.
        - Push-invalidate when `AffiliateProductSelection` rows change (via observer or explicit forget in the product selection write endpoints) and when custom photos are uploaded/deleted (via `SiteMediaObserver`).
    - **Technical:** `show()` queries `AffiliateProductSelection` (ordered GIDs), `BrandPartnerLink`, `ProfessionalIntegration`, `Site`, and `SiteMedia` (custom photos with `mediaVariants` eager-load) on every request. Product selections change only when an affiliate curates their product list; custom photos change only on upload/delete. A 60 s `rememberLocked` cache with push-invalidation would absorb effectively all reads with zero correctness impact, matching the pattern established for commerce analytics.
    - **Plain English:** The list of products an affiliate chooses to showcase — and the custom photos they've uploaded for them — changes maybe once a week, but the server assembles it fresh from the database on every page load. A 60-second snapshot that automatically refreshes when the affiliate makes an edit would cut database work dramatically.
    - **Evidence:**
        ```php
        $selections = AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $affiliateId)
            ->orderBy('sort_order')
            ->pluck('shopify_product_gid')
            ->all();

        $link = BrandPartnerLink::query()
            ->where('affiliate_professional_id', $affiliateId)
            ->first();
        // ... resolves integration, custom photos via getCustomPhotos() ...
        ```

---

## P3 — Nice to have

- [ ] **#CACHE-8** · P3 — StaffAnalyticsController runs 4–5 uncached analytics aggregation queries per staff page load
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffAnalyticsController.php:44–121 (summary method)
    - **Affects:** Staff operations dashboard — analytics view per professional. Low traffic (support staff use), but the queries scan date-range-indexed `analytics.site_visits` and `analytics.link_clicks` tables, which are the heaviest read paths in the schema.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `summary()` response body in `CacheLockService::rememberLocked` keyed by professional ID + date range string, with a 60–120 s TTL.
        - No push-invalidation needed at staff-tool traffic levels; TTL-only eviction is fine. Add ±20% jitter to prevent synchronized expiration if a monitoring tool ever auto-refreshes multiple professionals in parallel.
    - **Technical:** `summary()` executes (1) site_visits aggregation (total, unique, last visit), (2) link_clicks aggregation in a separate try/catch, (3) daily visits timeseries, (4) daily clicks timeseries, and (5) a top-links join against `site.blocks` — five separate heavy queries. The try/catch fallback pattern for link_clicks (returning zeros when the table is unavailable) indicates the query is known to be fragile; wrapping in a cache that serves last-good results during degradation is a useful side-effect of the fix. `StaffStatsController` (the platform-wide stats endpoint) already uses `CacheLockService::rememberLocked` with 60 s TTL as the reference implementation.
    - **Plain English:** Each time a support person views a professional's analytics, the server runs five separate statistical queries even if they just refreshed the page 10 seconds ago. A 60-second snapshot makes the page feel instant and reduces load on the analytics tables.
    - **Evidence:**
        ```php
        $visitsAgg = DB::table('analytics.site_visits')
            ->where('professional_id', $professional->id)
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw('COUNT(*) as total_visits')
            ->selectRaw('COUNT(DISTINCT COALESCE(visitor_id::text, ip_hash)) as unique_visitors')
            ->selectRaw('MAX(occurred_at) as last_visit_at')
            ->first();
        // ... separate link_clicks aggregation in try/catch ...
        // ... daily visits timeseries, daily clicks timeseries, top links join ...
        ```

- [ ] **#CACHE-9** · P3 — HydrogenAffiliateController sends `Cache-Control: no-store` with no server-side SWR, preventing all edge caching of a fully server-cached payload
    - **Where:** app/Http/Controllers/Api/Internal/HydrogenAffiliateController.php:82 (show return)
    - **Affects:** CDN and Oxygen edge caching strategy for affiliate storefront pages. Dependent on CACHE-1 being implemented first.
    - **Effort:** S (~0.5–1h) — contingent on CACHE-1 completion
    - **What to do:**
        - After CACHE-1 is in place (Redis `rememberLocked` absorbing DB load), evaluate changing `Cache-Control: no-store` to `public, max-age=0, must-revalidate` plus an `ETag` derived from the Redis cache key's version token. This allows Oxygen/CDN edge nodes to serve stale-while-revalidate without personalised data leaking across users.
        - Alternatively: keep `no-store` and rely entirely on the Redis layer — this is the simpler path if affiliate page content is considered personalised (e.g. `linked` status check per brand).
    - **Technical:** `no-store` prevents all intermediate caching including Oxygen edge nodes geographically close to end-users. Once CACHE-1 adds a Redis SWR cache, the Redis layer handles DB-level single-flight correctly, but HTTP-level edge caching still requires a valid `Cache-Control` directive. The note in the source — `// no-store: payload shape has evolved (e.g. links.id added in b9de807). Prevent Oxygen/CDN from caching a stale shape across deploys.` — indicates the `no-store` was originally added to prevent deploy-day shape mismatches. A deploy-keyed `ETag` or a version segment in the cache key resolves this concern without disabling edge caching entirely.
    - **Plain English:** After we add the server-side cache in CACHE-1, we can also tell the content delivery network "you may hold a copy of this page and only check back every 60 seconds" — which would serve requests from servers geographically closer to each visitor. Right now the app tells every server in between "never cache this, ever," which was a safe default during active development but costs performance in production.
    - **Evidence:**
        ```php
        // no-store: payload shape has evolved (e.g. links.id added in b9de807).
        // Prevent Oxygen/CDN from caching a stale shape across deploys.
        return $this->success([...])->header('Cache-Control', 'no-store');
        ```
