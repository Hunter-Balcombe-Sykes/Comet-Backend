Good — two DeepSeek claims are factually wrong:
- **SCALE-1** (`integrations:refresh` lacks `withoutOverlapping`): already has `->withoutOverlapping(60)` at `routes/console.php:86`. Drop.
- **CACHE-1** (`staffAnalyticsSummary` lacks version token): the controller appends `:v{$version}` using `analyticsSummaryVersion` at line 78. Drop.

`★ Insight ─────────────────────────────────────`
DeepSeek read the `CacheKeyGenerator` method body correctly but missed the call-site where the version is appended via string concatenation *outside* the helper. This is a classic "reading the library without reading the consumer" mistake. The adjudicator role's value is exactly this: verifying findings against actual usage, not just declaration.
`─────────────────────────────────────────────────`

Now compiling the final audit with verified evidence only.

# Core Integration Feature Audit — 2026-06-06

**Branch:** development
**Lens:** Bundle 'core' audit across 8 focused themes: security/policy (SEC-*), lifecycle correctness (LIFE-*), scaling antipatterns (CACHE-*), database/queue scaling — N+1/throughput (SCALE-*), schema/RLS correctness (SCHEMA-*), caching gold-standard adherence (CCH-*), webhook idempotency & delivery (WHK-*), and transaction-boundary correctness (TXN-*)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/Platforms/AppleController.php
- app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php
- app/Http/Controllers/Api/Platforms/EventbriteController.php
- app/Http/Controllers/Api/Platforms/FacebookController.php
- app/Http/Controllers/Api/Platforms/FreshaController.php
- app/Http/Controllers/Api/Platforms/InstagramController.php
- app/Http/Controllers/Api/Platforms/ShopifyController.php
- app/Http/Controllers/Api/Platforms/TiktokController.php
- app/Http/Controllers/Api/Platforms/YoutubeController.php
- app/Http/Controllers/Api/PublicSite/PublicConfigController.php
- app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php
- app/Models/Core/Site/IntegrationConnection.php
- app/Observers/Core/IntegrationConnectionObserver.php
- app/Policies/IntegrationConnectionPolicy.php
- app/Console/Commands/RefreshIntegrationConnectionsCommand.php
- app/Services/Platforms/AppleSearch.php
- app/Services/Platforms/EventbriteScraper.php
- app/Services/Platforms/InstagramScraper.php
- app/Services/Platforms/PlatformRefresher.php
- app/Services/Platforms/ShopifyScraper.php
- app/Services/Platforms/YoutubeScraper.php
- app/Services/Platforms/YoutubeThumbnailResolver.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/CacheLockService.php
- supabase/migrations/20260602150238_create_platform_connections.sql
- routes/api/integrations.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 14 complete
- P3 Low: 0 of 7 complete

---

## P2 — Should fix

- [ ] **#SCHEMA-1** · P2 — Missing RLS on `site.platform_connections`
    - **Where:** supabase/migrations/20260602150238_create_platform_connections.sql (entire table)
    - **Affects:** Defense-in-depth isolation between users. If any future raw-query path, admin tool, or Supabase Studio query bypasses the application's policy layer, all rows are visible without tenant scoping.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a new migration: `ALTER TABLE site.platform_connections ENABLE ROW LEVEL SECURITY;`
        - Add an RLS policy permitting `app_backend` to access rows where `user_id = current_setting('app.actor_id')::uuid`, mirroring the pattern on other `site.*` tenant tables.
    - **Technical:** All `site.*` tables containing user-scoped data carry RLS as a second lock behind the application's Policy layer. The `site.platform_connections` migration was reconstructed from the live dev DB and lacks the `ENABLE ROW LEVEL SECURITY` and accompanying policy statements. The application's `IntegrationConnectionPolicy` (confirmed registered in `AppServiceProvider::boot()`) enforces authorization for every authenticated path, so the current risk is low. RLS closes the gap for Supabase Studio queries, future data-migration scripts, and any route that bypasses Laravel policies.
    - **Plain English:** Your app's permission checks are like a keypad on the office door. RLS is the deadbolt behind it. Right now the platform integrations table has a keypad but no deadbolt — if someone ever props the keypad door open (a script, a dashboard query, a future bug), there's nothing stopping them from seeing everyone's store connections. Adding RLS is a one-line SQL change that installs the deadbolt.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS site.platform_connections (
            id                    uuid PRIMARY KEY,
            user_id               uuid NOT NULL REFERENCES core.users (id) ON DELETE CASCADE,
            platform              text NOT NULL CHECK (platform IN (...)),
            ...
        );
        -- No ALTER TABLE ... ENABLE ROW LEVEL SECURITY follows
        ```

- [ ] **#CCH-1** · P2 — `ShopifyController::brandProducts` writes catalog cache with an unjittered `DateTimeInterface` TTL
    - **Where:** app/Http/Controllers/Api/Platforms/ShopifyController.php:269
    - **Affects:** Dashboard users opening the Shopify product picker — if multiple users open the same brand's picker concurrently just as the 10-minute TTL expires, every one of them independently scrapes `/products.json` from the same Shopify store.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `now()->addMinutes(self::CATALOG_TTL_MINUTES)` with a jittered integer TTL: `JitteredTtl::applyJitter(self::CATALOG_TTL_MINUTES * 60)`.
        - Optionally wrap the scrape in `CacheLockService::rememberLocked` to coalesce concurrent cold misses to one scrape per brand.
    - **Technical:** `Cache::put($this->catalogKey($id), $products, now()->addMinutes(self::CATALOG_TTL_MINUTES))` produces a `DateTimeInterface` deadline — identical across all processes writing the same key in the same second. On the 10-minute boundary every concurrent picker load triggers a fresh `ShopifyScraper::fetchProducts()` call (an HTTP scrape of `/products.json`). `JitteredTtl::applyJitter()` is already available via `app/Services/Cache/Concerns/JitteredTtl.php` and resolves this with a one-line change. The gold-standard `CacheLockService::rememberLocked` adds a single-flight lock so only one scrape runs per cold miss.
    - **Plain English:** The product catalog cache expires at exactly the same second for everyone viewing the same store. If two people open the picker at that exact moment, both find an empty cache and both phone the Shopify store for the product list at the same time. Adding a small random wobble (9–11 minutes instead of exactly 10) means they almost never collide. At 30 stores with a handful of users this is a paper cut; at 200 it starts to sting.
    - **Evidence:**
        ```php
        private const CATALOG_TTL_MINUTES = 10;
        // ...
        Cache::put($this->catalogKey($id), $products, now()->addMinutes(self::CATALOG_TTL_MINUTES));
        ```

- [ ] **#SCALE-3** · P2 — All infrastructure jobs land on the `default` queue — no isolation from user-facing work
    - **Where:** app/Jobs/Cache/AggregateCacheMetricsJob.php:19, app/Jobs/Cache/WarmPublicSiteCacheJob.php:44, app/Jobs/Cloudflare/CloudflareCachePurgeJob.php:40, app/Jobs/Cloudflare/RetireSubdomainFromKvJob.php:20, app/Jobs/Cloudflare/SyncSubdomainToKvJob.php:30
    - **Affects:** Queue throughput at scale — a burst of Cloudflare purge jobs (e.g. 200 platform connection writes in one dashboard session) competes with notification dispatch for worker slots on the `default` queue.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Route `CloudflareCachePurgeJob`, `SyncSubdomainToKvJob`, and `RetireSubdomainFromKvJob` to a dedicated `cloudflare` queue with 1–2 Horizon workers.
        - Route `WarmPublicSiteCacheJob` to a `cache-warm` queue so payload rebuilds do not contend with mail/notification jobs.
        - Keep `AggregateCacheMetricsJob` on `default` (hourly, lightweight) or move it to `analytics`.
        - Add corresponding supervisors to `config/horizon.php`. Note: `WarmPublicSiteCacheJob` was intentionally moved to `default` (from `cache`) to avoid silent drops from unconfigured workers; new dedicated queues require matching Horizon supervisors before deploying.
    - **Technical:** Domain queues (`mail`, `notifications`, `webhooks`) already exist in this codebase (e.g. `SendStaffBroadcastEmailsJob` dispatches to `mail`). The Cloudflare and cache-warm jobs were not separated because `cache` was previously unconfigured — that concern is addressed by adding proper supervisors alongside the queue change. One platform-connection edit dispatches at minimum one `CloudflareCachePurgeJob`; a user connecting all 5 Shopify brands dispatches 5. Without isolation, this burst briefly starves notification workers.
    - **Plain English:** Right now every background task — sending emails, purging a Cloudflare cache, warming a profile page, syncing a routing table — waits in the same single lane. When someone connects five Shopify stores at once, five cache-purge tasks jump the queue and the email lane slows down. Giving each type of work its own dedicated lane takes an afternoon and means one person's flurry of store edits never slows everyone else's notifications.
    - **Evidence:**
        ```php
        // CloudflareCachePurgeJob
        public function __construct(public readonly string $handle)
        {
            $this->onQueue('default');
        }
        // WarmPublicSiteCacheJob
        public function __construct(public string $subdomain) {
            $this->onQueue('default');
        }
        // SyncSubdomainToKvJob
        public function __construct(public readonly string $userId)
        {
            $this->onQueue('default');
        }
        ```

- [ ] **#SCALE-1** · P2 — `EventbriteScraper::fetchEvents` fetches event detail pages serially
    - **Where:** app/Services/Platforms/EventbriteScraper.php:55–59
    - **Affects:** Daily `integrations:refresh` cron and every user who connects an Eventbrite organiser — up to 8 event detail pages are fetched one after another, each requiring a full HTTP round-trip.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the `foreach` with `Http::pool(fn (Pool $pool) => ...)` to fetch all event detail pages concurrently. Each event URL is independent so the pool is safe.
        - Parse each pool response in a second pass (JSON-LD extraction is CPU-bound, not I/O-bound).
    - **Technical:** Each `fetchEvent()` call inside the loop invokes `SafeUrlFetcher::fetch()` — DNS resolution, TLS handshake, full HTTP round-trip. At 8 serial events × ~1–3s RTT each, one organiser refresh takes 8–24s of wall time. `Http::pool` issues all 8 requests concurrently, completing in the time of the single slowest response (~1–3s). At 100 Eventbrite-connected users in the daily refresh cron, serial fetching extends cron runtime by up to 40 minutes; parallel fetching reduces it to the time of the slowest individual organiser.
    - **Plain English:** Right now the scraper visits each event page one at a time — like loading 8 web pages one after another before closing the browser. It could open all 8 tabs at once and read them as they finish. The fix rewrites the loop to use Laravel's built-in parallel HTTP client, cutting one organiser's refresh time from up to 24 seconds down to under 3.
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

- [ ] **#SCALE-2** · P2 — `InstagramController::connect` ties up a PHP-FPM worker for the full Apify scrape + serial image mirror chain
    - **Where:** app/Services/Platforms/InstagramScraper.php:31 (110s timeout), app/Http/Controllers/Api/Platforms/InstagramController.php:68–73 (`mirrorAll` loop)
    - **Affects:** All users of the PHP-FPM worker pool — connecting Instagram synchronously blocks a worker for up to 110s (Apify) + ~40s (8 serial image mirrors), shrinking the pool available for all other API traffic.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Move the Apify scrape + image mirroring to a queued job dispatched by `connect()`. Return `202 Accepted` with a status-poll URL immediately.
        - In the job, parallelise image mirrors with `Http::pool` (same pattern as SCALE-1 fix).
        - Keep the per-user cooldown (`Cache::add`) in the controller so rapid re-connects are still throttled before the job is dispatched.
    - **Technical:** `Http::withToken($token)->timeout(110)->post(...)` blocks the PHP-FPM worker thread for the full Apify actor runtime (5–110s). After that, `mirrorAll()` iterates up to 8 Instagram CDN images serially via `SafeUrlFetcher::fetch()` → `Storage::disk('media')->put()` (~2–5s each). Total wall time: 21–150s per connect. Under PHP-FPM's process-per-request model, 3–4 concurrent Instagram connects saturate a typical small worker pool, degrading response times for all other authenticated API traffic.
    - **Plain English:** When a user links their Instagram account, the server makes a call to an external scraping service that can take up to two minutes, then downloads and re-uploads up to eight photos one by one. During all of that time, one server worker is completely tied up waiting — like a cashier frozen mid-transaction while a queue forms. The fix is to hand the user a "we'll have this ready shortly" ticket and do the slow work in the background, freeing the cashier immediately.
    - **Evidence:**
        ```php
        // InstagramScraper::fetchProfile() — blocks the worker thread
        $response = Http::withToken($token)
            ->timeout(110)
            ->post(
                'https://api.apify.com/v2/acts/'.self::ACTOR.'/run-sync-get-dataset-items',
                ['usernames' => [$username], 'resultsLimit' => self::RESULTS_LIMIT],
            );
        ```
        ```php
        // InstagramController::mirrorAll() — serial loop after the 110s scrape
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

- [ ] **#LIFE-4** · P2 — `PlatformRefresher` never populates `last_refresh_error` — vendor failure reason is silently discarded
    - **Where:** app/Services/Platforms/PlatformRefresher.php (all four private `*Payload` methods and `refresh()`)
    - **Affects:** Operators debugging why a user's YouTube/Eventbrite/Apple tile stopped updating — the `last_refresh_error` column exists on `site.platform_connections` for exactly this purpose but is always NULL.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Have each `*Payload` method return a typed result (`['payload' => array, 'error' => null]` on success, `['payload' => null, 'error' => 'reason string']` on failure) instead of a bare `?array`.
        - In `refresh()`, write the failure reason to `last_refresh_error` when `$next === null`.
        - Scrapers that already propagate an exception or descriptive return should surface that string in the typed result.
    - **Technical:** `PlatformRefresher::refresh()` sets `last_refresh_status = 'unavailable'` and increments `consecutive_failures` when `$next === null`, but never writes `last_refresh_error`. All four private payload methods return `null` on any failure (no feed URL, empty results, network error, parse error), discarding the failure reason. The schema column was purpose-built for forensic debugging, mirroring the `last_refresh_error` pattern used on `SmartLink`. At 200+ connections refreshed daily, silent `unavailable` status without a reason makes production debugging a log-trawl.
    - **Plain English:** When the daily refresh fails to fetch a user's YouTube videos, the system records "failed" on the platform tile without writing down why — out of date playlist? YouTube throttle? No internet? The "reason" column in the database exists for exactly this note, but nobody fills it in. Adding a one-line write to that column means when you're debugging why a user's content isn't updating, you can look it up instead of tracing through logs.
    - **Evidence:**
        ```php
        if ($next === null) {
            $connection->forceFill([
                'last_refresh_status' => 'unavailable',
                'consecutive_failures' => (int) $connection->consecutive_failures + 1,
                // last_refresh_error never written
            ])->saveQuietly();
            return $connection;
        }
        ```
        ```sql
        -- Column exists in migration, always NULL:
        last_refresh_error    text,
        ```

- [ ] **#LIFE-2** · P2 — `FreshaController::fetchEmployeeServices` swallows all failures silently — hash rotation is invisible to ops
    - **Where:** app/Http/Controllers/Api/Platforms/FreshaController.php (`fetchEmployeeServices` method, all three null-return paths)
    - **Affects:** All users with a Fresha per-employee service menu. When Fresha redeploys and rotates their persisted-query hash, every per-employee service fetch silently returns null, the dashboard falls back to the whole-location menu, and neither the user nor ops knows the feature has degraded.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Log::warning('fresha.employee_services.failed', ['slug' => $slug, 'employee_id' => $employeeId, 'error' => $e->getMessage()])` in the `catch (Throwable)` block.
        - Add a similar log on the `! $response->ok()` branch with `'status' => $response->status()`.
        - Optionally write `last_refresh_error` on the connection row after N consecutive per-user failures so the dashboard can surface "Fresha needs reconnection."
    - **Technical:** The method has three silent null-return paths: a network exception (`catch (Throwable) { return null; }`), a non-2xx response, and a missing/malformed categories field. None produce a log entry. The class comment acknowledges that `BOOKING_INIT_HASH` and `FRESHA_CLIENT_VERSION` "rotate when they redeploy" — making silent failure a documented inevitability. The connection row has `last_refresh_error` and `consecutive_failures` columns unused in this path, providing purpose-built failure signal infrastructure that this code never writes.
    - **Plain English:** When Fresha updates their app (which happens every couple of weeks), the code that fetches an individual stylist's services quietly breaks and falls back to showing all services instead. Nobody gets an error message — the dashboard just silently shows less accurate information. Adding one log line means ops can see "Fresha hash rotation" in the monitoring dashboard and push a config update before users notice.
    - **Evidence:**
        ```php
        try {
            $response = Http::withHeaders([
                'content-type' => 'application/json',
                'x-client-version' => self::FRESHA_CLIENT_VERSION,
                // ...
            ])->timeout(12)->post(self::GRAPHQL_URL, $payload);
        } catch (Throwable) {
            return null;  // ← no log, no error recorded
        }

        if (! $response->ok()) {
            return null;  // ← no log
        }
        ```

- [ ] **#LIFE-5** · P2 — `InstagramScraper` log entries lack user/connection correlation context
    - **Where:** app/Services/Platforms/InstagramScraper.php (all three `Log::warning` calls in `fetchProfile`)
    - **Affects:** Nightwatch incident correlation — when Apify scrapes fail, every log line looks identical across all 200 users. A spike in failures cannot be attributed to a specific user, connection, or request.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `user_id` and `platform_connection_id` as parameters to `fetchProfile(string $username, string $userId, string $connectionId)` (or as a context array).
        - Include `user_id` and `platform_connection_id` in all three `Log::warning` calls.
        - Remove the `'body'` field from the `not_ok` log (see SEC-4 — this also addresses the PII concern).
    - **Technical:** The three `Log::warning` calls include `username` and `error`/`status`/`body` but no tenant identifier. Nightwatch groups log entries by message signature + context keys — without a stable `user_id`, every `instagram.apify.not_ok` entry across 200 users is indistinguishable. The scraper is called by `InstagramController::connect()` which already holds `$user->id`, so threading the ID down is a one-line caller change and a parameter addition.
    - **Plain English:** When the Instagram scraper has a bad day and fails, the error log records "failed for username @dancer" but doesn't say which of your 200 users owns that Instagram account. If you're trying to investigate why a specific user's images didn't load, you have to cross-reference usernames against your user database manually. Adding the user's account ID to the log entry takes five minutes and makes the error immediately actionable.
    - **Evidence:**
        ```php
        Log::warning('instagram.apify.threw', ['username' => $username, 'error' => $e->getMessage()]);
        Log::warning('instagram.apify.not_ok', [
            'username' => $username,
            'status' => $response->status(),
            'body' => mb_substr($response->body(), 0, 800), // also a PII risk — see SEC-4
        ]);
        Log::warning('instagram.apify.bad_items', [
            'username' => $username,
            'type' => gettype($items),
            'count' => is_array($items) ? count($items) : 0,
        ]);
        ```

- [ ] **#LIFE-3** · P2 — `ShopifyController::addBrand` has a read-modify-write race — concurrent adds can produce a lost update
    - **Where:** app/Http/Controllers/Api/Platforms/ShopifyController.php (`addBrand` method)
    - **Affects:** Users who submit concurrent addBrand requests (two browser tabs, a retry). At worst, one brand addition is silently overwritten by the other; at the cap boundary, both requests may slip through the `count($map) >= 5` check.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap the read-check-write sequence in `DB::transaction` with `lockForUpdate` on the user's Shopify `IntegrationConnection` row: `IntegrationConnection::where(...)->lockForUpdate()->first()`.
        - Because `writeConnection` uses `updateOrCreate`, the lock must be acquired before the `brandMap()` read to prevent another request from writing between the read and the upsert.
    - **Technical:** Shopify stores all brands as a JSONB map in one row per user (`resource_id = 'shopify'`). `addBrand` reads the map, checks the count, appends the new brand, then calls `writeConnection` which does `updateOrCreate` — a blind overwrite of the JSONB payload. Two concurrent requests both read a 4-brand map, both pass `count($map) >= 5`, and both write their own 5-brand version. The second write overwrites the first, silently losing one brand addition. At `MAX_BRANDS = 5`, two requests at count-4 can also both slip the cap check and produce a 6-brand payload.
    - **Plain English:** Imagine two assistants are both updating the same client file at the same time. The first reads "4 brands," adds a new one, and saves "5 brands." The second also read "4 brands" a moment earlier, adds a different new one, and saves "5 brands" — but their save wipes out the first assistant's work. The fix is a "do not disturb" sign on the file: only one person can edit it at a time.
    - **Evidence:**
        ```php
        $map = $this->brandMap($user);
        $id = $brand['id'];
        if (! isset($map[$id]) && count($map) >= self::MAX_BRANDS) {
            return $this->error('You can connect up to '.self::MAX_BRANDS.' brands.', 422);
        }
        // ... append to $map ...
        $this->writeConnection($user, $map);  // ← blind JSONB overwrite, no lock
        ```

- [ ] **#LIFE-1** · P2 — Instagram Apify daily budget counter has a read-modify-write race
    - **Where:** app/Http/Controllers/Api/Platforms/InstagramController.php (`guardApifyBudget` method)
    - **Affects:** Cost control — the `APIFY_DAILY_CAP = 200` limit can be exceeded by the number of concurrent connect requests at the moment of cap-boundary crossing. The code comment acknowledges this as "good enough for a pilot — backend dev to harden."
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::get` + `Cache::put` with atomic `Cache::increment`. Use `Cache::add($dayKey, 0, now()->addDay())` to initialise with a TTL on first use, then `Cache::increment($dayKey)` and compare the returned value against `APIFY_DAILY_CAP`.
        - Remove the `now()->addDay()` `DateTimeInterface` TTL (which also lacks jitter — see CCH-3).
    - **Technical:** `$count = (int) Cache::get($dayKey, 0); if ($count >= self::APIFY_DAILY_CAP) {...} Cache::put($dayKey, $count + 1, now()->addDay())` is a classic check-then-act race. Two concurrent requests both read 199, both pass the `>= 200` guard, both write 200 — one Apify call beyond the cap slips through and the counter undercounts by 1. Redis `INCR` (Laravel's `Cache::increment`) is atomic and closes this race with a two-line change. At 200 brands with a daily scrape cadence, the concurrent window is narrow but real during business hours.
    - **Plain English:** The daily Instagram budget uses a "read, check, then write" counter — like two cashiers looking at the same tally and both deciding there's room for one more customer before either updates the total. Using Redis's built-in atomic increment is like replacing two cashiers with a turnstile that counts each person as they walk through, with no chance of two people being counted as one.
    - **Evidence:**
        ```php
        $dayKey = 'platforms:instagram:apify-daily:'.now()->format('Y-m-d');
        $count = (int) Cache::get($dayKey, 0);
        if ($count >= self::APIFY_DAILY_CAP) {
            return $this->error('Instagram is busy right now — please try again later.', 429);
        }
        Cache::put($dayKey, $count + 1, now()->addDay());
        // Code comment: "good enough for a pilot — backend dev to harden"
        ```

- [ ] **#SEC-4** · P2 — `InstagramScraper` logs the raw Apify response body, which may contain Instagram PII
    - **Where:** app/Services/Platforms/InstagramScraper.php:40–44
    - **Affects:** Log aggregator (Nightwatch) — up to 800 characters of the Apify error response body are persisted per non-2xx response. Apify error payloads can echo back the Instagram profile data that was requested (full name, bio, post captions).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `'body' => mb_substr($response->body(), 0, 800)` from the `not_ok` log context entirely.
        - Log only `'status' => $response->status()` for the `not_ok` branch. If structured error detail is needed for debugging, extract only `$response->json('error')` or similar non-PII field — and gate behind `config('app.debug')` if the shape is uncertain.
    - **Technical:** The `not_ok` log branch fires on any non-2xx Apify response. Apify's instagram-profile-scraper returns profile data even on partial failures. The 800-byte truncation includes enough of a profile payload to capture a full name, biography excerpt, or post caption. Log aggregators like Nightwatch retain entries indefinitely; GDPR Article 5(1)(c) data-minimisation principle applies to log storage. The `threw` and `bad_items` branches are safe — they log only exception messages and type metadata. Only `not_ok` over-logs.
    - **Plain English:** When the Instagram scraper gets an error back from the scraping service, it copies a piece of the error message into the permanent error log. That snippet can include bits of an Instagram user's profile — their real name, a line of their bio, or a post caption. We should only record the error code (e.g. "HTTP 429"), not repeat any personal details. Removing one line fixes this.
    - **Evidence:**
        ```php
        Log::warning('instagram.apify.not_ok', [
            'username' => $username,
            'status' => $response->status(),
            'body' => mb_substr($response->body(), 0, 800),  // ← potential PII
        ]);
        ```

- [ ] **#SEC-1** · P2 — Google Maps API key served at a public, CDN-cached, unauthenticated endpoint
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicConfigController.php:60–66
    - **Affects:** Any visitor or crawler — the key is returned at `GET /api/public/config/integrations` with `Cache-Control: public, max-age=3600`. If the key lacks HTTP-referrer restrictions in Google Cloud Console (or those restrictions are misconfigured), it can be used to exhaust quota from any origin.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add an automated check (CI step or a Nightwatch alert) that verifies the Google Maps key in each environment has referrer restrictions configured in Google Cloud Console — the code's own docblock makes this a contractual requirement.
        - Add a comment in `.env.example` explicitly documenting the required GCP restriction: `# GOOGLE_MAPS_API_KEY — must have HTTP referrer restriction to *.partna.au/* in GCP`.
        - Consider serving the key only on authenticated dashboard config endpoints so the CDN caching surface is removed entirely.
    - **Technical:** `PublicConfigController::integrations()` returns `config('services.google_maps.api_key')` with `Cache-Control: public, max-age=3600`. The docblock states "Each key here must be HTTP-referrer-restricted … so exposing it publicly is safe." This design is intentional and can be safe — but the safety guarantee lives entirely in a GCP console setting that is not enforced, tested, or documented in the repository. A deploy to a fresh environment (or a key rotation that loses the referrer restriction) would expose the key with no code-level indication of the risk.
    - **Plain English:** Your Google Maps key is intentionally printed on a public page with a note saying "it's fine because Google only accepts it from our website." That's true — as long as someone remembered to configure Google's side correctly. But that configuration lives in Google's cloud console, not in your code, and nothing checks it's still in place. The fix is to add that reminder somewhere developers can't miss it: a comment in the environment file and a note in CI.
    - **Evidence:**
        ```php
        public function integrations(): JsonResponse
        {
            return response()
                ->json([
                    'googleMapsApiKey' => config('services.google_maps.api_key'),
                ])
                ->header('Cache-Control', 'public, max-age=3600');
        }
        ```

- [ ] **#SEC-3** · P2 — Platform controllers bypass `IntegrationConnectionPolicy` — authorization is distributed across query scoping instead of centralized in the Policy gate
    - **Where:** app/Http/Controllers/Api/Platforms/Concerns/ManagesIntegrationConnection.php (entire trait) and all eight platform controllers that use it
    - **Affects:** Authorization auditability and future safety — cross-user isolation currently relies on relationship scoping in the trait, but the registered `IntegrationConnectionPolicy` is never called, making it untestable in isolation and ineffective against any future code path that bypasses the scoped query.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In `ManagesIntegrationConnection::connectionFor()`, after the model is resolved, add `app(Gate::class)->forUser($user)->authorize('view', $connection)` (or `throw_unless` with `authorizeForUser`).
        - In `writeConnection()`, call `authorizeForUser($user, 'update', $connection)` for existing rows and `authorizeForUser($user, 'create', $skeleton)` for new rows using the skeleton pattern from the Partna doctrine.
        - All eight platform controllers inherit the trait, so adding the gate calls there fixes coverage in one place.
    - **Technical:** `IntegrationConnectionPolicy` is confirmed registered in `AppServiceProvider::boot()` (`Gate::policy(IntegrationConnection::class, IntegrationConnectionPolicy::class)`). It has correct `ownerMatches`, `denyAsNotFound`, and `denyIfPendingDeletion` logic. However, no platform controller or the shared trait ever calls `authorizeForUser`. Tenant isolation is currently achieved by scoping every query through `$user->integrationConnections()`. This works for the eight existing controllers, but violates the single-pattern doctrine: a future controller that fetches an `IntegrationConnection` by UUID directly (e.g., an admin tool, a background job, or a new route) would have no Policy gate to stop cross-user access.
    - **Plain English:** You've installed a proper lock on the front door (the Policy class) but every room in the house also has a hidden keypad that controls access separately. Both currently work, but if anyone builds a new room and forgets the keypad, there's no lock to fall back on. Wiring all room access through the front door lock means a future mistake can't silently bypass security.
    - **Evidence:**
        ```php
        // ManagesIntegrationConnection — scopes to user but never authorizes via Policy:
        protected function connectionFor(User $user, ?string $resourceId = null): ?IntegrationConnection
        {
            return $user->integrationConnections()
                ->where('platform', $this->platform())
                ->where('resource_id', $resourceId ?? $this->defaultResourceId())
                ->first();
        }

        // IntegrationConnectionPolicy — registered in AppServiceProvider, never called:
        public function view(User $actor, Model $resource): bool|Response
        {
            return $this->ownerMatches($actor, $resource)
                ? true
                : $this->denyAsNotFound();
        }
        ```

- [ ] **#SEC-2** · P2 — `PublicIntegrationController` returns raw `payload` JSONB without a Resource class allowlist
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicIntegrationController.php:55–62
    - **Affects:** Every public sitepage visitor — the full `payload` JSONB blob is served verbatim to unauthenticated visitors with no server-side field filtering. Any field added to `payload` by a developer (internal reference IDs, staff names, cost prices) would silently become public.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create a `PublicIntegrationConnectionResource` class that explicitly allowlists the fields the Astro sitepage renders per platform (e.g., for Shopify: `name`, `products`, `discountCode` but not internal IDs or cost fields).
        - Apply the Resource in `PublicIntegrationController::show()` instead of the inline array mapping.
        - Document in the Resource which payload fields are public vs. dashboard-only so future payload additions are a conscious decision.
    - **Technical:** The canonical Partna pattern requires Resource classes on all API responses — never raw Eloquent models or raw array mapping. `PublicIntegrationController` maps each row to `['resourceId' => ..., 'payload' => $r->payload, ...]` and returns the unfiltered result. The `payload` column is a free-form JSONB blob written by eight different platform controllers. Currently the payload contains only public-facing data, but there is no code-level contract preventing a developer from adding a sensitive field. A Resource class provides a centralized allowlist that makes future leaks fail review rather than fail silently in production.
    - **Plain English:** Imagine handing a restaurant guest the kitchen's entire inventory database instead of just the menu. Right now everything in the database is food — but if a chef ever adds the cleaning supply inventory to the same system, the guest gets that too. A Resource class is the menu: it says "only these items are for customers," and anything new that gets added to the kitchen stays in the kitchen until it's explicitly put on the menu.
    - **Evidence:**
        ```php
        ->map(fn ($rows) => $rows->map(fn (IntegrationConnection $r) => [
            'resourceId' => $r->resource_id,
            'payload' => $r->payload,   // ← full JSONB, no allowlist
            'lastRefreshedAt' => $r->last_refreshed_at?->toIso8601String(),
        ])->values())
        ->toArray();
        ```

## P3 — Nice to have

- [ ] **#SCHEMA-2** · P3 — UUID primary key on `site.platform_connections` has no database-side default
    - **Where:** supabase/migrations/20260602150238_create_platform_connections.sql:1
    - **Affects:** Raw SQL inserts and admin data-migration scripts that bypass Eloquent — if `HasUuids` is not active (e.g. a raw `DB::statement`, a future data backfill, or a Supabase SQL editor insert), a NULL primary key is accepted by the DB.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a follow-up migration: `ALTER TABLE site.platform_connections ALTER COLUMN id SET DEFAULT gen_random_uuid();`
        - Audit other recent migrations for the same gap.
    - **Technical:** The `HasUuids` trait generates UUIDs in PHP before writing — this works in all current Eloquent paths. A DB-side `DEFAULT gen_random_uuid()` is a safety net for raw inserts. The migration was reconstructed verbatim from the live dev DB, so if the live DB lacks the default, it should be added via a follow-up migration rather than modifying the reconstructed migration file.
    - **Plain English:** The app always fills in the ID column automatically before saving, which works fine. But if someone runs a raw database command and forgets to include an ID, the database would accept a blank one. Adding a database-level default means the database generates one automatically as a backup, the same way a form can have a default value pre-filled even if the user doesn't type anything.
    - **Evidence:**
        ```sql
        CREATE TABLE IF NOT EXISTS site.platform_connections (
            id                    uuid PRIMARY KEY,
            -- No DEFAULT gen_random_uuid() clause
        ```

- [ ] **#CCH-4** · P3 — `YoutubeThumbnailResolver` writes verdict cache with unjittered `DateTimeInterface` TTL
    - **Where:** app/Services/Platforms/YoutubeThumbnailResolver.php:115
    - **Affects:** YouTube thumbnail verdict cache — all videos connected in the same batch refresh expire at the same wall-clock second 30 days later, causing a synchronized wave of HEAD probes to `i.ytimg.com`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `now()->addDays(self::CACHE_DAYS)` with `JitteredTtl::applyJitter(self::CACHE_DAYS * 86400)` so the 30-day window spreads ±20% (24–36 days).
    - **Technical:** `Cache::put($this->cacheKey($id), ..., now()->addDays(self::CACHE_DAYS))` creates a `DateTimeInterface` deadline that passes through `JitteredTtl::applyJitter` unmodified (the helper only acts on int TTLs). Videos connected during the same batch expiry all expire together 30 days later. The probe is a fast batched HEAD via `Http::pool` (3s timeout), so the blast radius is small; this is a hygiene fix rather than an urgent one.
    - **Plain English:** Every thumbnail verdict expires exactly 30 days after it was saved — so if the system caches 15 videos all at once, exactly 30 days later they all expire at the same second and trigger 15 simultaneous quick checks with YouTube's image server. Adding a small random variation (±6 days) spreads these checks over a week instead of a spike.
    - **Evidence:**
        ```php
        Cache::put($this->cacheKey($id), $hasMaxres ? 'maxres' : 'hq', now()->addDays(self::CACHE_DAYS));
        ```

- [ ] **#CCH-3** · P3 — `InstagramController::guardApifyBudget` TTLs are not jittered
    - **Where:** app/Http/Controllers/Api/Platforms/InstagramController.php:297, 304
    - **Affects:** The Apify cost-guard caches — both the per-user cooldown (600s int TTL) and the daily counter (`now()->addDay()`) expire at synchronized wall-clock offsets across all users and workers.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Cooldown: `Cache::add($cooldownKey, 1, JitteredTtl::applyJitter(self::APIFY_COOLDOWN_SECONDS))`.
        - Daily counter: replace `now()->addDay()` with `JitteredTtl::applyJitter(86400)` (integer seconds, jitter-eligible).
        - This also resolves the non-atomic counter concern (LIFE-1) if combined with the `Cache::increment` fix.
    - **Technical:** `Cache::add($cooldownKey, 1, 600)` passes a literal int — eligible for jitter but not jittered. `now()->addDay()` is a `DateTimeInterface` that bypasses the jitter helper entirely. Both TTL types should use the `JitteredTtl::applyJitter()` helper per the gold standard. At pilot scale (< 200 concurrent connects) the collision probability is negligible, but it's a two-line change.
    - **Plain English:** Every user's 10-minute cooldown timer and the global daily limit all reset at the same clock moment. This is harmless at small scale but inconsistent with how all other caches in the app are configured. Two lines of code fixes it.
    - **Evidence:**
        ```php
        if (! Cache::add($cooldownKey, 1, self::APIFY_COOLDOWN_SECONDS)) { // ← int, not jittered
            return $this->error('You refreshed Instagram recently...', 429);
        }
        // ...
        Cache::put($dayKey, $count + 1, now()->addDay()); // ← DateTimeInterface, bypasses jitter
        ```

- [ ] **#CCH-2** · P3 — Ad-hoc cache key construction in three places bypasses `CacheKeyGenerator`
    - **Where:** app/Http/Controllers/Api/Platforms/ShopifyController.php:279–281; app/Services/Platforms/YoutubeThumbnailResolver.php:120–122; app/Http/Controllers/Api/Platforms/InstagramController.php:296, 299
    - **Affects:** Cache key discoverability and future invalidation sweeps — these keys are not registered in `CacheKeyGenerator` and cannot be found by tools that enumerate cache key prefixes.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add to `CacheKeyGenerator`: `shopifyBrandCatalog(string $id)`, `youtubeThumbnailVerdict(string $videoId)`, `instagramCooldown(string $userId)`, `instagramDailyLimit(string $date)`.
        - Replace inline constructions in all three files with the centralized helpers.
    - **Technical:** The gold standard requires all cache keys to originate from `CacheKeyGenerator`. All three constructions work correctly in isolation — reader and writer share the same method or file — but the prefixes `platforms.shopify.brands`, `yt_thumb:`, and `platforms:instagram:` are invisible to the centralized registry. Future cache inspections, prefix-based invalidation sweeps, or key-usage audits require grepping controller code rather than reading one file.
    - **Plain English:** Three sets of cache keys are like house keys cut by a tenant without telling the building manager. They work fine, but the master key list has gaps. If the manager ever needs to change the lock, they won't know to replace those three keys too.
    - **Evidence:**
        ```php
        // ShopifyController
        private function catalogKey(string $id): string
        {
            return self::CATALOG_KEY.'.catalog.'.$id;
        }
        // YoutubeThumbnailResolver
        private function cacheKey(string $videoId): string
        {
            return "yt_thumb:{$videoId}";
        }
        // InstagramController
        $cooldownKey = "platforms:instagram:cooldown:{$user->id}";
        $dayKey = 'platforms:instagram:apify-daily:'.now()->format('Y-m-d');
        ```

- [ ] **#LIFE-7** · P3 — Fresha persisted-query hash and client version are hardcoded class constants — rotation requires a full code deploy
    - **Where:** app/Http/Controllers/Api/Platforms/FreshaController.php (class constants `BOOKING_INIT_HASH`, `FRESHA_CLIENT_VERSION`)
    - **Affects:** Operational agility when Fresha redeploys their frontend — the only recovery path is a code deploy rather than a config push.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move both constants to `config/services.php` under a `fresha` key: `'booking_init_hash' => env('FRESHA_BOOKING_INIT_HASH', '4ea9...')`, `'client_version' => env('FRESHA_CLIENT_VERSION', 'd135...')`.
        - Reference via `config('services.fresha.booking_init_hash')` in the method body.
        - Document the expected rotation cadence (every 1–2 weeks) in `.env.example`.
    - **Technical:** The code comment explicitly states these values "rotate when they redeploy." Moving them to config means ops can hotfix by updating an env variable and running `php artisan config:cache` without triggering a full Laravel deploy. This is the same pattern as `STRIPE_API_VERSION` in the existing codebase. The constants' current values serve as config defaults so no functionality changes.
    - **Plain English:** The cheat sheet for the Fresha employee service lookup is printed directly in the codebase. When Fresha renovates, you have to republish the entire app just to update two lines. Moving them to a settings file means you can update just those two lines and restart the app in under a minute, without touching any code.
    - **Evidence:**
        ```php
        // Code comment: "rotate when they redeploy"
        private const BOOKING_INIT_HASH = '4ea9d1b31075d62f789fcec884c45d76aaeb42e56ffb1b78cc1b7f7c557ad7cb';
        private const FRESHA_CLIENT_VERSION = 'd135e4b3a3be51f9dd24f5cc2af6dd6a647f85dd';
        ```

- [ ] **#LIFE-6** · P3 — `RefreshIntegrationConnectionsCommand` aggregates ok/failed counts but cannot distinguish "refreshed with new content" from "refreshed but unchanged"
    - **Where:** app/Console/Commands/RefreshIntegrationConnectionsCommand.php (`handle` method)
    - **Affects:** Operators monitoring the daily cron — "300 ok" could mean 300 no-ops or 300 genuine content updates; there is no way to tell from the command output or logs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Compare `$connection->payload` before and after `$refresher->refresh()` (or use `$refreshed->wasChanged('payload')`) and track a separate `$updated` counter.
        - Output: `"Platform connections refreshed: {$ok} ok ({$updated} with new content), {$failed} failed."` 
    - **Technical:** `$refreshed->last_refresh_status === 'ok'` confirms the scrape succeeded but not that the payload changed. `PlatformRefresher::refresh()` calls `$connection->update(['payload' => $next, ...])` only when content changes, so `wasChanged('payload')` is a reliable signal for distinguishing no-ops from real updates. The canonical reconcile pattern (established in `ReconcileStuckTransferringPayoutsJob`) requires logging when caught-up work is found. At 300 connections refreshed daily, ops need to know whether the cron is doing useful work or just validating stale content.
    - **Plain English:** The nightly refresh reports "checked 300 connections, all OK" but doesn't say whether any of them actually had new content. It's like a security guard reporting "completed my rounds" without noting which doors were actually unlocked. Tracking how many connections got genuinely new content takes five lines of code and makes the report meaningfully different from a no-op run.
    - **Evidence:**
        ```php
        $ok = 0;
        $failed = 0;
        foreach ($connections as $connection) {
            try {
                $refreshed = $refresher->refresh($connection);
                $refreshed->last_refresh_status === 'ok' ? $ok++ : $failed++;
            } catch (\Throwable $e) {
                $failed++;
                Log::warning('integrations:refresh failed for a connection', [...]);
            }
        }
        $this->info("Platform connections refreshed: {$ok} ok, {$failed} failed ...");
        // ← no "updated with new content" counter
        ```

- [ ] **#SEC-5** · P3 — Platform controllers use inline `$request->validate()` instead of dedicated Form Request classes
    - **Where:** app/Http/Controllers/Api/Platforms/AppleController.php (all action methods), EventbriteController.php:40, FreshaController.php (connect, saveSelection, employeeServices, serviceVisibility), InstagramController.php (connect, saveSelection), ShopifyController.php (addBrand, updateBrand, setProducts), TiktokController.php:30, YoutubeController.php (connect, highlights)
    - **Affects:** Testability and consistency — validation rules for each platform operation cannot be unit-tested without booting the full HTTP stack, and 422 error shapes can drift across controllers.
    - **Effort:** M (~2–4h) across all controllers
    - **What to do:**
        - Extract validation rules into dedicated Form Request classes (e.g. `ConnectInstagramRequest`, `SaveFreshaSelectionRequest`).
        - The Form Request `authorize()` method is the canonical location for `authorizeForUser` calls (see SEC-3), so creating these classes creates a natural home for the Policy integration fix.
    - **Technical:** The Partna architecture specifies Form Request classes for all endpoints that accept user input. All seven controllers validated inline at the time of writing in "test-mode velocity." While functionally correct, scattered inline rules make it impossible to write a unit test for "does the Fresha URL regex reject non-Fresha domains?" without a full HTTP test, and 422 error shapes may diverge across platforms (e.g. `url` field validated as `'url'` in Shopify but `'regex:'` in Fresha with no enforcement of consistency). Creating Form Requests also bundles the SEC-3 fix into a reviewable, isolated class.
    - **Plain English:** Each platform controller has its own handwritten "what inputs do we accept" checklist buried in the middle of the action method. If we want to change a rule — say, increase the maximum product selection from 250 to 500 — we have to find and update the right line in the right controller. Form Request classes put each checklist in its own dedicated file, making rules easy to find, easy to test, and consistent across the whole dashboard.
    - **Evidence:**
        ```php
        // AppleController — representative of the pattern across all seven controllers:
        $validated = $request->validate(['artist' => ['required', 'string', 'max:200']]);
        $validated = $request->validate(['albumIds' => ['present', 'array', 'max:'.self::MAX_HIGHLIGHTS], ...]);
        // FreshaController:
        $validated = $request->validate(['url' => ['required', 'string', 'max:500', 'regex:'.self::URL_PATTERN]]);
        $validated = $request->validate(['serviceId' => ['required', 'string', 'max:50'], 'hidden' => ['required', 'boolean']]);
        // ShopifyController:
        $validated = $request->validate(['productIds' => ['present', 'array', 'max:250'], ...]);
        ```

`★ Insight ─────────────────────────────────────`
Two DeepSeek P1 findings were dropped after verification: `integrations:refresh` already has `->withoutOverlapping(60)` in `routes/console.php` (a schedule-level guard the command class doesn't need to implement), and `staffAnalyticsSummary` already appends `:v{$version}` at the call site — the version token is real, just applied via string concatenation outside the helper. Both are examples of DeepSeek reading declarations without reading consumers. The adjudicator's job is exactly this cross-file verification pass.
`─────────────────────────────────────────────────`
