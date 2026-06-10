- [ ] **#SCALE-1** · P1 — `RefreshIntegrationConnectionsCommand` lacks `WithoutOverlapping` — overlapping cron runs risk
    - **Where:** app/Console/Commands/RefreshIntegrationConnectionsCommand.php:27-29 (class + signature)
    - **Affects:** Daily platform refresh cron — at 200 brands with 4 refreshable platforms each, up to 800 connections refreshed per run. If a run takes longer than the cron interval (default throttle 200ms × 800 = ~160s wall time plus vendor I/O), two instances overlap, doubling load on vendor APIs (Apple iTunes, YouTube RSS, Eventbrite) and the `site.platform_connections` table.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->withoutOverlapping()` to the scheduled command in `routes/console.php` (or add the `WithoutOverlapping` trait equivalent if using a custom scheduler).
        - Set a lock timeout slightly longer than the worst-case run duration (e.g. 600s) so a crashed run doesn't hold the lock permanently.
    - **Technical:** Laravel's `WithoutOverlapping` middleware uses an atomic cache lock keyed by the command signature. Without it, two cron invocations can interleave — the second starts fetching connections that the first hasn't marked as refreshed yet, causing double-scrapes against vendor endpoints and redundant DB writes through `IntegrationConnectionObserver`. At the scale target of ~800 refreshes/day this doubles vendor API consumption for Apple/YouTube/Eventbrite endpoints that have no formal rate-limit budget tracking.
    - **Plain English:** Imagine a daily housekeeping script that normally takes 3 minutes. If the clock ticks over and fires it again before the first copy finishes, now there are two copies running — both sweeping the same floors, both ringing the same doorbells. A simple "do not start if already running" lock prevents this.
    - **Evidence:**
        ```php
        class RefreshIntegrationConnectionsCommand extends Command
        {
            protected $signature = 'integrations:refresh {--limit=300 : Max connections to refresh this run} {--throttle-ms=200 : Politeness delay between fetches}';

            protected $description = 'Re-fetch stale auto-content platform connections (pilot).';
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SCALE-2** · P2 — `EventbriteScraper::fetchEvents()` fetches event detail pages serially in a foreach loop
    - **Where:** app/Services/Platforms/EventbriteScraper.php:55-59
    - **Affects:** Daily `integrations:refresh` cron — every Eventbrite-connected brand (est. ~50–100 at scale target) triggers up to 8 serial HTTP requests to eventbrite.com. Serial I/O makes each organiser refresh take ~8 × (network RTT + Eventbrite render time), piling latency onto the cron window.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the serial `foreach` with `Http::pool(fn (Pool $pool) => ...)` to fetch all event detail pages concurrently (safe since each event URL is independent).
        - Keep the `array_slice(…, 0, $limit + 3)` cap — the pool limit stays the same, only the I/O model changes.
        - After the pool resolves, parse each response in a second pass (the JSON-LD extraction is CPU-bound, not I/O).
    - **Technical:** Each `fetchEvent()` call does a `SafeUrlFetcher::fetch()` — DNS resolution, TLS handshake, HTTP round-trip, response body read — holding the PHP process for the full duration. At 8 serial calls per organiser, a single organiser takes `8 × ~1–3s = 8–24s` of wall time. With `Http::pool`, all 8 run concurrently and complete in ~1–3s total. This directly shortens the refresh cron's runtime for Eventbrite connections, reducing the probability of an overlapping-run stampede (see SCALE-1).
    - **Plain English:** Right now the scraper visits each event page one at a time — like reading 8 web pages by opening the first, waiting for it to load, then opening the second. It could open all 8 tabs at once, read them as they arrive, and be done in the time the slowest single page takes.
    - **Evidence:**
        ```php
        // Fetch a few extra (some may be past / unparseable), parse each event's JSON-LD.
        $events = [];
        foreach (array_slice($eventUrls, 0, $limit + 3) as $url) {
            $event = $this->fetchEvent($url, $headers);
            if ($event) {
                $events[] = $event;
            }
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#SCALE-3** · P2 — Instagram `connect()` ties up a PHP-FPM worker for the full Apify scrape + image-mirror chain
    - **Where:** app/Services/Platforms/InstagramScraper.php:31 (`->timeout(110)`) and app/Http/Controllers/Api/Platforms/InstagramController.php:68-73 (`mirrorAll` serial loop)
    - **Affects:** Any professional connecting an Instagram account — the Apify actor runs synchronously (`run-sync-get-dataset-items`) with a 110s timeout, and the controller then mirrors up to 8 images serially (each an HTTP fetch + R2 upload). At 200 brands, if even 3–4 professionals connect Instagram simultaneously, they hold 3–4 PHP-FPM workers for 30–120s each, shrinking the pool available for all other API traffic.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Offload the Apify scrape + image mirroring to a queued job. The `connect()` endpoint should acknowledge fast (return a `202 Accepted` with a status-poll URL) and let the job do the heavy work asynchronously.
        - Keep the per-user cooldown (`APIFY_COOLDOWN_SECONDS`) so re-connects are still rate-limited.
        - In the job, parallelise the image mirrors with `Http::pool` (same pattern as SCALE-2).
    - **Technical:** `Http::withToken($token)->timeout(110)->post(...)` inside `fetchProfile()` means the controller thread blocks for up to 110 seconds on the Apify actor. After that, `mirrorAll()` loops through up to 8 images, each going through `SafeUrlFetcher::fetch()` → `Storage::disk('media')->put()` — another ~2–5s per image serially. Total wall time: `Apify latency (~5–110s) + 8 × 2–5s = 21–150s`. Under PHP-FPM's process-per-request model, this directly consumes a worker slot. At the scale target, even with the 200/day global Apify cap, a burst of concurrent connects during peak hours would degrade response times for unrelated API endpoints sharing the same FPM pool.
    - **Plain English:** When a user links their Instagram, the server makes a call to a scraping service that can take nearly two minutes, then downloads and re-uploads up to 8 photos one at a time. During that whole time, one server worker is tied up waiting — like a checkout clerk waiting for a slow customer to find their wallet while a line forms behind them. The fix is to give the user a "we're working on it" ticket and do the slow work in the back room.
    - **Evidence:**
        ```php
        // InstagramScraper::fetchProfile()
        $response = Http::withToken($token)
            ->timeout(110)
            ->post(
                'https://api.apify.com/v2/acts/'.self::ACTOR.'/run-sync-get-dataset-items',
                ['usernames' => [$username], 'resultsLimit' => self::RESULTS_LIMIT],
            );
        ```
        ```php
        // InstagramController::connect()
        $profile = $this->scraper->fetchProfile($username);
        // ...
        $coverUrls = $this->scraper->recentCoverImages($profile, self::AUTO_IMAGE_COUNT);
        $images = $this->mirrorAll($coverUrls, $folder);
        ```
        ```php
        // InstagramController::mirrorAll() — serial foreach
        private function mirrorAll(array $urls, string $folder): array
        {
            $out = [];
            foreach (array_values($urls) as $i => $url) {
                $mirrored = $this->mirror($url, "{$folder}/img-{$i}.jpg");
                if ($mirrored) {
                    $out[] = $mirrored;
                }
            }
            return $out;
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#SCALE-4** · P2 — All infrastructure jobs land on the `default` queue — no isolation from user-facing work
    - **Where:** app/Jobs/Cache/AggregateCacheMetricsJob.php:19, app/Jobs/Cache/WarmPublicSiteCacheJob.php:44, app/Jobs/Cloudflare/CloudflareCachePurgeJob.php:40, app/Jobs/Cloudflare/RetireSubdomainFromKvJob.php:20, app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:30
    - **Affects:** Queue throughput at the scale target (~10K daily payout jobs, ~40K daily notifications, ~3K daily webhooks all landing on `default` alongside these infrastructure jobs). A burst of Cloudflare purge jobs from 200 brands updating platforms simultaneously competes with notification dispatch for worker slots.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Route `CloudflareCachePurgeJob`, `SyncSubdomainToKvJob`, and `RetireSubdomainFromKvJob` to a dedicated `cloudflare` queue with its own Horizon supervisor (1–2 workers — these are fast API calls).
        - Route `WarmPublicSiteCacheJob` to a `cache-warm` queue so payload rebuilds don't contend with user notifications.
        - Keep `AggregateCacheMetricsJob` on `default` (hourly, lightweight) or move it to `analytics`.
        - Add corresponding supervisors in `config/horizon.php` with conservative min/max process counts.
    - **Technical:** At the scale target, the `default` queue handles ~53K jobs/day (payouts + notifications + webhooks) plus every Cloudflare purge (one per platform write), KV sync, and cache-warm dispatch. A single `default` supervisor means one brand's Shopify import bursting 100 purge jobs starves notification workers briefly. Queue separation is the standard Laravel pattern for noisy-neighbour mitigation — domain queues (`mail`, `notifications`, `webhooks`) already exist in this codebase (see `SendStaffBroadcastEmailsJob` dispatching to `mail`); the Cloudflare and cache-warm jobs just haven't been moved yet.
    - **Plain English:** Right now every job — sending an email, purging a cache, warming a profile — lines up in the same single queue. It's like having one cashier for groceries, returns, and lottery tickets. When someone returns 100 items, the person buying milk has to wait. Giving each type of work its own lane means a burst in one doesn't block the others.
    - **Evidence:**
        ```php
        // AggregateCacheMetricsJob
        public function __construct()
        {
            $this->onQueue('default');
        }
        ```
        ```php
        // WarmPublicSiteCacheJob
        public function __construct(
            public string $subdomain
        ) {
            $this->onQueue('default');
        }
        ```
        ```php
        // CloudflareCachePurgeJob
        public function __construct(public readonly string $handle)
        {
            $this->onQueue('default');
        }
        ```
        ```php
        // RetireSubdomainFromKvJob
        public function __construct(public readonly string $handle)
        {
            $this->onQueue('default');
        }
        ```
        ```php
        // SyncSubdomainToKvJob
        public function __construct(public readonly string $userId)
        {
            $this->onQueue('default');
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SCALE-5** · P3 — Instagram Apify daily counter uses non-atomic read-modify-write
    - **Where:** app/Http/Controllers/Api/Platforms/InstagramController.php:144-149
    - **Affects:** The global daily Apify call cap (`APIFY_DAILY_CAP = 200`). Under concurrent re-connects (~5+ professionals hitting connect at the same second), the race window allows the actual count to overshoot the cap — two requests both read `199`, both pass the `>= 200` check, and both increment to `200` and `201` respectively.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::get` + `Cache::put` with `Cache::increment()` wrapped in a `Cache::add()` initialisation guard, or use a Redis `INCR` + `TTL` pattern via the `Redis` facade for atomicity.
        - Alternatively, accept the imprecision for the pilot (the comment already acknowledges "good enough for a pilot — backend dev to harden") and defer to the hardening pass.
    - **Technical:** The current code does `$count = Cache::get($dayKey, 0); if ($count >= self::APIFY_DAILY_CAP) { ... } Cache::put($dayKey, $count + 1, ...)`. Between the `get` and `put`, concurrent requests see the same `$count` value and both write `$count + 1` — losing one increment. Redis `INCR` is atomic by design and would close this race. The impact is bounded to at most `N_concurrent - 1` extra Apify calls beyond the cap during a burst.
    - **Plain English:** The daily Instagram budget counter works like two people both reading "199 calls used" from a sticky note, both thinking "that's under 200 so I'll add mine," and both writing "200." One of their calls was never counted. An atomic counter — like a turnstile that clicks forward no matter how fast people push through — fixes this.
    - **Evidence:**
        ```php
        $dayKey = 'platforms:instagram:apify-daily:'.now()->format('Y-m-d');
        $count = (int) Cache::get($dayKey, 0);
        if ($count >= self::APIFY_DAILY_CAP) {
            return $this->error('Instagram is busy right now — please try again later.', 429);
        }
        Cache::put($dayKey, $count + 1, now()->addDay());
        ```
    - `[DRAFT, confidence: 0.95]`
