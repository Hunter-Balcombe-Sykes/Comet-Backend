`★ Insight ─────────────────────────────────────`
Key adjudication decisions before writing:
1. **Drop all booking-related findings** — CLAUDE.md memory confirms "2026-05-11: not building booking, not finishing Fresha or Square." Drops: `bookingAnalytics`/`bookingMilestoneTotals` cache keys, `analytics.booking_events` UPDATE-overwrite, `BookingAnalyticsController` Carbon TTL (both professional + staff), Square/Fresha per-row sync patterns, and `ServiceObserver` Fresha/Square bulk-import fan-out.
2. **Drop low-confidence non-safety findings** (conf < 0.7): Entitlements per-request cache (0.60), observer-bodies audit pointer (0.60), AccountTypeTransition listeners (0.50), StripeTransactionFetcher/StripeBalanceService pure-service notes (0.50), bustPayoutCaches asymmetry (0.40), global broadcast job internals (0.60), `Cache::remember` sweep pointer (0.60), `StorePlanSubscriptionRequest` (0.70 borderline single-global-key trivial query — drop per "clean beats noisy").
3. **Verified**: `AffiliateProductCatalogService::queryAdminCatalog` confirmed using raw `Http::post` (line 655). `PublicSiteResolver` confirmed zero caching (42-line file, pure DB queries). `BrandProfileObserver` confirmed missing `:stale` twin on line 38.
4. **Merge**: `fetchProductCustomPhotosMetafield` CACHE-1 + CACHE-2 (DateTimeInterface TTL is a sub-issue of the same raw-Cache antipattern — merged into one P2).
`─────────────────────────────────────────────────`

# Scaling Audit — 2026-05-21

**Branch:** development
**Lens:** Whole-backend PILOT audit — scaling lens (cache stampedes, N+1 fan-out, write amplification, vendor rate-limit bypasses, queue shape)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Services/Cache/`
- `app/Services/FeatureFlags/`
- `app/Listeners/`, `app/Events/`
- `app/Observers/Core/`
- `app/Console/`
- `app/Services/Professional/`
- `app/Services/Stripe/`
- `app/Services/Shopify/`, `app/Services/Store/`
- `app/Services/Media/`, `app/Services/Analytics/`, `app/Services/Site/`, `app/Services/PublicSite/`
- `app/Services/Billing/`, `app/Services/Accounts/`, `app/Services/Notifications/`
- `app/Models/`
- `app/Policies/`, `app/Providers/`, `app/Mail/`
- `app/Jobs/`
- `app/Http/Controllers/Api/Professional/`
- `app/Http/Controllers/Api/Staff/`
- `app/Http/Controllers/Api/PublicSite/`
- `app/Http/Requests/`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 3 complete
- P2 Medium: 0 of 12 complete
- P3 Low: 0 of 14 complete

---

## P1 — Fix before pilot launch

- [ ] **#SCALE-1** · P1 — `analyticsSummary` cache key lacks version token — stale dashboard numbers for up to 24h after a Shopify order webhook
    - **Where:** `app/Services/Cache/CacheKeyGenerator.php` — `analyticsSummary()`
    - **Affects:** Every brand and affiliate dashboard summary panel — revenue, commission totals, and order counts do not update until the key's natural TTL expires after a Shopify order webhook fires.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Read the `analyticsSummaryVersion` token from Redis and embed it in the key, exactly as `brandCommerceAnalytics` and `affiliateCommerceAnalytics` already do.
        - `AnalyticsCacheService::bumpAnalyticsVersion()` is already called on every commerce write — the fix is one line in the key generator.
    - **Technical:** `CacheKeyGenerator::analyticsSummary()` returns `analytics:summary:q3:{pro}:{start}:{end}` — a static key. `brandCommerceAnalytics` and `affiliateCommerceAnalytics` both call `Cache::get(self::analyticsSummaryVersion($professionalId), 0)` and embed `$version` in the key, so `bumpAnalyticsVersion()` invalidates them atomically. `analyticsSummary` does not participate in that mechanism; after a Shopify order webhook fires the cache continues serving pre-order numbers for its full TTL (up to 24h per `CacheLockService` SWR extension). This is a known-scenario bug — Shopify webhooks are the main commerce write path.
    - **Plain English:** Every time a new order comes in, the app correctly refreshes two of the three analytics panels on the dashboard. The third panel — the summary at the top — is frozen. It shows yesterday's numbers until its timer runs out on its own, which can take up to a day. The fix is to connect it to the same "refresh now" trigger the other two panels already use.
    - **Evidence:**
        ```php
        // CacheKeyGenerator.php — static key, no version token
        public static function analyticsSummary(string $professionalId, string $startDate, string $endDate): string
        {
            // q3: commerce fields now read from commerce.orders instead of commission_movements (Phase 3)
            return "analytics:summary:q3:{$professionalId}:{$startDate}:{$endDate}";
        }

        // Compare — brandCommerceAnalytics embeds the dynamic version token:
        public static function brandCommerceAnalytics(string $professionalId, string $from, string $to): string
        {
            $version = \Illuminate\Support\Facades\Cache::get(self::analyticsSummaryVersion($professionalId), 0);
            return "analytics:commerce:brand:v7:{$professionalId}:{$version}:{$from}:{$to}";
        }
        ```

- [ ] **#SCALE-2** · P1 — `PublicSiteResolver` has no caching — every public page view executes up to 3 DB queries
    - **Where:** `app/Services/PublicSite/PublicSiteResolver.php:11–41`
    - **Affects:** Every visitor to any `<handle>.partna.au` page — subdomain-to-Site resolution runs raw DB queries on every page view.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap `resolvePublishedSite()` in `CacheLockService::rememberLocked` keyed by subdomain, with a 60s TTL + int jitter for single-flight.
        - Push-invalidate the key from `SiteCacheService` on every `Site` publish/unpublish, subdomain change, and `Professional` status change — hooks that already exist there.
        - Use a version-token key pattern (`public_site:v{version}:{subdomain}`) so invalidation is atomic.
    - **Technical:** The method issues up to three queries: (1) `Site` by subdomain with `whereHas('professional', active)`, (2) `SiteSubdomainAlias` by subdomain on miss, (3) `Site` by `alias->site_id`. With no cache barrier, at 30 brands × 50 affiliates × any non-trivial public traffic this is the single highest-frequency read path in the entire application. `SiteCacheService` already has invalidation hooks that `SiteObserver` calls on write — extending them to cover this key is straightforward. The `CacheLockService::rememberLocked` pattern provides single-flight so concurrent visitors on a cold key share one DB round-trip.
    - **Plain English:** Every single person who visits a brand's public storefront triggers up to three database lookups just to figure out which page to show them. If a hundred people land on the same page within a minute, that's up to three hundred database queries asking "what site is 'brandname'?" when the answer hasn't changed. Putting the answer on a sticky note for 60 seconds means the first visitor finds the answer, everyone else reads the note.
    - **Evidence:**
        ```php
        public function resolvePublishedSite(string $subdomain): ?Site
        {
            $subdomain = strtolower($subdomain);

            $siteQuery = Site::query()
                ->where('is_published', true)
                ->with('professional')
                ->whereHas('professional', function ($q) {
                    $q->where('status', 'active');
                });

            $site = (clone $siteQuery)
                ->whereRaw('lower(subdomain) = ?', [$subdomain])
                ->first();

            if ($site) { return $site; }

            $alias = SiteSubdomainAlias::query()
                ->whereRaw('lower(subdomain) = ?', [$subdomain])
                ->first();

            if (! $alias) { return null; }

            return (clone $siteQuery)->where('id', $alias->site_id)->first();
        }
        ```

- [ ] **#SCALE-3** · P1 — `AffiliateProductCatalogService::queryAdminCatalog` bypasses `ShopifyAdminClient` — no rate-limit token-bucket, no throttled retry
    - **Where:** `app/Services/Store/AffiliateProductCatalogService.php:654–663`
    - **Affects:** Affiliate catalog cold-cache loads — a THROTTLED response from Shopify logs a warning and returns an empty product list to the affiliate. Two brands hitting a cold cache simultaneously can exhaust the shared Shopify Admin API budget with no local gate.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Inject `ShopifyAdminClient` (already used in `BrandCatalogService`) into `AffiliateProductCatalogService` and replace the `Http::` call with `$this->shopifyClient->graphql(...)`.
        - Remove the manual HTTP construction, manual error branches, and manual JSON parsing — `ShopifyAdminClient` handles throttled retry, cost reconciliation, and typed exceptions.
    - **Technical:** Every other Admin API call site in the codebase routes through `ShopifyAdminClient::graphql()`, which pre-acquires from the Redis token bucket, reconciles bucket state from Shopify's `throttleStatus` response, and re-raises `ShopifyThrottledException` for Horizon's `backoff()` to retry. `queryAdminCatalog` in `AffiliateProductCatalogService` fires `Http::post` directly, bypassing all of this. On a THROTTLED response it logs a warning and `break`s the pagination loop — returning a partial or empty catalog silently. Two brands with cold caches simultaneously produce two uncoordinated bursts to Shopify.
    - **Plain English:** The rest of the app uses a traffic-light system that queues Shopify API calls to stay under the speed limit, and automatically retries if rate-limited. This one method ignores the traffic light — it floors it onto the highway, and if it gets pulled over (rate-limited), it quietly returns an empty product catalog instead of waiting for clearance. The fix wires it into the same traffic-light system everyone else uses.
    - **Evidence:**
        ```php
        // AffiliateProductCatalogService::queryAdminCatalog — direct Http::post
        $response = Http::timeout(20)
            ->acceptJson()
            ->withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
            ])
            ->post($url, [
                'query' => $query,
                'variables' => $variables,
            ]);

        if (! $response->successful()) {
            Log::warning('Shopify Admin API request failed.', [...]);
            break;
        }

        // BrandCatalogService::queryAdminCatalog — correct pattern via ShopifyAdminClient:
        fn () => $this->queryAdminCatalog($brand)  // delegates to $this->graphql() → ShopifyAdminClient
        ```

---

## P2 — Should fix

- [ ] **#SCALE-4** · P2 — `BrandProfileObserver` forgets only the primary cache key, leaving the `:stale` SWR companion live
    - **Where:** `app/Observers/Core/BrandProfileObserver.php:37–41`
    - **Affects:** Affiliates' dashboard brand-status banner — after a brand transitions live/building/systems_down, affiliates see the old status for up to the SWR stale-serving window (600s default) because the companion key survives the invalidation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::forget(CacheKeyGenerator::brandPartnerStatus($brandProfessionalId))` with a `Cache::deleteMultiple([$key, $key.':stale'])` pattern, matching `CommissionPayoutObserver::bustPayoutStateCache()` and `CustomerObserver::invalidateCount()`.
    - **Technical:** `CacheLockService::rememberLocked` writes a `:stale` companion key for stale-while-revalidate. Forgetting only the primary key leaves the `:stale` twin live; any request that arrives between the primary delete and the next fresh write serves the pre-transition brand status. Every other observer in this codebase that calls `Cache::forget` directly also busts the `:stale` twin — this is the single outlier.
    - **Plain English:** When a brand's status changes, the code tears up the main sticky note showing that status. But there's a backup copy stuck underneath it. Anyone reading the fridge before the new note goes up sees the old status from the backup copy. The fix is to tear up both notes at the same time.
    - **Evidence:**
        ```php
        try {
            Cache::forget(CacheKeyGenerator::brandPartnerStatus($brandProfessionalId));
        } catch (\Throwable $e) {
            Log::warning('brand-partner-status cache invalidation failed', $this->logContext(__METHOD__, [
                'brand_professional_id' => $brandProfessionalId,
                'message' => $e->getMessage(),
            ]));
        }
        ```

- [ ] **#SCALE-5** · P2 — `BrandStatusService::isStorefrontReachable` uses raw `Cache::get`/`Cache::put` — cold-cache stampede on concurrent admin requests
    - **Where:** `app/Services/Professional/Brand/BrandStatusService.php:263–281`
    - **Affects:** Brand onboarding checklist polls and embedded admin dashboard loads — on cold cache, every concurrent request fires its own 5-second `Http::get()` probe to the brand's storefront URL.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `Cache::get`/`Cache::put` pair with `CacheLockService::rememberLocked('brand_status:storefront_reachable:'.sha1($url), 60, fn() => ...)`.
        - Pass an int TTL so `writeWithJitter` applies ±20% jitter, preventing synchronised expiry across brands.
    - **Technical:** The comment acknowledges this HTTP probe "dominates p95 on hot endpoints." The existing `Cache::get`/`Cache::put` pair is a classic cold-cache race: after a Redis eviction or flush, every concurrent request observes `null` from `Cache::get` and dispatches its own outbound HTTP probe before any of them writes back. `CacheLockService::rememberLocked` wraps the probe in a Redis mutex so only one request pays the HTTP cost. The 15s negative TTL is a good intuition but the raw `Cache::put` path has no jitter, so all brands' negative-result caches expire simultaneously after a deploy.
    - **Plain English:** Every time a brand or admin checks whether the storefront is live, the system pings the storefront URL. Right now, when that ping result expires from memory, the next ten people who check at the same moment all send their own ping instead of sharing one answer. The fix puts a "take a number" system in front of the ping — first in line makes the call, everyone else waits for the result.
    - **Evidence:**
        ```php
        $cacheKey = 'brand_status:storefront_reachable:'.sha1($url);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (bool) $cached;
        }

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'timeout' => 5,
                'connect_timeout' => 3,
            ])->get($url);

            $reachable = $response->successful();
        } catch (\Throwable) {
            $reachable = false;
        }

        Cache::put($cacheKey, $reachable, $reachable ? 60 : 15);

        return $reachable;
        ```

- [ ] **#SCALE-6** · P2 — `SiteObserver::cascadeAffiliateKvSync` dispatches N individual `SyncSubdomainToKvJob` jobs on brand subdomain change
    - **Where:** `app/Observers/Core/SiteObserver.php:117–124`
    - **Affects:** All affiliates connected to a brand when that brand changes their subdomain — N jobs dispatched simultaneously, each making a Cloudflare KV API call.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the per-affiliate dispatch loop with a single `SyncBrandAffiliatesToKvJob` that receives the brand's professional ID and iterates affiliates internally with chunked KV writes.
        - The single job can honour the existing `ShouldBeUnique` dedup window across the whole fan-out instead of per-affiliate.
    - **Technical:** `BrandPartnerLink::query()->where(...)->pluck('affiliate_professional_id')->each(fn($id) => SyncSubdomainToKvJob::dispatch($id))` creates one Redis `RPUSH` per affiliate. At 200 affiliates per brand this spikes queue depth by 200 simultaneously. `SyncSubdomainToKvJob` has `ShouldBeUnique` with a 45s window, so duplicates coalesce — but the initial burst of N unique jobs still hits the queue and the KV API simultaneously. The canonical replacement from the codebase's own patterns is a single chunked/batched fan-out job.
    - **Plain English:** When a brand changes their website address, we need to update every affiliate's routing entry in Cloudflare. Right now we hand a separate work order to a courier for each affiliate — 50 affiliates means 50 couriers all dispatched at once. The fix gives one courier a list of all 50 addresses.
    - **Evidence:**
        ```php
        BrandPartnerLink::query()
            ->where('brand_professional_id', $brandProfessionalId)
            ->pluck('affiliate_professional_id')
            ->each(function (string $affiliateId): void {
                SyncSubdomainToKvJob::dispatch($affiliateId);
            });
        ```

- [ ] **#SCALE-7** · P2 — `ProfessionalIntegrationObserver` busts per-affiliate cache keys synchronously on the request thread when brand toggles custom-photo flag
    - **Where:** `app/Observers/Core/ProfessionalIntegrationObserver.php:157–180`
    - **Affects:** Brand dashboard users toggling `custom_photos_enabled` or photo position — the request thread queries all linked affiliates and issues `Cache::deleteMultiple` before the HTTP response returns.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Dispatch the bust as a queued job — the docblock already names `InvalidateBrandAffiliatesCacheJob` as the canonical pattern.
        - Accept the minor stale-while-revalidate window (the primary TTL is 60s, stale window 600s).
    - **Technical:** The docblock reads: "Synchronous bust — typical brands have <100 affiliates… If brand fan-out grows, dispatch the bust as a queued job mirroring InvalidateBrandAffiliatesCacheJob." The query + `foreach` + `Cache::deleteMultiple` is already acknowledged as on-thread work that grows linearly with affiliate count. At 200 affiliates this adds ~200ms of latency to what the brand perceives as a settings save. The `Cache::deleteMultiple` itself is a single Redis `UNLINK` (fast), but the preceding `BrandPartnerLink` query + key array construction still runs inline.
    - **Plain English:** When a brand flips a toggle in their settings, the server builds a list of every affiliated partner and clears their cached data before sending the brand a "saved" response. For 50 affiliates this is imperceptible; for 200 it's noticeable lag on a settings save. The fix is to hand the clearing work to a background worker so the brand gets an instant response.
    - **Evidence:**
        ```php
        $affiliateIds = BrandPartnerLink::query()
            ->where('brand_professional_id', $brandId)
            ->pluck('affiliate_professional_id')
            ->all();

        if ($affiliateIds === []) {
            return;
        }

        $keys = [];
        foreach ($affiliateIds as $affiliateId) {
            $primary = CacheKeyGenerator::hydrogenAffiliateProducts((string) $affiliateId);
            $keys[] = $primary;
            $keys[] = $primary.':stale';
        }

        Cache::deleteMultiple(array_values(array_unique($keys)));
        ```

- [ ] **#SCALE-8** · P2 — `BrandCatalogService::fetchProductCustomPhotosMetafield` uses raw `Cache::get`/`Cache::put` with a `DateTimeInterface` TTL — no single-flight lock and no jitter
    - **Where:** `app/Services/Store/BrandCatalogService.php` — `fetchProductCustomPhotosMetafield`
    - **Affects:** Affiliates viewing product detail pages — concurrent requests for the same product's custom-photos flag each fire independent Shopify Admin API calls on a cache miss. All instances expire in lockstep (no jitter) causing a coordinated burst after TTL.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the raw `Cache::get`/`Cache::put` pair with `$this->cacheLock->rememberLocked($cacheKey, (int) config('partna.cache.ttls.product_custom_photos'), fn() => ...)`.
        - Pass an int TTL so `writeWithJitter` applies ±20% jitter. Remove the `'true'`/`'false'`/`'unset'` string sentinels — `rememberLockedNullable` eliminates the need for that workaround.
        - `now()->addSeconds()` produces a `Carbon` instance; passing it to `writeWithJitter` bypasses jitter because the jitter path only applies when the TTL is an integer. Every other `rememberLocked` call site in the codebase uses a plain int.
    - **Technical:** Two concurrent requests for the same product both observe a cache miss and both fire Shopify Admin API calls before the first writes back — the classic cold-cache stampede. Additionally, `Cache::put($cacheKey, ..., now()->addSeconds(...))` stores an absolute expiry timestamp: every call to this method for the same product will expire at exactly the same wall-clock time, so a fleet-wide TTL rollover simultaneously invalidates all product custom-photos flags and triggers a synchronised burst of Shopify calls.
    - **Plain English:** Two affiliates open the same product page at the same moment and both phone Shopify to ask "are custom photos allowed?" instead of sharing one answer. On top of that, every product's "custom photos" answer expires at the same time, so after a deploy the whole fleet calls Shopify simultaneously. The fix wraps it in the same lock-and-jitter pattern the rest of the catalog uses.
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
        Cache::put($cacheKey, $bool ? 'true' : 'false', now()->addSeconds((int) config('partna.cache.ttls.product_custom_photos')));
        // ...
        Cache::put($cacheKey, 'unset', now()->addSeconds((int) config('partna.cache.ttls.product_custom_photos')));
        ```

- [ ] **#SCALE-9** · P2 — `AffiliateProductCatalogService::fetchCollectionProducts` has no cache — every affiliate catalog view re-paginates Shopify for the favourites collection
    - **Where:** `app/Services/Store/AffiliateProductCatalogService.php` — `getCatalogWithSelections` → `fetchCollectionGids` → `fetchCollectionProducts`
    - **Affects:** Every affiliate viewing their catalog — the favourites collection membership is re-fetched from Shopify on each page load regardless of how recently it was retrieved.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap `fetchCollectionGids('favourites_collection_handle')` in `CacheLockService::rememberLocked` with a 120s int TTL + jitter.
        - Push-invalidate the key from `BrandCatalogService::addProductsToCollection` and `removeProductsFromCollection` when collection membership changes.
        - Or fold favourites GID resolution into the existing cached `fetchActiveCatalog` payload as a `favourites_gids` key, eliminating the extra round-trip entirely.
    - **Technical:** While `resolveCollectionGid` is cached, the subsequent `fetchCollectionProducts` pagination loop is not. At 30 brands × 50 affiliates viewing their catalog daily, this produces 1,500 redundant Shopify pagination sequences per day returning the same collection membership list. `fetchCollectionProducts` is a `ShopifyAdminClient::graphql()` call (correct — not the raw `Http::post` bypass of SCALE-3), so it participates in token-bucket throttling, but repeated identical calls still consume bucket budget unnecessarily.
    - **Plain English:** Every time an affiliate opens their product catalog, the app calls Shopify and asks "what's in the Favourites collection?" — even if the answer was fetched ten seconds ago. The fix caches that answer locally so 50 affiliates refreshing at once only generate one Shopify call instead of 50.
    - **Evidence:**
        ```php
        // getCatalogWithSelections:
        $favouritesGids = $this->fetchCollectionGids($integration, 'favourites_collection_handle');

        // fetchCollectionGids → fetchCollectionProducts — no caching:
        public function fetchCollectionProducts(ProfessionalIntegration $integration, string $collectionGid): array
        {
            // ... paginated Shopify API calls, no Cache::remember / rememberLocked
        }
        ```

- [ ] **#SCALE-10** · P2 — `AffiliateProjectionsService::build()` executes 5 DB queries per dashboard load with no cache layer
    - **Where:** `app/Services/Analytics/AffiliateProjectionsService.php` — `build()` method
    - **Affects:** Affiliate dashboards showing run-rate, momentum, YTD, and year-end forecasts — five DB queries per request, every request.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap `build()` in `CacheLockService::rememberLocked` with a 60–120s int TTL + jitter, keyed by `(professional_id, window_days_override)`.
        - Push-invalidate on every `brand_affiliate_rollup` upsert (the trigger-maintained source table), or couple to `analyticsSummaryVersion` so a commerce webhook write rolls the key forward automatically.
    - **Technical:** `build()` calls `resolveDataHistoryDays` (1 query), `fetchPerCurrencyAggregates` (1), `fetchPriorWindowAggregates` (1), `fetchYtdAggregates` (1), `fetchBestMonthPerCurrency` (1) — five round-trips per request with no barrier. The result is a pure read projection from `commerce.brand_affiliate_rollup`, which is trigger-maintained and changes only on commission events. It is an ideal candidate for `rememberLocked` with push invalidation: the source table is written infrequently, the projection is queried on every dashboard load.
    - **Plain English:** The "you're on track to earn £X this year" section of the affiliate dashboard recalculates from scratch every single time the page loads — five separate database trips per visit. The numbers only change when a new order or commission event arrives, which is rare. Caching the answer for 60 seconds means the dashboard renders from memory for everyone except the first visitor after a change.
    - **Evidence:**
        ```php
        public function build(Professional $professional, ?int $windowDaysOverride = null): array
        {
            // no cache — every call executes:
            $dataHistoryDays = $this->resolveDataHistoryDays($professional->id, $now);
            $perCurrency = $this->fetchPerCurrencyAggregates(...);
            $priorByCurrency = $this->fetchPriorWindowAggregates(...)->keyBy('currency_code');
            $ytdByCurrency = $this->fetchYtdAggregates(...)->keyBy('currency_code');
            $bestMonthByCurrency = $this->fetchBestMonthPerCurrency(...)->keyBy('currency_code');
        }
        ```

- [ ] **#SCALE-11** · P2 — `AnalyticsService` issues 6 separate `COUNT DISTINCT` queries per time-window set instead of one PostgreSQL `FILTER` query
    - **Where:** `app/Services/Analytics/AnalyticsService.php` — `windowedDistinctCount` (~line 200) and `windowedCartSessions` (~line 212)
    - **Affects:** The single cold-cache request that fills the analytics cache after a deploy or eviction — currently pays for 12+ sequential DB round-trips where 2 would suffice.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the `foreach (self::WINDOWS as $w)` loop in `windowedDistinctCount` and `windowedCartSessions` with a single query using PostgreSQL's `COUNT(DISTINCT col) FILTER (WHERE occurred_at >= ?)` clause — one query, six columns.
        - Apply the same treatment to `brandWindowedCartSessions`, `brandWindowedViews`, and `brandWindowedUniqueVisitors` which follow the same pattern.
    - **Technical:** `windowedDistinctCount` iterates over 6 `self::WINDOWS` keys and issues one `COUNT DISTINCT` per window. `windowedCartSessions` does the same. `computeBrand()` adds a `pluck('affiliate_professional_id')` pre-query plus 6 more — totalling 25+ queries for a single brand analytics cold-cache fill. `CacheLockService::rememberLocked` provides single-flight so only one request pays this cost, but that one request still suffers avoidable sequential latency. PostgreSQL's `FILTER` clause (already used in `AffiliateProjectionsService`) collapses all six windows into one query.
    - **Plain English:** When the analytics dashboard loads from scratch after a restart, it asks the database the same question six times in a row — "how many unique visitors in the last 24 hours? 7 days? 30 days?" and so on — when one question with six sub-answers would work. The smart cache means only the very first visitor after a deploy pays this price, but making the underlying question cheaper is worthwhile.
    - **Evidence:**
        ```php
        private function windowedDistinctCount(string $table, string $distinctColumn, array $bounds, array $where): array
        {
            $result = [];
            foreach (self::WINDOWS as $w) {   // 6 separate DB queries
                $query = DB::table($table)->whereNotNull($distinctColumn);
                foreach ($where as $col => $val) { $query->where($col, $val); }
                if ($bounds[$w] !== null) { $query->where('occurred_at', '>=', $bounds[$w]); }
                $result[$w] = (int) $query->distinct()->count($distinctColumn);
            }
            return $result;
        }

        private function windowedCartSessions(string $professionalId, array $bounds): array
        {
            $result = [];
            foreach (self::WINDOWS as $w) {   // 6 more separate DB queries
                $query = DB::table('analytics.cart_events')
                    ->where('professional_id', $professionalId)
                    ->where('event_type', 'checkout_start')
                    ->whereNotNull('session_id');
                if ($bounds[$w] !== null) { $query->where('occurred_at', '>=', $bounds[$w]); }
                $result[$w] = (int) $query->distinct()->count('session_id');
            }
            return $result;
        }
        ```

- [ ] **#SCALE-12** · P2 — `VoidExpiredPayoutsJob::fireGraceWarnings()` uses unbounded `->get()` with a per-payout synchronous notification publish loop
    - **Where:** `app/Jobs/Stripe/VoidExpiredPayoutsJob.php:142–150` (unbounded get) and `:166–175` (per-payout publish loop)
    - **Affects:** Stripe payout grace-warning delivery — memory pressure and timeout risk if pending-payout volume grows; each qualifying payout triggers a synchronous `NotificationPublisher::publish()` + `$payout->save()`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the unbounded `->get()` with `->chunkById(200, ...)` and process all three tiers (T-30/T-7/T-1) inside the chunk callback.
        - Collect publish payloads per chunk and flush them as a batch rather than one-by-one.
    - **Technical:** The method fetches every `pending` payout whose `void_at` falls within a 30-day window in a single `->get()`. At the target scale of 30 brands × 50 affiliates with recurring payout cycles, a Stripe outage that stalls payout creation could produce enough pending rows to exhaust the 300s job timeout on serial publish + save iterations alone. The chunked+bulk replacement mirrors `FanOutBrandStatusNotificationJob`.
    - **Plain English:** Once a day this job picks up every overdue payout in one armload, then sends a warning about each one individually. For 50 payouts that's fine. If a Stripe outage causes hundreds to pile up, the worker drops the pile before finishing. The fix is to carry the mail in manageable stacks.
    - **Evidence:**
        ```php
        $allCandidates = CommissionPayout::query()
            ->where('status', 'pending')
            ->whereBetween('void_at', [$windowStart, $windowEnd])
            ->where(function ($q) use ($brandSideCodes) {
                $q->whereIn('failure_code', $brandSideCodes)
                    ->orWhereDoesntHave('affiliateProfessional', fn ($a) => $a->where('stripe_connect_status', 'active'));
            })
            ->get();   // unbounded

        foreach ([30, 7, 1] as $daysOut) {
            foreach ($candidates as $payout) {
                $publisher->publish(/* ... */);  // per-payout synchronous INSERT
                $payout->forceFill([/* ... */])->save();  // per-payout synchronous UPDATE
            }
        }
        ```

- [ ] **#SCALE-13** · P2 — `BrandCommerceAnalyticsController` issues the same `brand_partner_links` query three times inside a single cache-miss closure
    - **Where:** `app/Http/Controllers/Api/Professional/Analytics/BrandCommerceAnalyticsController.php` — `queryPageViewsByBucket`, `querySiteVisitTotals`, `queryCartEventCounts`
    - **Affects:** Brand dashboard analytics overview cold-cache load — three identical `brand.brand_partner_links` pluck queries per cache miss.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Hoist the `brand.brand_partner_links` pluck into the `overview()` closure and pass the resulting `$affiliateIds` array as a parameter to each helper.
        - Short-circuit all three helpers when `$affiliateIds` is empty.
    - **Technical:** Inside the `rememberLocked` callback, `queryPageViewsByBucket`, `querySiteVisitTotals`, and `queryCartEventCounts` each independently run `DB::table('brand.brand_partner_links')->where('brand_professional_id', ...)->whereNull('deleted_at')->pluck('affiliate_professional_id')`. The result is deterministic within the closure's lifetime — computing it once and passing it through eliminates two redundant DB round-trips per cold-cache fill.
    - **Plain English:** Three staff members all walk to the same filing cabinet, pull the same list of affiliate IDs, and return to their desks — one after another — before any one of them shares the list. One person should pull the list and hand copies to the other two.
    - **Evidence:**
        ```php
        // queryPageViewsByBucket (~line 408):
        $affiliateIds = DB::table('brand.brand_partner_links')
            ->where('brand_professional_id', $brandProfessionalId)
            ->whereNull('deleted_at')
            ->pluck('affiliate_professional_id')
            ->toArray();

        // querySiteVisitTotals (~line 433) — identical:
        $affiliateIds = DB::table('brand.brand_partner_links')
            ->where('brand_professional_id', $brandProfessionalId)
            ->whereNull('deleted_at')
            ->pluck('affiliate_professional_id')
            ->toArray();

        // queryCartEventCounts (~line 453) — identical:
        $affiliateIds = DB::table('brand.brand_partner_links')
            ->where('brand_professional_id', $brandProfessionalId)
            ->whereNull('deleted_at')
            ->pluck('affiliate_professional_id')
            ->toArray();
        ```

- [ ] **#SCALE-14** · P2 — `AffiliateProductController::resetToDefaults` calls `seedDefaultSelections` synchronously per brand on the request thread
    - **Where:** `app/Http/Controllers/Api/Professional/Store/AffiliateProductController.php:279–296`
    - **Affects:** Affiliates linked to multiple brands clicking "reset to defaults" — each brand's re-seed is a synchronous Shopify read + write call serialised in a `foreach`, blocking the HTTP response.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Dispatch a `ResetAffiliateDefaultSelectionsJob` per brand professional ID instead of calling `seedDefaultSelections` on the request thread.
        - Add a short-lived rate-limit key (`Cache::add`) to prevent double-dispatch on double-click.
    - **Technical:** `seedDefaultSelections` issues both GraphQL read and write calls to Shopify (fetching the default collection, then creating/updating affiliate product selections). With 1–3 linked brands per affiliate, this is 1–3 synchronous Shopify round-trips holding the PHP-FPM worker open. A transient 3-second Shopify slowdown on 3 brands produces a 9-second response. The canonical replacement is a queued fan-out: return 202 immediately, let Horizon process the per-brand seeds concurrently.
    - **Plain English:** When an affiliate clicks "reset to defaults," the server makes a phone call to Shopify for every brand they're linked to, one at a time, while the affiliate stares at a spinner. If Shopify is slow and they're linked to three brands, that's a multi-second wait per brand. The fix queues the work in the background and sends the affiliate an immediate response.
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

- [ ] **#SCALE-15** · P2 — `VideoVariantService::processVariants` uploads HLS segments in a sequential per-file loop — processing time grows linearly with video duration
    - **Where:** `app/Services/Media/VideoVariantService.php:186–200`
    - **Affects:** Video upload processing jobs — a 5-minute video produces ~100 sequential `Storage::disk()->put()` calls to R2, each a separate network round-trip.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Use Laravel's HTTP pool or `aws s3 sync` via subprocess for the HLS directory to upload segments concurrently.
        - As a minimum improvement: chunk the `scandir` results and note that R2 supports multipart upload for the directory, so the bottleneck is the per-file sequential loop, not total data size.
    - **Technical:** For each HLS variant, the loop scans the temp directory and issues one `$disk->put()` per segment. HLS segments are typically 6 seconds each, so a 5-minute video produces ~50 segments × 2 variants = ~100 sequential `put()` calls. Each `put()` is a blocking network round-trip to R2. Sequential upload means total processing time = 100 × (per-segment upload latency). Concurrent upload (even 4-at-a-time) would reduce this ~4×. This is a worker throughput concern, not a correctness issue.
    - **Plain English:** When processing a video for streaming, the system uploads each 6-second clip one at a time — like posting 100 letters individually rather than dropping them all at the post office counter at once. For a 5-minute video that's about 100 trips to the counter. Sending a batch of four at a time would finish in a quarter of the time.
    - **Evidence:**
        ```php
        foreach ($hlsDirs as $variantKey => $hlsDir) {
            $remoteHlsBase = "{$basePath}/hls/{$variantKey}";
            foreach (scandir($hlsDir) ?: [] as $file) {
                if ($file === '.' || $file === '..') { continue; }
                $localFile = "{$hlsDir}/{$file}";
                $remotePath = "{$remoteHlsBase}/{$file}";
                $stream = fopen($localFile, 'rb');
                $disk->put($remotePath, $stream, ['visibility' => 'public', 'ContentType' => $mime]);
                if (is_resource($stream)) { fclose($stream); }
            }
        }
        ```

---

## P3 — Nice to have

- [ ] **#SCALE-16** · P3 — `FeatureFlagService::jitteredTtl()` double-jitters with `CacheLockService::writeWithJitter` — effective TTL spread is wider than documented
    - **Where:** `app/Services/FeatureFlags/FeatureFlagService.php` — `jitteredTtl()` → `CacheLockService::writeWithJitter`
    - **Affects:** Feature flag cache TTL precision only — no user-visible impact.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Remove the `random_int(-self::TTL_JITTER_SECONDS, self::TTL_JITTER_SECONDS)` from `jitteredTtl()` and rely solely on `CacheLockService::writeWithJitter` for jitter.
        - Or document the double-jitter as intentional and rename the method.
    - **Technical:** `jitteredTtl()` applies ±60s additive jitter, then passes the result to `rememberLocked`, which internally calls `writeWithJitter` (multiplicative ±20%). For a 300s base TTL the effective range is ~192–432s instead of the documented ±20% (240–360s). Harmless in practice, but the `±60s` annotation in the code is misleading.
    - **Plain English:** Two separate timers are adding randomness to the same feature-flag expiry. The result is a wider spread than either timer intends, and the code comment describes only one of them. No one notices the difference, but the comment is wrong.
    - **Evidence:**
        ```php
        private function jitteredTtl(?Carbon $nearestExpiry = null): Carbon|int
        {
            $base = self::BASE_TTL_SECONDS + random_int(-self::TTL_JITTER_SECONDS, self::TTL_JITTER_SECONDS);
            // ...
            return $base;
        }
        // Then passed to CacheLockService::rememberLocked which calls writeWithJitter (±20%) internally
        ```

- [ ] **#SCALE-17** · P3 — `CommissionPayoutRefundService::buildClawbackPlan` hydrates full Eloquent models to sum a single JSONB field
    - **Where:** `app/Services/Stripe/CommissionPayoutRefundService.php:197–201`
    - **Affects:** Shopify refund webhook handler — loads every prior clawback row into PHP memory to sum one JSONB key.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->get()->sum(fn ($c) => ...)` with `->selectRaw('COALESCE(SUM((metadata->>\'refund_share_cents\')::int), 0) as total')->value('total')` so PostgreSQL does the aggregation.
    - **Technical:** The code hydrates full `CommissionClawback` Eloquent models (all columns, JSONB casts, timestamps) to sum a single nested JSONB key. At pre-beta clawback counts (0–2 per order) this is negligible. The pattern is nonetheless a category-5 micro-antipattern — aggregate in SQL, not PHP — and `selectRaw` aggregates are already used elsewhere in the codebase (`CommissionVoidService::pendingSummaryForAffiliateBrand`).
    - **Plain English:** Instead of asking the database "what's the total refund covered so far?", the code fetches every clawback row and adds them up in PHP — like taking every coin out of a jar to count them instead of just asking the bank for the balance.
    - **Evidence:**
        ```php
        $priorRefundCovered = (int) CommissionClawback::query()
            ->where('payout_id', $payout->id)
            ->where('order_id', $order->id)
            ->get()
            ->sum(fn ($c) => (int) ($c->metadata['refund_share_cents'] ?? 0));
        ```

- [ ] **#SCALE-18** · P3 — `AffiliateProductCatalogService::seedDefaultSelections` creates selections with individual `create()` calls in a loop
    - **Where:** `app/Services/Store/AffiliateProductCatalogService.php` — `seedDefaultSelections` `foreach ($defaultGids as $gid)` loop
    - **Affects:** Affiliate-brand connection onboarding — N individual INSERTs for a default collection of up to 500 products.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Collect new selections into a `$batch[]` array, then use `AffiliateProductSelection::insert($batch)` for a single multi-row INSERT.
        - Pre-compute sort offsets within the batch array rather than incrementing `$maxSort` inside the loop after individual inserts.
    - **Technical:** Each `AffiliateProductSelection::create()` is a separate INSERT with its own round-trip. For a 100-product default collection that's 100 round-trips. A single `insert([...])` reduces this to one. This runs once per affiliate-brand connection (not a hot path), but at 1,500 connections at target scale the cumulative write pressure matters.
    - **Plain English:** When an affiliate connects to a brand, their default product picks are added one at a time — one database write per product. For a 100-product catalog that's 100 individual writes instead of one batch. The fix bundles them into a single delivery.
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

- [ ] **#SCALE-19** · P3 — `BrandDesignMediaService::deletePlaceholder` uses a two-pass UPDATE loop — up to 8 UPDATEs for a single delete
    - **Where:** `app/Services/Media/BrandDesignMediaService.php:145–176`
    - **Affects:** Brand dashboard users deleting placeholder images — unnecessary write amplification.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the two-pass offset/final loop with a single `UPDATE site_media SET sort_order = CASE id WHEN ? THEN ? … END WHERE id IN (...)` statement, which avoids the unique-constraint collision without the intermediate offset pass.
    - **Technical:** Two UPDATE passes (first to `PLACEHOLDER_MAX + 1000` offset, then to final values) produce up to 4 rows × 2 passes = 8 UPDATE statements per delete. The guard against the partial unique index on `(site_id, pool, purpose, sort_order)` is correct, but a single `CASE WHEN` update atomically assigns all new values without triggering any collision. Cardinality is bounded at 5 placeholders, so the impact is trivial — but the pattern is duplicated in `reorderPlaceholders` (SCALE-20) and both call sites should be fixed together.
    - **Plain English:** Deleting one placeholder image causes up to eight database writes: the code moves all remaining images to temporary positions, then moves them again to their final positions, to avoid a numbering conflict. A smarter single-step update can skip the intermediate shuffle entirely.
    - **Evidence:**
        ```php
        $offset = self::PLACEHOLDER_MAX + 1000;
        foreach ($remaining as $idx => $r) {
            SiteMedia::query()->where('id', $r->id)->update(['sort_order' => $offset + $idx]);
        }
        foreach ($remaining as $idx => $r) {
            SiteMedia::query()->where('id', $r->id)->update(['sort_order' => $idx]);
        }
        ```

- [ ] **#SCALE-20** · P3 — `BrandDesignMediaService::reorderPlaceholders` uses the same two-pass UPDATE loop as `deletePlaceholder`
    - **Where:** `app/Services/Media/BrandDesignMediaService.php:188–214`
    - **Affects:** Brand dashboard users reordering placeholder images — up to 10 UPDATEs for a 5-placeholder reorder.
    - **Effort:** S (~0.5h) — fix alongside SCALE-19 in one pass.
    - **What to do:**
        - Apply the same single `CASE WHEN` bulk UPDATE fix as SCALE-19. Both methods share the pattern and should be changed together.
    - **Technical:** Identical two-pass antipattern to `deletePlaceholder`. Two UPDATE passes × up to 5 rows = 10 UPDATE statements per reorder. Same fix applies.
    - **Plain English:** Reordering the placeholder images also triggers the double-shuffle. Same fix as SCALE-19.
    - **Evidence:**
        ```php
        $offset = self::PLACEHOLDER_MAX + 1000;
        foreach ($orderedIds as $idx => $id) {
            SiteMedia::query()->where('id', $id)->update(['sort_order' => $offset + $idx]);
        }
        foreach ($orderedIds as $idx => $id) {
            SiteMedia::query()->where('id', $id)->update(['sort_order' => $idx]);
        }
        ```

- [ ] **#SCALE-21** · P3 — `BrandDesignMediaService::getLogoFullUrls` has no caching on a batch-read path used by partner cards and invite flows
    - **Where:** `app/Services/Media/BrandDesignMediaService.php:290–309`
    - **Affects:** Partner card displays and invite emails showing brand logos — re-queries `site_media` with a `WHERE IN` on every render.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap in `CacheLockService::rememberLocked` keyed by a sorted hash of `$siteIds` with a 60s int TTL + jitter.
        - Push-invalidate per-site within `BrandDesignMediaService::invalidateSiteCache()`, which already calls `forgetBrandDesign` on every logo upload/delete.
    - **Technical:** The method queries `site_media` with `WHERE site_id IN (...)` plus eager-loaded `mediaVariants` on every call. The invalidation hook already exists (`forgetBrandDesign` in the existing `invalidateSiteCache` flow) — a `rememberLocked` wrap completes the read-path hygiene at negligible cost.
    - **Plain English:** Every time the app renders a list of partner cards showing brand logos, it asks the database for every logo from scratch. Logos don't change between uploads. A 60-second cache means the list builds from memory instead of re-querying, and the cache automatically clears whenever someone updates their logo.
    - **Evidence:**
        ```php
        public function getLogoFullUrls(array $siteIds): array
        {
            if (empty($siteIds)) { return []; }

            return SiteMedia::query()
                ->whereIn('site_id', $siteIds)
                ->where('pool', SiteMedia::POOL_DESIGN)
                ->where('purpose', SiteMedia::PURPOSE_LOGO_FULL)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->where('processing_state', '!=', SiteMedia::PROCESSING_STATE_FAILED)
                ->with('mediaVariants')
                ->get()
                ->mapWithKeys(fn (SiteMedia $m): array => [
                    (string) $m->site_id => $m->variantUrls()['optimized'] ?? null,
                ])
                ->filter()
                ->all();
        }
        ```

- [ ] **#SCALE-22** · P3 — `EmailSubscription` observer dispatches one `SyncCustomerMarketingOptInJob` per row save — bulk import creates N simultaneous queue jobs
    - **Where:** `app/Models/Core/Notifications/EmailSubscription.php` — `booted()` `saved` hook
    - **Affects:** Queue workers and dashboard responsiveness when professionals bulk-import marketing consent lists — a 1,000-row CSV import dispatches 1,000 jobs simultaneously.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `ShouldBeUniqueUntilProcessing` (or `ShouldBeUnique`) to `SyncCustomerMarketingOptInJob` keyed on `(professional_id, email)` so rapid re-saves coalesce within a dedup window.
        - Or detect bulk-import context (a header or a service-layer flag) and skip the per-save dispatch, instead triggering a single batch-sync job at the end of the import.
    - **Technical:** The `saved` observer fires on every INSERT/UPDATE of an `EmailSubscription` row. A 1,000-row CSV import dispatches 1,000 jobs each performing a `Customer` lookup and `UPDATE`. This is job-level write amplification that saturates the queue and causes back-pressure for unrelated work. The canonical Partna replacement is a debounced or batch approach rather than per-row fan-out.
    - **Plain English:** Every subscriber in a bulk upload gets their own background task dispatched at the same moment. A thousand-row upload equals a thousand simultaneous workers competing for attention. One task that processes the whole batch would achieve the same result without the traffic jam.
    - **Evidence:**
        ```php
        static::saved(function (self $subscription) {
            if ($subscription->list_key === 'marketing' && $subscription->professional_id && $subscription->email) {
                DB::afterCommit(function () use ($professionalId, $email, $isSubscribed) {
                    \App\Jobs\Notifications\SyncCustomerMarketingOptInJob::dispatch(
                        $professionalId,
                        $email,
                        $isSubscribed,
                    );
                });
            }
        });
        ```

- [ ] **#SCALE-23** · P3 — `HandleAliasExpiringMail` accepts `object` type hint instead of the concrete Eloquent model class — obscures `SerializesModels` contract
    - **Where:** `app/Mail/HandleAliasExpiringMail.php:9–12`
    - **Affects:** Queue serialization of the handle-alias expiry mail — a future caller passing a non-Model object gets fully serialized inline, silently bloating the queue payload.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Replace `public readonly object $alias` with the concrete Eloquent model class (e.g. `ProfessionalHandleAlias` or whatever the actual model is) so the `SerializesModels` trait stores only the model ID on the queue.
        - Verify the dispatch site always passes an Eloquent model instance.
    - **Technical:** `SerializesModels` checks `instanceof Model` at runtime — it works correctly if the property is a Model, regardless of the declared type hint. But `object` means static analysis can't verify this, and a future developer passing a non-Model DTO gets a full PHP serialization of the object inline in the queue payload with no warning. Replacing with the concrete type costs nothing and makes the contract explicit.
    - **Plain English:** This queued email is told its payload is "some object" instead of "a specific type of database record." When the background worker picks it up, it figures out the right way to load the data. But if someone accidentally passes the wrong kind of object, it gets fully stored in the queue instead of just storing a reference ID — silently bloating the queue. Naming the exact expected type prevents that.
    - **Evidence:**
        ```php
        class HandleAliasExpiringMail extends Mailable implements ShouldQueue
        {
            use Queueable, SerializesModels;

            public function __construct(
                public readonly object $alias,
                public readonly string $bucket  // 't3' or 't1'
            ) {}
        }
        ```

- [ ] **#SCALE-24** · P3 — Notification sweep jobs (`InviteExpirySweepJob`, `NudgeStuckOnboardingJob`, `SendWeeklyAnalyticsNotificationJob`) publish per-row synchronously inside chunk loops
    - **Where:** `app/Jobs/Notifications/InviteExpirySweepJob.php:72–97`, `app/Jobs/Notifications/NudgeStuckOnboardingJob.php:124–151`, `app/Jobs/Notifications/SendWeeklyAnalyticsNotificationJob.php:87–94`
    - **Affects:** Notification delivery during scheduled sweeps — serial `NotificationPublisher::publish()` INSERTs inside chunk loops, unbounded by recipient count within each chunk.
    - **Effort:** S (~0.5–1h) per job
    - **What to do:**
        - For each job: collect publish payloads per chunk and dispatch a bulk-notification job or call a batch-publish method if `NotificationPublisher` gains one.
        - The bulk status updates (`whereIn → update`) in these jobs are already batched correctly — only the publish side remains per-row.
    - **Technical:** Each job chunks the database query (`chunkById(500)`) but then calls `NotificationPublisher::publish()` once per row inside the chunk callback, producing one notification INSERT per expired invite / stuck brand / active professional. At target scale: `InviteExpirySweepJob` could process hundreds of expired invites per day; `SendWeeklyAnalyticsNotificationJob` targets ~1,500 active professionals once per week (1,500 serial INSERTs during Monday's 09:00 UTC sweep). Batching the publish calls would reduce the per-sweep DB round-trip count significantly.
    - **Plain English:** Three scheduled jobs that send notifications each walk to each recipient's desk and hand them a note individually instead of leaving a stack in the mailroom. At current scale this is a short walk; at 1,500 desks the weekly analytics digest becomes a Monday morning stroll that takes noticeably longer than it needs to.
    - **Evidence:**
        ```php
        // InviteExpirySweepJob:
        foreach ($chunk as $invite) {
            $publisher->publish(professionalId: $brandId, /* ... */, dedupeKey: "invite.expired.{$invite->id}", /* ... */);
        }

        // SendWeeklyAnalyticsNotificationJob:
        foreach ($professionals as $professional) {
            if ($this->notifyProfessional($publisher, $professional, $metrics, $yearWeek)) { $sent++; }
        }
        // notifyProfessional() calls $publisher->publish(...) synchronously
        ```

- [ ] **#SCALE-25** · P3 — `CheckStreamingLiveStatusJob` hydrates full `Block` Eloquent models to extract two scalar JSONB fields
    - **Where:** `app/Jobs/Streaming/CheckStreamingLiveStatusJob.php:64–84`
    - **Affects:** Streaming live-status polling job running every 2 minutes — hydrates all Block attributes, casts, and timestamps when only `settings->platform` and `settings->handle` are needed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `Block::query()->chunkById(500, ...)` with `DB::table('site.blocks')->select(['id', 'settings'])->chunkById(500, ...)` to avoid Eloquent model hydration overhead.
    - **Technical:** Each Block model hydration fires JSONB casts for `settings`, Carbon casts for `created_at`/`updated_at`, soft-delete attribute checks, and the model's `booted` hooks. For a job that runs every 2 minutes and only reads two leaf values from the `settings` JSONB, this is unnecessary overhead. The `select(['id', 'settings'])` query builder approach avoids all of it while retaining the correct `chunkById` pagination.
    - **Plain English:** Every two minutes the system pulls out every streaming block's complete personnel file just to read two lines — the platform name and the streamer's handle. A lighter query that fetches only those two fields would be faster and use less memory.
    - **Evidence:**
        ```php
        Block::query()
            ->where('block_group', 'links')
            ->whereRaw("settings->>'live_check_enabled' = ?", ['true'])
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->chunkById(500, function ($blocks) use (&$handlesByPlatform, $streamingPlatforms): void {
                foreach ($blocks as $block) {
                    $settings = is_array($block->settings) ? $block->settings : [];
                    $platform = $settings['platform'] ?? null;
                    $handle   = $settings['handle'] ?? null;
                    // only these two fields are used
                }
            });
        ```

- [ ] **#SCALE-26** · P3 — `ProfessionalAnalyticsController::shopSummary` is deprecated but still carries live cache-key logic and query infrastructure
    - **Where:** `app/Http/Controllers/Api/Professional/Analytics/ProfessionalAnalyticsController.php:259–403`
    - **Affects:** Maintenance burden — any change to `CacheKeyGenerator::analyticsSummary()` or the commerce aggregate schema must be mirrored in this dead path.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Confirm no in-flight callers remain (check route definitions, frontend, mobile clients).
        - Remove the method and its route registration once confirmed clear.
    - **Technical:** The method is annotated `@deprecated Data is now folded into summary()` and duplicates the caching pattern from `summary()`: it builds its own raw `$cacheKey` string (`'analytics:shop:...'`) instead of using `CacheKeyGenerator`, re-implements date-range parsing, and runs independent live queries against `commerce.orders` and `analytics.*` tables. Every future schema change creates a drift risk between the two code paths.
    - **Plain English:** There's an old, disconnected checkout counter sitting behind the new one. It still has a working cash register but nobody uses it. Every time the store remodels, someone has to remember to update both counters. Remove the old one.
    - **Evidence:**
        ```php
        /**
         * Shop analytics funnel for the authenticated professional (as affiliate).
         *
         * @deprecated Data is now folded into summary() — kept temporarily for in-flight callers.
         */
        public function shopSummary(Request $request): JsonResponse
        {
            $cacheKey = 'analytics:shop:'.$professional->id.':'.$from->format('YmdH').':'.$to->format('YmdH').':'
                .($useHourlyBuckets ? 'hour' : 'day').":v{$summaryVersion}";
            // ...
        }
        ```

- [ ] **#SCALE-27** · P3 — Three reorder controllers use two-pass `foreach` UPDATE loops producing 2N individual UPDATEs per reorder
    - **Where:** `app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php:284–297`, `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffLinkBlockManagementController.php:126–141`, `app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffSectionManagementController.php:126–141`
    - **Affects:** Media gallery reorders (professional), staff link-block reorders, staff section-block reorders — each generates 2N individual UPDATEs inside a transaction holding a `pg_advisory_xact_lock`.
    - **Effort:** S (~0.5–1h) — fix all three in one pass; they share the same pattern.
    - **What to do:**
        - Replace the offset-then-final two-pass pattern in all three controllers with a single `UPDATE ... SET sort_order = CASE id WHEN ? THEN ? … END WHERE id IN (...)`.
        - The explicit `$site->touch()` call at the end of `ProfessionalUploadController::reorder()` should be retained — it correctly closes the observer-bypass gap.
    - **Technical:** All three controllers move items to a high-offset sort_order first (to avoid partial unique-index collisions), then move them to final positions — producing 2N UPDATE statements. A single `CASE WHEN` update assigns all new values atomically, avoiding any collision because no intermediate state exists. At current scale (6–10 items per pool) this is invisible; the pattern is worth fixing because it's duplicated across three files and establishes the wrong habit.
    - **Plain English:** When someone reorders photos or links, the code moves everything to temporary positions and then moves them all again to their final places — twice the work for one operation. Sending all the final positions in a single instruction achieves the same result with half the database writes.
    - **Evidence:**
        ```php
        // ProfessionalUploadController (identical pattern in all three):
        foreach ($finalIds as $index => $id) {
            SiteMedia::query()->where('site_id', $site->id)->where('id', $id)
                ->update(['sort_order' => $offset + $index]);
        }
        foreach ($finalIds as $index => $id) {
            SiteMedia::query()->where('site_id', $site->id)->where('id', $id)
                ->update(['sort_order' => $index]);
        }
        ```

- [ ] **#SCALE-28** · P3 — `PublicShopifyStorefrontController` uses `Cache::has` + `Cache::put` (TOCTOU race) where `Cache::add` would be atomic
    - **Where:** `app/Http/Controllers/Api/PublicSite/PublicShopifyStorefrontController.php:111–117`
    - **Affects:** Storefront token creation dedup — under concurrent requests for a brand with no `storefront_token`, two `CreateStorefrontAccessTokenJob` instances may be dispatched before either writes the dedup key.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Replace `if (! Cache::has($jobKey)) { ... Cache::put($jobKey, true, 600); }` with `if (Cache::add($jobKey, true, 600)) { ... }`.
        - `Cache::add` is Redis `SETNX` — atomic check-and-set. The `Log::info` stays inside the `if` block.
    - **Technical:** `Cache::has($jobKey)` is a read; `Cache::put($jobKey, true, 600)` is a separate write. Two concurrent requests can both pass the `has()` check before either executes `put()`, dispatching two jobs. `CreateStorefrontAccessTokenJob` likely has `ShouldBeUnique` so the double-dispatch is deduplicated at the queue, but the TOCTOU race is still an antipattern. `Cache::add` (Redis `SETNX`) is the standard atomic replacement.
    - **Plain English:** Two receptionists both check a shared calendar, see an empty slot, and both book it — then the system cancels the duplicate. We have a one-step "check and book simultaneously" tool (`Cache::add`) that prevents the double-booking from ever occurring. The fix is one line.
    - **Evidence:**
        ```php
        $jobKey = 'storefront-token-job:'.$integration->id;
        if (! Cache::has($jobKey)) {
            Log::info('Storefront token missing, dispatching creation job.', [...]);
            CreateStorefrontAccessTokenJob::dispatch((string) $integration->id);
            Cache::put($jobKey, true, 600);
        }
        ```

`★ Insight ─────────────────────────────────────`
Three structural patterns dominate this audit — worth remembering for future work:
1. **The `:stale` twin rule** (SCALE-4): `CacheLockService::rememberLocked` writes two keys for every cache entry. Every `Cache::forget` call site must delete both `$key` and `$key.':stale'` or the SWR window serves stale data after invalidation. This is easy to miss and already bit `BrandProfileObserver`.
2. **`DateTimeInterface` TTL silently disables jitter** (SCALE-8): `CacheLockService::writeWithJitter` only applies ±20% jitter when the TTL is an integer. Passing `now()->addMinutes(2)` looks equivalent but produces an absolute expiry timestamp — all instances expire in lockstep. Always pass plain ints to `rememberLocked`.
3. **The vendor-client contract** (SCALE-3): `ShopifyAdminClient` is not just an HTTP wrapper — it owns token-bucket throttling, cost reconciliation, and typed retry semantics. Any Shopify API call that bypasses it inherits none of those guarantees, and failures become silent broken states rather than retryable exceptions.
`─────────────────────────────────────────────────`
