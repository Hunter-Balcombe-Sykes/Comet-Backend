`BackfillBrandHasEnabledVariantsJob` (that job uses 30/90/180 s; 10/30/60 s is more appropriate here given the higher webhook volume — longer first-delay is counter-productive when Shopify's p99 recovery is under 60 s).
        - No further changes needed: both jobs already have `failed()` handlers and a `$timeout = 30` ceiling.
    - **Technical:** Without `$backoff`, failed attempts re-enter the `integrations` queue instantly. At peak flash-sale volume (~500 webhooks/hour, three attempts each on a 30-second Shopify degradation) all 1 500 re-attempts land within seconds of each other — far exceeding the 4-worker `supervisor-integrations` capacity and creating a cascade that outlasts the degradation itself. Exponential backoff spaces retries so the supervisor drains the burst before the next retry wave arrives. The `$timeout = 30` ceiling is already correct for both jobs; only the inter-attempt delay is missing.
    - **Plain English:** When Shopify has a brief hiccup, your system tries every failed order webhook again immediately. If 500 orders failed during a 30-second outage, all 500 queue back up at once — which overloads the workers and turns a 30-second outage into a 5-minute backlog. Adding a short waiting period between retries (10 s, then 30 s, then 60 s) spreads the load out so the system recovers cleanly once Shopify is back.
    - **Evidence:**
        ```php
        // ProcessShopifyOrderWebhookJob.php
        public int $tries = 3;
        public int $timeout = 30;
        // (no $backoff property)

        // ProcessShopifyOrderUpdatedWebhookJob.php
        public int $tries = 3;
        public int $timeout = 30;
        // (no $backoff property)
        ```

- [ ] **#SCALE-10** · P2 — `InvalidateConnectedAffiliateCachesJob` and `WarmPublicSiteCacheJob` declare no retry config; with `supervisor-default`'s `tries => 1` default, a transient Redis blip silently skips cache warming or invalidation
    - **Where:** app/Jobs/Cache/InvalidateConnectedAffiliateCachesJob.php, app/Jobs/Cache/WarmPublicSiteCacheJob.php
    - **Affects:** Public site visitors after a brand publish or affiliate connection change — a single Redis timeout causes the cache to remain cold or stale with no retry and no alert; the next inbound HTTP request rebuilds it but bears full cold-cache latency
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `public int $tries = 3;` and `public array $backoff = [5, 15, 30];` to both classes — short delays are appropriate since both jobs touch only Redis (sub-millisecond operations under normal conditions; failures indicate a transient blip).
        - Add a `failed(\Throwable $e): void` handler to `WarmPublicSiteCacheJob` that calls `report($e)` so a persistent Redis failure surfaces in Nightwatch rather than vanishing silently. `InvalidateConnectedAffiliateCachesJob` failure is lower risk (stale key expires naturally) but a `report()` call there is equally cheap.
        - Consider adding `public int $timeout = 10;` to cap each attempt — a Redis operation that takes more than 10 s is hanging, not slow.
    - **Technical:** Horizon supervisor `supervisor-default` in `config/horizon.php` declares `'tries' => 1` as the default for all jobs on the `default` queue that don't override it. Both cache jobs are dispatched to `default` (`$this->onQueue('default')`) and neither defines a `$tries` property. This means a single transient failure — e.g., a Redis reconnect during a deploy — silently discards the job. Laravel moves it to the `failed_jobs` table without any observable signal unless you have Horizon failure alerts configured. Adding `$tries = 3` with short backoff is the minimal fix; it correctly handles transient blips at no cost to throughput.
    - **Plain English:** When someone publishes their site or links a new affiliate, your system queues a background task to refresh the cache. If Redis has a tiny hiccup at that exact moment, the task fails and is thrown away — silently. The next visitor to that site then waits several seconds for a cold load instead of getting the cached fast response. Adding a couple of automatic retries means a momentary glitch is handled invisibly without the visitor noticing anything.
    - **Evidence:**
        ```php
        // InvalidateConnectedAffiliateCachesJob.php — no $tries, $backoff, $timeout, or failed()
        class InvalidateConnectedAffiliateCachesJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public function __construct(
                public string $subdomain
            ) {
                $this->onQueue('default');
            }

            public function handle(): void
            {
                $key = CacheKeyGenerator::publicSitePayload($this->subdomain);
                Cache::deleteMultiple([$key, $key.':stale']);
            }
        }

        // WarmPublicSiteCacheJob.php — no $tries, $backoff, $timeout, or failed()
        class WarmPublicSiteCacheJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public function __construct(
                public string $subdomain
            ) {
                $this->onQueue('default');
            }

            public function handle(SiteCacheService $siteCache): void
            {
                $siteCache->warmSiteCache(strtolower($this->subdomain));
            }
        }
        ```

- [ ] **#SCALE-9** · P2 — `SendEnquiryNotificationJob` has `$tries = 3` but no `$backoff`, no `$timeout`, and no `failed()` handler; transient mail failures produce instant retry storms and exhausted retries are silently dropped
    - **Where:** app/Jobs/Notifications/SendEnquiryNotificationJob.php
    - **Affects:** Affiliates receiving contact-form enquiries — a transient mail provider failure causes three immediate retry attempts (hammering the same degraded SMTP endpoint), and if all three fail the affiliate never learns a potential customer reached out
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `public array $backoff = [30, 90, 180];` — mail provider degradations typically resolve within a minute; 30 s first delay avoids the instant-retry hammer pattern without meaningfully delaying delivery.
        - Add `public int $timeout = 30;` — a `Mail::send()` call that blocks for more than 30 s is hanging on a broken SMTP connection, not just slow; cap it so the worker slot is released.
        - Add a `failed(\Throwable $e): void` handler: at minimum call `report($e)` so Nightwatch surfaces the failure. Ideal: also log with `enquiry_id` and `notification_email` context so ops can manually forward the missed notification.
        - The enquiry model lookup (`Enquiry::query()->find(...)`) is correct — returning early on null is right; no change needed there.
    - **Technical:** `SendEnquiryNotificationJob` wraps `Mail::to()->send()`, which is a synchronous SMTP call inside a queue job. Without `$timeout`, a TCP connection to the mail provider that never closes holds the worker for up to the PHP `max_execution_time` limit (or indefinitely under some SMTP libraries). Without `$backoff`, all three attempts fire within seconds when the provider is degraded — three consecutive strikes against the same unhealthy endpoint rather than waiting for recovery. Without `failed()`, the Laravel failed-job record is written to `failed_jobs` but nothing calls `report()`, so Nightwatch never sees the exception, and an affiliate whose contact enquiry email silently failed has no recourse until they notice empty inbox. The `notifications` supervisor runs 3 workers; a mail provider outage combined with instant retries can hold all three workers simultaneously, delaying all other notification jobs (e.g., payout notifications, weekly analytics) behind the mail backlog.
    - **Plain English:** When someone fills out a contact form on an affiliate's site, this job sends them an email notification. If the email service is having a momentary problem, the system tries three times in a row immediately — which is like knocking on a door three times in 3 seconds when no one is home. Adding a waiting period between tries (30 s, then 90 s, then 3 min) gives the email service time to recover. More importantly, if all three tries fail, the affiliate currently gets no notification at all — the failure just silently disappears. Adding an error alert means your team gets notified so you can follow up manually and the affiliate doesn't lose the lead.
    - **Evidence:**
        ```php
        class SendEnquiryNotificationJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;
            // no $backoff, no $timeout, no failed() method

            public function __construct(
                public readonly string $enquiryId,
                public readonly string $notificationEmail,
            ) {
                $this->onQueue('notifications');
            }

            public function handle(): void
            {
                $enquiry = Enquiry::query()->find($this->enquiryId);
                // ...
                Mail::to($this->notificationEmail)->send(new SiteEnquiryNotification($enquiry));
            }
        }
        ```
