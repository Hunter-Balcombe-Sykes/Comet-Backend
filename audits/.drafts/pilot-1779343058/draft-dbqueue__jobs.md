- [ ] **SCALE-1** · P1 — `VoidExpiredPayoutsJob::fireGraceWarnings` materialises unbounded `->get()` of all pending payouts in a 30-day window
    - **Where:** app/Jobs/Stripe/VoidExpiredPayoutsJob.php:149-159
    - **Affects:** Stripe payout grace-warning path; at 10K daily payouts a 30-day window can hold thousands of rows, all loaded into PHP memory before the tiered filter runs.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace `->get()` with `->chunkById(500)` and run the tiered-publish loop once per chunk.
        - Accumulate `grace_notifications_sent` writes inside each chunk's DB transaction (the current per-row `save()` inside the foreach is already safe; just move the chunk boundary up).
    - **Technical:** Laravel's `->get()` hydrates every matching Eloquent model into a Collection in a single allocation. The 30-day `whereBetween('void_at', ...)` window grows linearly with payout volume. At the scale target — ~10K daily payouts, many held in `pending` status while affiliates are nudged toward Stripe Connect — this query returns thousands of rows, each a fully-hydrated `CommissionPayout` model with casts and relations. The subsequent `foreach` tier-filter and `$publisher->publish()` loop then processes them all with a single memory footprint. `chunkById(500)` keeps PHP memory flat regardless of volume.
    - **Plain English:** Imagine opening a filing cabinet drawer and pulling out every single invoice from the last 30 days in one armful before sorting through them on your desk. As the business grows from 10 invoices a day to 3,000, that armful becomes a forklift. Chunking means you pull out a small stack at a time, process it, and put it back before grabbing the next — your desk never overflows.
    - **Evidence:**
        ```php
        $allCandidates = CommissionPayout::query()
            ->where('status', 'pending')
            ->whereBetween('void_at', [$windowStart, $windowEnd])
            ->where(function ($q) use ($brandSideCodes) {
                $q->whereIn('failure_code', $brandSideCodes)
                    ->orWhereDoesntHave('affiliateProfessional', fn ($a) => $a->where('stripe_connect_status', 'active'));
            })
            ->get();
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **SCALE-2** · P2 — `CheckStreamingLiveStatusJob` omits queue assignment — lands on `default` alongside web traffic and cache work
    - **Where:** app/Jobs/Streaming/CheckStreamingLiveStatusJob.php (no constructor or `onQueue` call)
    - **Affects:** All jobs on the `default` queue while a Twitch/Kick poll cycle runs (up to 90s timeout).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$this->onQueue('integrations');` in a constructor (or a dedicated `streaming` queue).
        - Set `$tries = 2` with a short backoff so a transient Twitch outage doesn't park the job on a retry chain.
    - **Technical:** Laravel queues default to `default` when no queue is specified. `CheckStreamingLiveStatusJob` polls external APIs — Twitch and Kick — with a 90s timeout. At scale, dozens of streaming blocks can exist across 200 brands, each triggering API round-trips. When this job occupies the `default` queue, it blocks cache-warming jobs (`WarmPublicSiteCacheJob`) and cache-invalidation jobs (`InvalidateBrandAffiliatesCacheJob`) that also land on `default`. A single slow poll cycle backs up unrelated work. The `integrations` queue already hosts Shopify, Cloudflare, and Fresha jobs — external-API work belongs there.
    - **Plain English:** This is like having a delivery driver who also answers the phone. When they're out on a 90-second delivery, nobody answers the phone. Moving the streaming checks to the same queue that handles Shopify and Cloudflare calls keeps the main phone line free.
    - **Evidence:**
        ```php
        class CheckStreamingLiveStatusJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 1;
            public int $backoff = 0;
            public int $timeout = 90;

            public function handle(LiveStatusPoller $poller): void
            {
                // no constructor, no onQueue() — falls to 'default'
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **SCALE-3** · P2 — `ProcessImageVariantsJob` loads full original image into PHP memory via `Storage::get()`
    - **Where:** app/Jobs/ProcessImageVariantsJob.php:150-154
    - **Affects:** Image processing workers. A single 20 MB brand-design photo costs 20 MB of PHP heap; 10 concurrent image jobs cost 200 MB.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$disk->get()` + `file_put_contents()` with `$disk->readStream()` + `stream_copy_to_stream()`, matching the pattern already used in `ProcessVideoVariantsJob`.
    - **Technical:** `Storage::get()` reads the entire file into a string in PHP memory. For R2/S3-backed disks, this also means the full object passes through the HTTP response buffer before hitting `file_put_contents`. `ProcessVideoVariantsJob` already uses the correct pattern: `$stream = $disk->readStream($this->originalPath);` followed by `stream_copy_to_stream($stream, $dest)`. This streams chunks directly to the temp file without materialising the full file in PHP. At the scale target, brand design assets and product images can be multi-megabyte, and Horizon may run multiple image workers concurrently — memory pressure adds up.
    - **Plain English:** The video processing pipeline streams the file like a garden hose — bytes flow from storage to disk without filling a bucket first. The image pipeline fills the entire bucket before pouring it out. For a small glass of water that's fine. For a 20 MB firehose, the bucket overflows. Switch the image pipeline to use the same hose approach.
    - **Evidence:**
        ```php
        $content = $disk->get($this->originalPath);
        if (! file_put_contents($localTmp, $content)) {
            throw new \RuntimeException('Failed to write original to temp file.');
        }
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **SCALE-4** · P2 — `ReconcileStuckShopifyIntegrationsJob` fires sequential Shopify Admin API calls with no per-request delay
    - **Where:** app/Jobs/Shopify/ReconcileStuckShopifyIntegrationsJob.php:91-107
    - **Affects:** Shopify Admin API rate-limit budget. At the `BATCH_LIMIT` of 200, a single run can burn through 200 REST HEAD requests in a tight loop.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a 100–200ms `usleep` between iterations so the loop doesn't burst at wire speed.
        - Optionally check `X-Shopify-Shop-Api-Call-Limit` response headers and pause if the bucket is low.
    - **Technical:** Shopify's Admin REST API enforces a leaky-bucket rate limit (typically 40 requests/second per shop, but the reconcile job iterates across *different* shops, so the per-shop limit isn't the primary risk). The global app-level limit across all shops is higher but not infinite. A tight `foreach` loop issuing 200 HEAD requests sequentially with no artificial delay can saturate the local HTTP client connection pool and, in edge cases, trigger Shopify's abuse-detection heuristics. The job already has a wall-clock guard (80% of 600s timeout), but that only caps total runtime — it doesn't shape the request rate. A small `usleep(100000)` costs at most 20s across 200 iterations and keeps the request pattern friendly.
    - **Plain English:** This is like a telemarketer who dials 200 numbers in rapid succession without pausing between calls. Even though each call goes to a different person, the phone company notices the burst and may flag the caller. Adding a short breath between dials costs 20 seconds across all 200 calls but keeps the system happy.
    - **Evidence:**
        ```php
        foreach ($candidates as $integration) {
            if (microtime(true) - $start > $softDeadlineSeconds) {
                $deadlineReached = true;
                break;
            }

            $inspected++;
            $check = $this->validateAccessToken($integration);
            // no delay between validateAccessToken calls
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **SCALE-5** · P3 — `AggregateCacheMetricsJob::hGetAll` loads entire hourly Redis hash into PHP memory unboundedly
    - **Where:** app/Jobs/Cache/AggregateCacheMetricsJob.php:31
    - **Affects:** The hourly metrics aggregation job. Memory use scales with the number of cache prefixes across all tenants.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `hGetAll` with `hScan` iterating in small batches, or split the hash into per-prefix keys (`cache_metrics:{bucket}:{prefix}`) and scan keys instead of fields.
    - **Technical:** `Redis::hGetAll($bucketKey)` returns every field-value pair in the hash in a single Redis response. `RecordCacheMetrics` increments `HINCRBY` on fields like `site:hits`, `block:misses`, etc. At 200 brands × ~50 affiliates each, with multiple cache prefixes per site (`public_site_payload`, `brand_design`, `public_profile`, etc.), the per-hour hash could contain thousands of fields. While this is unlikely to exceed PHP's memory limit at current scale, the hash grows linearly with tenant count and the job has no bound on it. `hScan` with a small count per iteration keeps the Redis response size and PHP allocation constant.
    - **Plain English:** This job opens a drawer and counts every paper inside at once. Right now there are maybe 100 papers. But the drawer fills up as Partna adds more brands and affiliates. Switching to counting papers one handful at a time means the job runs the same speed whether there are 100 papers or 10,000.
    - **Evidence:**
        ```php
        $bucket = now('UTC')->subHour()->format('Y-m-d-H');
        $bucketKey = "cache_metrics:{$bucket}";

        $raw = Redis::hGetAll($bucketKey);
        ```
    - `[DRAFT, confidence: 0.75]`
