`★ Insight ─────────────────────────────────────`
- DeepSeek's CACHE-1 draft evidence was verbatim-correct but the premise was wrong: it quoted the 5-query `build()` body but missed that `AffiliateProjectionsController::show()` wraps the call in `CacheLockService::rememberLocked` with a 300s TTL + SWR + push-invalidation from `AnalyticsCacheService::invalidateAnalytics()` — a complete, canonical implementation. Always chase the call graph up to the controller before accepting a "no caching" finding.
- The real antipattern surfaced instead in `AffiliateProductCatalogService` and `BrandCatalogService`: both use `Cache::memo()->remember` — which has no distributed lock — against external Shopify API calls, allowing N concurrent requests to each fire their own Shopify call on a cold cache.
- `Cache::memo()->remember` with a `DateTimeInterface` TTL also bypasses `CacheLockService`'s ±20% jitter because jitter is only applied to int-second TTLs — meaning all cache entries populated in the same second expire at exactly the same second, compounding the stampede window.
`─────────────────────────────────────────────────`

# Scaling Antipatterns Audit — 2026-05-11

**Branch:** development
**Lens:** Scaling antipatterns: write amplification, rebuild-on-write, weak caching
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Analytics/AffiliateProjectionsService.php
- app/Http/Controllers/Api/Professional/Analytics/AffiliateProjectionsController.php
- app/Services/Store/AffiliateProductCatalogService.php
- app/Services/Store/BrandCatalogService.php
- app/Services/Cache/CacheLockService.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/AnalyticsCacheService.php
- app/Services/Cache/SiteCacheService.php
- app/Services/Cache/ProfessionalCacheService.php
- app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php
- app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php
- app/Jobs/Notifications/SendWeeklyAnalyticsNotificationJob.php
- app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php
- app/Jobs/Shopify/ProcessShopifyOrderWebhookJob.php
- app/Services/Notifications/NotificationPublisher.php
- app/Services/Notifications/CommerceNotificationService.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffStatsController.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffAnalyticsController.php
- app/Http/Controllers/Api/Professional/Booking/BookingAnalyticsController.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 0 complete

---

## P2 — Should fix

- [ ] **#CACHE-1** · P2 — `AffiliateProductCatalogService::fetchActiveCatalog` uses unlocked cache against Shopify Storefront API
    - **Where:** app/Services/Store/AffiliateProductCatalogService.php:192–196
    - **Affects:** Every affiliate who views a brand's store page. After any catalog-busting write (product enable/disable/active-flip), all concurrently-loading affiliates fire independent Shopify Storefront API calls for the same catalog instead of sharing one result. Category 3: weak cache on hot read — no single-flight lock, no jitter, no SWR.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Inject `CacheLockService` into `AffiliateProductCatalogService::__construct`.
        - Replace `Cache::memo()->remember(CacheKeyGenerator::brandActiveCatalog($brandProfessionalId), now()->addMinutes(5), ...)` with `$this->cacheLock->rememberLocked(CacheKeyGenerator::brandActiveCatalog($brandProfessionalId), 300, ...)`. Pass `300` (int seconds) not `now()->addMinutes(5)` (`DateTimeInterface`) — `CacheLockService::writeWithJitter` skips ±20% jitter for `DateTimeInterface` deadlines, so a `DateTime` TTL produces synchronised expiry across all cached entries that were filled in the same second.
        - Extend every existing bust call — currently `Cache::forget(CacheKeyGenerator::brandActiveCatalog(...))` at lines 589, 678, 712, 982 — to also forget the SWR stale key: `Cache::forget(CacheKeyGenerator::brandActiveCatalog($id).':stale')`.
    - **Technical:** `Cache::memo()->remember` provides in-process memoisation layered over Redis but no distributed lock. When the Redis key is cold (evicted by TTL or by a `Cache::forget` on a write), every PHP Horizon worker serving an affiliate request independently calls `queryStorefrontCatalog()`, which issues a paginated Shopify Storefront GraphQL query. At 50 affiliates per brand loading the store page within the same second — the documented pattern after a brand publishes a product update — this dispatches 50 simultaneous Storefront API calls for identical data. Shopify's Storefront API limit is generous but the calls are wasted, increase P95 latency for affiliates waiting in parallel, and the pattern scales linearly with affiliate count. The canonical pattern already deployed for site payloads, analytics, and projections is `CacheLockService::rememberLocked`: only one worker runs the closure, the rest block (cold) or return the last-good value (SWR), and TTL jitter spreads the next expiry wave across the ±20% window.
    - **Plain English:** Imagine a restaurant where every waiter has to personally go to the supplier to pick up the day's menu whenever the kitchen changes it. If 50 waiters are on shift and the kitchen updates the menu at 6pm, all 50 run to the supplier simultaneously — even though one trip would have been enough. The fix is to designate one runner: the first waiter fetches the new menu, everyone else waits at the door; when the runner returns, all 50 read from the same copy. If the runner is taking too long, the others can hand out yesterday's menu in the meantime.
    - **Evidence:**
        ```php
        public function fetchActiveCatalog(string $brandProfessionalId): array
        {
            return Cache::memo()->remember(
                CacheKeyGenerator::brandActiveCatalog($brandProfessionalId),
                now()->addMinutes(5),
                fn () => $this->queryStorefrontCatalog($brandProfessionalId),
            );
        }
        ```

- [ ] **#CACHE-2** · P2 — `BrandCatalogService::fetchBrandCatalog` uses unlocked cache against Shopify Admin API
    - **Where:** app/Services/Store/BrandCatalogService.php:374–378
    - **Affects:** Brand admin catalog dashboard, `EmbeddedSetupController`, `EmbeddedProductAnalyticsController`, and the `AffiliateProductCatalogService` metafield-merge fallback path. After any metafield or variant write (which calls `Cache::forget` on `brandAdminCatalog`), concurrent requests fire simultaneous paginated Shopify Admin GraphQL queries. Category 3: weak cache without single-flight, no SWR, bust calls leave no stale fallback.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Inject `CacheLockService` into `BrandCatalogService::__construct`.
        - Replace `Cache::memo()->remember(CacheKeyGenerator::brandAdminCatalog((string) $brand->id), (int) config('partna.cache.ttls.brand_admin_catalog'), ...)` with `$this->cacheLock->rememberLocked(CacheKeyGenerator::brandAdminCatalog((string) $brand->id), (int) config('partna.cache.ttls.brand_admin_catalog'), ...)`. The config value is already an int, so jitter will be applied automatically.
        - Extend all four bust sites (lines 588, 677, 711, 982) to also clear `CacheKeyGenerator::brandAdminCatalog($id).':stale'`.
        - Do the same for the `Cache::memo()->remember` on `CacheKeyGenerator::brandCollectionGid` at line 835 — same root cause, same fix.
    - **Technical:** `queryAdminCatalog()` issues a paginated Shopify Admin GraphQL query using the `PRODUCTS_WITH_METAFIELDS` query shape (50-product pages × however many pages the brand's catalog requires, each consuming ~600–800 Admin API cost points). The Admin API budget is 1000 points/second with a leak-refill model; a concurrent stampede of even 3–4 parallel full-catalog fetches (brand admin with multiple open tabs, plus the embedded setup flow loading on the same Shopify admin page) can exhaust the per-store budget, causing subsequent calls to return throttle errors until the bucket refills. The GDPR `Cache::forget` bust calls at lines 588, 677, and 711 are correct in intent but leave no stale copy — meaning a bust under concurrent load is a hard cold miss with no fallback, whereas `rememberLocked`'s SWR layer would serve last-good immediately to all waiters except the one lock-winner doing the refetch.
    - **Plain English:** The brand's full product list (with all the special settings like commission rates and enable/disable states) gets fetched fresh from Shopify whenever the saved copy expires or someone saves a change. Shopify limits how many requests can be made per second per store. If a brand admin has three browser tabs open and two of them load at the same time after a save, the system makes three separate trips to Shopify for the same list simultaneously. The fix is the same runner pattern as CACHE-1: only one request goes to Shopify, the others wait and read the same result. While the runner is fetching, anyone who asks gets the previous version instantly rather than waiting or hitting an error.
    - **Evidence:**
        ```php
        public function fetchBrandCatalog(Professional $brand): array
        {
            return Cache::memo()->remember(
                CacheKeyGenerator::brandAdminCatalog((string) $brand->id),
                (int) config('partna.cache.ttls.brand_admin_catalog'),
                fn () => $this->queryAdminCatalog($brand),
            );
        }
        ```
