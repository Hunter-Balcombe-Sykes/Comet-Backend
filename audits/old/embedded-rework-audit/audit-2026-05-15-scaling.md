`★ Insight ─────────────────────────────────────`
The audit reveals a structural asymmetry worth understanding: `EmbeddedProductAnalyticsController` was already fixed (uses `rememberLocked` + push invalidation) but its sibling `EmbeddedProductSettingsController` shares the same extension mount path without any caching — a classic "we fixed one endpoint but forgot the adjacent one" pattern. Meanwhile, the analytics ingest path has the opposite problem: it invalidates too aggressively, using a version-token approach designed for commerce writes (rare, high-value) applied to site analytics events (frequent, low-value).
`─────────────────────────────────────────────────`

# Scaling Antipatterns: Write Amplification, Rebuild-on-Write, Weak Caching — 2026-05-15

**Branch:** development
**Lens:** Scaling antipatterns: write amplification, rebuild-on-write, weak caching
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php
- app/Http/Controllers/Api/Internal/EmbeddedProductAnalyticsController.php
- app/Http/Controllers/Api/Internal/EmbeddedSetupController.php
- app/Http/Controllers/Api/Internal/EmbeddedOrderAnalyticsController.php
- app/Http/Controllers/Api/Internal/EmbeddedConnectController.php
- app/Http/Controllers/Api/Professional/ProfessionalAnalyticsController.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffStatsController.php
- app/Http/Controllers/Api/Staff/StaffSite/StaffAnalyticsController.php
- app/Http/Controllers/Api/PublicSite/AnalyticsController.php
- app/Services/Analytics/AffiliateProjectionsService.php
- app/Services/Cache/CacheLockService.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/AnalyticsCacheService.php
- app/Services/Notifications/NotificationPublisher.php
- app/Services/Notifications/CommerceNotificationService.php
- app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php
- app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php
- app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php
- app/Http/Middleware/Auth/VerifyShopifySessionToken.php
- app/Http/Controllers/Api/Webhooks/ShopifyAppUninstalledWebhookController.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **CACHE-2** · P2 — Analytics version-token bumped on every pageview/click/cart-event defeats SWR on active sites
    - **Where:** app/Http/Controllers/Api/PublicSite/AnalyticsController.php:73-77, 185-189, 227-232; app/Services/Cache/AnalyticsCacheService.php:18-21; app/Http/Controllers/Api/Professional/ProfessionalAnalyticsController.php:91-100
    - **Affects:** All brand analytics dashboards (`/api/professional/analytics/summary`, `shopSummary`). On any brand with active storefront traffic the analytics summary cache is invalidated on every ingest event, causing repeated raw-table scans against `analytics.site_visits`, `analytics.link_clicks`, and `analytics.cart_events`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the `invalidateAnalytics()` call from `pageview()`, `click()`, and `cartEvent()` in `AnalyticsController`. Site-analytics counts tolerate 60–300s TTL staleness; they don't need per-event real-time freshness.
        - If any per-event freshness is required, debounce the version bump: `Cache::add("analytics:ingest-debounce:{$professionalId}", 1, 30)` and only call `bumpAnalyticsVersion()` when the key was absent, collapsing burst invalidation into at most one bust per 30s.
        - Retain `invalidateAnalytics()` on commerce-write paths (Shopify webhook jobs, order/refund processing) — those are the genuinely high-value signals that warrant immediate cache busting.
    - **Technical:** `AnalyticsCacheService::bumpAnalyticsVersion()` increments the `analyticsSummaryVersion` counter (e.g., `0 → 1`). Because the version is embedded in the analytics summary cache key (`":v{$summaryVersion}"` suffix, read in `ProfessionalAnalyticsController::summary()` line 91 and `shopSummary()` line 507), every call transitions the key from `analytics:summary:q3:{id}:{...}:v0` to `analytics:summary:q3:{id}:{...}:v1`. The `CacheLockService::rememberLocked` stale-while-revalidate (SWR) copy lives at `$key:stale` — but that stale copy is keyed to `v0`, not `v1`. After a version bump, neither the primary key nor the SWR stale key exists for `v1`; the next reader faces a full cold miss and must block on the lock, running ~6–8 raw DB queries. At pilot scale (30 brands × Hydrogen storefronts), a brand with 200 daily pageviews invalidates the cache ~200 times/day — every 7 minutes on average against a 300s TTL — meaning the analytics summary rebuild from raw tables runs roughly 3× more often than the TTL alone would imply. Commerce writes (the pattern that motivated the version-token design) occur ~100 times/affiliate/year, where the per-event invalidation is clearly correct. Site analytics events (daily volume ≥ 10× commerce volume for live brands) are a poor fit for the same mechanism.
    - **Plain English:** Imagine you hired a chef to cook dinner, and every time a guest walked through the front door, someone ran into the kitchen and threw out whatever was cooking. On a quiet evening (few guests) this isn't a big deal — dinner is ready before the next guest arrives anyway. But on a busy night, you'd never finish cooking because the kitchen is constantly being reset. That's what's happening here: every page visit by any Hydrogen storefront visitor throws out the cached analytics summary, forcing the system to recount every visit from scratch. Commerce events (actual sales) genuinely need that reset. Page views do not.
    - **Evidence:**
        ```php
        // AnalyticsController.php — called on every pageview, click, and cartEvent:
        try {
            $this->analyticsCache->invalidateAnalytics($site->professional_id);
        } catch (Throwable $e) {
            report($e);
            Log::warning('Analytics cache invalidation failed on pageview', ['site_id' => $site->id, 'error' => $e->getMessage()]);
        }
        ```
        ```php
        // AnalyticsCacheService.php — bumpAnalyticsVersion increments the version token:
        public function bumpAnalyticsVersion(string $professionalId): void
        {
            Cache::increment(CacheKeyGenerator::analyticsSummaryVersion($professionalId));
        }
        ```
        ```php
        // ProfessionalAnalyticsController.php — version embedded in every analytics cache key:
        $summaryVersion = (int) Cache::get(
            CacheKeyGenerator::analyticsSummaryVersion($professional->id),
            0
        );
        $cacheKey = CacheKeyGenerator::analyticsSummary(
            $professional->id,
            $from->format('YmdH'),
            $to->format('YmdH')
        ).':'.($useHourlyBuckets ? 'hour' : 'day').":v{$summaryVersion}";
        ```

- [ ] **CACHE-1** · P2 — `EmbeddedProductSettingsController::show()` fires 3 uncached Shopify API calls on every admin extension mount
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php — `show()` (calling `fetchProductMetafields()` and `isInCollection()` twice), `fetchProductMetafields()`, and `isInCollection()`
    - **Affects:** Brand operators using the `sidest-product-settings` Shopify admin UI extension. Every extension mount fires 1 Admin GraphQL call (metafields + variants) and 2 Storefront GraphQL calls (collection membership). At 30 brands × concurrent product-editing sessions, this exhausts Shopify's 2 req/s Admin API budget and adds >1s latency to every load. Contrast with the sibling `EmbeddedProductAnalyticsController` (same extension, same route group) which is already behind `rememberLocked` with 5-minute TTL + SWR + push-invalidation.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `CacheKeyGenerator::embeddedProductSettings(string $professionalId, string $productId): string` method keyed identically to `embeddedProductAnalytics` (same (brand, product) scope).
        - Wrap the `show()` body — including `fetchProductMetafields()` and both `isInCollection()` calls — in `$this->cacheLock->rememberLocked(key, 300, ...)` with ±20% jitter (int TTL, not `DateTimeInterface`).
        - In `update()`, call `Cache::forget($key)` and `Cache::forget($key.':stale')` after any successful Shopify write so a brand sees their save reflected immediately on the next load.
        - Inject `CacheLockService` into the controller constructor (already available in `EmbeddedSetupController` and `EmbeddedProductAnalyticsController` as a pattern to follow).
        - Note from codebase: `EmbeddedProductAnalyticsController::resolveActive()` comment acknowledges "EmbeddedProductSettingsController writes do NOT currently bust it (deferred Step 3 — Master Pattern 17 follow-up)." Add the `embeddedProductActive` bust to the `update()` write path at the same time.
    - **Technical:** Category 3 (weak cache on hot read) combined with Shopify Admin API budget pressure. `show()` calls `fetchProductMetafields()` — a synchronous `Http::timeout(15)` POST to Shopify Admin GraphQL — on every extension mount with no cache layer. It then calls `isInCollection()` twice (once for `favourites_collection_handle`, once for `default_collection_handle`), each performing a synchronous `Http::timeout(10)` POST to the Shopify Storefront GraphQL API. This is 3 sequential Shopify round-trips (up to 35s worst case, typically 300–900ms combined) on every mount, with no stale-while-revalidate fallback. The sibling controller `EmbeddedProductAnalyticsController::show()` wraps its equivalent build in `$this->cacheLock->rememberLocked($cacheKey, 300, fn () => $this->build(...))`, making the absence here a clear oversight rather than a design choice. At 30 brands × typical product page opens, this also means a spike in Admin API bucket consumption during catalog audits or bulk product edits.
    - **Plain English:** Every time a brand opens any product in their Shopify admin panel to edit its Partna settings, the app phones Shopify three separate times to ask "what are this product's current settings?" — even if nothing has changed since the last time someone looked 20 seconds ago. It's like a customer service rep calling three different departments every time a customer asks their account number, rather than writing it on a sticky note for the next 5 minutes. The fix caches the answer locally for 5 minutes and only re-phones Shopify when a setting is actually saved.
    - **Evidence:**
        ```php
        // show() — no cache; fetchProductMetafields called on every extension mount:
        $result = $this->fetchProductMetafields($integration, $productId);
        $metafields = $result['metafields'];
        $variants = $result['variants'];
        ```
        ```php
        // fetchProductMetafields — synchronous 15s Admin API call, no cache:
        $response = \Illuminate\Support\Facades\Http::timeout(15)
            ->acceptJson()
            ->withHeaders(['X-Shopify-Access-Token' => $adminToken])
            ->post("https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json", [
                'query' => $query,
                'variables' => ['id' => "gid://shopify/Product/{$productId}"],
            ]);
        ```
        ```php
        // show() — two additional uncached Storefront API calls per mount:
        $inFavourites = $this->isInCollection($metadata, 'favourites_collection_handle', $productGid, $integration);
        $inDefault = $this->isInCollection($metadata, 'default_collection_handle', $productGid, $integration);
        ```
        ```php
        // isInCollection — synchronous 10s Storefront API call, no cache:
        $response = \Illuminate\Support\Facades\Http::timeout(10)
            ->acceptJson()
            ->withHeaders(['X-Shopify-Storefront-Access-Token' => $storefrontToken])
            ->post("https://{$shopDomain}/api/{$apiVersion}/graphql.json", [
                'query' => $query,
                'variables' => ['handle' => $collectionHandle, 'productId' => $productGid],
            ]);
        ```

## P3 — Nice to have

- [ ] **CACHE-3** · P3 — `StaffAnalyticsController::summary()` scans raw event tables on every staff request with no cache
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffAnalyticsController.php — `summary()` method
    - **Affects:** Staff users viewing analytics for a professional's site. All DB queries run directly against `analytics.site_visits` and `analytics.link_clicks` on every request with no caching.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Inject `CacheLockService` and wrap `summary()` body in `rememberLocked` keyed by `(professional_id, from, to, days)` with a 60s TTL.
        - Alternatively, re-use the professional's own analytics version token (same key as `analyticsSummaryVersion`) so staff and self views share a cache miss when analytics are invalidated.
    - **Technical:** Category 3 (weak cache on hot reads — staff variant). `ProfessionalAnalyticsController::summary()` wraps the identical query set in `$this->cacheLock->rememberLocked($cacheKey, $cacheTTL, ...)`. `StaffAnalyticsController::summary()` performs the same multi-query aggregation pattern (totals, daily charts, top links across `analytics.site_visits`, `analytics.link_clicks`, `site.blocks`) without any caching. At pre-beta scale (2–5 staff users), DB impact is negligible. At post-pilot scale with staff checking multiple professionals' analytics during incidents or customer-success calls, this creates unbounded unprotected scans. Low urgency given staff endpoint traffic, but the fix is one wrapper call.
    - **Plain English:** The version of the analytics dashboard that staff members see rebuilds from scratch every time they open it, while the identical dashboard that brand owners see is cached for up to 5 minutes. It's a minor inconsistency — staff users are few and rarely stare at the screen — but takes 30 minutes to fix and makes the staff tool faster during any investigation that requires clicking between multiple brands quickly.
    - **Evidence:**
        ```php
        // StaffAnalyticsController::summary() — no cache; raw table scan on every request:
        $visitsAgg = DB::table('analytics.site_visits')
            ->where('professional_id', $professional->id)
            ->whereBetween('occurred_at', [$from, $to])
            ->selectRaw('COUNT(*) as total_visits')
            ->selectRaw('COUNT(DISTINCT COALESCE(visitor_id::text, ip_hash)) as unique_visitors')
            ->selectRaw('MAX(occurred_at) as last_visit_at')
            ->first();
        ```
        ```php
        // ProfessionalAnalyticsController::summary() — same queries, correctly wrapped:
        $data = $this->cacheLock->rememberLocked($cacheKey, $cacheTTL, function () use (...) {
            $visitsAgg = DB::table('analytics.site_visits')
                ->where('professional_id', $professional->id)
                ->whereBetween('occurred_at', [$from, $to])
                ->selectRaw('COUNT(*) as total_visits')
                ...
        });
        ```

`★ Insight ─────────────────────────────────────`
The audit found 0 rebuild-on-write jobs (none of the `Rebuild*AggregatesJob` files exist — they were already eliminated in Phase 4). The notification fan-out paths (`FanOutBrandStatusNotificationJob`, `SendStaffBroadcastEmailsJob`) already use `Bus::batch()` with chunked dispatch, which is the correct pattern. The remaining issues are concentrated in the embedded Shopify admin controllers — one endpoint hasn't received the caching treatment its siblings already have, and the analytics invalidation mechanism is correctly designed for commerce writes but over-applied to site-analytics ingest.
`─────────────────────────────────────────────────`
