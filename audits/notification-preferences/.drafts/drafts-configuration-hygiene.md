- [ ] **#CFG-1** · P2 — `config('partna.public_domain')` called without fallback default in public site route file
    - **Where:** routes/api/publicSite.php:15
    - **Affects:** All public-site routes (site rendering, bookings, leads, enquiries, subscribe/unsubscribe). If the key is missing, the domain group pattern resolves to `{subdomain}.` (empty string), breaking every public-site endpoint.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a sensible fallback: `config('partna.public_domain', 'partna.au')` so the route file degrades gracefully.
        - Add a deployment-time assertion in a service provider's `boot()` that fails loudly in production if the key returns an empty string.
    - **Technical:** Laravel's `Route::group(['domain' => ...])` with an empty domain segment matches against an empty host portion, which is never the intended behaviour. The public-site route file is the only place this config key is consumed, and there is no defensive default — one missing env-to-config mapping silently breaks the entire public surface. Category 5 (config file correctness / missing default).
    - **Plain English:** The public-site route file reads the domain name that visitors use to see a brand's site. If that setting ever goes missing from config, every public page becomes unreachable instead of falling back to a known-good domain. Think of it as a signpost with a missing arrow — nobody knows where to go.
    - **Evidence:**
        ```php
        $publicDomain = config('partna.public_domain');

        Route::group([
            'domain' => '{subdomain}.'.$publicDomain,
            'where' => ['subdomain' => '[A-Za-z0-9-]+'],
            'prefix' => 'public',
        ], function () {
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CFG-2** · P2 — `config('partna.gdpr.queue')` called without fallback in three GDPR job constructors
    - **Where:** app/Jobs/Shopify/Gdpr/ExportCustomerDataJob.php:37, app/Jobs/Shopify/Gdpr/RedactCustomerJob.php:33, app/Jobs/Shopify/Gdpr/RedactShopJob.php:37
    - **Affects:** All Shopify GDPR compliance jobs (customer data export, customer redaction, shop redaction). Missing config silently routes these jobs to the default queue instead of the isolated GDPR queue.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a fallback: `config('partna.gdpr.queue', 'gdpr')` in all three constructors.
        - Ensure `.env.example` lists the corresponding env var (e.g., `GDPR_QUEUE=gdpr`) so new environments route these jobs correctly.
    - **Technical:** The GDPR queue exists to isolate compliance jobs from the main queue so they can have independent retry/backoff policies and a dedicated Horizon pool. `onQueue(null)` (which is what `config()` returns when the key doesn't exist) silently puts these jobs on the `default` queue, defeating the isolation. The `RedactShopJob` goes further and also sets `onConnection('redis_gdpr')` — the queue name inconsistency between connection override and missing queue default compounds the risk. Category 5 (config file correctness).
    - **Plain English:** GDPR jobs — like deleting a customer's data when Shopify tells us — are meant to run in a separate lane so they don't get stuck behind newsletters and booking notifications. If the config key for that lane goes missing, these jobs quietly merge into the main traffic lane with no one noticing. The fix is a safety net so the lane name defaults to something sensible even if someone forgets to set it.
    - **Evidence:**
        ```php
        // ExportCustomerDataJob.php:37
        $this->onQueue(config('partna.gdpr.queue'));

        // RedactCustomerJob.php:33
        $this->onQueue(config('partna.gdpr.queue'));

        // RedactShopJob.php:37
        $this->onConnection('redis_gdpr')->onQueue(config('partna.gdpr.queue'));
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **#CFG-3** · P3 — Duplicated `BATCH_CHUNK_SIZE` constant with explicit sync-warning comments in two fan-out jobs
    - **Where:** app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php:37-39, app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php:37-39
    - **Affects:** Brand status fan-out and staff broadcast email dispatch. If only one constant is changed and the other is forgotten, the two fan-out paths diverge in batch size, causing uneven Redis pipeline pressure.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract to a single config key: `config('sidest.notifications.batch_chunk_size', 200)`.
        - Replace both `private const BATCH_CHUNK_SIZE` declarations with config reads.
        - Remove the "keep in sync" comments — the config file becomes the single source of truth.
    - **Technical:** Both classes define `private const BATCH_CHUNK_SIZE = 200` with near-identical docblocks warning "Shared with [the other class] — keep in sync if changed." This is a textbook signal that the value belongs in shared config. The constant controls how many jobs are packed into one `Bus::batch()` call, which determines Redis pipeline write load. Category 4 (hardcoded values that should be config-driven).
    - **Plain English:** Two different parts of the system split a big list of recipients into chunks of 200 before handing them to the queue. Both parts have a note saying "if you change this, also change it in the other file." That's like two cooks in a kitchen each having their own measuring cup with a sticky note saying "use the same size as the other cook." Put the measurement on the recipe card (config) and both cooks read from the same place.
    - **Evidence:**
        ```php
        // FanOutBrandStatusNotificationJob.php:37
        // Bound batch size so any one Redis pipeline write stays predictable.
        // Shared with SendStaffBroadcastEmailsJob — keep in sync if changed.
        private const BATCH_CHUNK_SIZE = 200;

        // SendStaffBroadcastEmailsJob.php:37
        // Bound batch size so any one Redis pipeline write stays predictable.
        // Shared with FanOutBrandStatusNotificationJob — keep in sync if changed.
        private const BATCH_CHUNK_SIZE = 200;
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#CFG-4** · P3 — Hardcoded notification listing cache TTL in NotificationListingService
    - **Where:** app/Services/Notifications/NotificationListingService.php:54
    - **Affects:** In-app notification bell polling — the 15-second TTL governs how quickly a newly-published notification surfaces in the dashboard without refreshing. Tuning requires a code change and deploy.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the hardcoded `15` with `config('sidest.notifications.listing_cache_ttl_seconds', 15)`.
        - Consider extracting other cache TTLs in the notifications domain (`NotificationPublisher::CACHE_TTL_SECONDS = 3600`, `CommerceNotificationService::MILESTONE_TOTALS_TTL_SECONDS = 60`) for consistency.
    - **Technical:** The `rememberLocked()` call in `NotificationListingService::index()` bakes `15` as the cache lifetime. A 15-second TTL is a deliberate UX tradeoff (notifications appear within one poll cycle vs. cache hit rate), and tuning it via config lets operators adjust without a deployment. Category 4 (hardcoded values).
    - **Plain English:** The notification bell on the dashboard refreshes every 15 seconds because of a number baked into the code. If we ever want to make it faster (5 seconds) or slower (30 seconds, to reduce server load), someone has to edit the code and deploy. Moving this number to a settings file means a quick config change does the job.
    - **Evidence:**
        ```php
        return $this->cache->rememberLocked(
            $this->cacheKey($professionalId, $limit, $includeDismissed),
            15,
            fn () => $this->buildIndexPayload($professionalId, $limit, $includeDismissed),
        );
        ```
    - `[DRAFT, confidence: 0.70]`
