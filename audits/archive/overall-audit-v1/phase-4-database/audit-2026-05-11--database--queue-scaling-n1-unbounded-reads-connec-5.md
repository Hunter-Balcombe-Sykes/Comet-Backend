`★ Insight ─────────────────────────────────────`
Three key patterns caught during verification:
1. **False SCALE-5 (queue routing):** All 5 install jobs call `$this->onQueue('integrations')` in their `__construct()` — invisible at the dispatch site. DeepSeek saw the undecorated `$jobClass::dispatch($integrationId)` and assumed `default`.
2. **False SCALE-7 (N+1):** `SiteMedia::variantUrls()` reads from `$this->mediaVariants` (the already-loaded Eloquent relation). DeepSeek confused this with `ImageVariantService::variantUrls(string $imageId)` which does issue a fresh query.
3. **Real SCALE-8 reframe:** `ProcessVideoVariantsJob` IS on the correct `redis_video`/`videos` queue — the dead issue. The live issue is the class has no `$timeout` property, leaving 11-minute FFmpeg encodes at the mercy of whatever `--timeout` the Horizon supervisor happens to configure.
`─────────────────────────────────────────────────`

# Database & Queue Scaling Audit — 2026-05-11

**Branch:** development
**Lens:** Database & queue scaling: N+1, unbounded reads, connection scoping, queue shape, vendor budgets, migration safety, backpressure
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Shopify/BrandDesignImporter.php
- app/Services/Shopify/BrandSignupService.php
- app/Services/Shopify/Client/ShopifyAdminClient.php
- app/Services/Shopify/Client/ShopifyBudgetTracker.php
- app/Services/Shopify/Client/ShopifyBulkOperationLock.php
- app/Services/Shopify/Client/ShopifyCostTracker.php
- app/Services/Shopify/Client/ShopifyMetrics.php
- app/Services/Shopify/ShopifyDataResyncService.php
- app/Services/Shopify/ShopifyTeardownService.php
- app/Services/Stripe/CommissionPayoutRefundService.php
- app/Services/Stripe/CommissionPayoutService.php
- app/Services/Stripe/CommissionVoidService.php
- app/Services/Stripe/StripeBillingService.php
- app/Services/Stripe/StripeConnectService.php
- app/Services/Cloudflare/CloudflareDnsService.php
- app/Services/Cloudflare/CloudflareKvService.php
- app/Services/Hydrogen/HydrogenDeploymentService.php
- app/Services/Media/BrandDesignMediaService.php
- app/Services/Media/ImageVariantService.php
- app/Services/Media/VideoVariantService.php
- app/Services/Streaming/KickApiClient.php
- app/Services/Streaming/LiveStatusPoller.php
- app/Services/Streaming/StreamingTokenManager.php
- app/Services/Streaming/TwitchApiClient.php
- app/Jobs/ProcessVideoVariantsJob.php *(verified via Read)*
- app/Jobs/Shopify/SyncShopifyBrandDesignJob.php *(verified via Read)*
- app/Jobs/Shopify/RegisterShopifyWebhooksJob.php *(verified via Read)*
- app/Models/Core/Site/SiteMedia.php *(verified via Read)*

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#SCALE-1** · P1 — Storefront API calls bypass Admin rate-limit machinery in BrandDesignImporter
    - **Where:** app/Services/Shopify/BrandDesignImporter.php — `fetchBrand()` method
    - **Affects:** Every `SyncShopifyBrandDesignJob` execution for every brand. Category: outbound vendor rate-limit budgets (§5). At 200 brands reinstalling (e.g. after a version update), 200 raw Storefront API calls fire simultaneously with no pre-acquisition, no throttle-status reconciliation, and no `THROTTLED` retry path. A throttled call returns an error array that the code treats as an empty brand (`emptyBrand()`) — logos, colours, and slogan are silently dropped from the installed design, and no retry is dispatched.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extend `ShopifyAdminClient` (or a sibling `ShopifyStorefrontClient`) with a `storefront()` method that accepts the Storefront access token header and wraps the existing budget/cost/throttle plumbing.
        - At minimum, check for a `429` or `THROTTLED` error code in the Storefront response and throw a `ShopifyThrottledException` so the queue's `backoff()` mechanism handles the retry (all 5 install jobs already configure `backoff: [10, 30, 60]`).
        - Register the Storefront query hash with `ShopifyCostTracker` so future syncs benefit from cost learning (Storefront and Admin points systems are different — track separately).
    - **Technical:** `fetchBrand()` issues the `STOREFRONT_BRAND_QUERY` directly via `Http::withHeaders()->post()`, bypassing `ShopifyAdminClient::graphql()` entirely. The Admin client calls `preAcquireBudget()` (Lua atomic bucket), `reconcileFromResponse()` (overwrites local state with Shopify's authoritative `throttleStatus`), and falls back to `ShopifyThrottledException` after `max_inprocess_retries`. None of this applies to the Storefront path. Shopify's Storefront API uses its own rate-limit bucket (separate from the Admin API bucket) and signals throttling via GraphQL-level errors — the same `THROTTLED` extension code. The current code swallows any `errors` array from the Storefront response and returns `emptyBrand()`, which permanently loses design data for the affected brand until a manual re-sync.
    - **Plain English:** Every Shopify call in this codebase goes through a traffic-control booth that knows how many calls each store is allowed and slows down when Shopify says "too fast." The brand-design import call bypasses that booth entirely and goes straight to the highway. If 200 stores reinstall at the same time, we send 200 calls with no coordination — Shopify may start rejecting some of them, and instead of retrying, we silently record "no logo, no colours" for those brands.
    - **Evidence:**
        ```php
        private function fetchBrand(string $shopDomain, string $apiVersion, ?string $storefrontToken): array
        {
            // ...
            try {
                $response = Http::withHeaders([
                    'X-Shopify-Storefront-Access-Token' => $storefrontToken,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ])->post("https://{$shopDomain}/api/{$apiVersion}/graphql.json", [
                    'query' => self::STOREFRONT_BRAND_QUERY,
                ]);
            } catch (\Throwable $e) {
                // ...
                return $this->emptyBrand();
            }

            $errors = $response->json('errors', []);
            if (! empty($errors)) {
                // ...
                return $this->emptyBrand();  // THROTTLED silently dropped here
            }
        ```

- [ ] **#SCALE-2** · P1 — Stripe refund API call inside `DB::transaction` holds a PostgreSQL row lock during external I/O
    - **Where:** app/Services/Stripe/StripeConnectService.php — `creditWalletFromCheckoutSession()`
    - **Affects:** Brands whose top-up currency doesn't match their wallet currency. Category: connection pool & transaction scoping (§3). The `lockForUpdate()` row lock on the `professionals` table is held across the full Stripe HTTP round-trip (typically 200–500ms; spikes to seconds during Stripe degradation). Any concurrent wallet operation for the same brand (another top-up confirmation, payout debit, wallet credit) queues behind this lock for the duration of the external call.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Read the wallet currency and compute `walletCurrency !== $currency` **inside** the transaction (to ensure the row is fresh), then exit the transaction immediately.
        - Issue `$this->stripe->refunds->create()` **outside** the transaction after the lock has been released.
        - If the refund fails post-release, log `critical` and set `needs_manual_refund = true` on the `wallet_movements` record (or surface via a new flag) so ops can action it — the same pattern already used elsewhere in `CommissionPayoutService`.
    - **Technical:** The `DB::transaction()` wraps `Professional::lockForUpdate()` followed conditionally by `$this->stripe->refunds->create()`. PostgreSQL's row lock acquired by `lockForUpdate()` is held until the transaction commits or rolls back. The Stripe SDK performs a synchronous HTTP call with no timeout configured here — under Stripe API degradation events (which Stripe documents as occasional), this lock can persist for several seconds. At the scale target (200 brands × concurrent top-ups during a promo event), lock queuing on the `professionals` table can cascade into application-level connection pool exhaustion.
    - **Plain English:** When a brand tops up their wallet with the wrong currency, we need to refund them. Right now we've frozen their account record in the database, then pick up the phone to call Stripe to arrange the refund — and we keep the account frozen the whole time we're on hold. If Stripe is slow that day, everything else trying to touch that brand's account is queued up waiting. The fix is to put the phone down first, then make the call.
    - **Evidence:**
        ```php
        DB::transaction(function () use ($professionalId, $session, $sessionId, $amountCents, $currency, $stripeEventId, $actorOverride) {
            $brand = Professional::query()
                ->where('id', $professionalId)
                ->lockForUpdate()
                ->first();
            // ...
            $walletCurrency = strtoupper($brand->stripe_manual_balance_currency ?? 'AUD');
            if ($walletCurrency !== $currency) {
                // ...
                if (! empty($session->payment_intent)) {
                    try {
                        $this->stripe->refunds->create(
                            [
                                'payment_intent' => is_string($session->payment_intent)
                                    ? $session->payment_intent
                                    : ($session->payment_intent->id ?? null),
                                'reason' => 'requested_by_customer',
                                'metadata' => [
                                    'sidest_reason' => 'currency_mismatch',
                                    'professional_id' => $professionalId,
                                ],
                            ],
                            ['idempotency_key' => 'currency_mismatch_refund:'.$sessionId],
                        );
                    } catch (ApiErrorException $e) {
        ```

## P2 — Should fix

- [ ] **#SCALE-3** · P2 — Shopify Admin client blocks Horizon workers with `usleep` during throttle and budget-wait paths
    - **Where:** app/Services/Shopify/Client/ShopifyAdminClient.php — `graphql()` retry loop + `preAcquireBudget()`
    - **Affects:** Every queued Shopify job during throttling events. Category: connection pool & transaction scoping (§3) + outbound vendor rate-limit budgets (§5). During a THROTTLED response, the worker sleeps up to `max_wait_ms` (default 5000ms) per retry for up to `max_inprocess_retries` (default 3) attempts, tying the PHP process for ~15s. `preAcquireBudget()` adds a second `usleep()` on every budget-starved call. At the scale target (~3K Shopify webhooks/day with occasional burst from bulk product updates), several concurrent throttled jobs can hold the same worker pool for dozens of seconds.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - On the first `THROTTLED` response, throw `ShopifyThrottledException` immediately and let the job's `backoff()` mechanism handle the retry delay across workers. All production Shopify jobs already define `backoff: [10, 30, 60]` — the infrastructure is in place.
        - Keep at most one immediate in-process retry (no sleep) for single-packet transient blips where the bucket refills faster than a queue round-trip.
        - In `preAcquireBudget()`, replace the second `tryAcquire()` + `usleep()` sequence with a no-op after the first wait: accept the deficit and let the THROTTLED path handle over-commitment, which it already does.
    - **Technical:** The current design intentionally keeps `max_inprocess_retries` low (default 3) and `max_wait_ms` capped (5000ms) to bound the blocking window. The code comment acknowledges this tradeoff. At pre-launch scale (< 50 brands) this is acceptable. At the 200-brand target with 50 affiliates each, simultaneous burst events (a brand deleting 500 products triggering 500 `THROTTLED` responses in parallel jobs) can consume the entire `integrations` queue worker pool for 15s per burst. The fix trades marginal retry latency for worker availability.
    - **Plain English:** When Shopify tells us to slow down, instead of putting a job back in the queue and moving on, the worker just sits there waiting — for up to 15 seconds per job, per Shopify slowdown. If 20 workers all hit Shopify at the same moment, we could lose 5 minutes of combined worker time doing nothing. The queue already has a "try again later" feature built in; we're just not using it for this case.
    - **Evidence:**
        ```php
        // In graphql() THROTTLED retry path:
        usleep($wait * 1000);
        $attempt++;
        continue;

        // In preAcquireBudget():
        if (! $result['acquired']) {
            $wait = min($result['wait_ms'], $maxWait);
            $this->metrics->budgetWait($shopDomain, $wait, $estimated);
            usleep($wait * 1000);
            // Retry once after the wait — if still short, proceed anyway and
            // let the THROTTLED retry path handle it.
            $this->budget->tryAcquire($shopDomain, $estimated, $max, $rate);
        }
        ```

- [ ] **#SCALE-4** · P2 — No per-brand debounce on Hydrogen deployment dispatch
    - **Where:** app/Services/Hydrogen/HydrogenDeploymentService.php — `dispatchDeployment()`
    - **Affects:** Brands completing the Oxygen setup wizard. Category: outbound vendor rate-limit budgets (§5). Consecutive `dispatchDeployment()` calls within the same wizard session (e.g., frontend auto-save on every field change) fire duplicate GitHub Actions workflow dispatches. At 200 brands completing setup within a short window, the GitHub API rate limit (5,000 requests/hr per token, shared across all brands) becomes the constraint. Duplicate dispatches waste ~2–3 calls per duplicate, and GitHub will queue or silently drop workflow runs beyond its concurrency limits.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - At the top of `dispatchDeployment()`, check for a Redis key `hydrogen:deploy:debounce:{professionalId}` with a 60s TTL. Return early if the key exists.
        - Set the key (Redis `SET NX EX 60`) before issuing the GitHub API call. A 60s window collapses any wizard auto-saves into a single deploy per minute per brand.
        - Use `Log::info` (not `Log::warning`) on the debounced path to avoid alarming operators with what is normal fast-save behavior.
    - **Technical:** `dispatchDeployment()` proceeds directly from the token-empty guard to `Http::withToken($token)->post($url, [...])` with no guard against rapid re-invocation. The method is called on every credential save in the setup wizard — which may happen multiple times per session if the frontend auto-saves or the user corrects a typo. The GitHub rate limit is shared across all workflow dispatch calls made by the same token, so duplicate dispatches from one brand reduce the budget available to others. A Redis `SET NX` with a 60s TTL is the standard debounce pattern used elsewhere in the codebase (e.g., `ShopifyBulkOperationLock`).
    - **Plain English:** Every time a brand hits Save in the setup wizard — even if it's accidental or the browser auto-saves — we tell GitHub to kick off a full deployment. If they save three times in a minute, GitHub gets three identical deployment requests. Multiply by 200 brands all finishing setup at once, and we're wasting hundreds of GitHub requests on duplicates. A 60-second "one call per brand per minute" rule costs almost nothing to add and eliminates the churn.
    - **Evidence:**
        ```php
        public function dispatchDeployment(string $professionalId): void
        {
            $token = config('partna.hydrogen.github_token');

            if (empty($token)) {
                Log::info('HydrogenDeployment: skipping dispatch — PARTNA_HYDROGEN_GITHUB_TOKEN not set (legacy fallback: SIDEST_HYDROGEN_GITHUB_TOKEN).', [
                    'professional_id' => $professionalId,
                ]);

                return;
            }

            $repo = config('partna.hydrogen.github_repo', 'hunterbalcombesykes/sidest-storefront');
            $ref = config('partna.hydrogen.github_ref', 'main');
            $url = "https://api.github.com/repos/{$repo}/actions/workflows/oxygen-deployment.yml/dispatches";

            try {
                $response = Http::withToken($token)
                    ->withHeaders([
                        'Accept' => 'application/vnd.github+json',
                        'X-GitHub-Api-Version' => '2022-11-28',
                    ])
                    ->post($url, [
                        'ref' => $ref,
                        'inputs' => [
                            'professional_id' => $professionalId,
                        ],
                    ]);
        ```

- [ ] **#SCALE-5** · P2 — `ProcessVideoVariantsJob` has no `$timeout` property — 11-minute FFmpeg encodes depend on supervisor configuration
    - **Where:** app/Jobs/ProcessVideoVariantsJob.php + app/Services/Media/VideoVariantService.php:155
    - **Affects:** Any video upload when the feature flag is enabled. Category: queue/Horizon shape (§4). `VideoVariantService::processVariants()` calculates an encoding timeout up to 660s for 300-second videos (the documented maximum). `ProcessVideoVariantsJob` has no `public int $timeout` property. Without this property, Horizon uses the supervisor's `--timeout` value, which defaults to 60s if not explicitly overridden. A Horizon supervisor configured without `--timeout=720` (or higher) will kill video encoding jobs mid-transcode, leaving `SiteMedia` rows stuck in `processing` state.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `public int $timeout = 720;` to `ProcessVideoVariantsJob` (720s = 660s max encoding + 60s upload buffer). Horizon uses the job-level `$timeout` when set, which makes the requirement explicit and independent of supervisor configuration.
        - Ensure the `redis_video` supervisor in `config/horizon.php` also sets `timeout` ≥ 720 so the two are consistent (the job-level property governs; the supervisor is a floor).
        - The job docstring already says `--timeout=3600` — a job-class property is the canonical way to encode this contract.
    - **Technical:** `$encodingTimeout = max(120, (int) round($durationMs / 1000) * 2 + 60)` yields 660s for a 300s video. `Process::setTimeout()` controls the FFmpeg subprocess, not the PHP process itself — the PHP process stays alive and synchronously blocks for the duration. Without `$timeout` on the job class, Laravel's default 60-second supervisor timeout (if the horizon.php supervisor is not explicitly tuned) will kill the PHP process mid-FFmpeg, orphaning temp files and leaving `SiteMedia.processing_state = 'processing'` forever. The feature flag (`PARTNA_VIDEO_UPLOADS_ENABLED`) currently limits blast radius, but the timeout contract should be codified before the flag is enabled in production.
    - **Plain English:** Processing a 5-minute video takes up to 11 minutes of computer time. If we don't tell our job queue "this job is allowed to run for 12 minutes," the queue manager might cancel it after just 1 minute — like a kitchen timer going off before the roast is done. The roast (video) is ruined, and the system's records still say it's in the oven. Adding one line to the job class makes the allowed cooking time explicit, so it doesn't depend on whoever set up the queue to have read the right documentation.
    - **Evidence:**
        ```php
        // ProcessVideoVariantsJob — no $timeout property:
        class ProcessVideoVariantsJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 2;

            public int $backoff = 60;

            // $timeout not declared — Horizon supervisor --timeout governs.
            // Docstring recommends --timeout=3600 but job class enforces nothing.

            public function __construct(
                public readonly string $mediaId,
                public readonly string $originalPath,
                public readonly string $basePath,
            ) {
                $this->onConnection((string) config('partna.video_queue.connection', 'redis_video'));
                $this->onQueue((string) config('partna.video_queue.name', 'videos'));
            }
        ```
        ```php
        // VideoVariantService::processVariants() — max encoding window:
        $encodingTimeout = max(120, (int) round($durationMs / 1000) * 2 + 60);
        ```

## P3 — Nice to have

- [ ] **#SCALE-6** · P3 — `ShopifyMetrics::graphqlError` logs the full unbounded errors array
    - **Where:** app/Services/Shopify/Client/ShopifyMetrics.php — `graphqlError()`
    - **Affects:** Nightwatch / log ingestion pipeline during Shopify API incidents. Category: memory pressure (§2). Each GraphQL error response can include an `extensions` block with query snippets, request IDs, and diagnostic context. At the scale target (~3K Shopify webhook deliveries/day), a prolonged Shopify API degradation event generates a high-cardinality log stream with full error payloads per failed call, potentially bloating log indices and approaching Nightwatch per-event payload limits.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Truncate `$errors` to the first 3 entries before logging and strip `extensions` sub-keys.
        - The full `$errors` array is already passed to `ShopifyGraphQLException` (thrown on line immediately after this call), which Nightwatch captures separately as an exception with a stack trace. The log entry only needs enough context to correlate `shop_domain` + `query_hash` with the exception.
    - **Technical:** `Log::error('shopify.client.graphql_error', ['errors' => $errors])` passes the raw `array<int, array<string, mixed>>` from the GraphQL response body. A single GraphQL error object can contain `message`, `locations`, `path`, and `extensions.code` + `extensions.requestId` + `extensions.cost`. At high error rates (API incident at peak webhook volume), these payloads compound. The `ShopifyGraphQLException` already carries the full context for post-incident analysis; the structured log is redundant at full fidelity.
    - **Plain English:** When a Shopify call fails, we write the complete failure report into the monitoring system's log stream — every detail Shopify sends back. During a Shopify outage with thousands of failing calls, this is like photocopying every error report and faxing the whole stack to the filing room. The monitoring system already captures the full report separately as an "exception" entry. The log just needs the short summary (which store, which query) to link them together.
    - **Evidence:**
        ```php
        public function graphqlError(string $shopDomain, string $queryHash, array $errors): void
        {
            Log::error('shopify.client.graphql_error', [
                'shop_domain' => $shopDomain,
                'query_hash' => $queryHash,
                'errors' => $errors,  // full unbounded array from Shopify response
            ]);
        }
        ```

`★ Insight ─────────────────────────────────────`
Two structural patterns worth noting across this codebase:
1. **The Admin API rate-limiting machinery is excellent** (`ShopifyBudgetTracker` Lua atomic bucket + `ShopifyCostTracker` sliding-window cost learning) — the Storefront bypass (SCALE-1) is a gap in an otherwise well-designed system, not a sign of careless vendor handling.
2. **Job configuration discipline is strong** — every Shopify job has `$tries`, `$timeout`, `backoff()`, and `ShouldBeUnique` with `uniqueFor`. The video job timeout gap (SCALE-5) is the only structural miss across all examined job classes.
`─────────────────────────────────────────────────`
