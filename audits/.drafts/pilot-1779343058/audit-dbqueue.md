# DB + Queue Throughput Audit — 2026-05-21

**Branch:** development
**Lens:** Whole-backend PILOT audit — dbqueue lens (N+1, unbounded reads, queue shape, vendor rate-limits, migration lock hygiene)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/`
- `supabase/migrations/`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 5 complete
- P2 Medium: 0 of 17 complete
- P3 Low: 0 of 6 complete

---

## P1 — Fix before pilot launch

- [ ] **#SCALE-1** · P1 — CommissionPayoutService::createPayoutBatchTransactional holds unbounded FOR UPDATE lock across all eligible orders
    - **Where:** app/Services/Stripe/CommissionPayoutService.php (createPayoutBatchTransactional)
    - **Affects:** Daily payout sweep for every brand–affiliate pair. A busy pair accumulating hundreds of orders in one sweep holds row locks across all of them simultaneously, blocking concurrent refund webhooks and the next sweep run.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace `->lockForUpdate()->get()` with `->lockForUpdate()->chunkById(200, function ($chunk) use (&$payout, ...) { ... })` so locks are held only per-chunk.
        - Accumulate `CommissionPayoutItem` rows within each chunk and batch-insert with `CommissionPayoutItem::insert($rows)` (see also SCALE-18 for the per-row insert issue).
    - **Technical:** `->lockForUpdate()->get()` loads every eligible order for a (brand, affiliate, currency) tuple into a single PHP array while holding `SELECT ... FOR UPDATE` locks on all rows simultaneously. At scale a busy brand–affiliate pair can accumulate hundreds of orders per sweep window. The refund webhook handler (`ProcessShopifyRefundWebhookJob`) also locks `commerce.orders` rows — a long-held batch lock causes those jobs to queue behind it. `chunkById(200)` releases the lock between chunks, keeping max lock duration proportional to chunk size rather than the full set.
    - **Plain English:** The system grabs every unpaid order in one armful and locks each one so nothing else can touch them — then slowly counts through the stack. If there are 500 orders, that lock lasts the entire count. During that time, if a customer tries to return something, the refund processing has to wait in line. Breaking the count into groups of 200 means the queue opens up again every few seconds.
    - **Evidence:**
        ```php
        $orders = Order::query()
            ->where('status', 'approved')
            ->whereNull('payout_id')
            ->where('refund_cents', 0)
            ->where('brand_professional_id', $brandId)
            ->where('affiliate_professional_id', $affiliateId)
            ->where('currency_code', $currency)
            ->where(function ($q) use ($cutoff) {
                $q->where('payout_eligible_at', '<=', now())
                    ->orWhere(function ($q2) use ($cutoff) {
                        $q2->whereNull('payout_eligible_at')
                            ->where('occurred_at', '<=', $cutoff);
                    });
            })
            ->lockForUpdate()
            ->get();
        ```

- [ ] **#SCALE-2** · P1 — CommissionPayoutService::revalidatePayoutOrders holds unbounded FOR UPDATE lock across all orders for a payout
    - **Where:** app/Services/Stripe/CommissionPayoutService.php (revalidatePayoutOrders)
    - **Affects:** Every `ExecuteCommissionPayoutJob` execution — revalidation runs once per payout job, locking all orders linked to that payout for the full duration of the PHP method.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace `->lockForUpdate()->get()` with `->lockForUpdate()->chunkById(200, ...)` and accumulate `$validOrders` / `$staleOrders` aggregates across chunks.
    - **Technical:** Same root cause as SCALE-1 — `->lockForUpdate()->get()` holds FOR UPDATE on every row for the payout batch. A large batch (hundreds of orders aggregated over a week for a high-volume brand) blocks concurrent refund processing for the entire revalidation duration. The fix is identical to SCALE-1: chunk the read, hold locks only per-chunk.
    - **Plain English:** Before sending money to an affiliate, the system re-checks every order in the batch is still valid — locking all of them at once. Same problem as SCALE-1, different step. An order processed during that window can't be touched by refund logic until the check finishes.
    - **Evidence:**
        ```php
        $orders = Order::query()
            ->where('payout_id', $payout->id)
            ->lockForUpdate()
            ->get();
        ```

- [ ] **#SCALE-3** · P1 — VoidExpiredPayoutsJob::fireGraceWarnings materialises unbounded 30-day pending-payout window into PHP memory
    - **Where:** app/Jobs/Stripe/VoidExpiredPayoutsJob.php:149-159
    - **Affects:** Nightly grace-warning sweep; at 10K daily payouts the 30-day window holds potentially tens of thousands of `CommissionPayout` Eloquent models in one allocation.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace `->get()` with `->chunkById(500, function ($chunk) use ($publisher, $brandSideCodes) { ... })` and move the tier-publish loop inside each chunk callback.
    - **Technical:** `CommissionPayout::query()->...->get()` materialises every matching payout into a PHP Collection before the tier filter runs. At scale a 30-day window of pending payouts can be thousands of fully-hydrated Eloquent models, each with casts and relation slots. `chunkById(500)` keeps peak memory at ~500 models regardless of window size and doesn't change the publish semantics.
    - **Plain English:** The nightly sweep pulls every invoice from the last 30 days into its arms at once before sorting through them. As volume grows, that armful becomes a forklift load. Sorting in batches of 500 keeps the desk clear.
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

- [ ] **#SCALE-4** · P1 — AffiliateProductCatalogService::queryAdminCatalog bypasses ShopifyAdminClient, disabling per-shop rate-limit enforcement
    - **Where:** app/Services/Store/AffiliateProductCatalogService.php:652-661 (queryAdminCatalog)
    - **Affects:** Every affiliate catalog load — the hot path that serves the affiliate storefront. At 200 brands with active affiliates, raw `Http::post()` calls can burst Shopify 429 errors across all brands with no local defence.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the raw `Http::timeout(20)->withHeaders(...)->post(...)` call with `$this->brandCatalogService->graphql(...)` or inject `ShopifyAdminClient` and use its `query()` method, which handles pre-budget acquisition, throttle-retry, and cost reconciliation.
    - **Technical:** `queryAdminCatalog` constructs its own URL, access-token header, and timeout and POSTs directly to Shopify Admin GraphQL. Every other GraphQL call in the codebase routes through `ShopifyAdminClient`, which maintains a per-shop token-bucket via `ShopifyCostTracker`, honours the `THROTTLED` retry contract, and logs cost metrics to Horizon. Bypassing this means the per-shop budget is never decremented for these reads, so the tracker under-estimates consumed capacity and allows other calls to fire into an already-drained bucket. At scale (many affiliates loading catalog concurrently) this materialises as cascading 429 errors with no local retry.
    - **Plain English:** Every other Shopify call in the codebase goes through a shared "traffic manager" that tracks how much bandwidth we've used per store and slows down when we're getting close to the limit. This one call skips that manager entirely — it dials Shopify directly. When many affiliates load their catalog at the same time, we can easily blow through Shopify's limit and get blocked, with no retry logic to recover gracefully.
    - **Evidence:**
        ```php
        $response = Http::timeout(20)
            ->acceptJson()
            ->withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
            ])
            ->post($url, [
                'query' => $query,
                'variables' => $variables,
            ]);
        ```

- [ ] **#SCALE-5** · P1 — ExportProfessionalDataJob dispatched inside a DB transaction without afterCommit
    - **Where:** app/Services/Professional/DataExport/DataExportService.php:56-61
    - **Affects:** Every GDPR data-export request. The job is pushed to Redis before the `DataExportAudit` row commits — the worker finds no row, fails, and wastes a retry slot.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Append `->afterCommit()` to the `ExportProfessionalDataJob::dispatch($audit->id)` call, or move the dispatch call to after the `DB::transaction(...)` closure.
    - **Technical:** `DB::connection('pgsql')->transaction(...)` creates the `DataExportAudit` row and immediately calls `ExportProfessionalDataJob::dispatch(...)`. With a Redis queue driver, `dispatch()` pushes the job atomically to Redis before the outer PostgreSQL transaction commits. A fast Horizon worker dequeues the job, does `DataExportAudit::find($auditId)`, and the row is not yet visible (still within the open transaction). The job fails with a not-found error, consumes a retry slot, and eventually succeeds on retry once the transaction is committed. `->afterCommit()` defers the Redis push until the Postgres transaction commits successfully — the job then always finds its row.
    - **Plain English:** The system writes a task slip and drops it in the workers' inbox before actually filing the task in the cabinet. A fast worker grabs the slip, opens the cabinet, and the folder isn't there yet. It tries again later and finds it — but in the meantime it wasted an attempt. The fix is to drop the slip only after the folder is safely filed.
    - **Evidence:**
        ```php
        return DB::connection('pgsql')->transaction(function () use ($professional, $triggeredBy, $staffId, $sendTo, $recipient) {
            // ...
            $audit = DataExportAudit::create([...]);
            ExportProfessionalDataJob::dispatch($audit->id);
            return $audit;
        });
        ```

---

## P2 — Should fix

- [ ] **#SCALE-6** · P2 — Observer lazy-load N+1: CommissionMovementObserver, BlockObserver, SiteMediaObserver each fire a SELECT per model event
    - **Where:** app/Observers/Core/CommissionMovementObserver.php:204 · app/Observers/Core/BlockObserver.php:53-75 · app/Observers/Core/SiteMediaObserver.php:73,89,101
    - **Affects:** Any batch operation that triggers multiple observer firings: bulk block reorders, multi-upload media pipelines, batch commission processing. Each event fires one extra SELECT against `sites` or `core.professionals`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `CommissionMovementObserver`: ensure the caller pre-loads `affiliateProfessional` (e.g. via `with('affiliateProfessional')` on the query that retrieves the entry) so the observer's `$entry->affiliateProfessional?->display_name` access is never a lazy-load.
        - In `BlockObserver::onBlockMutated` and `SiteMediaObserver`'s `touchParentSite`/`bustHydrogenCaches`/`reevaluateIfRelevant`: add `$block->loadMissing('site')` / `$media->loadMissing('site')` at the top of each method so repeat-access within the same observer invocation is free.
    - **Technical:** All three observers access a belongs-to relation (`$entry->affiliateProfessional`, `$block->site`, `$media->site`) without a prior eager-load. Eloquent issues one `SELECT` per event invocation. For a bulk block reorder of 50 links, `BlockObserver` fires 50 times, generating 50 extra queries. `loadMissing()` is a low-effort fix within the observer itself; for `CommissionMovement`, the caller must pre-load since the relation is accessed once per entry across many entries.
    - **Plain English:** When the system processes a batch of items (reordering 50 links, uploading 10 images), it asks "which site does this belong to?" individually for every item instead of looking it all up at the start. Like checking your own address before mailing each envelope in a batch instead of writing it down once.
    - **Evidence:**
        ```php
        // CommissionMovementObserver.php
        $affiliateName = $entry->affiliateProfessional?->display_name ?? 'An affiliate';

        // BlockObserver.php
        if (! $block->site) { return; }

        // SiteMediaObserver.php
        private function touchParentSite(SiteMedia $media, string $action): void
        {
            try {
                $site = $media->site;
                if (! $site) { return; }
                $site->touch();
        ```

- [ ] **#SCALE-7** · P2 — Square and Fresha API clients sleep the Horizon worker on 429 instead of releasing the job back to the queue
    - **Where:** app/Services/Square/SquareApiClient.php:226-231 · app/Services/Fresha/FreshaApiClient.php:161-169
    - **Affects:** Any Horizon job that calls the Square or Fresha APIs during a rate-limit event. One 429 with `Retry-After: 5` holds a Horizon worker process idle for up to 15 seconds (3 retries × 5s). Under a vendor outage, multiple jobs can hold all available workers simultaneously.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the retry/backoff decision into the calling job: catch a new `RateLimitedException` from the client, call `$this->release($retryAfterSeconds)` to return the job to the queue, and let the worker process other work during the wait.
        - The client itself should throw rather than loop-sleep when the 429 limit is reached.
        - Consider extracting a shared `VendorApiClient` base class to prevent the pattern recurring in future integrations.
    - **Technical:** Both clients use an identical `while (true)` loop with `usleep($wait * 1000)` inside the retry branch. In Horizon, each worker is an OS process; a sleeping worker cannot process other jobs. Under rate-limit pressure from Square (or a Fresha outage), multiple sync jobs block concurrently — a classic worker-exhaustion pattern. The canonical Laravel fix is `$this->release($delay)` inside the job's `handle()`, which re-enqueues the job with a delay and frees the worker immediately. Note: Fresha and Square features are marked unfinished per the current roadmap, but the code paths still execute for existing integrations.
    - **Plain English:** When Square or Fresha tells the system to slow down, the worker stops and naps on the job instead of stepping aside so others can be handled. If five brands hit the rate limit at once, five workers are all napping simultaneously. The fix is for the worker to put the task back in the queue with a "try me again in 5 seconds" note and immediately move on to the next task.
    - **Evidence:**
        ```php
        // SquareApiClient.php (identical pattern in FreshaApiClient.php)
        while (true) {
            $response = $this->makeRequest($token, $method, $path, $query, $body);

            if ($response->status() === 429 && $attempt < $maxRetries) {
                $wait = max(1000, ((int) ($response->header('Retry-After') ?? 1)) * 1000);
                usleep($wait * 1000);
                $attempt++;
                continue;
            }
            break;
        }
        ```

- [ ] **#SCALE-8** · P2 — ProcessImageVariantsJob materialises the full image file in PHP memory via Storage::get()
    - **Where:** app/Jobs/ProcessImageVariantsJob.php:148-151
    - **Affects:** Image processing workers. A 20 MB design asset allocates 20 MB of PHP heap. Multiple concurrent image jobs multiply this linearly; Horizon's default concurrency means 8 concurrent jobs = 160 MB of raw file data held in memory.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$disk->get($this->originalPath)` + `file_put_contents($localTmp, $content)` with `$stream = $disk->readStream($this->originalPath)` + `stream_copy_to_stream($stream, fopen($localTmp, 'w'))` — matching the pattern already used in `ProcessVideoVariantsJob`.
    - **Technical:** `Storage::get()` reads the entire R2/S3 object body into a PHP string before writing it to the temp file. For R2-backed disks, this means the full object passes through the HTTP response buffer into PHP heap. `ProcessVideoVariantsJob` already uses `readStream()`, which pipes bytes directly from the HTTP response to the temp file descriptor without ever materialising the full file in PHP. At the scale target, brand design assets and product gallery uploads are routinely 5–20 MB; the video pattern is correct and should be applied consistently.
    - **Plain English:** Video uploads stream through a pipe — bytes flow from cloud storage straight to disk without stopping. Image uploads fill a bucket first — the whole file sits in memory before being poured onto disk. For large images, that bucket is big. The video approach is already built; this just applies it to images too.
    - **Evidence:**
        ```php
        $content = $disk->get($this->originalPath);
        if (! file_put_contents($localTmp, $content)) {
            throw new \RuntimeException('Failed to write original to temp file.');
        }
        ```

- [ ] **#SCALE-9** · P2 — ProcessImageVariantsJob and CheckStreamingLiveStatusJob dispatched without queue assignment — land on default alongside webhooks and payments
    - **Where:** app/Services/Media/BrandDesignMediaService.php:~292 (ProcessImageVariantsJob dispatch) · app/Jobs/Streaming/CheckStreamingLiveStatusJob.php (no `$queue` property, no `onQueue()`)
    - **Affects:** Any time an image is uploaded or a streaming live-status poll runs, these CPU/network-bound jobs share the `default` queue with Shopify webhook processing, commission jobs, and cache invalidation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `BrandDesignMediaService::dispatchVariantJob`: add `->onQueue('media')` to the `ProcessImageVariantsJob::dispatch(...)` call.
        - In `CheckStreamingLiveStatusJob`: add `public string $queue = 'integrations';` (the `integrations` queue already hosts Shopify, Cloudflare, and Fresha jobs — external-API work belongs there). Also set `$tries = 2` and a short `$backoff` since the 90s timeout means a transient Twitch outage can hold a slot for a long time.
        - Configure a `media` supervisor in `config/horizon.php` if one doesn't already exist.
    - **Technical:** Laravel places jobs on `default` when no queue is specified. `ProcessImageVariantsJob` is CPU-intensive; `CheckStreamingLiveStatusJob` has a 90s HTTP timeout. Both compete with time-sensitive Shopify webhook handlers on the `default` queue. A single live-status poll that hangs at the 90s timeout blocks commission-webhook processing by occupying a worker for 90 seconds.
    - **Plain English:** Image processing and "is this streamer live?" checks share the same lane as payment confirmations and order updates. A slow truck blocks all the cars behind it. Giving each type of job its own lane keeps the important stuff moving.
    - **Evidence:**
        ```php
        // BrandDesignMediaService.php — no ->onQueue()
        ProcessImageVariantsJob::dispatch(
            originalPath: $originalPath,
            imageId: $imageId,
            basePath: $basePath,
            siteId: $siteId,
        );

        // CheckStreamingLiveStatusJob.php — no $queue property
        class CheckStreamingLiveStatusJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
            public int $tries = 1;
            public int $backoff = 0;
            public int $timeout = 90;
        ```

- [ ] **#SCALE-10** · P2 — LiveStatusInjector fires one Redis GET per streaming block instead of a single mget
    - **Where:** app/Services/Streaming/LiveStatusInjector.php:64-65
    - **Affects:** Every public site page render with streaming blocks. The penalty is paid even on cache-hit renders (the docblock confirms live status is intentionally excluded from the cached payload). At 200 brands each with several streaming links, and at peak public traffic, this multiplies Redis round-trips linearly with block count.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Before the `array_map` loop, collect all `LIVE_KEY_PREFIX."{$platform}:{$handle}"` keys from the block settings into an array.
        - Replace per-block `Redis::get($key)` with a single `Redis::mget($keys)` call, then map the indexed results back to each block.
    - **Technical:** The `array_map` callback calls `Redis::get()` inside a loop — a textbook N+1 against Redis. While each call is sub-millisecond, the round-trip cost is paid for every page render on every site that has streaming blocks, including cache-hit renders. A single `Redis::mget($allKeys)` collapses N network hops to one and is naturally atomic across all keys.
    - **Plain English:** Each "is this person live on Twitch?" check is a separate question to Redis. If a profile has 8 streaming links, that's 8 questions. Grouping them into one "check all 8" question is free and the answer comes back at the same time.
    - **Evidence:**
        ```php
        $redisKey = self::LIVE_KEY_PREFIX."{$platform}:{$handle}";
        $block['settings']['is_live'] = Redis::get($redisKey) === '1';
        ```

- [ ] **#SCALE-11** · P2 — LiveStatusPoller::filterStaleHandles fires one Redis TTL call per handle in a sequential filter
    - **Where:** app/Services/Streaming/LiveStatusPoller.php:160-167
    - **Affects:** Every polling cycle for every streaming platform. At 500+ streaming handles across the fleet, each poll cycle issues 500+ sequential Redis TTL commands.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `array_filter` with a Redis pipeline: collect all keys, execute `Redis::pipeline(fn($pipe) => array_map(fn($k) => $pipe->ttl($k), $keys))`, then filter on the returned array.
    - **Technical:** `array_filter` calls `Redis::ttl()` once per handle in sequence. Redis pipelining batches all TTL commands into one network round-trip, with Redis executing them in a single pass. The result is the same; the cost drops from N network hops to 1.
    - **Plain English:** The poller checks freshness of hundreds of streaming handles by asking Redis "how old is this one? … how old is this one? …" one at a time. Pipelining asks about all of them in a single request — same answer, one trip instead of hundreds.
    - **Evidence:**
        ```php
        return array_values(array_filter($handles, function (string $handle) use ($platform): bool {
            $key = self::LIVE_KEY_PREFIX."{$platform}:{$handle}";
            $ttl = Redis::ttl($key);
            return $ttl < self::TTL_SKIP_THRESHOLD;
        }));
        ```

- [ ] **#SCALE-12** · P2 — PruneNotifications issues an unbounded single-transaction DELETE potentially covering 1M+ rows
    - **Where:** app/Console/Commands/PruneNotifications.php:32-36
    - **Affects:** Nightly pruning job. At ~40K notifications/day and a 30-day retention window, one sweep can attempt to delete 1.2M rows in a single statement, generating heavy WAL writes and holding a long-running transaction.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the single `$q->delete()` with a batched loop: `while ($q->clone()->limit(10000)->delete() > 0) { /* yield */ }` so each iteration is a short transaction.
    - **Technical:** A single `DELETE` on a million-row table holds a transaction for the full scan duration, generates WAL proportional to deleted rows in one burst, and can cause replication lag on Supabase's logical replication. Batching in 10K-row chunks keeps each transaction short, reduces WAL burst size, and allows Postgres vacuum to reclaim dead tuples progressively rather than waiting for one giant delete to commit.
    - **Plain English:** The nightly cleanup tries to shred a year's worth of old messages in one go. That clogs the database's filing-shredder. Shredding 10,000 at a time and pausing briefly between batches keeps everything flowing.
    - **Evidence:**
        ```php
        $deleted = $q->delete(); // relies ON DELETE CASCADE to remove receipts
        $this->info("Deleted {$deleted} notifications.");
        ```

- [ ] **#SCALE-13** · P2 — EmbeddedSetupController dispatches all Shopify provisioning jobs to the default queue
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedSetupController.php (provisionShopifyIntegration dispatch loop)
    - **Affects:** Brand onboarding pipeline — 6 provisioning jobs per new brand land on `default`, temporarily congesting it alongside webhook processing and commission jobs. Under a cohort onboarding event (e.g. 20 brands signing up in a window), 120 jobs burst onto the queue.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->onQueue('shopify')` to every `::dispatch()` inside the provisioning loop.
        - Ensure the `shopify` queue supervisor in `config/horizon.php` has sufficient workers to handle provisioning bursts without starving itself.
    - **Technical:** Without a queue assignment, each of the six provisioning jobs (`RegisterShopifyWebhooksJob`, `CreateStorefrontAccessTokenJob`, etc.) lands on `default`. A cohort of 20 new brands generates 120 jobs simultaneously — alongside time-critical Shopify order webhooks on the same queue. A dedicated `shopify` queue isolates this burst pattern from the critical path.
    - **Plain English:** When a new brand signs up, six background tasks fire at once. They join the same line as urgent order and payment tasks. If many brands sign up together, those six tasks each clog the line. Giving onboarding tasks their own dedicated lane keeps the urgent lane clear.
    - **Evidence:**
        ```php
        foreach ($jobs as $jobClass) {
            try {
                $jobClass::dispatch((string) $integration->id);
            } catch (\Throwable $e) {
                Log::warning('Failed to dispatch embedded integration setup job', [...]);
            }
        }
        ```

- [ ] **#SCALE-14** · P2 — SquareCatalogWebhookController falls back to inline synchronous sync when job dispatch fails
    - **Where:** app/Http/Controllers/Api/Webhooks/SquareCatalogWebhookController.php (__invoke catch block)
    - **Affects:** Square webhook ingestion during any Redis/queue outage. Every `catalog.version.updated` webhook blocks a PHP-FPM worker for the duration of a full Square API catalog pull instead of acknowledging fast and letting Square retry.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Remove the inline `$syncService->syncFromSquare($professional, fullSync: false)` from the catch block.
        - Log the dispatch failure and return `200 OK` — Square will re-deliver the webhook and the job will succeed once the queue recovers.
    - **Technical:** When `SyncSquareCatalogDeltaJob::dispatch()` throws (e.g. Redis connection refused), the controller calls `syncFromSquare()` synchronously on the request thread. This inverts the intended architecture: under exactly the conditions when the queue is overloaded, the controller absorbs the vendor-API workload directly. Multiple concurrent webhook deliveries during a queue degradation can exhaust PHP-FPM workers, amplifying the outage. Square's at-least-once webhook delivery makes returning 200 + logging the dispatch failure the correct response.
    - **Plain English:** Normally when Square sends an update, the system quickly says "got it" and puts the heavy work in a queue. But if the queue system is having trouble, it tries to do the heavy work right there instead of saying "got it, I'll handle it when the queue recovers." This clogs the front door exactly when everything is already under stress.
    - **Evidence:**
        ```php
        try {
            SyncSquareCatalogDeltaJob::dispatch($merchantId, null, false);
            return $this->success(['received' => true, 'queued' => true]);
        } catch (\Throwable $dispatchError) {
            // ... logs and then inline sync:
            $stats = $syncService->syncFromSquare($professional, fullSync: false);
            return $this->success(['received' => true, 'queued' => false, 'synced_inline' => true, ...]);
        }
        ```

- [ ] **#SCALE-15** · P2 — PurgeAffiliateProductSelectionsJob dispatched to the default queue after a brand uninstall transaction
    - **Where:** app/Http/Controllers/Api/Webhooks/Shopify/ShopifyAppUninstalledWebhookController.php (post-transaction dispatch)
    - **Affects:** The `default` queue — the purge job deletes many rows with chunked locking and can run for several seconds, occupying a worker during other critical processing.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->onQueue('shopify')` to the `PurgeAffiliateProductSelectionsJob::dispatch(...)` call so purge work runs on the same queue as other Shopify lifecycle jobs.
    - **Technical:** The purge job chunks affiliate product selection deletes to avoid long row locks — the right approach — but it still occupies a queue worker for the chunked delete duration. Routing it to the `shopify` queue ensures a brand uninstall doesn't slow down time-sensitive `default` queue processing (cache invalidation, payment webhooks).
    - **Plain English:** After a brand removes the app, a slow cleanup task joins the fast lane instead of going to the slower lane where it belongs. A short queue hint fixes the routing.
    - **Evidence:**
        ```php
        $result = DB::transaction(function () use ($shopDomain) { ... });
        // after commit
        PurgeAffiliateProductSelectionsJob::dispatch($result['professional_id']);
        ```

- [ ] **#SCALE-16** · P2 — ProfessionalGalleryController::index and ProfessionalServiceController::index return unpaginated result sets
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalGalleryController.php:23-31 · app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalServiceController.php:47-64
    - **Affects:** Any professional with a large gallery or service catalog — the dashboard loads every row in one response, causing unbounded memory and JSON serialisation pressure on both server and client.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Gallery: add `->paginate(50)` and return a paginated resource with `current_page`, `last_page`, and `total`.
        - Services: paginate the fallback `->get()` path, and ensure the cached `getDashboardServices` path also returns a bounded page rather than the full collection.
    - **Technical:** Both controllers call `->get()` without any `limit` or `paginate()`. A brand that has synced 200+ services from Square or uploaded 300 gallery images generates a single large JSON payload on every dashboard load. For services, the hot path is cached via `getDashboardServices` — but the cache itself stores and re-serialises the full unbounded collection. Pagination bounds both the response size and the cached payload.
    - **Plain English:** When you open the gallery or services page on the dashboard, the server loads every single item you've ever uploaded — even if you only see the first 50 on screen. As your library grows, the page gets slower to load. Showing a page at a time means the first page is always fast.
    - **Evidence:**
        ```php
        // ProfessionalGalleryController.php
        $images = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_GALLERY)
            ->where('is_active', true)
            ->with('mediaVariants')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get(); // ← no pagination, unbounded

        // ProfessionalServiceController.php
        $services = $servicesQuery->orderBy('sort_order')->orderBy('created_at')->get();
        // no paginate() — unbounded result set
        ```

- [ ] **#SCALE-17** · P2 — BrandAffiliateController::snapshot loads all CommissionPayouts for an affiliate then slices in PHP
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateController.php:146-149
    - **Affects:** Brand dashboard affiliate detail view. An affiliate active for two or more years accumulates hundreds of payout rows — all loaded into memory to produce a 5-row slice.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `->get()` with `->latest()->take(5)->get()` (or whatever `recentPayouts` slices to), so the database returns only the rows that will be displayed.
    - **Technical:** The query loads all historical payouts for a brand–affiliate pair. A high-volume affiliate active for two years could have hundreds of rows. The subsequent PHP slice (`$recentPayouts = $payouts->take(5)`) discards everything except the last 5. Pushing the `LIMIT 5` into the SQL statement is a trivial one-line fix that eliminates the unnecessary data transfer and Eloquent model hydration.
    - **Plain English:** Asking the bank for your last 5 transactions but they print your full statement since you opened the account — then you fold it up and throw away everything except the top 5 lines. Just ask for the top 5.
    - **Evidence:**
        ```php
        $payouts = CommissionPayout::query()
            ->where('brand_professional_id', $brandId)
            ->where('affiliate_professional_id', $affiliateId)
            ->get();   // ← unbounded, then sliced in memory
        ```

- [ ] **#SCALE-18** · P2 — CommissionPayoutService inserts CommissionPayoutItem rows one at a time inside the batch transaction
    - **Where:** app/Services/Stripe/CommissionPayoutService.php (createPayoutBatchTransactional foreach loop)
    - **Affects:** Every payout batch creation. At hundreds of orders per batch and thousands of batches per day at scale, this is hundreds of thousands of individual INSERT round-trips per day.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Accumulate `$rows = []` inside the `foreach`, then call `CommissionPayoutItem::insert($rows)` once after the loop. The wrapping transaction preserves atomicity.
    - **Technical:** `CommissionPayoutItem::create([...])` inside a `foreach` issues one `INSERT` per order. Eloquent's `insert(array $records)` accepts an array of arrays and emits a single multi-row `INSERT ... VALUES (...), (...), (...)`. At 200 orders per batch, `insert()` is 200× fewer round-trips. Note that `insert()` bypasses Eloquent model events and timestamps — if `PayoutItem` has no observers and timestamps are set manually, this is safe.
    - **Plain English:** Writing 200 items into a ledger one line at a time when you could hand the whole stack to the accountant in one pass. Each individual line requires a separate trip to the filing cabinet. One trip with all the lines is faster.
    - **Evidence:**
        ```php
        foreach ($orders as $order) {
            CommissionPayoutItem::create([
                'payout_id' => $payout->id,
                'order_id' => $order->id,
                'amount_cents' => $order->commission_cents,
            ]);
        }
        ```

- [ ] **#SCALE-19** · P2 — AccountDeletionService::purgeMediaArtifacts loads all SiteMedia rows into PHP memory
    - **Where:** app/Services/Professional/AccountDeletionService.php (purgeMediaArtifacts)
    - **Affects:** Nightly `PurgeSoftDeleted` sweep — every hard-deleted brand account. A brand with 500+ uploaded images (gallery + design + product photos) spikes worker memory.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->get()` with `->chunkById(100, fn ($chunk) => ...)` and move the dispatch/delete logic inside the chunk callback. The async `DeleteMediaArtifactsJob` dispatch and synchronous S3 deletes are both safe inside chunks.
    - **Technical:** `SiteMedia::query()->withTrashed()->where('site_id', $site->id)->get()` materialises every media row for a site. A brand with a large gallery can hold 500+ rows, each a fully-hydrated Eloquent model. The `foreach` body dispatches async video jobs and synchronously deletes images — no value in holding all rows simultaneously. `chunkById(100)` keeps peak allocation at ~100 rows regardless of library size.
    - **Plain English:** When clearing out a closed store, the cleanup crew lifts every item off every shelf at once before starting to bin them. For a large store, that's a huge armful. Working shelf by shelf means the team never has to hold the whole store at once.
    - **Evidence:**
        ```php
        $mediaItems = SiteMedia::query()
            ->withTrashed()
            ->where('site_id', $site->id)
            ->get();
        ```

- [ ] **#SCALE-20** · P2 — BrandCatalogService::fetchCollectionProducts results uncached — repeated paginated Shopify API calls per affiliate catalog request
    - **Where:** app/Services/Store/BrandCatalogService.php:960 (fetchCollectionProducts) · app/Services/Store/AffiliateProductCatalogService.php:~340 (fetchCollectionGids call site)
    - **Affects:** Every affiliate catalog load that touches collection-based data (favourites, default collections). Many concurrent affiliates hitting `/affiliate/catalog` re-fetch the same collection product lists, multiplying Shopify API cost with no local defence.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap `fetchCollectionProducts` (or its call site in `fetchCollectionGids`) with a short-TTL cache keyed on `(brand_id, collection_gid)` — e.g. `Cache::remember("collection_products:{$brandId}:{$collectionGid}", 300, fn () => ...)`.
        - Bust this key on any metafield or product-visibility change (the surrounding code already invalidates the main catalog cache — add the collection key to the same invalidation path).
    - **Technical:** `resolveCollectionGid` is cached, but `fetchCollectionProducts` — which paginates through every product in a collection via a `do ... while` GraphQL loop — is not. The collection product list changes rarely (only on explicit brand catalog edits), yet it is re-fetched from Shopify on every affiliate catalog request. At 50 concurrent affiliates each loading a catalog with two collections, this generates 100+ paginated Shopify API requests per second against the same data.
    - **Plain English:** Every time an affiliate opens their store, the system makes multiple trips to Shopify to fetch the list of favourite products — even though that list rarely changes. Writing the answer down for 5 minutes and handing it to the next affiliate who asks would eliminate almost all of those trips.
    - **Evidence:**
        ```php
        do {
            $variables = ['id' => $collectionGid, 'first' => self::PRODUCTS_PER_PAGE];
            if ($cursor !== null) { $variables['after'] = $cursor; }
            $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::COLLECTION_PRODUCTS, $variables);
            // … edges parsed …
        } while ($hasNextPage && $cursor !== null);
        ```

- [ ] **#SCALE-21** · P2 — Index creation on hot tables without CONCURRENTLY in multiple migrations
    - **Where:**
        - `supabase/migrations/20260411000000_add_custom_product_photos.sql:10-12` — `site.site_media` partial index
        - `supabase/migrations/20260414100000_site_media_design_pool.sql:70-76` — two unique indexes on `site.site_media`
        - `supabase/migrations/20260416000000_add_commission_grace_period.sql:33-41` — two indexes on `commerce.commission_ledger_entries` and `core.professionals`
        - `supabase/migrations/20260421010000_add_caption_to_site_media.sql:25-28` — covering index replace on `site.site_media`
        - `supabase/migrations/20260506000000_create_orders_schema.sql:603-611` — three BRIN indexes on analytics event tables
        - `supabase/migrations/20260420220000`, `20260428000000`, `20260510000000`, `20260513500000`, `20260513700000` — multiple commerce table indexes
    - **Affects:** These migrations have been applied to dev on an empty database (no lock contention). When applied to production with live data during pilot, a plain `CREATE INDEX` on `site_media`, `commission_ledger_entries`, or analytics tables acquires a `SHARE` lock, blocking all concurrent writes for the duration of the index build.
    - **Effort:** M (~2–4h) — pattern change for all future migrations; retroactive concern only applies to any un-applied migrations.
    - **What to do:**
        - For all future index creation on non-empty tables: use `CREATE INDEX CONCURRENTLY IF NOT EXISTS` outside a transaction block.
        - For `CREATE UNIQUE INDEX`: use `CREATE UNIQUE INDEX CONCURRENTLY` — requires running outside `BEGIN/COMMIT`.
        - Add a convention note to `supabase/migrations/CONVENTIONS.md` (or equivalent): all `CREATE INDEX` statements on tables that receive writes must use `CONCURRENTLY`.
        - For `ALTER TABLE ... ADD CONSTRAINT CHECK (...)` on hot tables: use `NOT VALID` + subsequent `VALIDATE CONSTRAINT` in a separate transaction to avoid a full-table scan under `ACCESS EXCLUSIVE` lock.
    - **Technical:** PostgreSQL's plain `CREATE INDEX` takes a `SHARE` lock that blocks all `INSERT`/`UPDATE`/`DELETE`. `CREATE INDEX CONCURRENTLY` uses a multi-phase build that takes only brief `SHARE UPDATE EXCLUSIVE` locks, allowing writes throughout. The migrations listed include two tables that are write-hot at pilot scale: `site_media` (every upload) and `commerce.commission_ledger_entries` (every Shopify webhook). The BRIN indexes on analytics event tables (`site_visits`, `link_clicks`, `cart_events`) are on append-heavy tables — blocking inserts during a BRIN build drops storefront analytics events.
    - **Plain English:** Adding an index to a busy database table without the "don't lock" flag is like reorganising a library by closing it completely while you reshelve books. Everyone who needs a book has to wait. The "CONCURRENTLY" keyword lets you reshelve while the library stays open. These migrations set a precedent — any future index added the same way will block the system for however long the build takes.
    - **Evidence:**
        ```sql
        -- 20260411000000_add_custom_product_photos.sql
        CREATE INDEX IF NOT EXISTS site_media_product_gid_idx
            ON site.site_media (site_id, product_gid)
            WHERE product_gid IS NOT NULL;

        -- 20260506000000_create_orders_schema.sql
        CREATE INDEX IF NOT EXISTS idx_site_visits_occurred_brin
            ON analytics.site_visits USING BRIN(occurred_at)
            WITH (pages_per_range = 64);

        -- 20260421000000_add_about_to_professionals.sql
        ALTER TABLE core.professionals
            ADD CONSTRAINT professionals_about_is_object
            CHECK (jsonb_typeof(about) = 'object');
        ```

---

## P3 — Nice to have

- [ ] **#SCALE-22** · P3 — StripeRowGenerator issues one Stripe API call per payout during export with no backoff
    - **Where:** app/Services/Stripe/StripeRowGenerator.php (yieldBrand, yieldAffiliate)
    - **Affects:** Commission export pipeline. At pilot scale with few payouts per tenant this is fine; at a mature tenant with thousands of payouts it saturates Stripe's per-second rate limit.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Add exponential backoff with jitter around the `paymentIntents->retrieve()` / `charges->retrieve()` calls using Stripe's `Stripe-Should-Retry` header semantics.
        - Consider caching PI/charge responses keyed by ID with a short TTL so re-exports don't re-fetch the same data.
    - **Technical:** Each `yield` in the generator calls `$this->stripe->paymentIntents->retrieve(...)`. At 50K payouts this is 50K sequential Stripe API calls. Stripe's rate limit (100/sec in production for most endpoints) will throttle long before then. Until tenant payout counts reach the hundreds, this path is safe — add the backoff before pilot scale becomes production scale.
    - **Plain English:** Checking on 50,000 individual payments by calling Stripe once per payment as fast as possible. Stripe will eventually put the call on hold. Adding a polite pause between calls avoids that.
    - **Evidence:**
        ```php
        private function yieldBrand(CommissionPayout $payout): \Generator
        {
            if (! $payout->payment_intent_id) { return; }
            $pi = $this->stripe->paymentIntents->retrieve($payout->payment_intent_id, [
                'expand' => ['latest_charge.refunds'],
            ]);
            // no throttle or backoff between calls
        }
        ```

- [ ] **#SCALE-23** · P3 — StripeTransactionFetcher issues up to 25 synchronous Stripe API calls per dashboard page view
    - **Where:** app/Services/Stripe/StripeTransactionFetcher.php (forBrand, forAffiliate)
    - **Affects:** Brand and affiliate transaction history pages. At low concurrent user counts this is fine; at peak dashboard usage by many brands concurrently, outbound Stripe call volume grows.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Cache individual `PaymentIntent` / `Charge` responses keyed by ID with a short TTL (30–60s) — financial state doesn't change second-to-second.
        - Alternatively, gate concurrent Stripe fetches with a per-account Redis semaphore to prevent duplicate in-flight fetches for the same PI.
    - **Technical:** `forBrand()` iterates up to 25 payouts and calls `$this->stripe->paymentIntents->retrieve(...)` for each. At 200 brands with active dashboards, a peak-hour burst can generate 5,000 Stripe API calls per minute across all users. Short-TTL caching of PI data (which changes only on state transitions) dramatically reduces outbound call volume.
    - **Plain English:** Every time someone browses their transaction history, the system phones Stripe 25 times. Ten people browsing simultaneously is 250 phone calls in a few seconds. Caching the answers for 30 seconds means the second person to open the page gets the answer immediately without calling Stripe again.
    - **Evidence:**
        ```php
        foreach ($payouts as $payout) {
            if (! $payout->payment_intent_id) { continue; }
            $pi = $this->stripe->paymentIntents->retrieve($payout->payment_intent_id, [
                'expand' => ['latest_charge.refunds'],
            ]);
        }
        ```

- [ ] **#SCALE-24** · P3 — Shopify rate-limit disregard in console commands (BackfillHasEnabledVariants, ReconcileSmartCollectionRules, MigrateMetafieldNamespace, ReconcileShopifyOrders)
    - **Where:** app/Console/Commands/BackfillHasEnabledVariantsCommand.php · app/Console/Commands/ReconcileSmartCollectionRulesCommand.php:117-146 · app/Console/Commands/MigrateMetafieldNamespaceCommand.php · app/Console/Commands/ReconcileShopifyOrders.php
    - **Affects:** Any of these commands run against a store with a large catalog or across 200 brands — tight loops issue GraphQL mutations without checking `X-Shopify-Shop-Api-Call-Limit`, causing 429 errors and incomplete runs.
    - **Effort:** S (~0.5–1h) each
    - **What to do:**
        - After each Shopify API call, inspect the `X-Shopify-Shop-Api-Call-Limit` header; if the remaining budget drops below 10%, `usleep(500000)` (500ms) before the next call.
        - For the reconcile and migration commands, add a small inter-brand delay (e.g. `usleep(200000)`) to shape the overall burst rate.
    - **Technical:** All four commands loop over products or integrations and issue Shopify API calls with no throttle. The backfill issues one GraphQL mutation per product; the smart collection reconcile issues 3–5 calls per brand × 200 brands. Without respecting the rate-limit bucket, a run against 200 brands will trigger 429s partway through and leave the reconciliation incomplete. Reading the bucket header and sleeping when it's low is the standard mitigation.
    - **Plain English:** These maintenance scripts talk to Shopify as fast as they can. When they're processing a large catalog or many stores at once, they hit Shopify's speed limit and get temporarily blocked — the run fails partway through. A brief pause between calls keeps everything within the speed limit.
    - **Evidence:**
        ```php
        // ReconcileSmartCollectionRulesCommand.php
        foreach ($integrations as $integration) {
            foreach (self::COLLECTIONS as $title => $desiredRules) {
                $result = $this->reconcileCollection(…);
            }
        }

        // ReconcileShopifyOrders.php
        do {
            $response = $shopifyClient->rest(…);
            $pageInfo = $this->extractNextPageInfo(…);
        } while ($pageInfo !== null);
        ```

- [ ] **#SCALE-25** · P3 — EmailSubscription saved hook dispatches SyncCustomerMarketingOptInJob on every save regardless of status change
    - **Where:** app/Models/Core/Notifications/EmailSubscription.php:98-112
    - **Affects:** Any bulk import or mass-update of email subscriptions — 10K rows saved = 10K job dispatches, most of them redundant because the status didn't change.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$subscription->isDirty('status') || $subscription->wasRecentlyCreated` as a guard before the dispatch — only push the job when the marketing opt-in state actually changes.
    - **Technical:** The `saved` observer fires on every `save()`, including rows where `status` is unchanged (e.g. a touch to `updated_at`). A bulk CSV import of 10K subscribers issues 10K Redis `lpush` commands even for rows that were already subscribed with the same status. Adding an `isDirty` guard reduces dispatches to only meaningful state transitions.
    - **Plain English:** Every time a subscriber record is saved — even if nothing changed — a background task is created to sync some cached data. Importing 10,000 subscribers creates 10,000 tasks. Most are pointless because the subscription status didn't change. A simple "only fire if something actually changed" guard eliminates the noise.
    - **Evidence:**
        ```php
        static::saved(function (self $subscription) {
            if ($subscription->list_key === 'marketing' && $subscription->professional_id && $subscription->email) {
                DB::afterCommit(function () use ($professionalId, $email, $isSubscribed) {
                    \App\Jobs\Notifications\SyncCustomerMarketingOptInJob::dispatch(
                        $professionalId, $email, $isSubscribed,
                    );
                });
            }
        });
        ```

- [ ] **#SCALE-26** · P3 — StaffShopifyEventReplayController uses dispatchSync, holding the request thread for a full order-processing job
    - **Where:** app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffShopifyEventReplayController.php:161-174
    - **Affects:** Staff replaying a Shopify webhook — the request thread holds a worker for Shopify round-trip + DB writes before responding.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Switch `ProcessShopifyOrderWebhookJob::dispatchSync(...)` to `ProcessShopifyOrderWebhookJob::dispatch(...)` and return `202 Accepted`.
        - The unique partial index on `shopify_event_id` already guarantees idempotency regardless of sync vs async dispatch — deduplication is unaffected.
    - **Technical:** `dispatchSync()` runs the job inline on the request thread, blocking the HTTP response until the Shopify fetch and DB writes complete. This is a staff-only endpoint rate-limited to 3 replays per event, so the blast radius is small. Switching to async `dispatch()` returns the response immediately, matches the architecture of the original webhook handler, and eliminates a needless per-request Shopify API call on the request thread.
    - **Plain English:** A staff member clicking "replay webhook" waits for the full order re-processing to finish before their browser responds. Moving it to a background job lets the screen respond instantly while the work happens behind the scenes.
    - **Evidence:**
        ```php
        ProcessShopifyOrderWebhookJob::dispatchSync(
            brandProfessionalId: (string) $professional->id,
            orderPayload: $orderPayload,
            shopifyEventId: $shopifyEventId,
            source: 'manual',
        );
        ```

- [ ] **#SCALE-27** · P3 — CSV subscriber exports have no row cap — PHP-FPM worker held for full streaming duration on large lists
    - **Where:** app/Http/Controllers/Api/Professional/Notifications/ProfessionalEmailSubscriptionController.php:162-174 · app/Http/Controllers/Api/Staff/StaffSite/StaffEmailSubscriberController.php:274-286
    - **Affects:** Any brand or staff export against a large subscriber list. `cursor()` keeps memory constant but holds a PHP-FPM worker for the full streaming duration (potentially 30–60s for 50K+ rows).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->take(config('partna.exports.subscribers.max_rows', 100000))` to the export query as a safety cap.
        - For lists above a configurable threshold (e.g. 25K), consider dispatching a background export job that emails a download link — matching the pattern already used by `CommissionExportService`.
    - **Technical:** Both `export()` implementations use `->cursor()` (correct for memory), but no `LIMIT` is applied. `cursor()` does not reduce the PHP-FPM worker occupancy — it holds the database cursor and streams row-by-row, keeping the worker busy for the full export duration. A brand with 50K subscribers blocks one of the limited worker slots for up to a minute.
    - **Plain English:** Streaming a large subscriber list is memory-efficient but still ties up a server slot for the whole download. One person downloading 50,000 rows for a minute takes a slot that other users need. A row cap or background export job prevents any single download from monopolising server capacity.
    - **Evidence:**
        ```php
        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'full_name', 'status', 'subscribed_at', 'unsubscribed_at']);
            foreach ($query->cursor() as $row) {   // ← no ->limit()
                fputcsv($out, [...]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        ```
