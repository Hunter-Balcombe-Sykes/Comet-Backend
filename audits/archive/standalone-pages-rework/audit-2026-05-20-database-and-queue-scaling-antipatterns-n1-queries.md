# Database & Queue Scaling Antipatterns Audit — 2026-05-20

**Branch:** development
**Lens:** database and queue scaling antipatterns N+1 queries cache stampede
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Cache/SiteCacheService.php
- app/Jobs/Cache/InvalidateConnectedAffiliateCachesJob.php
- app/Jobs/Shopify/ReconcileStuckShopifyIntegrationsJob.php
- app/Jobs/Shopify/BackfillBrandHasEnabledVariantsJob.php
- app/Jobs/Shopify/ProcessShopifyOrderWebhookJob.php
- app/Jobs/Stripe/VoidExpiredPayoutsJob.php
- app/Services/Cache/CacheKeyGenerator.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **#CACHE-6** · P2 — Brand site edits don't bust Hydrogen affiliate-page caches
    - **Where:** app/Services/Cache/SiteCacheService.php:`invalidateSite` (Master Pattern 15 block)
    - **Affects:** Affiliates viewing Hydrogen storefronts — after a brand edits design tokens, logo, or settings, affiliated storefronts serve stale data for up to 60 seconds
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a call inside the `$owner?->isBrand()` branch of `invalidateSite` that walks all active `BrandPartnerLink` rows for this brand and calls `forgetHydrogenAffiliate` for each connected affiliate site
        - Alternatively, extend the existing `InvalidateConnectedAffiliateCachesJob` to also clear `hydrogen:affiliate:v1:{brandId}:{slug}` keys (including `:stale` twins), and dispatch it per-affiliate in the same jittered loop already present in `invalidateSite`
        - Note: `InvalidateConnectedAffiliateCachesJob::handle()` currently only clears `site:payload:{subdomain}` — it must be extended or a parallel job dispatched
    - **Technical:** When a brand saves site settings or design, `invalidateSite` busts `hydrogen:brand-config` (keyed by `shop_domain`) via `forgetHydrogenBrandConfig`. It also dispatches `InvalidateConnectedAffiliateCachesJob` per affiliate, but that job only deletes `CacheKeyGenerator::publicSitePayload($subdomain)` — i.e. `site:payload:{subdomain}`. The Hydrogen affiliate cache (`hydrogen:affiliate:v1:{brandProfessionalId}:{affiliateHandle}`) is a separate key family populated by `HydrogenAffiliateController`, and is only busted via `forgetHydrogenAffiliate` — which fires when the *affiliate's own* site is changed, not when the connected brand changes. A brand logo update therefore leaves every affiliate's Hydrogen page serving the pre-edit payload until the 60s TTL expires.
    - **Plain English:** When a brand updates their storefront — say, changes their logo or accent colour — every affiliate who promotes that brand keeps showing visitors the old design for up to a minute. It's like a shop repainting their storefront but all the leaflets printed by their sales reps still showing the old colour. The existing code already busts the brand's own page and the affiliate's "profile page" cache, but it misses a third cache that specifically powers the affiliate-branded storefront view.
    - **Evidence:**
        ```php
        // invalidateSite — brand branch busts brand-config but never hydrogen:affiliate:v1 keys:
        if ($owner?->isBrand()) {
            $this->forgetHydrogenBrandConfig($professionalId);
        } else {
            $this->forgetHydrogenAffiliate((string) $site->id);
        }

        // InvalidateConnectedAffiliateCachesJob::handle() — only clears publicSitePayload:
        public function handle(): void
        {
            $key = CacheKeyGenerator::publicSitePayload($this->subdomain);
            Cache::deleteMultiple([$key, $key.':stale']);
        }
        ```

- [ ] **#QUEUE-3** · P2 — ReconcileStuckShopifyIntegrationsJob sequential HTTP calls may exhaust its 600s timeout at scale
    - **Where:** app/Jobs/Shopify/ReconcileStuckShopifyIntegrationsJob.php:`handle` (foreach $candidates loop)
    - **Affects:** Daily stuck-integration auto-healing — integrations processed at the tail of a 200-item batch that hits repeated timeouts may be skipped, leaving brands permanently stuck with revoked tokens
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Record `$start = microtime(true)` before the loop and add a guard at the top of each iteration: if `microtime(true) - $start > ($this->timeout * 0.8)`, break early and log a structured warning with the count of remaining unprocessed candidates
        - Log the early-break count so ops can detect whether the 200-candidate batch limit needs reducing against real-world Shopify latency
    - **Technical:** The job loops over up to 200 integrations sequentially. Each `validateAccessToken` call makes one synchronous HTTP GET with a 10-second timeout. In the pathological case — 200 integrations all timing out — the loop needs 2,000 seconds, but `$timeout = 600`. At a realistic 10% timeout rate (20 integrations × 10s = 200s) plus ~200ms per healthy response (180 × 0.2s = 36s), the total approaches 236s — well within budget today. But as the connected brand count grows toward 200 brands with stuck integrations in a single daily sweep, the margin shrinks. The job has `$tries = 1` with no backoff, so a timeout means those integrations wait until the next daily run. An 80% wall-clock guard lets the job complete cleanly and leave unprocessed candidates for the next scheduled run rather than being killed mid-loop.
    - **Plain English:** This job checks up to 200 disconnected Shopify stores one at a time, calling each store's API and waiting up to 10 seconds for a response before moving on. If enough stores are slow or unresponsive, the job can run out of its 10-minute time limit mid-way through the list — and the stores it didn't reach won't be checked again until the next day. Adding a time-check inside the loop lets the job stop gracefully when it's running short, instead of being cut off mid-batch.
    - **Evidence:**
        ```php
        public int $tries = 1;
        public int $timeout = 600;
        private const BATCH_LIMIT = 200;

        foreach ($candidates as $integration) {
            $check = $this->validateAccessToken($integration);
            // validateAccessToken does a synchronous Http::timeout(10)->get($url)
            // one call per integration, up to 200 calls, no wall-clock guard
        }
        ```

- [ ] **#API-2** · P2 — BackfillBrandHasEnabledVariantsJob makes one Shopify GraphQL call per product (N+1 API writes) and will timeout for large catalogs
    - **Where:** app/Jobs/Shopify/BackfillBrandHasEnabledVariantsJob.php:`handle` (foreach $catalog loop)
    - **Affects:** Brands with large catalogs (>500 products) during initial Shopify OAuth install — backfill times out and only partially seeds `has_enabled_variants`, leaving Active Products smart collection resolving incorrectly from the start
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add progress checkpointing: after each successful write, merge the product GID into `provider_metadata.has_enabled_variants_backfill_cursor`; on retry, skip products already in the cursor so the job resumes rather than restarts
        - Longer-term: batch `metafieldsSet` calls — Shopify's mutation accepts an array of up to 25 metafields in a single round-trip; group products by value (true/false) and chunk into batches of 25, each with its own `ownerId`
        - Update `$timeout` from 120s to 300s and `$tries` from 3 to 5 with appropriate backoff to cover the retry-resume path until batching is implemented
    - **Technical:** `fetchBrandCatalog` returns all products for a brand, then the job iterates every product calling `$catalogService->writeHasEnabledVariants($integration, $gid, $hasEnabled)` — one Shopify GraphQL `metafieldsSet` round-trip per product. The job comment acknowledges "each write is a separate GraphQL call" and budgets 120s for "~500 products comfortably." At 500ms per write (realistic for Admin API under quota), 500 products = 250s — already over 120s. Shopify GraphQL's `metafieldsSet` mutation accepts an array input, so 25 products per call would reduce 500 calls to 20. Until batching is built, cursor checkpointing in `provider_metadata` allows the 3-attempt retry budget to make genuine forward progress rather than replaying from zero each time.
    - **Plain English:** When a brand first connects their Shopify store, this job visits every product and flips a switch on it — one trip to Shopify per product. For a brand with 600 products that's 600 separate round-trips. The job gives itself 2 minutes, which covers maybe 500 products at best, so bigger catalogues will always time out. Rather than retrying from scratch each time, the job should remember how far it got — like a bookmark — so each retry picks up where the previous one left off. Batching multiple products into one Shopify call (like loading a truck instead of making one car trip per box) would also help.
    - **Evidence:**
        ```php
        // Timeout comment acknowledges ~500 products as the ceiling:
        public int $timeout = 120; // "120s covers ~500 products comfortably"

        foreach ($catalog as $product) {
            $gid = $product['gid'] ?? '';
            if ($gid === '') { continue; }
            // ...
            try {
                $result = $catalogService->writeHasEnabledVariants($integration, $gid, $hasEnabled);
                // one GraphQL metafieldsSet call per product
            } catch (\Throwable $e) {
                $failures++;
            }
        }
        ```

---

## P3 — Nice to have

- [ ] **#DB-14** · P3 — VoidExpiredPayoutsJob::fireGraceWarnings runs three near-identical DB queries instead of one
    - **Where:** app/Jobs/Stripe/VoidExpiredPayoutsJob.php:`fireGraceWarnings` (foreach [30, 7, 1] loop)
    - **Affects:** Daily grace-warning sweep — triples query load on `commission_payouts` for no correctness benefit on a once-daily cron
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the three `whereBetween` queries with a single query using `where('void_at', '<=', now()->addDays(30))->where('void_at', '>=', now()->startOfDay())`, then bucket candidates by `floor(now()->diffInDays($payout->void_at))` in PHP
        - Apply the existing per-tier `grace_notifications_sent` gate and the `$isBrandSide` routing in the same post-query loop; the dedupe key on `NotificationPublisher::publish` remains the safety net
    - **Technical:** `fireGraceWarnings` iterates `[30, 7, 1]` and for each value constructs a `CommissionPayout` query with matching base conditions (`status = 'pending'`, the `failure_code`/`whereDoesntHave` filter) differing only in the `whereBetween` date window. These three index scans on `void_at` can be collapsed to one. The partial index on `void_at` keeps each scan fast, but the reduce-from-three-to-one is a clean improvement for a once-daily job with predictable call frequency.
    - **Plain English:** This job checks what commissions are due to expire in 30, 7, and 1 day — but it asks the database three separate times, once for each deadline. It could ask once ("show me everything expiring within 30 days") and then sort the results by urgency at the application level. On a daily cron this doesn't hurt anyone, but it's needless work.
    - **Evidence:**
        ```php
        foreach ([30, 7, 1] as $daysOut) {
            $target = now()->addDays($daysOut);
            $windowStart = $target->copy()->startOfDay();
            $windowEnd = $target->copy()->endOfDay();

            $candidates = CommissionPayout::query()
                ->where('status', 'pending')
                ->whereBetween('void_at', [$windowStart, $windowEnd])
                ->where(function ($q) use ($brandSideCodes) {
                    $q->whereIn('failure_code', $brandSideCodes)
                        ->orWhereDoesntHave('affiliateProfessional', fn ($a) => $a->where('stripe_connect_status', 'active'));
                })
                ->get()
                // ...filter and publish per candidate
        }
        ```

- [ ] **#DB-15** · P3 — ProcessShopifyOrderWebhookJob loads the brand Professional twice per orders/paid event
    - **Where:** app/Jobs/Shopify/ProcessShopifyOrderWebhookJob.php:`process` + `dispatchInstantPayoutIfEligible`
    - **Affects:** Every orders/paid webhook on a brand with `payout_hold_days === 0` — one redundant primary-key lookup on `core.professionals` per processed order
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Pass the already-loaded `$brandProfessional` model from `process()` into `dispatchInstantPayoutIfEligible()` as a parameter, then change the second load to read columns directly from the passed model rather than re-querying; the first load already fetches the full row so all columns (`stripe_connect_account_id`, `stripe_connect_status`, `stripe_payment_method_id`) are already present
    - **Technical:** `process()` loads the brand professional with `Professional::query()->whereKey($this->brandProfessionalId)->first()` to verify `isBrand()`. Later, `dispatchInstantPayoutIfEligible()` — called on the same code path — reloads the same row with `Professional::query()->whereKey($this->brandProfessionalId)->first([...])` to read Stripe columns. The first load fetches `SELECT *` so those Stripe columns are already available on the model. Impact is one extra PK lookup per order on the instant-payout path only (`payout_hold_days === 0`), which makes this cosmetic rather than material. However it's the hottest write path in the system and the fix is a one-line signature change.
    - **Plain English:** When processing a new order, the code asks the database "who is this brand?" twice in a row — once to check they're actually a brand, and a second time a few lines later to check their payment settings. The first lookup already pulled all that information; it just needs to be handed forward instead of discarded and re-fetched.
    - **Evidence:**
        ```php
        // First load — process() method:
        $brandProfessional = Professional::query()->whereKey($this->brandProfessionalId)->first();
        if (! $brandProfessional || ! $brandProfessional->isBrand()) { ... }

        // Second load — dispatchInstantPayoutIfEligible(), called later in same method:
        $brand = Professional::query()
            ->whereKey($this->brandProfessionalId)
            ->first([
                'id', 'stripe_connect_account_id', 'stripe_connect_status', 'stripe_payment_method_id',
            ]);
        ```
