- [ ] **#CCH-1** · P2 — ShopifyController::brandProducts writes the picker-catalog cache with an unjittered DateTimeInterface TTL
    - **Where:** app/Http/Controllers/Api/Platforms/ShopifyController.php:269
    - **Affects:** Dashboard users opening the Shopify product picker — synchronized expiry across the fleet causes thundering-herd re-scrapes at the deploy boundary or when multiple users open the picker at the same wall-clock offset.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Convert the `Cache::put` to route through `CacheLockService::rememberLocked` so the TTL receives automatic ±20% jitter via `JitteredTtl`.
        - Alternatively, wrap the TTL with `JitteredTtl::withJitter(self::CATALOG_TTL_MINUTES * 60)` at the write site so it stays an int.
    - **Technical:** `now()->addMinutes(N)` produces a `DateTimeInterface` TTL — an exact wall-clock deadline. Every process writing the same key at roughly the same time sets identical expiry. When the key is cold (deploy, scheduled flush, or the first picker-open of the hour), all concurrent brand-product requests from the dashboard will miss together and re-scrape the Shopify store in parallel. The gold standard requires every int-duration TTL to pass through the jitter helper so expiry spreads uniformly across the fleet. `CacheLockService` does this automatically; raw `Cache::put` with `now()->addMinutes()` sidesteps it entirely.
    - **Plain English:** Imagine every clock in a building is set to ring at exactly 10:00 AM. When the alarm goes off, everyone rushes the door at once. Jitter is like setting each clock a few seconds different — the rush spreads out. Right now the Shopify product catalog expires at exactly the same second on every server, so when it goes cold every dashboard user who opens the picker hits the store at the same moment.
    - **Evidence:**
        ```php
        Cache::put($this->catalogKey($id), $products, now()->addMinutes(self::CATALOG_TTL_MINUTES));
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CCH-2** · P3 — ShopifyController::catalogKey uses ad-hoc string concatenation instead of CacheKeyGenerator
    - **Where:** app/Http/Controllers/Api/Platforms/ShopifyController.php:279–281
    - **Affects:** The Shopify picker-catalog cache — writer (brandProducts) and reader (setProducts) share the same method, so no current drift, but future refactors that duplicate the key string risk silent cache misses.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `shopifyBrandCatalog(string $id): string` method to `CacheKeyGenerator`.
        - Replace the inline `self::CATALOG_KEY.'.catalog.'.$id` concatenation with the centralized helper in both `catalogKey()` and any future call sites.
    - **Technical:** The gold standard (rule 8) requires cache keys to originate from `CacheKeyGenerator` (or an equivalent domain helper). Inline key construction is fragile: if a second reader duplicates the string with a different separator or casing, the writer and reader target different keys. Currently both reader and writer are in the same controller, so the risk is bounded — but the pattern is still a deviation from the centralized-key contract every other cache service follows.
    - **Plain English:** All the house keys are cut by one locksmith using the same master template. If a tenant cuts their own copy at a different shop, it might not turn the lock. Right now the Shopify catalog key is cut by hand in the controller — it works because the writer and reader are next to each other, but it doesn't follow the house standard.
    - **Evidence:**
        ```php
        private function catalogKey(string $id): string
        {
            return self::CATALOG_KEY.'.catalog.'.$id;
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CCH-3** · P3 — InstagramController::guardApifyBudget daily counter has a read-modify-write race under concurrent connects
    - **Where:** app/Http/Controllers/Api/Platforms/InstagramController.php:299–304
    - **Affects:** The pilot Apify cost guard — concurrent dashboard connects can exceed the `APIFY_DAILY_CAP` by a few requests before the counter catches up.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::get` + `Cache::put` with an atomic `Redis::incr` (with a TTL set on first increment) so the check-and-increment is a single operation.
        - Alternatively, wrap the read and write in a short-lived `Cache::lock` so only one request evaluates the cap at a time.
    - **Technical:** `$count = (int) Cache::get($dayKey, 0); if ($count >= $cap) { reject; } Cache::put($dayKey, $count + 1, ...);` is a classic check-then-act race. Two concurrent Instagram connects can both read 199, both pass the guard, and both increment to 200/201 — the cap is breached by 1 request and the counter undercounts. The code itself notes "good enough for a pilot." This is not a data-cache correctness issue (the counter is advisory cost control) and the concurrency window is narrow (two users hitting connect at the same instant), so the impact is bounded. But it's a deviation from the gold standard's requirement for atomicity on cache state that gates behaviour.
    - **Plain English:** A bouncer at the door counts people with a hand-clicker but has to look down at the number before clicking. If two people walk in at the exact same moment, both see "199" and both click to "200" — the real count is 201 but the clicker says 200. The room is only over capacity by one person and it fixes itself on the next entry, so it's not dangerous — just not precise.
    - **Evidence:**
        ```php
        $dayKey = 'platforms:instagram:apify-daily:'.now()->format('Y-m-d');
        $count = (int) Cache::get($dayKey, 0);
        if ($count >= self::APIFY_DAILY_CAP) {
            return $this->error('Instagram is busy right now — please try again later.', 429);
        }
        Cache::put($dayKey, $count + 1, now()->addDay());
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#CCH-4** · P2 — InstagramController::guardApifyBudget writes two TTLs without jitter (literal int + DateTimeInterface)
    - **Where:** app/Http/Controllers/Api/Platforms/InstagramController.php:297,304
    - **Affects:** The pilot Apify cost guard — the per-user cooldown key and the global daily counter both expire at synchronized wall-clock times across the fleet.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - For the cooldown `Cache::add`, wrap the TTL: `JitteredTtl::withJitter(self::APIFY_COOLDOWN_SECONDS)`.
        - For the daily `Cache::put`, replace `now()->addDay()` with a jittered int TTL (e.g. `JitteredTtl::withJitter(86400)`).
    - **Technical:** `Cache::add($cooldownKey, 1, 600)` uses a literal integer TTL of 600 seconds — every user's cooldown expires at the same fleet-wide offset. `Cache::put($dayKey, ..., now()->addDay())` uses a `DateTimeInterface` deadline — all daily counters flip at exactly midnight UTC. On a large deploy or a scheduled job that triggers many Instagram connects, these synchronized expiries concentrate load. The gold standard requires jitter on every int TTL; `DateTimeInterface` TTLs sidestep the jitter helper entirely and should be replaced with jittered int durations where the deadline is not a hard business requirement.
    - **Plain English:** Same clock problem as the Shopify catalog — every user's cooldown and the daily limit counter all hit zero at the same second. If a batch job or deploy triggers a wave of Instagram connects right as these expire, they all land at once instead of spreading out over a few seconds.
    - **Evidence:**
        ```php
        if (! Cache::add($cooldownKey, 1, self::APIFY_COOLDOWN_SECONDS)) {
            return $this->error('You refreshed Instagram recently — please wait a few minutes.', 429);
        }
        // ...
        Cache::put($dayKey, $count + 1, now()->addDay());
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CCH-5** · P2 — YoutubeThumbnailResolver::bestForMany writes the thumbnail verdict cache with an unjittered DateTimeInterface TTL
    - **Where:** app/Services/Platforms/YoutubeThumbnailResolver.php:115
    - **Affects:** Dashboard users loading the YouTube picker or highlights picker — all cached maxres/hq verdicts expire at synchronized wall-clock offsets, causing a wave of HEAD probes when a batch of 30-day-old verdicts expire together.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `now()->addDays(self::CACHE_DAYS)` with a jittered int: `JitteredTtl::withJitter(self::CACHE_DAYS * 86400)` so the 30-day window spreads ±20% (24–36 days).
    - **Technical:** `Cache::put(..., now()->addDays(30))` creates a `DateTimeInterface` deadline identical for every video cached in the same second. Videos connected at the same time (e.g. during a batch YouTube channel refresh) all expire together 30 days later. While 30 days is a long window and the re-probe is a fast HEAD (3s timeout, batched via `Http::pool`), the pattern still deviates from the gold standard's requirement that every TTL be jittered. The jitter helper's `applyJitter` only activates for int TTLs; `DateTimeInterface` values pass through unmodified.
    - **Plain English:** Every thumbnail verdict expires exactly 30 days after it was saved. If a professional connects their YouTube channel and the system caches verdicts for 15 videos all at once, exactly 30 days later all 15 expire at the same second and trigger 15 simultaneous HEAD requests to YouTube's CDN. Jitter would spread that burst over a few days instead.
    - **Evidence:**
        ```php
        Cache::put($this->cacheKey($id), $hasMaxres ? 'maxres' : 'hq', now()->addDays(self::CACHE_DAYS));
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CCH-6** · P3 — YoutubeThumbnailResolver::cacheKey uses ad-hoc key construction instead of CacheKeyGenerator
    - **Where:** app/Services/Platforms/YoutubeThumbnailResolver.php:120–122
    - **Affects:** The thumbnail verdict cache — currently writer and reader share the same private method, but the bare `yt_thumb:` prefix lives outside the centralized key registry.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `youtubeThumbnailVerdict(string $videoId): string` method to `CacheKeyGenerator`.
        - Replace the inline `"yt_thumb:{$videoId}"` with the centralized helper.
    - **Technical:** Same category 8 deviation as CCH-2. The gold standard requires all cache keys to originate from `CacheKeyGenerator`. The `cacheKey` helper is private and used only within `YoutubeThumbnailResolver`, so the reader/writer drift risk is currently zero — but the prefix `yt_thumb` is not discoverable in the central registry, making future cache inspections, invalidation sweeps, or key-usage audits harder than they should be.
    - **Plain English:** The key is cut by hand in a single room and only used there, so it works fine. But it's not registered with the locksmith, so nobody else knows it exists.
    - **Evidence:**
        ```php
        private function cacheKey(string $videoId): string
        {
            return "yt_thumb:{$videoId}";
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CCH-7** · P3 — InstagramController::guardApifyBudget uses ad-hoc key strings for rate-limiting cache keys
    - **Where:** app/Http/Controllers/Api/Platforms/InstagramController.php:296,299
    - **Affects:** The pilot Apify cost-guard caches — keys are constructed inline and not discoverable through CacheKeyGenerator.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `instagramCooldown(string $userId): string` and `instagramDailyLimit(string $date): string` methods to `CacheKeyGenerator`.
        - Replace the inline `"platforms:instagram:cooldown:{$user->id}"` and `'platforms:instagram:apify-daily:'.now()->format('Y-m-d')` with the centralized helpers.
    - **Technical:** Same Category 8 issue as CCH-2 and CCH-6. The rate-limiting keys are self-contained (writer and reader are the same `guardApifyBudget` method), so there's no practical drift risk today. But the `platforms:instagram:` prefix is an ad-hoc namespace that doesn't appear in `CacheKeyGenerator`, breaking the convention that every cache key in the application is centrally registered and greppable.
    - **Plain English:** Same pattern — the keys work but aren't on the official registry. If someone later needs to flush all Instagram rate-limit keys or inspect their TTLs, they have to grep controller code instead of looking in one place.
    - **Evidence:**
        ```php
        $cooldownKey = "platforms:instagram:cooldown:{$user->id}";
        // ...
        $dayKey = 'platforms:instagram:apify-daily:'.now()->format('Y-m-d');
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CCH-8** · P3 — YoutubeThumbnailResolver::bestForMany returns the hqdefault fallback without a stale-while-revalidate companion on the cache
    - **Where:** app/Services/Platforms/YoutubeThumbnailResolver.php:105–117
    - **Affects:** Dashboard users loading the YouTube picker after the 30-day thumbnail verdict cache expires — all videos whose verdicts expired are re-probed synchronously (batched via Http::pool, but still within the request).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `:stale` companion key at 10× TTL so expired verdicts return the last-known-good thumbnail immediately while one worker re-probes in the background.
        - Or accept the current behaviour given the 30-day TTL makes expiry rare and the probe is a fast batched HEAD.
    - **Technical:** The gold standard's SWR pattern (rule 3) keeps a `$key:stale` copy at 10× the primary TTL. When the primary expires, readers get the stale value instantly and the lock-holder recomputes. `bestForMany` does a `Cache::get` → collect misses → `Http::pool` probe misses → `Cache::put` each result. There's no `:stale` key, so when a verdict expires, every concurrent request that includes that video ID will include it in the probe batch (wasteful but bounded — Http::pool deduplicates within a single process). The impact is minimal because: (a) TTL is 30 days, (b) probes are fast HEAD requests (3s timeout), (c) the batch is concurrent within one process. Flagging as P3 because the deviation is real but the blast radius is small.
    - **Plain English:** After 30 days, the system forgets whether a YouTube video has an HD thumbnail and has to ask YouTube again. If several people load the picker at that moment, they all ask YouTube the same question. The answer comes back in under 3 seconds and gets cached for another 30 days. The "stale-while-revalidate" pattern would let the first person see last month's answer instantly while quietly refreshing in the background — but since this only happens once a month per video, the current approach is acceptable for now.
    - **Evidence:**
        ```php
        $cached = Cache::get($this->cacheKey($id));
        if ($cached !== null) {
            $result[$id] = $cached === 'maxres' ? $this->maxresUrl($id) : $this->hqUrl($id);
        } else {
            $misses[] = $id;
        }
        // ... probe misses via Http::pool ...
        Cache::put($this->cacheKey($id), $hasMaxres ? 'maxres' : 'hq', now()->addDays(self::CACHE_DAYS));
        ```
    - `[DRAFT, confidence: 0.75]`
