
<!-- ═══ SUB-CHUNK: s1 (app/Services/Shopify app/Services/Store) ═══ -->

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

<!-- ═══ SUB-CHUNK: s2 (app/Services/Media app/Services/Analytics app/Services/Site app/Services/PublicSite) ═══ -->

- [ ] **CACHE-1** · P2 — AffiliateProjectionsService has no caching layer, executing 5+ DB queries per request on a dashboard read path
    - **Where:** app/Services/Analytics/AffiliateProjectionsService.php (entire `build()` method)
    - **Affects:** Affiliate dashboards viewing run-rate, momentum, YTD, and year-end forecast projections
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap the `build()` method in `CacheLockService::rememberLocked` with a 60–120s TTL + jitter.
        - Push-invalidate the cache key on every `brand_affiliate_rollup` upsert (the trigger that updates the source table), or version-token bust on any commission movement.
        - Consider exposing the version token from `CacheKeyGenerator` so writes automatically roll the cache key forward (the `analyticsSummaryVersion` pattern).
    - **Technical:** The `build()` method calls `resolveDataHistoryDays` (1 query), `fetchPerCurrencyAggregates` (1), `fetchPriorWindowAggregates` (1), `fetchYtdAggregates` (1), and `fetchBestMonthPerCurrency` (1 subquery) — five DB round-trips per request with no cache barrier. This is purely a read projection; it has no side effects and is an ideal candidate for `CacheLockService::rememberLocked`, which provides single-flight lock, TTL jitter, and SWR semantics. The source table `commerce.brand_affiliate_rollup` is trigger-maintained, so invalidation can be coupled to the upsert trigger or a version-token increment.
    - **Plain English:** Imagine the dashboard that shows an affiliate "you're on track to earn £X this year" recalculates that number from scratch every single time the page loads — five separate trips to the database. The numbers don't change between commission events, so we're doing fresh math when the answer hasn't changed. Wrapping it in a short-lived cache (like putting the answer on a sticky note for 60 seconds) eliminates the redundant work without making the numbers stale.
    - **Evidence:**
        ```php
        public function build(Professional $professional, ?int $windowDaysOverride = null): array
        {
            // ... no cache — every call runs:
            $dataHistoryDays = $this->resolveDataHistoryDays($professional->id, $now);
            $perCurrency = $this->fetchPerCurrencyAggregates(...);
            $priorByCurrency = $this->fetchPriorWindowAggregates(...)->keyBy('currency_code');
            $ytdByCurrency = $this->fetchYtdAggregates(...)->keyBy('currency_code');
            $bestMonthByCurrency = $this->fetchBestMonthPerCurrency(...)->keyBy('currency_code');
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **CACHE-2** · P1 — PublicSiteResolver has no caching on the hottest read path in the application
    - **Where:** app/Services/PublicSite/PublicSiteResolver.php:18-38
    - **Affects:** Every public site visitor; subdomain → Site resolution runs on every page view
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap `resolvePublishedSite()` in `CacheLockService::rememberLocked` with a 30–60s TTL + jitter.
        - Push-invalidate on every `Site` publish/unpublish, subdomain change, and `Professional` status change to `active`/`suspended`.
        - Use a versioned key (`public_site:v{site_version}`) so the cache auto-rolls on any relevant mutation.
    - **Technical:** `resolvePublishedSite()` runs on every public page request. It queries `Site` by subdomain (1 query), and if that misses, queries `SiteSubdomainAlias` (1 query) and then `Site` again by `site_id` (1 query). At target scale — 30 brands × 50 affiliates × an unknown but non-trivial public traffic volume — this is the single most frequently executed read path in the entire application. The `CacheLockService::rememberLocked` pattern with push-invalidation is already proven on the commerce analytics path and fits perfectly here; the `SiteCacheService` already has invalidation hooks that could be extended.
    - **Plain English:** Every person who visits an affiliate's storefront triggers up to three separate database lookups just to figure out which site to show them. If a thousand people visit in a minute, that's three thousand database queries asking "which site is this subdomain?" when the answer hasn't changed since the last deployment. Putting the answer in a short-lived cache is like putting the site address on a whiteboard instead of walking to the filing cabinet every time someone asks.
    - **Evidence:**
        ```php
        public function resolvePublishedSite(string $subdomain): ?Site
        {
            $subdomain = strtolower($subdomain);
            $siteQuery = Site::query()
                ->where('is_published', true)
                ->with('professional')
                ->whereHas('professional', function ($q) { $q->where('status', 'active'); });

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
    - `[DRAFT, confidence: 0.90]`

- [ ] **CACHE-3** · P2 — BrandDesignMediaService::deletePlaceholder uses double-UPDATE repack loop — write amplification on every placeholder delete
    - **Where:** app/Services/Media/BrandDesignMediaService.php:145-176 (the repack loop)
    - **Affects:** Brand dashboard users deleting placeholder images; unnecessary DB write load
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Compute the final `sort_order` values in PHP, then issue a single bulk `UPDATE` using a `CASE WHEN` statement or a batch upsert.
        - Alternatively, accept gaps in `sort_order` and let the `listDesignMedia` query renumber on read with `ROW_NUMBER()` — the list is always sorted anyway.
    - **Technical:** When a placeholder is deleted, the method re-packs the remaining placeholders' `sort_order` to `(0, 1, 2, ...)` using two UPDATE passes: first to a high offset (`PLACEHOLDER_MAX + 1000`) to avoid unique-index collisions, then back to the final values. Each pass executes one UPDATE per remaining row — up to 4 placeholders × 2 passes = 8 UPDATE statements for a single delete. This is write amplification: one user action triggers up to 8 DB writes on a table whose cardinality is bounded at 5. The two-pass technique correctly avoids the unique-index collision, but a single `UPDATE ... SET sort_order = CASE WHEN id = ... THEN ... END` would achieve the same result in one statement.
    - **Plain English:** When a brand deletes one of their five placeholder images, the code doesn't just remove it — it renumbers every remaining placeholder by moving them to temporary numbers and then to their final positions, generating up to eight database updates for one delete. It's like re-filing every folder in a drawer when you remove one file, instead of just closing the gap with one shuffle. The fix is to send all the new positions in a single instruction.
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
    - `[DRAFT, confidence: 0.80]`

- [ ] **CACHE-4** · P2 — BrandDesignMediaService::reorderPlaceholders uses identical double-UPDATE repack loop
    - **Where:** app/Services/Media/BrandDesignMediaService.php:188-214
    - **Affects:** Brand dashboard users reordering placeholder images; same write-amplification profile as delete
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Same single-`CASE WHEN` bulk UPDATE approach as CACHE-3.
        - Or delegate renumbering to `ROW_NUMBER()` at read time so the write path is a single pass with the caller-supplied order.
    - **Technical:** Identical antipattern to `deletePlaceholder` — two UPDATE passes per placeholder, up to 10 UPDATEs for a 5-placeholder reorder. The two passes guard against the partial unique index on `(site_id, pool, purpose, sort_order)`, but a single `CASE WHEN` update avoids the collision entirely because all new values are assigned atomically. The cardinality is capped at 5, so the blast radius is tiny, but the pattern is duplicated and both call sites should be fixed together.
    - **Plain English:** Same as deleting a placeholder — reordering them also triggers the double-shuffle update pattern. If they reorder all five images, that's ten database writes when one could do it. It's like rewriting every index card's position twice instead of once.
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
    - `[DRAFT, confidence: 0.80]`

- [ ] **CACHE-5** · P2 — VideoVariantService::processVariants uploads HLS segments in a sequential per-file loop — network amplification proportional to video duration
    - **Where:** app/Services/Media/VideoVariantService.php:186-200 (the `scandir` loop inside HLS upload)
    - **Affects:** Video upload processing jobs; worker time grows linearly with video length
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Batch segment uploads using Laravel's HTTP pool or `Storage::disk()->put()` with a concurrent stream wrapper, or use `aws s3 sync` via a subprocess for the HLS directory.
        - As a lighter touch: at minimum, wrap the uploads in a `collect()->chunk()` with a note that R2 supports multipart upload for the directory.
    - **Technical:** For each HLS variant, `processVariants()` scans the temp directory and issues one `$disk->put()` per segment file. HLS segments are typically 6 seconds each, so a 5-minute video produces ~50 segments per variant, × 2 variants = ~100 sequential `put()` calls. Each `put()` is a network round-trip to R2 (or S3-compatible storage). This is not write amplification in the database sense — the segments are necessary artifacts — but the sequential loop amplifies total processing wall-clock time linearly with video duration. The canonical fix is a concurrent upload (multi-threaded or async HTTP pool) or a directory-level sync. At pre-beta with occasional uploads this is fine; at target scale with 30 brands potentially uploading training/intro videos, worker throughput becomes a bottleneck.
    - **Plain English:** When the system processes a video, it breaks it into short segments for streaming and uploads each segment one at a time — like mailing 100 postcards individually instead of putting them all in one envelope. For a 5-minute video, that's about 100 separate uploads, each waiting for the previous one to finish. This doesn't break anything, but it makes video processing slower than it needs to be. Sending the whole batch at once cuts the waiting time significantly.
    - **Evidence:**
        ```php
        foreach ($hlsDirs as $variantKey => $hlsDir) {
            $remoteHlsBase = "{$basePath}/hls/{$variantKey}";
            foreach (scandir($hlsDir) ?: [] as $file) {
                if ($file === '.' || $file === '..') { continue; }
                $localFile = "{$hlsDir}/{$file}";
                $remotePath = "{$remoteHlsBase}/{$file}";
                // ...
                $stream = fopen($localFile, 'rb');
                $disk->put($remotePath, $stream, ['visibility' => 'public', 'ContentType' => $mime]);
                if (is_resource($stream)) { fclose($stream); }
            }
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **CACHE-6** · P3 — BrandDesignMediaService::getLogoFullUrls has no caching for a batch-read path called by partner cards and invite flows
    - **Where:** app/Services/Media/BrandDesignMediaService.php:290-309
    - **Affects:** Partner card displays, invite emails showing brand logos — any caller that needs multiple site logos at once
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CacheLockService::rememberLocked` with a 60s TTL + jitter, keyed by `implode(',', sort($siteIds))` and a version token tied to `SiteCacheService`.
        - Push-invalidate per-site logo caches in the existing `invalidateSiteCache()` method (which already runs `forgetBrandDesign`).
    - **Technical:** `getLogoFullUrls()` is the batch counterpart to `getLogoFullUrl()` and queries `site_media` with a `WHERE IN` on `site_id` plus eager-loaded `mediaVariants`. It's used anywhere multiple brand logos need to be displayed simultaneously (partner cards on the affiliate dashboard, invite emails). Without caching, every render re-queries. The method is already side-effect free and the invalidation hook exists in `BrandDesignMediaService::invalidateSiteCache()` — `forgetBrandDesign` is already called on every logo upload/delete. A simple `rememberLocked` wrap completes the read-path cache hygiene.
    - **Plain English:** When the system builds a list of partner cards showing multiple brand logos, it asks the database for every logo from scratch each time. The logos don't change between uploads — they're the same PNGs that were stored last time. A short cache (60 seconds) means the list renders from memory instead of re-querying, and the cache is automatically cleared whenever someone updates their logo.
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
    - `[DRAFT, confidence: 0.70]`

- [ ] **CACHE-7** · P2 — AnalyticsService::windowedDistinctCount and windowedCartSessions execute 6 separate COUNT DISTINCT queries (one per time window) instead of a single query
    - **Where:** app/Services/Analytics/AnalyticsService.php (windowedDistinctCount at ~line 200, windowedCartSessions at ~line 212)
    - **Affects:** Cold-cache analytics page loads (post-deploy, eviction); latency for the one unlucky request that fills the cache
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the per-window loop with a single `SELECT COUNT(DISTINCT col) FILTER (WHERE occurred_at >= ?) ...` query using PostgreSQL's `FILTER` clause — one query, six columns.
        - Apply the same treatment to `windowedCartSessions`, `brandWindowedCartSessions`, `brandWindowedViews`, and `brandWindowedUniqueVisitors`.
    - **Technical:** The `windowedDistinctCount` method iterates over 6 `self::WINDOWS` keys and issues a separate `COUNT DISTINCT` query per window. `windowedCartSessions` does the same. `brandWindowedCartSessions`, `brandWindowedViews`, and `brandWindowedUniqueVisitors` each add a preliminary `pluck('affiliate_professional_id')` query plus 6 more. This is 12+ queries for `computeAffiliate()` and 25+ for `computeBrand()` on a cold cache. The `CacheLockService::rememberLocked` wrapper provides single-flight protection, so only one request pays this cost, but that request still suffers avoidable DB latency. PostgreSQL's `FILTER` clause (already in use in `AffiliateProjectionsService`) allows all six windows to be aggregated in one query. At target scale (30 brands × 50 affiliates with cold-cache after deploys), the latency hit is noticeable but not catastrophic — the cache absorbs it 99% of the time.
    - **Plain English:** When the analytics dashboard loads for the first time after a deploy, it asks the database the same question six times in a row — "how many unique visitors in the last 24 hours?" then "…in the last 7 days?" then "…in the last 30 days?" and so on. It's like calling someone six times to ask six questions when you could ask them all in one phone call. The dashboard is smart enough to remember the answers for five minutes after that, so only the first person pays the price — but the fix is simple enough to be worth doing.
    - **Evidence:**
        ```php
        private function windowedDistinctCount(string $table, string $distinctColumn, array $bounds, array $where): array
        {
            $result = [];
            foreach (self::WINDOWS as $w) {   // 6 separate queries
                $query = DB::table($table)->whereNotNull($distinctColumn);
                foreach ($where as $col => $val) { $query->where($col, $val); }
                if ($bounds[$w] !== null) { $query->where('occurred_at', '>=', $bounds[$w]); }
                $result[$w] = (int) $query->distinct()->count($distinctColumn);
            }
            return $result;
        }
        ```
        ```php
        private function windowedCartSessions(string $professionalId, array $bounds): array
        {
            $result = [];
            foreach (self::WINDOWS as $w) {   // 6 more separate queries
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
    - `[DRAFT, confidence: 0.80]`
