- [ ] **#CACHE-1** · P2 — `staffAnalyticsSummary` cache key lacks version token for bulk invalidation
    - **Where:** app/Services/Cache/CacheKeyGenerator.php:170-173
    - **Affects:** Staff-facing analytics dashboard; any schema or logic change to staff analytics aggregation would require enumerating every (user, date range) key to bust, which is impossible at scale.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `staffAnalyticsSummaryVersion(string $userId)` method mirroring `analyticsSummaryVersion`.
        - Increment the version on every aggregate recompute or schema change so all staff-analytics keys for a professional rotate at once.
    - **Technical:** The sibling `analyticsSummary` key has a dedicated `analyticsSummaryVersion` token consumed by the controller/service that builds the summary cache key — bumping the version busts every (startDate, endDate) variant for a professional atomically. `staffAnalyticsSummary` was added later without this paired token, so a deploy that changes the aggregation query or data shape would need a code-enumeration of all possible `(from, to)` ranges or a blunt `cache:clear`. At 30 brands tracking 365+ days of date-range queries, that's ~10K keys un-bustable by prefix alone. The canonical replacement is a version token + bump-on-write, matching the `analyticsSummaryVersion` pattern already in the same class.
    - **Plain English:** Imagine a filing cabinet with two drawers. One drawer has a master key that lets you replace every folder inside at once when you reorganise. The other drawer requires you to find and replace each folder individually — and it has thousands of folders. The staff analytics cache drawer is the second one. Adding the same master-key system costs an hour and prevents a future data-consistency emergency.
    - **Evidence:**
        ```php
        public static function staffAnalyticsSummary(string $userId, string $from, string $to): string
        {
            return "staff:analytics:summary:{$userId}:{$from}:{$to}";
        }

        // Compare — the sibling key HAS a version token:
        /**
         * Version token used to bust all analytics summary keys for a professional at once.
         */
        public static function analyticsSummaryVersion(string $userId): string
        {
            return "analytics:summary:ver:{$userId}";
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CACHE-2** · P3 — `RefreshIntegrationConnectionsCommand` linearly throttles refreshes with `usleep` instead of chunked/batched dispatching
    - **Where:** app/Console/Commands/RefreshIntegrationConnectionsCommand.php:35-50
    - **Affects:** Operator running the nightly refresh cron; at peak (300 connections × 200ms throttle) the command takes 60+ seconds wall-clock and blocks the process, holding a worker thread.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Chunk connections into batches of ~25 and dispatch each batch as a dedicated `RefreshConnectionBatchJob` queued to `default`.
        - Keep the throttle for the per-batch head-of-line delay but let Horizon parallelise across workers.
    - **Technical:** The command loads up to 300 stale connections in one query then iterates them in a single PHP process, sleeping 200ms between each. `PlatformRefresher::refresh()` calls an external scraper (YouTube/Eventbrite/Apple) per connection; a single slow scrape blocks all subsequent connections. At 300 connections × 200ms throttle = 60s minimum, but real scrapes add network latency (1–5s each at the 99th percentile), pushing total runtime toward 3–10 minutes. The canonical replacement is chunked batch-dispatch so Horizon distributes the work across workers, each batch holding a short `Cache::lock` to prevent duplicate refreshes of the same connection.
    - **Plain English:** The nightly refresh is a single-file queue at the post office — one clerk processes every letter in order, pausing between each. If one letter takes 5 seconds (because the destination server is slow), everyone behind it waits. Splitting into batches that can be processed in parallel by multiple clerks keeps the whole run under a minute instead of stretching to 10. At 300 connections the pain is mild; at 900 it blocks the cron window.
    - **Evidence:**
        ```php
        foreach ($connections as $connection) {
            try {
                $refreshed = $refresher->refresh($connection);
                $refreshed->last_refresh_status === 'ok' ? $ok++ : $failed++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('integrations:refresh failed for a connection', [...]);
            }
            if ($throttleMs > 0) {
                usleep($throttleMs * 1000);
            }
        }
        ```
    - `[DRAFT, confidence: 0.65]`

- [ ] **#CACHE-3** · P3 — `ShopifyController` catalog cache uses a fixed 10-minute TTL with no jitter
    - **Where:** app/Http/Controllers/Api/Platforms/ShopifyController.php:156, app/Http/Controllers/Api/Platforms/ShopifyController.php:36
    - **Affects:** Multiple users opening the product picker for the same brand simultaneously just as the shared catalog TTL expires — each triggers an independent re-scrape of `/products.json`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply `JitteredTtl::applyJitter()` to `CATALOG_TTL_MINUTES` on each `Cache::put` in `brandProducts()`.
        - Optionally wrap the scrape in a `CacheLockService::rememberLocked` call so concurrent cold misses coalesce to one scrape.
    - **Technical:** `CATALOG_TTL_MINUTES = 10` is passed directly to `now()->addMinutes()` — every write sets an exact 10-minute expiry. The catalog key is per-brand (`platforms.shopify.brands.catalog.{id}`), so all users viewing the same brand's picker share one cache entry. When that entry expires, every concurrent dashboard load for that brand triggers `$this->scraper->fetchProducts()` — a full HTTP scrape of `/products.json` (up to 250 products). At 30 brands with 3–5 users each, the thundering-herd window is small, but with ±20% jitter (±2 min) the risk drops to near zero. The canonical pattern is `JitteredTtl::applyJitter()` already available in `app/Services/Cache/Concerns/JitteredTtl.php`.
    - **Plain English:** The product catalog cache expires exactly 10 minutes after it's filled, like a kitchen timer with no randomness. If two chefs walk in at the exact same second wanting the same recipe book, both grab the phone and call the store separately. Adding a small random wobble to the timer (9–11 minutes instead of exactly 10) means they almost never collide. At 30 stores this is a paper cut; at 500 it's a scrape storm.
    - **Evidence:**
        ```php
        private const CATALOG_TTL_MINUTES = 10;
        // ...
        Cache::put($this->catalogKey($id), $products, now()->addMinutes(self::CATALOG_TTL_MINUTES));
        ```
    - `[DRAFT, confidence: 0.70]`

- [ ] **#CACHE-4** · P3 — `InstagramController::guardApifyBudget()` daily counter uses non-atomic read-modify-write
    - **Where:** app/Http/Controllers/Api/Platforms/InstagramController.php:189-199
    - **Affects:** Pilot cost-control guard for the paid Apify Instagram scraper; two concurrent connects can both read the old count, both increment, and both write the same value, exceeding `APIFY_DAILY_CAP` by 1.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::get` + `Cache::put` with `Cache::increment()` (Redis `INCR` is atomic) on the daily counter key, gating on the returned value.
        - Keep an `EXPIRE` on the key for TTL, or use Redis with an expiry set once.
    - **Technical:** The daily cap counter reads the current value, checks it, then writes `$count + 1`. Between the read and write, a concurrent request can read the same stale value. Both threads write `$count + 1` instead of `$count + 2`, so two requests consume one cap slot. At `APIFY_DAILY_CAP = 200` with ≤10 concurrent connects, the overflow is at most 1–2 extra scrapes — negligible cost. But `Cache::increment()` (which maps to Redis `INCRBY`, an atomic operation) closes the gap entirely with a one-line change. The comment in the code already acknowledges this is "good enough for a pilot."
    - **Plain English:** Two cashiers share a tally sheet for "how many customers today." Each looks at the number (say, 199), adds 1, and writes 200. Neither sees the other's update, so they both think they're customer #200. The real count should be 201. Using a counter that locks during read-and-write (which Redis provides natively) fixes this with almost no code change.
    - **Evidence:**
        ```php
        $dayKey = 'platforms:instagram:apify-daily:'.now()->format('Y-m-d');
        $count = (int) Cache::get($dayKey, 0);
        if ($count >= self::APIFY_DAILY_CAP) {
            return $this->error('Instagram is busy right now — please try again later.', 429);
        }
        Cache::put($dayKey, $count + 1, now()->addDay());
        ```
    - `[DRAFT, confidence: 0.90]`
