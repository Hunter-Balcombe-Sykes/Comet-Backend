
<!-- ═══ CHUNK: infra ═══ -->


<!-- ═══ SUB-CHUNK: s1 (app/Services/Cache app/Services/FeatureFlags app/Listeners app/Events) ═══ -->

- [ ] **#CACHE-1** · P1 — `analyticsSummary` cache key lacks version token embedding — survives commerce-write invalidation
    - **Where:** app/Services/Cache/CacheKeyGenerator.php:analyticsSummary() line ~L145
    - **Affects:** Professional dashboard analytics summary views; stale commerce data (revenue, commissions, order counts) served for up to 24h after any commerce webhook write.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Embed `analyticsSummaryVersion` token in `analyticsSummary` key, matching the pattern already used by `affiliateCommerceAnalytics` and `brandCommerceAnalytics`.
        - Or add explicit `Cache::forget` per date-range variant inside `AnalyticsCacheService::invalidateAnalytics()` (less scalable — requires key enumeration).
    - **Technical:** `CacheKeyGenerator::analyticsSummary()` returns a static key `analytics:summary:q3:{pro}:{start}:{end}` with a hardcoded `q3` schema marker. Unlike `affiliateCommerceAnalytics` and `brandCommerceAnalytics` (which read `analyticsSummaryVersion` from Redis and embed `$version` into the key), the summary key never changes on a version bump. `AnalyticsCacheService::invalidateAnalytics()` calls `bumpAnalyticsVersion()` which atomically busts every version-embedded key — but `analyticsSummary` keys are invisible to that mechanism. Result: after a Shopify order webhook fires, the professional's dashboard analytics summary continues serving the pre-order numbers until the key's natural TTL expires (up to 24h per `CacheLockService` stale extension).
    - **Plain English:** Imagine a restaurant's daily sales whiteboard. When a new order comes in, the manager updates the board. But this particular whiteboard has one section that's locked in a glass case — the manager only updates it once a day regardless of how many orders come in. The `analyticsSummary` cache is that glass case. The fix is to link it to the same "update the board" trigger that everything else uses.
    - **Evidence:**
        ```php
        // CacheKeyGenerator.php — static q3 marker, no dynamic version token
        public static function analyticsSummary(string $professionalId, string $startDate, string $endDate): string
        {
            // q3: commerce fields now read from commerce.orders instead of commission_movements (Phase 3)
            return "analytics:summary:q3:{$professionalId}:{$startDate}:{$endDate}";
        }
        
        // Compare with brandCommerceAnalytics — embeds dynamic version token
        public static function brandCommerceAnalytics(string $professionalId, string $from, string $to): string
        {
            $version = \Illuminate\Support\Facades\Cache::get(self::analyticsSummaryVersion($professionalId), 0);
            return "analytics:commerce:brand:v7:{$professionalId}:{$version}:{$from}:{$to}";
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CACHE-2** · P2 — `bookingAnalytics` cache key lacks version token embedding — no atomic invalidation path
    - **Where:** app/Services/Cache/CacheKeyGenerator.php:bookingAnalytics() line ~L160
    - **Affects:** Professional booking analytics dashboard views; stale booking aggregates (counts, revenue) served after booking webhook writes until TTL expiry.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Embed `analyticsSummaryVersion` token in the `bookingAnalytics` key, matching the version-embedding pattern on `affiliateCommerceAnalytics`.
        - Verify that the booking write path (observer / rebuild job) also bumps the version token or has its own invalidation — if not, add a `bumpAnalyticsVersion` call on booking writes.
    - **Technical:** The `bookingAnalytics` key returns `analytics:booking:{pro}:{from}:{to}:{groupBy}` with no dynamic version component. If `BookingAnalyticsAggregateService` or a `RebuildBooking*AggregatesJob` exists (suggested by the key shape and the scope notes), any version-token bump from `AnalyticsCacheService` is a no-op for these keys. They would require per-key `Cache::forget` enumeration across all active date-range/groupBy combinations on every booking write — the exact antipattern the commerce rebuild eliminated. Without seeing the booking job code, the conservative fix is to embed the version token now so invalidation is atomic regardless of what the rebuild jobs do.
    - **Plain English:** Same glass-case whiteboard problem, but for booking data. When someone books an appointment, the booking analytics chart should refresh. It doesn't — it waits for its timer to run out. The fix is the same: connect it to the "update now" button that everything else uses.
    - **Evidence:**
        ```php
        // CacheKeyGenerator.php — no version token in booking analytics key
        public static function bookingAnalytics(string $professionalId, string $from, string $to, string $groupBy): string
        {
            return "analytics:booking:{$professionalId}:{$from}:{$to}:{$groupBy}";
        }
        ```
    - `[DRAFT, confidence: 0.65]`

- [ ] **#CACHE-3** · P2 — `bookingMilestoneTotals` key defined but never invalidated in any provided cache service
    - **Where:** app/Services/Cache/CacheKeyGenerator.php:bookingMilestoneTotals() line ~L210
    - **Affects:** Milestone notification path — lifetime booking counts may serve stale after new bookings, causing missed or delayed milestone notifications ("You've reached 100 bookings!").
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CacheKeyGenerator::bookingMilestoneTotals($professionalId)` (with `:stale`) to the key list in `ProfessionalCacheService::invalidateProfessional()` if booking writes update the professional timestamp.
        - Or add explicit bust in the booking write path (observer / job) — and document it so future readers know where invalidation lives.
    - **Technical:** `bookingMilestoneTotals` generates the key `pro:{id}:bookings:milestone-totals`. It lives in the `pro:` namespace but is absent from `ProfessionalCacheService::invalidateProfessional()`, which enumerates `professionalPayloadById`, `professionalServices`, `customerCount`, `brandPartnerStatus`, etc. but does not include this key. `AnalyticsCacheService::invalidateAnalytics()` also doesn't touch it. If there is a booking observer that separately busts this key, it's not visible in the audit scope. The key's purpose is to prevent re-scanning `analytics.booking_events` during a booking burst — but if the key is never invalidated, the first cached value persists until TTL expiry regardless of new bookings.
    - **Plain English:** There's a sticky note on the fridge tracking "lifetime bookings." Every time a new booking comes in, someone should cross out the old number and write the new one. But right now, nobody is crossing it out — the note stays frozen until it falls off the fridge on its own. The milestone celebration ("100 bookings!") might not fire on time because the note still says "99."
    - **Evidence:**
        ```php
        // CacheKeyGenerator.php — key defined, semantically in pro: namespace
        public static function bookingMilestoneTotals(string $professionalId): string
        {
            return "pro:{$professionalId}:bookings:milestone-totals";
        }
        ```
        ```php
        // ProfessionalCacheService.php — invalidateProfessional enumerates pro:* keys
        // but bookingMilestoneTotals is absent from the list:
        $keys = [
            CacheKeyGenerator::professionalPayloadById($professional->id),
            CacheKeyGenerator::professionalPayloadByHandle($handleLc),
            CacheKeyGenerator::professionalPayloadByAuthId($professional->auth_user_id),
            // ... professionalServices, customerCount, brandPartnerStatus ...
            // bookingMilestoneTotals NOT present
        ];
        ```
    - `[DRAFT, confidence: 0.70]`

- [ ] **#CACHE-4** · P3 — `FeatureFlagService::jitteredTtl()` double-jitters with `CacheLockService::writeWithJitter()`
    - **Where:** app/Services/FeatureFlags/FeatureFlagService.php:jitteredTtl() → app/Services/Cache/CacheLockService.php:writeWithJitter()
    - **Affects:** Feature flag cache TTL precision — effective jitter range is wider than intended (~192–432s instead of ~240–360s for a 300s base TTL). No user-visible impact; purely a hygiene inconsistency.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the `random_int` jitter from `FeatureFlagService::jitteredTtl()` and let `CacheLockService::writeWithJitter()` be the single jitter layer.
        - Or document the double-jitter as intentional (wider spread for feature flags) and rename the method to avoid confusion.
    - **Technical:** `FeatureFlagService::jitteredTtl()` applies ±60s additive jitter via `random_int(-60, 60)` then passes the already-jittered int to `CacheLockService::rememberLocked()`, which internally calls `writeWithJitter()` → `JitteredTtl::applyJitter()` (multiplicative ±20%). For a base TTL of 300s, the first pass yields 240–360s; the second multiplies that by 0.8–1.2, giving an effective range of ~192–432s. This is harmless — feature flag TTLs are not SLO-critical — but it means the `±60s` jitter annotation in the code is misleading. The actual spread is ~±30% instead of the documented ±20%.
    - **Plain English:** The kitchen has two separate timers for the same dish. One timer adds a bit of randomness (±60 seconds), then the second timer adds more randomness on top (±20%). The dish is never burned, but nobody can say exactly when it'll be done either. It's fine, just a bit sloppy.
    - **Evidence:**
        ```php
        // FeatureFlagService.php — applies additive jitter
        private function jitteredTtl(?Carbon $nearestExpiry = null): Carbon|int
        {
            $base = self::BASE_TTL_SECONDS + random_int(-self::TTL_JITTER_SECONDS, self::TTL_JITTER_SECONDS);
            // ...
            return $base;
        }
        
        // Then passes to CacheLockService which applies multiplicative jitter internally:
        // CacheLockService::writeWithJitter → JitteredTtl::applyJitter (mt_rand-based ±20%)
        ```
    - `[DRAFT, confidence: 0.80]`

<!-- ═══ SUB-CHUNK: s2 (app/Observers app/Console) ═══ -->

- [ ] **#CACHE-1** · P1 — Missing `:stale` companion cache key bust in BrandProfileObserver
    - **Where:** app/Observers/Core/BrandProfileObserver.php:54–56
    - **Affects:** Every affiliate's `/api/me` dashboard — stale brand status banner persists for up to the SWR window (600s default) after a brand transitions live/building/systems_down.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::forget(CacheKeyGenerator::brandPartnerStatus($brandProfessionalId))` with `Cache::deleteMultiple([$key, $key.':stale'])`.
        - Match the pattern already used in `CommissionPayoutObserver::bustPayoutStateCache()` and `CustomerObserver::invalidateCount()`.
    - **Technical:** The cache read path almost certainly uses `CacheLockService::rememberLocked`, which writes a `:stale` companion key for stale-while-revalidate. Forgetting only the primary key leaves `:stale` live; any request that arrives between the primary delete and the next fresh write serves the pre-change brand status. Every other observer that directly calls `Cache::forget` in this codebase also busts the `:stale` twin — this is the single outlier. The comment references "CACHE-5" suggesting this was flagged in a prior audit but the `:stale` bust was never added.
    - **Plain English:** Imagine a sticky note on the fridge that says "bakery is closed." When the bakery reopens, you rip up the note — but there's a carbon copy underneath that you forgot to remove. Anyone who glances at the fridge before you write a new note sees "closed" and turns away. That's what happens here: when a brand's status changes, the old status still shows to affiliates because a backup copy of the cache isn't cleared.
    - **Evidence:**
        ```php
        try {
            Cache::forget(CacheKeyGenerator::brandPartnerStatus($brandProfessionalId));
        } catch (\Throwable $e) {
            Log::warning('brand-partner-status cache invalidation failed', $this->logContext(__METHOD__, [
                'brand_professional_id' => $brandProfessionalId,
                'message' => $e->getMessage(),
            ]));
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CACHE-2** · P2 — Unbounded per-affiliate job fan-out on brand subdomain change
    - **Where:** app/Observers/Core/SiteObserver.php:117–124
    - **Affects:** Every affiliate connected to a brand — when the brand changes their subdomain, N `SyncSubdomainToKvJob` jobs are dispatched (N = affiliate count), each touching Cloudflare KV. At 30 brands × 50 affiliates, 1,500 KV API calls per subdomain change across the fleet.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the per-affiliate `dispatch()` loop with a single batched job (e.g., `SyncBrandAffiliatesToKvJob`) that iterates internally with a short delay between KV writes.
        - Batch the KV writes within one job so the queue depth doesn't spike linearly with affiliate count.
    - **Technical:** `cascadeAffiliateKvSync()` does `BrandPartnerLink::query()->where(...)->pluck('affiliate_professional_id')->each(fn($id) => SyncSubdomainToKvJob::dispatch($id))`. Each dispatch is an atomic Redis push (fast), but at 200+ affiliates the queue backlog spikes and KV API rate limits become a risk. `SyncSubdomainToKvJob` has `ShouldBeUnique` (45s window) so duplicate dispatches coalesce, but the initial burst of N unique jobs still hits the queue simultaneously. The canonical replacement is a single chunked/batched fan-out job.
    - **Plain English:** When a brand changes their website address, we need to update every affiliate's routing entry. Right now we do that by handing one work order to a courier for each affiliate — 50 affiliates means 50 couriers dispatched at once. That's fine for 5 affiliates but at scale it clogs the dispatch system. The fix is to give one courier a list of 50 addresses.
    - **Evidence:**
        ```php
        BrandPartnerLink::query()
            ->where('brand_professional_id', $brandProfessionalId)
            ->pluck('affiliate_professional_id')
            ->each(function (string $affiliateId): void {
                SyncSubdomainToKvJob::dispatch($affiliateId);
            });
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#CACHE-3** · P2 — Synchronous N-affiliate loop on request thread when brand toggles custom-photo flag
    - **Where:** app/Observers/Core/ProfessionalIntegrationObserver.php:157–180
    - **Affects:** Brand dashboard users toggling `custom_photos_enabled` or photo position — the request thread loops over every linked affiliate to build cache keys. At 100 affiliates, ~100 cache key deletes run inline before the HTTP response returns.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Dispatch the bust as a queued job (mirroring the existing `InvalidateBrandAffiliatesCacheJob` pattern the docblock already suggests).
        - Accept the minor stale-while-revalidate window (the cache TTL is already 60s primary + 600s stale).
    - **Technical:** The docblock acknowledges the risk: "Synchronous bust — typical brands have <100 affiliates... If brand fan-out grows, dispatch the bust as a queued job mirroring InvalidateBrandAffiliatesCacheJob." The method queries `BrandPartnerLink` for all affiliates, builds `$keys[]` arrays in a `foreach` loop, then calls `Cache::deleteMultiple()`. While `deleteMultiple` is a single Redis `UNLINK` (non-blocking), the preceding query + loop adds latency linearly with affiliate count. For a brand with 200 affiliates this is ~200ms of extra request time — below the pain threshold but violating the "no per-N work on the request thread" principle the rebuild established. The canonical replacement is queueing into a chunked/batched job.
    - **Plain English:** When a brand flips a toggle in their settings, we need to clear the cached storefront data for every affiliate connected to them. Currently we do that list-clearing while the brand is waiting for the page to save — with 50 affiliates that's fine, but with 200 the save button starts to lag. The fix is to hand the clearing work to a background worker so the brand gets an instant response.
    - **Evidence:**
        ```php
        $affiliateIds = BrandPartnerLink::query()
            ->where('brand_professional_id', $brandId)
            ->pluck('affiliate_professional_id')
            ->all();

        if ($affiliateIds === []) {
            return;
        }

        $keys = [];
        foreach ($affiliateIds as $affiliateId) {
            $primary = CacheKeyGenerator::hydrogenAffiliateProducts((string) $affiliateId);
            $keys[] = $primary;
            $keys[] = $primary.':stale';
        }

        Cache::deleteMultiple(array_values(array_unique($keys)));
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **#CACHE-4** · P2 — Three-to-four independent job dispatches on every site save
    - **Where:** app/Observers/Core/SiteObserver.php:41–108
    - **Affects:** Any site mutation (settings, publish toggle, subdomain change) — dispatches `CloudflareCachePurgeJob`, optionally `WarmPublicSiteCacheJob`, optionally `SyncSubdomainToKvJob`, optionally `ProvisionBrandDnsJob`. At 30 brands with heavy editing, hundreds of jobs/day for operations that could be coalesced.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Introduce a single `SiteMutatedJob` that receives the site ID + a bitmask of what changed, and internally dispatches the appropriate sub-operations.
        - Alternatively, use Laravel's built-in `->chain()` or batched jobs so the queue depth reflects one logical unit of work rather than 3–4 independent jobs.
    - **Technical:** `SiteObserver::saved()` conditionally dispatches up to 4 different jobs, each gated on different flags (`wasRecentlyCreated`, `wasChanged('subdomain')`, `is_published`). Each dispatch is a separate Redis `RPUSH`. At current scale this is benign (each dispatch is ~0.1ms), but it creates observability noise — 4 job records in Horizon for one user action, no correlation ID linking them. A single orchestrator job would reduce queue depth, improve retry atomicity, and give one place to add correlation logging. This is a hardening concern, not a load concern at pre-beta scale.
    - **Plain English:** Every time a brand saves their website settings, we dispatch up to four separate background tasks — one to clear the Cloudflare edge cache, one to pre-warm the cache, one to update the routing table, and one to set up DNS. Each task is fast and lightweight, but there's no master checklist tying them together. If one fails silently, the others don't know. The fix is to hand one work order to a supervisor who ticks off each subtask.
    - **Evidence:**
        ```php
        CloudflareCachePurgeJob::dispatch($handle)->afterCommit();
        // ...
        if ($site->is_published) {
            WarmPublicSiteCacheJob::dispatch(strtolower($site->subdomain))->afterCommit();
        }
        if ($site->wasRecentlyCreated || $site->wasChanged('subdomain')) {
            SyncSubdomainToKvJob::dispatch($professionalId);
            // ...
            ProvisionBrandDnsJob::dispatch($professionalId);
        }
        ```
    - `[DRAFT, confidence: 0.70]`

- [ ] **#CACHE-5** · P3 — Per-service external sync job dispatch with no bulk coalescing
    - **Where:** app/Observers/Core/ServiceObserver.php:147–178
    - **Affects:** Professionals doing bulk service imports (CSV, migration, Fresha/Square onboarding) — 50 services dispatching 100 sync jobs (2 per service) within a 30s jittered window.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a short-circuit: if the request originates from a bulk-import context (detectable via header or auth context), skip the per-save sync dispatch and rely on a separate `SyncAllServicesJob` triggered at the end of the import.
        - Alternatively, debounce via `ShouldBeUnique` with a per-service lock key so rapid re-saves of the same service coalesce.
    - **Technical:** `ServiceObserver::runHooks()` dispatches `PushServiceToSquareJob` and `PushServiceToFreshaJob` on every `saved`/`deleted`/`restored` event, gated by integration presence and `services_auto_sync_enabled`. The 0–30s random delay (`syncDispatchDelay()`) mitigates rate limiting for single edits but doesn't reduce total job volume during bulk operations. For a 50-service CSV import, 100 jobs hit the queue within ~30s — each makes external API calls. The canonical replacement is a chunked/batched approach: detect bulk context and defer to a single "sync all" job. This is P3 because bulk imports are rare at pre-beta and the jitter already prevents rate-limit tripping for the current fleet size.
    - **Plain English:** When a business uploads 50 services at once, we send two API calls to Square or Fresha for each service — that's 100 calls staggered across 30 seconds. For a one-off edit, sending the update right away is great. But for a bulk upload, it's like mailing 50 letters individually instead of putting them in one envelope. The system already spaces them out to avoid overwhelming Square, but reducing the total number of envelopes would be even better for large imports.
    - **Evidence:**
        ```php
        private function runHooks(Service $service, string $action): void
        {
            try {
                $pro = $this->bust($service);
                $this->reevaluateBooking($service, $pro);

                if ($this->shouldDispatchSquareSync($pro)) {
                    $this->dispatchSquareSync($service->id, $action);
                }

                if ($this->shouldDispatchFreshaSync($pro)) {
                    $this->dispatchFreshaSync($service->id, $action);
                }
            } catch (\Throwable $e) {
                // ...
            }
        }
        ```
        ```php
        private function syncDispatchDelay(): \DateTimeInterface
        {
            return now()->addSeconds(random_int(0, 30));
        }
        ```
    - `[DRAFT, confidence: 0.65]`

<!-- ═══ CHUNK: svc-prof-stripe ═══ -->


<!-- ═══ SUB-CHUNK: s1 (app/Services/Professional) ═══ -->

- [ ] **CACHE-1** · P1 — `isStorefrontReachable()` cache lacks single-flight locking, TTL jitter, and push invalidation
    - **Where:** app/Services/Professional/Brand/BrandStatusService.php:263-281
    - **Affects:** Every admin page load hitting `/internal/embedded/provision-integration` and every brand onboarding checklist poll — all 30 brands at target scale.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::get` + `Cache::put` with `CacheLockService::rememberLocked('brand_status:storefront_reachable:' . sha1($url), 60, fn() => ...)` — the lock grants single-flight so concurrent requests share one HTTP probe.
        - Add ±20% TTL jitter via the `rememberLocked` jitter parameter to prevent synchronised expiry thundering herds.
        - On the storefront-deployment webhook path, push-invalidate the exact cache key so a freshly deployed storefront flips to reachable instantly rather than waiting up to 15s.
    - **Technical:** The code comment acknowledges this HTTP probe "dominates p95 on hot endpoints" without the cache. The existing `Cache::get`/`Cache::put` pair is a classic cold-cache stampede vector — after a Redis eviction, deploy, or flush, every concurrent request races its own 5-second `Http::get()` to the brand's storefront URL. `CacheLockService::rememberLocked` wraps the probe in a Redis-backed mutex (lock key scoped to the cache key), so only one request pays the HTTP cost. The 15s negative TTL is a good intuition but without push invalidation the dashboard stays stale for up to 15s after a deployment succeeds. The canonical replacement already deployed in commerce analytics (`rememberLocked` + jitter + SWR + push-invalidate on every write) maps directly onto this use case.
    - **Plain English:** Every time a staff member loads the admin dashboard or a brand checks their onboarding progress, the system pings the brand's storefront to see if it's live. The dev team already added a short-lived "sticky note" (cache) so we don't ping over and over. But when that sticky note falls off — after a server restart, a cache clear, or just when the timer runs out — every request that arrives at the same moment sends its own ping. That's like twenty people all calling the same store to ask if they're open, at the exact same second. The fix is a "take a number" system: the first person makes the call, everyone else waits and reads the answer from the board.
    - **Evidence:**
        ```php
        $cacheKey = 'brand_status:storefront_reachable:'.sha1($url);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (bool) $cached;
        }

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'timeout' => 5,
                'connect_timeout' => 3,
            ])->get($url);

            $reachable = $response->successful();
        } catch (\Throwable) {
            $reachable = false;
        }

        Cache::put($cacheKey, $reachable, $reachable ? 60 : 15);

        return $reachable;
        ```
    - `[DRAFT, confidence: 0.9]`

<!-- ═══ SUB-CHUNK: s2 (app/Services/Stripe) ═══ -->

- [ ] **#CACHE-1** · P3 — StripeTransactionFetcher delegates caching to controller but makes N sequential Stripe round-trips per page render; no in-service guard against uncached hot reads
    - **Where:** app/Services/Stripe/StripeTransactionFetcher.php:44–68 (forBrand) and :74–100 (forAffiliate)
    - **Affects:** Brand and affiliate dashboard users viewing transaction history; each page load fans out up to `limit` (default 25) sequential `paymentIntents->retrieve` or `charges->retrieve` calls to Stripe's API.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Confirm the calling controller wraps the fetcher in `CacheLockService::rememberLocked`. If it already does, downgrade this to a documentation note.
        - Add a `@see` docblock cross-reference from the fetcher to the controller so future readers don't assume the service self-caches.
    - **Technical:** The service docblock states "Cache wrapping is the controller's job." That is a valid separation, but there's no compile-time or runtime guard that ensures the controller actually implements it. If a developer adds a second call site (e.g., a webhook handler or admin endpoint) without realising caching lives elsewhere, Stripe rate limits and latency accumulate silently. The service could take a PSR-16 cache as an optional constructor dependency and self-cache when one is injected, falling back to direct fetch otherwise — that makes the contract explicit.
    - **Plain English:** Think of this like a library that charges your credit card every time you open it. The library's instructions say "your accountant will handle reimbursements," but nothing in the library actually talks to the accountant. If someone else opens the library without knowing the rule, they get charged too. A small label saying "see accountant" inside the book cover would prevent that surprise.
    - **Evidence:**
        ```php
        // app/Services/Stripe/StripeTransactionFetcher.php:27-29
        // Cache wrapping is the controller's job (CacheLockService::rememberLocked) — this service
        // stays pure for testability.
        class StripeTransactionFetcher
        {
            public function __construct(private readonly StripeClient $stripe) {}

            public function forBrand(Professional $brand, array $filters): array
            {
                $payouts = $this->scopedPayouts($brand->id, 'brand', $filters);
                $rows = [];

                foreach ($payouts as $payout) {
                    // ...
                    $pi = $this->stripe->paymentIntents->retrieve($payout->payment_intent_id, [
                        'expand' => ['latest_charge.refunds'],
                    ]);
                    // ...
                }
                // ...
            }
        ```
    - `[DRAFT, confidence: 0.5]`

- [ ] **#CACHE-2** · P3 — StripeBalanceService makes live Stripe API calls on every invocation; no caching layer inside the service
    - **Where:** app/Services/Stripe/StripeBalanceService.php:41–56 (forAffiliate), :69–92 (upcomingFor), :101–124 (payoutScheduleFor)
    - **Affects:** Affiliate dashboard pages showing available balance, pending payouts, and payout schedule. Three Stripe round-trips per dashboard load if the controller doesn't cache.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Verify the controller wraps each call in `CacheLockService::rememberLocked`. If it does, close this finding.
        - Add a design-constraint comment linking to the controller so the caching contract is traceable from the service.
    - **Technical:** Same delegation pattern as StripeTransactionFetcher. The service is intentionally pure, but three separate methods (`forAffiliate`, `upcomingFor`, `scheduleFor`) each hit Stripe independently. If a single dashboard widget calls all three without upstream caching, that's three sequential Stripe HTTP calls per render. The risk is lower than the transaction fetcher because balance data changes less frequently and the calls are lighter (simple retrieves without expands), but the pattern is identical.
    - **Plain English:** Same pattern as the transaction history library — three more credit cards that get swiped every time someone checks their balance. The note says "your accountant pays these too," but the cards don't know that.
    - **Evidence:**
        ```php
        // app/Services/Stripe/StripeBalanceService.php:18-19
        // Cache wrapping is the controller's responsibility (CacheLockService::rememberLocked)
        // — this service stays pure for testability.

        // :41-56 — live Stripe call, no cache
        $balance = $this->stripe->balance->retrieve([], [
            'stripe_account' => $affiliate->stripe_connect_account_id,
        ]);
        ```
    - `[DRAFT, confidence: 0.5]`

- [ ] **#CACHE-3** · P3 — buildClawbackPlan hydrates full Eloquent models to sum a single JSONB field where a selectRaw aggregate would suffice
    - **Where:** app/Services/Stripe/CommissionPayoutRefundService.php:197–201
    - **Affects:** Shopify refund webhook handler latency. Each refund event loads every prior clawback row for the payout+order pair into memory.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->get()->sum(fn ($c) => ...)` with a `selectRaw('COALESCE(SUM((metadata->>\'refund_share_cents\')::int), 0)')` query so PostgreSQL does the aggregation.
    - **Technical:** The current code hydrates a full `CommissionClawback` Eloquent model for every prior clawback row on this payout+order pair, then sums a nested JSONB field in PHP. At pre-beta scale (0–2 clawbacks per order) this is negligible. At the scaling target (30 brands × ~50 affiliates × ~100 orders/year), the per-order clawback count stays small because clawbacks are rare — but the pattern is still a Category 5 micro-optimisation: hydrating full Eloquent models for an aggregate query. The canonical pattern is a `selectRaw` aggregate, which the codebase already uses elsewhere (e.g., `CommissionVoidService::pendingSummaryForAffiliateBrand`).
    - **Plain English:** Imagine tallying how much change is in a jar by taking out every coin, writing down its value on a separate piece of paper, then adding the papers — instead of just counting the coins in place. It works fine for a jar with two coins. It's just a habit worth breaking before the jars get bigger.
    - **Evidence:**
        ```php
        // app/Services/Stripe/CommissionPayoutRefundService.php:197-201
        $priorRefundCovered = (int) CommissionClawback::query()
            ->where('payout_id', $payout->id)
            ->where('order_id', $order->id)
            ->get()
            ->sum(fn ($c) => (int) ($c->metadata['refund_share_cents'] ?? 0));
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#CACHE-4** · P3 — bustPayoutCaches directly forgets affiliatePayoutState but relies solely on version-bump for brand-side caches; asymmetric invalidation
    - **Where:** app/Services/Stripe/CommissionPayoutRefundService.php:148–155
    - **Affects:** Brand dashboard stale-read window after a refund-driven payout mutation. The brand's `affiliatePayoutState` equivalent (if one exists) is invalidated only via the version-token bump, not a direct forget.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Audit `CacheKeyGenerator` for a brand-side payout-state key. If one exists, add `Cache::forget` + `Cache::forget(':stale')` for it in `bustPayoutCaches`.
        - If none exists, document the asymmetry so future brand-side cache additions follow the direct-forget pattern.
    - **Technical:** The method bumps the analytics version token for both brand and affiliate (which invalidates version-token-keyed caches on next read), but only does a direct `Cache::forget` on the affiliate's `affiliatePayoutState` key (with its SWR `:stale` twin). If a brand-side payout-state cache key exists, it stays warm until the version token is re-read, creating a window where the brand dashboard could show stale post-refund payout totals. The version bump handles it eventually, but the direct-forget is the immediate invalidation the affiliate side gets.
    - **Plain English:** When a refund happens, the affiliate's dashboard updates instantly (the cache is cleared directly). The brand's dashboard updates on a timer (it waits for the next version check). Both get the right answer eventually, but the brand might see old numbers for a few extra seconds. Giving both sides the same instant-update treatment keeps the experience symmetric.
    - **Evidence:**
        ```php
        // app/Services/Stripe/CommissionPayoutRefundService.php:148-155
        private function bustPayoutCaches(Order $order): void
        {
            $this->analyticsCache->bumpAnalyticsVersion($order->affiliate_professional_id);
            $this->analyticsCache->bumpAnalyticsVersion($order->brand_professional_id);

            $stateKey = CacheKeyGenerator::affiliatePayoutState($order->affiliate_professional_id);
            Cache::forget($stateKey);
            Cache::forget($stateKey.':stale');
        }
        ```
    - `[DRAFT, confidence: 0.4]`

<!-- ═══ CHUNK: svc-commerce ═══ -->


<!-- ═══ SUB-CHUNK: s1 (app/Services/Shopify app/Services/Store) ═══ -->

- [ ] **CACHE-1** · P2 — `BrandCatalogService::fetchProductCustomPhotosMetafield` uses raw `Cache::get`/`Cache::put` without single-flight lock, risking cold-cache stampede
    - **Where:** app/Services/Store/BrandCatalogService.php (method `fetchProductCustomPhotosMetafield`)
    - **Affects:** Affiliates viewing product detail; brand team members toggling per-product custom-photos overrides.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the raw `Cache::get` + `Cache::put` with `$this->cacheLock->rememberLocked($cacheKey, $ttl, fn() => ...)`.
        - Use int TTL (not `now()->addSeconds(...)`) so `writeWithJitter` applies ±20% jitter.
    - **Technical:** Two concurrent requests for the same product's `custom_photos_enabled` metafield both observe a cache miss and both fire Shopify Admin API calls before the first one writes back. The `CacheLockService::rememberLocked` lock prevents this by holding a single-flight mutex so only one worker incurs the Shopify round-trip. The existing pattern is already used by `fetchBrandCatalog` and `fetchActiveCatalog` in sibling services — this method simply never adopted it.
    - **Plain English:** Imagine two affiliates open the same product page at the same moment. Both check the "custom photos allowed?" flag, find the cache empty, and both phone Shopify to ask. One answer would suffice, but we make two calls. The fix tells the second request to wait for the first answer instead of dialling Shopify itself.
    - **Evidence:**
        ```php
        $cached = Cache::get($cacheKey);

        if ($cached !== null) {
            return match ($cached) {
                'true' => true,
                'false' => false,
                default => null,
            };
        }
        // ... fetch from Shopify, then:
        Cache::put($cacheKey, 'unset', now()->addSeconds(...));
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **CACHE-2** · P3 — `BrandCatalogService::fetchProductCustomPhotosMetafield` uses `now()->addSeconds()` DateTimeInterface TTL, bypassing jitter and causing synchronised fleet-wide expiry
    - **Where:** app/Services/Store/BrandCatalogService.php (method `fetchProductCustomPhotosMetafield`, two `Cache::put` calls)
    - **Affects:** Every Horizon worker that serves a cache miss for the same product simultaneously after TTL expiry.
    - **Effort:** S (~0.5h) — resolved automatically when switching to `rememberLocked` with int TTL.
    - **What to do:**
        - Pass an int TTL to `rememberLocked` (e.g. `(int) config('partna.cache.ttls.product_custom_photos')`).
        - Remove the three sentinel branches (`'true'`, `'false'`, `'unset'`) — `rememberLockedNullable` or a boolean return from `rememberLocked` eliminates the sentinel workaround.
    - **Technical:** `CacheLockService::writeWithJitter` only applies jitter when the TTL is an integer. `now()->addSeconds()` produces a `Carbon` instance (DateTimeInterface), which the write path stores as an absolute expiry timestamp — every worker computes the same absolute timestamp, so all caches expire in lockstep. At expiry, a thundering herd of Shopify API calls follows for every product whose custom-photos flag is cold.
    - **Plain English:** Every cached flag is stamped with the exact same expiration time — like a parking meter that expires at the same moment for every car on the block. When the meter runs out, everyone rushes the pay station at once instead of staggering their return.
    - **Evidence:**
        ```php
        Cache::put($cacheKey, $bool ? 'true' : 'false', now()->addSeconds((int) config('partna.cache.ttls.product_custom_photos')));
        // ...
        Cache::put($cacheKey, 'unset', now()->addSeconds((int) config('partna.cache.ttls.product_custom_photos')));
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **CACHE-3** · P2 — `AffiliateProductCatalogService::getCatalogWithSelections` calls uncached `BrandCatalogService::fetchCollectionProducts` on every catalog view, causing redundant Shopify API pagination
    - **Where:** app/Services/Store/AffiliateProductCatalogService.php (method `getCatalogWithSelections` → `fetchCollectionGids` → `fetchCollectionProducts`)
    - **Affects:** Every affiliate viewing their catalog; the favourites collection membership is re-fetched from Shopify on each page load.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap the `fetchCollectionGids('favourites_collection_handle')` call in `CacheLockService::rememberLocked` with a short TTL (e.g. 120s) and int TTL for jitter.
        - Push-invalidate the favourites cache key from `BrandCatalogService::addProductsToCollection` and `removeProductsFromCollection` when the brand modifies collection membership.
        - Or fold favourites-GID resolution into the existing `fetchActiveCatalog` cached payload (appended as a `favourites_gids` key) so no extra API round-trip is needed.
    - **Technical:** `fetchCollectionProducts` paginates through the Shopify collection via `ShopifyAdminClient::graphql()` on every call. While `resolveCollectionGid` is cached, the subsequent product enumeration is not. At 30 brands × 50 affiliates, if each affiliate views their catalog daily, that's 1,500 unnecessary Shopify pagination sequences per day just for favourites — all returning the same answer. The canonical replacement is a live query fronted by `rememberLocked` with push invalidation on write.
    - **Plain English:** Every time an affiliate opens their product catalog, we call Shopify and ask "what's in the Favourites collection?" — even if the answer hasn't changed since ten seconds ago. The fix caches that answer locally for a couple of minutes so 50 affiliates hitting refresh don't all phone Shopify for the same list.
    - **Evidence:**
        ```php
        // In getCatalogWithSelections:
        $favouritesGids = $this->fetchCollectionGids($integration, 'favourites_collection_handle');

        // In fetchCollectionGids:
        $products = $this->brandCatalogService->fetchCollectionProducts($integration, $collectionGid);

        // fetchCollectionProducts — no caching layer:
        public function fetchCollectionProducts(ProfessionalIntegration $integration, string $collectionGid): array
        {
            // ... paginated Shopify API calls, no Cache::remember / rememberLocked
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **CACHE-4** · P1 — `AffiliateProductCatalogService::queryAdminCatalog` bypasses `ShopifyAdminClient`, making direct HTTP calls without token-bucket throttling or cost tracking
    - **Where:** app/Services/Store/AffiliateProductCatalogService.php (method `queryAdminCatalog`, lines using `Http::timeout(20)->...->post()`)
    - **Affects:** Cold-cache affiliate catalog loads — the call skips Shopify rate-limit budget pre-acquisition and proper THROTTLED retry.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Inject `ShopifyAdminClient` into `AffiliateProductCatalogService` and replace the `Http::` post call with `$this->shopifyClient->graphql(...)`.
        - Delete the manual HTTP construction (`Http::timeout(20)->acceptJson()->withHeaders([...])->post(...)`) and the manual error/logging branches — `ShopifyAdminClient` already handles throttled retry, cost reconciliation, and typed exception throwing.
    - **Technical:** Every other Shopify Admin API call site in the codebase (`BrandCatalogService`, `ShopifyTeardownService`, `ShopifyDataResyncService`) routes through `ShopifyAdminClient::graphql()`, which pre-acquires from the Redis token bucket, reconciles bucket state from Shopify's `throttleStatus` response, and throws `ShopifyThrottledException` on THROTTLED so the queue's `backoff()` can retry with delay. `queryAdminCatalog` does none of this — it fires `Http::post` directly, so two brands hitting cold cache simultaneously can exhaust the Shopify budget with no local gate, and a THROTTLED response is logged-and-broken instead of retried.
    - **Plain English:** The rest of the app uses a smart throttling system that paces calls to Shopify like a traffic light — it knows the speed limit and queues cars that would exceed it. This one method ignores the traffic light, floors it onto the highway, and if it gets pulled over (rate-limited), it just gives up instead of waiting its turn. The fix wires it into the same traffic-light system everyone else uses.
    - **Evidence:**
        ```php
        // queryAdminCatalog — direct Http::post, no ShopifyAdminClient:
        $response = Http::timeout(20)
            ->acceptJson()
            ->withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
            ])
            ->post($url, [
                'query' => $query,
                'variables' => $variables,
            ]);

        // Compare: BrandCatalogService::queryAdminCatalog uses the client:
        $response = $this->graphql($shopDomain, $accessToken, self::PRODUCTS_WITH_METAFIELDS, $variables);
        // ... which delegates to $this->client->graphql() (ShopifyAdminClient)
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **CACHE-5** · P3 — `AffiliateProductCatalogService::seedDefaultSelections` creates individual `AffiliateProductSelection` rows in a `foreach` loop instead of batch-inserting
    - **Where:** app/Services/Store/AffiliateProductCatalogService.php (method `seedDefaultSelections`, `foreach ($defaultGids as $gid)` loop)
    - **Affects:** Affiliates during brand-connection onboarding; N individual INSERTs where N = default-collection product count (potentially 50–500).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Collect new selections into an array, then use `AffiliateProductSelection::insert($batch)` for a single multi-row INSERT.
        - Pre-compute `$maxSort + 1` offsets within the batch rather than incrementing per iteration after the fact.
    - **Technical:** Each `AffiliateProductSelection::create()` fires a separate INSERT statement with its own transaction. For a default collection of 100 products, that's 100 round-trips to PostgreSQL. A single `insert([...])` call with a prepared array reduces this to one round-trip. This isn't on a hot webhook path (it runs once per affiliate-brand connection), but at the target scale of 1,500 connections, the cumulative DB load from per-row inserts adds unnecessary write pressure on `pgsql`.
    - **Plain English:** When an affiliate connects to a brand, we add their default product picks one at a time — like hand-delivering 100 letters instead of putting them all in one envelope. The fix bundles them into a single delivery.
    - **Evidence:**
        ```php
        foreach ($defaultGids as $gid) {
            if (in_array($gid, $existingGids, true)) {
                continue;
            }
            $maxSort++;
            AffiliateProductSelection::create([
                'affiliate_professional_id' => $affiliate->id,
                'brand_professional_id' => $brandProfessionalId,
                'shopify_product_gid' => $gid,
                'sort_order' => $maxSort,
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.7]`

<!-- ═══ SUB-CHUNK: s2 (app/Services/Media app/Services/Analytics app/Services/Site app/Services/PublicSite) ═══ -->

- [ ] **CACHE-1** · P2 — AffiliateProjectionsService has no caching layer, executing 5+ DB queries per request on a dashboard read path
    - **Where:** app/Services/Analytics/AffiliateProjectionsService.php (entire `build()` method)
    - **Affects:** Affiliate dashboards viewing run-rate, momentum, YTD, and year-end forecast projections
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap the `build()` method in `CacheLockService::rememberLocked` with a 60–120s TTL + jitter.
        - Push-invalidate the cache key on every `brand_affiliate_rollup` upsert (the trigger that updates the source table), or version-token bust on any commission movement.
        - Consider exposing the version token from `CacheKeyGenerator` so writes automatically roll the cache key forward (the `analyticsSummaryVersion` pattern).
    - **Technical:** The `build()` method calls `resolveDataHistoryDays` (1 query), `fetchPerCurrencyAggregates` (1), `fetchPriorWindowAggregates` (1), `fetchYtdAggregates` (1), and `fetchBestMonthPerCurrency` (1 subquery) — five DB round-trips per request with no cache barrier. This is purely a read projection; it has no side effects and is an ideal candidate for `CacheLockService::rememberLocked`, which provides single-flight lock, TTL jitter, and SWR semantics. The source table `commerce.brand_affiliate_rollup` is trigger-maintained, so invalidation can be coupled to the upsert trigger or a version-token increment.
    - **Plain English:** Imagine the dashboard that shows an affiliate "you're on track to earn £X this year" recalculates that number from scratch every single time the page loads — five separate trips to the database. The numbers don't change between commission events, so we're doing fresh math when the answer hasn't changed. Wrapping it in a short-lived cache (like putting the answer on a sticky note for 60 seconds) eliminates the redundant work without making the numbers stale.
    - **Evidence:**
        ```php
        public function build(Professional $professional, ?int $windowDaysOverride = null): array
        {
            // ... no cache — every call runs:
            $dataHistoryDays = $this->resolveDataHistoryDays($professional->id, $now);
            $perCurrency = $this->fetchPerCurrencyAggregates(...);
            $priorByCurrency = $this->fetchPriorWindowAggregates(...)->keyBy('currency_code');
            $ytdByCurrency = $this->fetchYtdAggregates(...)->keyBy('currency_code');
            $bestMonthByCurrency = $this->fetchBestMonthPerCurrency(...)->keyBy('currency_code');
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **CACHE-2** · P1 — PublicSiteResolver has no caching on the hottest read path in the application
    - **Where:** app/Services/PublicSite/PublicSiteResolver.php:18-38
    - **Affects:** Every public site visitor; subdomain → Site resolution runs on every page view
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap `resolvePublishedSite()` in `CacheLockService::rememberLocked` with a 30–60s TTL + jitter.
        - Push-invalidate on every `Site` publish/unpublish, subdomain change, and `Professional` status change to `active`/`suspended`.
        - Use a versioned key (`public_site:v{site_version}`) so the cache auto-rolls on any relevant mutation.
    - **Technical:** `resolvePublishedSite()` runs on every public page request. It queries `Site` by subdomain (1 query), and if that misses, queries `SiteSubdomainAlias` (1 query) and then `Site` again by `site_id` (1 query). At target scale — 30 brands × 50 affiliates × an unknown but non-trivial public traffic volume — this is the single most frequently executed read path in the entire application. The `CacheLockService::rememberLocked` pattern with push-invalidation is already proven on the commerce analytics path and fits perfectly here; the `SiteCacheService` already has invalidation hooks that could be extended.
    - **Plain English:** Every person who visits an affiliate's storefront triggers up to three separate database lookups just to figure out which site to show them. If a thousand people visit in a minute, that's three thousand database queries asking "which site is this subdomain?" when the answer hasn't changed since the last deployment. Putting the answer in a short-lived cache is like putting the site address on a whiteboard instead of walking to the filing cabinet every time someone asks.
    - **Evidence:**
        ```php
        public function resolvePublishedSite(string $subdomain): ?Site
        {
            $subdomain = strtolower($subdomain);
            $siteQuery = Site::query()
                ->where('is_published', true)
                ->with('professional')
                ->whereHas('professional', function ($q) { $q->where('status', 'active'); });

            $site = (clone $siteQuery)
                ->whereRaw('lower(subdomain) = ?', [$subdomain])
                ->first();

            if ($site) { return $site; }

            $alias = SiteSubdomainAlias::query()
                ->whereRaw('lower(subdomain) = ?', [$subdomain])
                ->first();

            if (! $alias) { return null; }

            return (clone $siteQuery)->where('id', $alias->site_id)->first();
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **CACHE-3** · P2 — BrandDesignMediaService::deletePlaceholder uses double-UPDATE repack loop — write amplification on every placeholder delete
    - **Where:** app/Services/Media/BrandDesignMediaService.php:145-176 (the repack loop)
    - **Affects:** Brand dashboard users deleting placeholder images; unnecessary DB write load
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Compute the final `sort_order` values in PHP, then issue a single bulk `UPDATE` using a `CASE WHEN` statement or a batch upsert.
        - Alternatively, accept gaps in `sort_order` and let the `listDesignMedia` query renumber on read with `ROW_NUMBER()` — the list is always sorted anyway.
    - **Technical:** When a placeholder is deleted, the method re-packs the remaining placeholders' `sort_order` to `(0, 1, 2, ...)` using two UPDATE passes: first to a high offset (`PLACEHOLDER_MAX + 1000`) to avoid unique-index collisions, then back to the final values. Each pass executes one UPDATE per remaining row — up to 4 placeholders × 2 passes = 8 UPDATE statements for a single delete. This is write amplification: one user action triggers up to 8 DB writes on a table whose cardinality is bounded at 5. The two-pass technique correctly avoids the unique-index collision, but a single `UPDATE ... SET sort_order = CASE WHEN id = ... THEN ... END` would achieve the same result in one statement.
    - **Plain English:** When a brand deletes one of their five placeholder images, the code doesn't just remove it — it renumbers every remaining placeholder by moving them to temporary numbers and then to their final positions, generating up to eight database updates for one delete. It's like re-filing every folder in a drawer when you remove one file, instead of just closing the gap with one shuffle. The fix is to send all the new positions in a single instruction.
    - **Evidence:**
        ```php
        $offset = self::PLACEHOLDER_MAX + 1000;
        foreach ($remaining as $idx => $r) {
            SiteMedia::query()->where('id', $r->id)->update(['sort_order' => $offset + $idx]);
        }
        foreach ($remaining as $idx => $r) {
            SiteMedia::query()->where('id', $r->id)->update(['sort_order' => $idx]);
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **CACHE-4** · P2 — BrandDesignMediaService::reorderPlaceholders uses identical double-UPDATE repack loop
    - **Where:** app/Services/Media/BrandDesignMediaService.php:188-214
    - **Affects:** Brand dashboard users reordering placeholder images; same write-amplification profile as delete
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Same single-`CASE WHEN` bulk UPDATE approach as CACHE-3.
        - Or delegate renumbering to `ROW_NUMBER()` at read time so the write path is a single pass with the caller-supplied order.
    - **Technical:** Identical antipattern to `deletePlaceholder` — two UPDATE passes per placeholder, up to 10 UPDATEs for a 5-placeholder reorder. The two passes guard against the partial unique index on `(site_id, pool, purpose, sort_order)`, but a single `CASE WHEN` update avoids the collision entirely because all new values are assigned atomically. The cardinality is capped at 5, so the blast radius is tiny, but the pattern is duplicated and both call sites should be fixed together.
    - **Plain English:** Same as deleting a placeholder — reordering them also triggers the double-shuffle update pattern. If they reorder all five images, that's ten database writes when one could do it. It's like rewriting every index card's position twice instead of once.
    - **Evidence:**
        ```php
        $offset = self::PLACEHOLDER_MAX + 1000;
        foreach ($orderedIds as $idx => $id) {
            SiteMedia::query()->where('id', $id)->update(['sort_order' => $offset + $idx]);
        }
        foreach ($orderedIds as $idx => $id) {
            SiteMedia::query()->where('id', $id)->update(['sort_order' => $idx]);
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **CACHE-5** · P2 — VideoVariantService::processVariants uploads HLS segments in a sequential per-file loop — network amplification proportional to video duration
    - **Where:** app/Services/Media/VideoVariantService.php:186-200 (the `scandir` loop inside HLS upload)
    - **Affects:** Video upload processing jobs; worker time grows linearly with video length
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Batch segment uploads using Laravel's HTTP pool or `Storage::disk()->put()` with a concurrent stream wrapper, or use `aws s3 sync` via a subprocess for the HLS directory.
        - As a lighter touch: at minimum, wrap the uploads in a `collect()->chunk()` with a note that R2 supports multipart upload for the directory.
    - **Technical:** For each HLS variant, `processVariants()` scans the temp directory and issues one `$disk->put()` per segment file. HLS segments are typically 6 seconds each, so a 5-minute video produces ~50 segments per variant, × 2 variants = ~100 sequential `put()` calls. Each `put()` is a network round-trip to R2 (or S3-compatible storage). This is not write amplification in the database sense — the segments are necessary artifacts — but the sequential loop amplifies total processing wall-clock time linearly with video duration. The canonical fix is a concurrent upload (multi-threaded or async HTTP pool) or a directory-level sync. At pre-beta with occasional uploads this is fine; at target scale with 30 brands potentially uploading training/intro videos, worker throughput becomes a bottleneck.
    - **Plain English:** When the system processes a video, it breaks it into short segments for streaming and uploads each segment one at a time — like mailing 100 postcards individually instead of putting them all in one envelope. For a 5-minute video, that's about 100 separate uploads, each waiting for the previous one to finish. This doesn't break anything, but it makes video processing slower than it needs to be. Sending the whole batch at once cuts the waiting time significantly.
    - **Evidence:**
        ```php
        foreach ($hlsDirs as $variantKey => $hlsDir) {
            $remoteHlsBase = "{$basePath}/hls/{$variantKey}";
            foreach (scandir($hlsDir) ?: [] as $file) {
                if ($file === '.' || $file === '..') { continue; }
                $localFile = "{$hlsDir}/{$file}";
                $remotePath = "{$remoteHlsBase}/{$file}";
                // ...
                $stream = fopen($localFile, 'rb');
                $disk->put($remotePath, $stream, ['visibility' => 'public', 'ContentType' => $mime]);
                if (is_resource($stream)) { fclose($stream); }
            }
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **CACHE-6** · P3 — BrandDesignMediaService::getLogoFullUrls has no caching for a batch-read path called by partner cards and invite flows
    - **Where:** app/Services/Media/BrandDesignMediaService.php:290-309
    - **Affects:** Partner card displays, invite emails showing brand logos — any caller that needs multiple site logos at once
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `CacheLockService::rememberLocked` with a 60s TTL + jitter, keyed by `implode(',', sort($siteIds))` and a version token tied to `SiteCacheService`.
        - Push-invalidate per-site logo caches in the existing `invalidateSiteCache()` method (which already runs `forgetBrandDesign`).
    - **Technical:** `getLogoFullUrls()` is the batch counterpart to `getLogoFullUrl()` and queries `site_media` with a `WHERE IN` on `site_id` plus eager-loaded `mediaVariants`. It's used anywhere multiple brand logos need to be displayed simultaneously (partner cards on the affiliate dashboard, invite emails). Without caching, every render re-queries. The method is already side-effect free and the invalidation hook exists in `BrandDesignMediaService::invalidateSiteCache()` — `forgetBrandDesign` is already called on every logo upload/delete. A simple `rememberLocked` wrap completes the read-path cache hygiene.
    - **Plain English:** When the system builds a list of partner cards showing multiple brand logos, it asks the database for every logo from scratch each time. The logos don't change between uploads — they're the same PNGs that were stored last time. A short cache (60 seconds) means the list renders from memory instead of re-querying, and the cache is automatically cleared whenever someone updates their logo.
    - **Evidence:**
        ```php
        public function getLogoFullUrls(array $siteIds): array
        {
            if (empty($siteIds)) { return []; }

            return SiteMedia::query()
                ->whereIn('site_id', $siteIds)
                ->where('pool', SiteMedia::POOL_DESIGN)
                ->where('purpose', SiteMedia::PURPOSE_LOGO_FULL)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->where('processing_state', '!=', SiteMedia::PROCESSING_STATE_FAILED)
                ->with('mediaVariants')
                ->get()
                ->mapWithKeys(fn (SiteMedia $m): array => [
                    (string) $m->site_id => $m->variantUrls()['optimized'] ?? null,
                ])
                ->filter()
                ->all();
        }
        ```
    - `[DRAFT, confidence: 0.70]`

- [ ] **CACHE-7** · P2 — AnalyticsService::windowedDistinctCount and windowedCartSessions execute 6 separate COUNT DISTINCT queries (one per time window) instead of a single query
    - **Where:** app/Services/Analytics/AnalyticsService.php (windowedDistinctCount at ~line 200, windowedCartSessions at ~line 212)
    - **Affects:** Cold-cache analytics page loads (post-deploy, eviction); latency for the one unlucky request that fills the cache
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the per-window loop with a single `SELECT COUNT(DISTINCT col) FILTER (WHERE occurred_at >= ?) ...` query using PostgreSQL's `FILTER` clause — one query, six columns.
        - Apply the same treatment to `windowedCartSessions`, `brandWindowedCartSessions`, `brandWindowedViews`, and `brandWindowedUniqueVisitors`.
    - **Technical:** The `windowedDistinctCount` method iterates over 6 `self::WINDOWS` keys and issues a separate `COUNT DISTINCT` query per window. `windowedCartSessions` does the same. `brandWindowedCartSessions`, `brandWindowedViews`, and `brandWindowedUniqueVisitors` each add a preliminary `pluck('affiliate_professional_id')` query plus 6 more. This is 12+ queries for `computeAffiliate()` and 25+ for `computeBrand()` on a cold cache. The `CacheLockService::rememberLocked` wrapper provides single-flight protection, so only one request pays this cost, but that request still suffers avoidable DB latency. PostgreSQL's `FILTER` clause (already in use in `AffiliateProjectionsService`) allows all six windows to be aggregated in one query. At target scale (30 brands × 50 affiliates with cold-cache after deploys), the latency hit is noticeable but not catastrophic — the cache absorbs it 99% of the time.
    - **Plain English:** When the analytics dashboard loads for the first time after a deploy, it asks the database the same question six times in a row — "how many unique visitors in the last 24 hours?" then "…in the last 7 days?" then "…in the last 30 days?" and so on. It's like calling someone six times to ask six questions when you could ask them all in one phone call. The dashboard is smart enough to remember the answers for five minutes after that, so only the first person pays the price — but the fix is simple enough to be worth doing.
    - **Evidence:**
        ```php
        private function windowedDistinctCount(string $table, string $distinctColumn, array $bounds, array $where): array
        {
            $result = [];
            foreach (self::WINDOWS as $w) {   // 6 separate queries
                $query = DB::table($table)->whereNotNull($distinctColumn);
                foreach ($where as $col => $val) { $query->where($col, $val); }
                if ($bounds[$w] !== null) { $query->where('occurred_at', '>=', $bounds[$w]); }
                $result[$w] = (int) $query->distinct()->count($distinctColumn);
            }
            return $result;
        }
        ```
        ```php
        private function windowedCartSessions(string $professionalId, array $bounds): array
        {
            $result = [];
            foreach (self::WINDOWS as $w) {   // 6 more separate queries
                $query = DB::table('analytics.cart_events')
                    ->where('professional_id', $professionalId)
                    ->where('event_type', 'checkout_start')
                    ->whereNotNull('session_id');
                if ($bounds[$w] !== null) { $query->where('occurred_at', '>=', $bounds[$w]); }
                $result[$w] = (int) $query->distinct()->count('session_id');
            }
            return $result;
        }
        ```
    - `[DRAFT, confidence: 0.80]`

<!-- ═══ CHUNK: svc-rest-models ═══ -->


<!-- ═══ SUB-CHUNK: s1 (app/Services/Square app/Services/Notifications app/Services/Fresha app/Services/Streaming app/Services/Billing app/Services/Accounts app/Services/Cloudflare app/Services/Customers app/Services/Auth app/Services/Exports app/Services/Diagnostics app/Services/Email app/Services/Audit) ═══ -->

- [ ] **#CACHE-1** · P3 — SquareServiceSyncService performs per-row Eloquent queries inside a single DB transaction during service sync
    - **Where:** app/Services/Square/SquareServiceSyncService.php:applySquareSnapshot (lines ~174–265)
    - **Affects:** Professionals with Square integration during catalog sync; sync latency grows linearly with service count.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the per-row `Service::query()->where(...)->first()` / `::create()` loop with a single batch upsert (`DB::table('core.services')->upsert(...)`) keyed on `(professional_id, square_variation_id)`.
        - Bulk-fetch all potentially-matching services before the loop with one `whereIn('square_variation_id', $variationIds)` query, then index by variation_id in PHP.
        - Batch the full-sync missing-service deletion into a single `UPDATE ... SET deleted_at = NOW() WHERE professional_id = ? AND square_variation_id NOT IN (...)`.
    - **Technical:** The current `applySquareSnapshot` opens a `DB::transaction()` then runs one `SELECT` + one `INSERT` or `UPDATE` per Square row inside a `foreach`, plus a second pass for full-sync missing-service cleanup. For a catalog of 100 variations this is ~200 individual queries holding a transaction open. The canonical replacement is a single `upsert()` call on the `services` table—Postgres `ON CONFLICT` handles insert-or-update atomically, and a bulk soft-delete `UPDATE` avoids the per-row save+delete loop. At the target scale of 30 brands × ~50 services each this is still sub-second, so the tier is P3, but the pattern is the same shape as the old commerce rebuild antipattern (per-row processing under a transaction) at a smaller magnitude.
    - **Plain English:** When Square sends a catalog update, the sync service opens one long database transaction and then asks a hundred separate questions, one per service—"do you exist? no? create. yes? update." It's like checking a hundred guests into a hotel by walking to each room individually instead of handing the front desk a single list. At the current scale this is fine, but as the number of services per professional grows, the sync gets proportionally slower because every service means two more round-trips to the database.
    - **Evidence:**
        ```php
        DB::transaction(function () use (
            $professional, $squareRows, &$syncedVariationIds, &$syncedCount, &$deletedCount
        ): void {
            // ... existingCategories query ...
            Service::withoutEvents(function () use (..., $squareRows, ...): void {
                foreach ($squareRows as $row) {
                    // ...
                    $service = Service::query()
                        ->withTrashed()
                        ->where('professional_id', $professional->id)
                        ->where('square_variation_id', $variationId)
                        ->first();

                    if (! $service) {
                        $service = Service::query()->create([...]);
                    } else {
                        // ...
                        $service->save();
                    }
                    // ...
                }
            });
        });
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#CACHE-2** · P3 — FreshaServiceSyncService performs per-row Eloquent queries inside a single DB transaction during service sync
    - **Where:** app/Services/Fresha/FreshaServiceSyncService.php:syncFromFresha (lines ~76–165)
    - **Affects:** Professionals with Fresha integration during catalog sync; same per-row query amplification as the Square path.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Apply the same batch-upsert pattern recommended for SquareServiceSyncService: pre-fetch all existing services by `fresha_variation_id`, index in PHP, then issue one `upsert()` call with all incoming rows.
        - Wrap the per-service deletion loop in `Service::withoutEvents()` (matching Square's pattern) to suppress observer-triggered side effects when soft-deleting many services.
        - Add `deleted_origin` tracking (mirroring Square's `deleted_origin = 'square'`) so a manually-deleted service isn't resurrected by a subsequent Fresha delta sync.
    - **Technical:** `FreshaServiceSyncService::syncFromFresha()` runs the same `DB::transaction()` + per-row `Service::query()->where(...)->first()` / `::create()` pattern as Square, producing one SELECT + one write per Fresha service row. Additionally, the deletion branch calls `$service->save(); $service->delete()` without `Service::withoutEvents()`, so any registered Service model observers (cache invalidation, push-to-provider jobs) fire for each individually-deleted row. The canonical replacement is a single `upsert()` over the service rows plus a bulk `UPDATE` for deletions, with `withoutEvents()` guarding the batch. The divergence from Square's `deleted_origin` tracking also means a professional who manually deletes a service in Partna may see it reappear after the next Fresha sync.
    - **Plain English:** This is the same per-row database pattern as the Square sync, but for Fresha-connected professionals. It also has a sharper edge: when Fresha says "these services were deleted," the code deletes them one at a time and triggers every automated side effect (cache clears, push jobs) for each one individually. It's like ringing a fire alarm separately for every room in a building instead of pulling it once. At current scale it's harmless noise, but as the number of integrated professionals grows it creates unnecessary work for the job queue.
    - **Evidence:**
        ```php
        DB::transaction(function () use ($professional, $rows, &$syncedCount, &$deletedCount) {
            foreach ($rows as $row) {
                // ...
                $service = Service::query()
                    ->withTrashed()
                    ->where('professional_id', $professional->id)
                    ->where('fresha_variation_id', $variationId)
                    ->first();

                if (! $service) {
                    $service = Service::query()->create([...]);
                } else {
                    // ...
                    Service::withoutEvents(function () use (...) {
                        Service::query()->withTrashed()->where('id', $service->id)->update([...]);
                    });
                }
                // ...
            }
        });
        // Deletion loop — no withoutEvents() guard:
        foreach ($toDelete as $service) {
            $service->is_active = false;
            $service->save();
            $service->delete();
            $deletedCount++;
        }
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#CACHE-3** · P3 — Entitlements service uses per-request in-memory cache only; no shared cache across requests
    - **Where:** app/Services/Billing/Entitlements.php:22-24 (cache property), 26-37 (currentSubscription method)
    - **Affects:** Every API request, job, and route guard that checks entitlements—each re-queries the subscription for the same professional independently.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap `currentSubscription()` in a `CacheLockService::rememberLocked` call with a short TTL (e.g., 30s + jitter), keyed by professional ID.
        - Push-invalidate the cache key from `ChangeProfessionalPlanAction` and the Stripe webhook handler when a subscription's plan or status changes.
        - Consider a version-token pattern (`entitlements_version:{professional_id}`) incremented on plan change so stale caches self-heal without explicit invalidation from every write path.
    - **Technical:** `Entitlements` memoizes `currentSubscription()` in a per-request `array` property (`$this->cache`). This eliminates N+1 within a single request but provides zero sharing across requests. Every inbound API call, Horizon job, and middleware guard that calls `hasPlan()` or `hasEntitlement()` runs a fresh `Subscription::query()->with('plan')->where(...)->first()` for the same professional. Subscriptions change infrequently (on plan upgrade/downgrade or billing period roll), so a shared cache with push-invalidation would eliminate >99% of these queries. At the target scale of 30 brands × 50 affiliates each (1,500 professionals), dashboard page loads that check multiple entitlements per render would hit the DB for the same row across every request, though the query itself is a single-row UUID lookup and not a scaling bottleneck at this size. The `rememberLocked` + push-invalidate pattern is already proven in `NotificationListingService` and `CommerceNotificationService`.
    - **Plain English:** Every time the app checks "can this user access feature X?", it walks to the database and asks, even if the same question was just answered for the same person two seconds ago. It's like a bouncer who checks your ID every time you walk through the door, even if you're just stepping out and back in. The fix is a short-term memory (a 30-second cache) shared across all the bouncers, with a rule that says "if their plan changes, forget what you memorized." The database query is fast—a single row lookup—so this isn't urgent, but eliminating it entirely is cheap and the pattern is already in use elsewhere in the codebase.
    - **Evidence:**
        ```php
        /** @var array<string, Subscription|null> Per-request cache keyed by professional ID */
        private array $cache = [];

        public function currentSubscription(Professional $professional): ?Subscription
        {
            $key = $professional->id;

            if (! array_key_exists($key, $this->cache)) {
                $this->cache[$key] = Subscription::query()
                    ->with('plan')
                    ->where('professional_id', $professional->id)
                    ->whereNull('ended_at')
                    ->latest('created_at')
                    ->first();
            }

            return $this->cache[$key];
        }
        ```
    - `[DRAFT, confidence: 0.6]`

<!-- ═══ SUB-CHUNK: s2 (app/Models) ═══ -->

- [ ] **CACHE-1** · P2 — EmailSubscription observer dispatches one job per save, risking queue amplification during bulk imports
    - **Where:** app/Models/Core/Notifications/EmailSubscription.php (booted() method)
    - **Affects:** Queue workers and dashboard responsiveness when professionals bulk-import marketing consent lists.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace per-row job dispatch with a debounced or batch approach (e.g., collect changed subscription IDs into a set and dispatch a single job to re-sync all affected customers).
        - Alternatively, use a scheduled command that periodically reconciles the Customer `marketing_opt_in_cached` column against `EmailSubscription` status.
    - **Technical:** The `saved` observer fires on every insert/update of an `EmailSubscription` row. A bulk import of 1,000 subscriptions would dispatch 1,000 `SyncCustomerMarketingOptInJob` jobs instantly, each performing a `Customer` lookup and update. This is job-level write amplification that can saturate the queue and cause back-pressure. The canonical replacement from the commerce rebuild is a single debounced job or a trigger‑maintained cache, rather than N individual jobs.
    - **Plain English:** Whenever someone uploads a spreadsheet of email subscribers, the system currently kicks off a separate background task for each email address. If the list has a thousand entries, that’s a thousand tasks all competing for attention at once — like shouting 1,000 reminder messages into a single room. It’s far more efficient to run one task that handles the whole batch, or to do the sync overnight when things are quiet.
    - **Evidence:**
        ```php
        protected static function booted(): void
        {
            static::saved(function (self $subscription) {
                if ($subscription->list_key === 'marketing' && $subscription->professional_id && $subscription->email) {
                    $professionalId = (string) $subscription->professional_id;
                    $email = (string) $subscription->email;
                    $isSubscribed = $subscription->status === 'subscribed';

                    DB::afterCommit(function () use ($professionalId, $email, $isSubscribed) {
                        \App\Jobs\Notifications\SyncCustomerMarketingOptInJob::dispatch(
                            $professionalId,
                            $email,
                            $isSubscribed,
                        );
                    });
                }
            });
        }
        ```
    - `[DRAFT, confidence: 0.7]`

<!-- ═══ SUB-CHUNK: s3 (app/Policies app/Providers app/Enums app/Exceptions app/Mail app/Rules app/Support) ═══ -->

- [ ] **CACHE-1** · P2 — 15 model observers registered create per-save dispatch hooks requiring audit for rebuild-on-write / fan-out antipatterns
    - **Where:** app/Providers/EventServiceProvider.php:37-51
    - **Affects:** Every create/update/delete on Professional, Site, Block, Service, ServiceCategory, Customer, BrandAffiliateInvite, BrandPartnerLink, CommissionMovement, CommissionPayout, ProfessionalIntegration, BrandProfile, BrandStoreSettings, SiteMedia, and AffiliateProductSelection — any synchronous rebuild dispatch or notification fan-out inside these observers amplifies single-user writes into multi-row recomputes.
    - **Effort:** L (~1–2d) — audit 15 observer classes, not a code change.
    - **What to do:**
        - Audit each non-commerce observer (`ProfessionalObserver`, `SiteObserver`, `BlockObserver`, `ServiceObserver`, `ServiceCategoryObserver`, `CustomerObserver`, `BrandAffiliateInviteObserver`, `BrandPartnerLinkObserver`, `ProfessionalIntegrationObserver`, `BrandProfileObserver`, `BrandStoreSettingsObserver`, `SiteMediaObserver`, `AffiliateProductSelectionObserver`) for dispatch of `Rebuild*Job`, `FanOut*Job`, or synchronous multi-row writes inside `created`/`updated`/`deleted` hooks.
        - For any observer that dispatches a rebuild job on every save, replace with push-invalidation + `CacheLockService::rememberLocked` (if the read is a dashboard) or a trigger-maintained signed-delta rollup (if the read is an aggregate table).
        - For any observer that dispatches a fan-out job creating N notification receipts per save, replace with lazy receipt creation (create receipt rows on first read, not at fan-out time) per the rebuild ADR.
    - **Technical:** The observer pattern is the most common home for rebuild-on-write in this codebase — the commerce rebuild already removed `commission_ledger_entries` observer-driven aggregates. Every remaining observer on a model with non-trivial write volume (Site, Block, SiteMedia are edited frequently; CommissionMovement rows arrive in webhook batches) is a candidate for the same antipattern. Without auditing the observer bodies themselves, the registration list is the dispatch surface that needs review.
    - **Plain English:** Think of observers like automatic notifications — every time someone saves a record, a hidden helper runs. If that helper decides to recalculate ALL the analytics for that entire hour from scratch, one small change becomes an expensive operation. We fixed this exact problem in the commerce system last month. Now we need to check whether the same pattern exists in the other 13 parts of the app that use these automatic helpers.
    - **Evidence:**
        ```php
        public function boot(): void
        {
            Professional::observe(ProfessionalObserver::class);
            Site::observe(SiteObserver::class);
            Block::observe(BlockObserver::class);
            Service::observe(ServiceObserver::class);
            ServiceCategory::observe(ServiceCategoryObserver::class);
            Customer::observe(CustomerObserver::class);
            BrandAffiliateInvite::observe(BrandAffiliateInviteObserver::class);
            BrandPartnerLink::observe(BrandPartnerLinkObserver::class);
            CommissionMovement::observe(CommissionMovementObserver::class);
            CommissionPayout::observe(CommissionPayoutObserver::class);
            ProfessionalIntegration::observe(ProfessionalIntegrationObserver::class);
            BrandProfile::observe(BrandProfileObserver::class);
            BrandStoreSettings::observe(BrandStoreSettingsObserver::class);
            SiteMedia::observe(SiteMediaObserver::class);
            AffiliateProductSelection::observe(AffiliateProductSelectionObserver::class);
        }
        ```
    - `[DRAFT, confidence: 0.6]` — Registrations are confirmed; observer bodies were not in the provided files, so the actual presence of rebuild/fan-out inside them is unverified.

- [ ] **CACHE-2** · P2 — `AccountTypeTransitionEvent` has 5 synchronous listeners that may contain cache-rebuild or fan-out work on a rare-but-complex event
    - **Where:** app/Providers/EventServiceProvider.php:11-18
    - **Affects:** Account type transitions (individual ↔ partner) — a low-frequency event, but if any listener does synchronous aggregate recomputation or multi-recipient notification dispatch, it blocks the transition request.
    - **Effort:** S (~0.5–1h) — audit 5 listener classes.
    - **What to do:**
        - Verify that `InvalidateProfessionalCacheOnTransition`, `SyncNotificationPreferencesOnTransition`, `ToggleStripeRequirementBannerOnTransition`, and `SetTransitionBannerOnTransition` are lightweight (single-row writes, enqueued mails, or cache key deletes — not multi-table rebuilds).
        - If any listener issues a rebuild job or a fan-out job synchronously, extract it to a queued job dispatch so the HTTP request completes quickly.
        - Confirm `LogAccountTypeTransition` is a fire-and-forget audit log append, not a synchronous analytics recompute.
    - **Technical:** Laravel's `$listen` array dispatches listeners synchronously by default (unless the listener implements `ShouldQueue`). The listener names suggest they do invalidation, notification preference sync, and banner toggling — likely lightweight — but if any one of them issues a query that touches aggregate tables or fans out notifications, the transition request blocks until that work finishes. Since transitions are rare this isn't a throughput risk, but it violates the principle that user-facing writes should never trigger synchronous aggregate rebuilds.
    - **Plain English:** When a user switches account types, five different helpers all run in sequence before the user gets a response. Four of them sound lightweight (clear a cache, sync some settings, show a banner). But if even one of them accidentally triggers a full recalculation, that user's request hangs. Given that account type switches are rare, this isn't urgent — but it's the same architectural smell we fixed in commerce, just on a less-busy road.
    - **Evidence:**
        ```php
        protected $listen = [
            AccountTypeTransitionEvent::class => [
                InvalidateProfessionalCacheOnTransition::class,
                LogAccountTypeTransition::class,
                // §28.5 side-effects — order matters: cache bust above ensures
                // AccountCapabilities::for() inside these listeners reads the new state.
                SyncNotificationPreferencesOnTransition::class,
                ToggleStripeRequirementBannerOnTransition::class,
                SetTransitionBannerOnTransition::class,
            ],
        ];
        ```
    - `[DRAFT, confidence: 0.5]` — Listener registrations confirmed; listener implementations were not in the provided files, so synchronous rebuild/fan-out inside them is unverified. Low-impact even if present because account type transitions are rare events.

- [ ] **CACHE-3** · P3 — `HandleAliasExpiringMail` passes a generic `object` to a queued mailable, bypassing Eloquent's `SerializesModels` contract clarity
    - **Where:** app/Mail/HandleAliasExpiringMail.php:9-12
    - **Affects:** Queue serialization of the handle-alias expiration mail — if `$alias` is ever not an Eloquent Model, serialization behaviour is unpredictable.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Replace `public readonly object $alias` with the concrete model type (e.g. `public readonly HandleAlias $alias` or the actual Eloquent model class) so static analysis and the `SerializesModels` trait contract are explicit.
        - Verify that `$alias` is always an Eloquent Model instance at the dispatch site.
    - **Technical:** The `HandleAliasExpiringMail` implements `ShouldQueue`, meaning Laravel serializes it for the queue worker. The `SerializesModels` trait handles Eloquent Model properties by storing only the model identifier (class + key) and rehydrating on wakeup — far more efficient than full PHP serialization. Using `object` as the type hint doesn't break this (the trait checks `instanceof Model` at runtime), but it obscures the contract from static analysis and makes it possible for a future caller to pass a non-Model object that gets fully serialized inline, quietly bloating the queue payload.
    - **Plain English:** This email is queued for background delivery, which means the system needs to package it up and store it temporarily. The good news is that Eloquent models are stored super efficiently — just an ID reference. But the code labels this parameter as a generic "object" instead of naming the specific model type. It works today, but it's like labelling a box "stuff" — someone could put a couch in there later and the whole system would struggle.
    - **Evidence:**
        ```php
        class HandleAliasExpiringMail extends Mailable implements ShouldQueue
        {
            use Queueable, SerializesModels;

            public function __construct(
                public readonly object $alias,
                public readonly string $bucket  // 't3' or 't1'
            ) {}
        ```
    - `[DRAFT, confidence: 0.7]` — The `object` type hint is visible; whether `$alias` is always an Eloquent Model is unverified without the dispatch site (not in provided files).

<!-- ═══ CHUNK: jobs ═══ -->


<!-- ═══ SUB-CHUNK: s1 (app/Jobs/Shopify) ═══ -->

I did not identify any scaling antipatterns in the provided files. The Shopify job implementations already reflect the post-rebuild patterns: append-only event logs, trigger-maintained rollups, push invalidation, LWW upserts, and chunked operations where needed. No findings to report.

<!-- ═══ SUB-CHUNK: s2 (app/Jobs/Cache app/Jobs/Cloudflare app/Jobs/Concerns app/Jobs/Exports app/Jobs/Fresha app/Jobs/Gdpr app/Jobs/Notifications app/Jobs/Square app/Jobs/Store app/Jobs/Streaming app/Jobs/Stripe app/Jobs/DeleteMediaArtifactsJob.php app/Jobs/ProcessImageVariantsJob.php app/Jobs/ProcessVideoVariantsJob.php) ═══ -->

- [ ] **#CACHE-1** · P2 — `VoidExpiredPayoutsJob::fireGraceWarnings()` unbounded `->get()` loads all candidate payouts into memory plus per-payout synchronous notification publishes
    - **Where:** app/Jobs/Stripe/VoidExpiredPayoutsJob.php:142-150 (unbounded get) and :166-175 (per-payout publish loop)
    - **Affects:** Stripe payout grace-warning delivery; ops visibility of stuck payouts. Memory pressure if pending-payout volume grows unexpectedly; job timeout risk from serial notification INSERTs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the unbounded `->get()` with `->chunkById(200)` and process tiers inside the chunk callback.
        - Batch per-payout `$publisher->publish()` calls into a bulk-notification dispatch (or at minimum collect publish payloads and flush once per chunk).
    - **Technical:** The method fetches every pending payout whose `void_at` falls within a 30-day window using a single `->get()` — no `limit`, no `chunkById`. The result set is then iterated in-memory three times (once per T-30/T-7/T-1 tier) and each qualifying payout triggers a synchronous `NotificationPublisher::publish()` call plus a `$payout->save()` to update the `grace_notifications_sent` JSONB column. At 30 brands × ~50 affiliates × ~100 orders/affiliate/year the absolute row count is bounded, but the pattern is fragile: a single brand with an anomalously large affiliate roster or a Stripe outage that stalls payout creation could produce enough pending rows to exhaust the 300s job timeout on the serial publish loop alone. The chunked+bulk replacement mirrors the pattern already used by `FanOutBrandStatusNotificationJob`.
    - **Plain English:** This is like a mailroom clerk who, once a day, picks up every single piece of outgoing mail from the entire building in one armload, then walks to the postbox and posts each letter one at a time. At 50 letters a day it works fine; at 500 letters the clerk drops the pile or the postbox closes before they finish. The fix is to carry the mail in small stacks and drop whole stacks into the postbox at once.
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

        // ... then iterated per-tier:
        foreach ([30, 7, 1] as $daysOut) {
            // ...
            foreach ($candidates as $payout) {
                // ...
                $publisher->publish(/* ... */);  // synchronous per-payout
                $payout->forceFill([/* ... */])->save();  // synchronous per-payout save
            }
        }
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **#CACHE-2** · P3 — `InviteExpirySweepJob` per-invite synchronous notification publish inside chunk loop without batching
    - **Where:** app/Jobs/Notifications/InviteExpirySweepJob.php:72-97
    - **Affects:** Brand managers receiving invite-expiry notifications. At pre-beta scale this is invisible; at target scale a brand with hundreds of pending invites hitting expiry on the same day sees serial INSERT latency inside the daily sweep.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Collect publish payloads per chunk and dispatch a single bulk-notification job or call a batch-publish method on `NotificationPublisher`.
        - Apply the same `Bus::batch()` fan-out pattern used by `FanOutBrandStatusNotificationJob` if per-recipient dedupe isolation is required.
    - **Technical:** Inside `chunkById(500)` the job iterates every expired invite row and calls `NotificationPublisher::publish()` synchronously — one INSERT (plus likely one notification-receipt INSERT) per expired invite. The bulk status update (`whereIn -> update`) is already batched; only the notification side remains per-row. At the scaling target (30 brands, each with perhaps 50–200 outstanding invites), the total daily volume is low enough that this is cosmetic, but the pattern is still a synchronous N× write loop where N is unbounded by the sweep event payload.
    - **Plain English:** After the clerk marks all the expired invitations in the ledger with one efficient stamp, they then walk to each brand manager's desk one at a time to hand-deliver a note about each individual invite. Grouping the notes by destination and delivering them in a single folder would be faster, but at current office size nobody notices the extra footsteps.
    - **Evidence:**
        ```php
        DB::table('brand.brand_affiliate_invites')
            ->whereIn('id', $ids)
            ->where('status', 'pending')
            ->update(['status' => 'expired', 'updated_at' => $now]);

        // ...

        foreach ($chunk as $invite) {
            try {
                // ...
                $publisher->publish(
                    professionalId: $brandId,
                    frontendType: 'Warning',
                    category: 'invites',
                    title: 'Invite expired',
                    body: "Your invite to {$label} has expired.",
                    dedupeKey: "invite.expired.{$invite->id}",
                    ctaUrl: '/account/affiliates',
                    retentionConfigKey: 'invite',
                );
                $notified++;
            } catch (\Throwable $e) {
                // ...
            }
        }
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#CACHE-3** · P3 — `NudgeStuckOnboardingJob` per-professional synchronous notification publish inside chunk loop without batching
    - **Where:** app/Jobs/Notifications/NudgeStuckOnboardingJob.php:124-151
    - **Affects:** Brands stuck in onboarding. Notification delivery latency within the daily sweep; at target scale (30 brands) the stuck-onboarding cohort is trivially small.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Mirror the fix for CACHE-2: collect publish payloads across the chunk and flush them as a batch rather than one-by-one.
        - Alternatively, since the stuck-onboarding cohort is intrinsically tiny (brands that signed up exactly 3/10/30 days ago and haven't advanced), this can be deferred until the NotificationPublisher itself gains a batch interface.
    - **Technical:** Same synchronous-per-row publish antipattern as `InviteExpirySweepJob`. The SQL query already chunks by 500 professionals; inside each chunk every qualifying professional triggers a `NotificationPublisher::publish()` call. The per-milestone dedupe key (`onboarding.nudge.{proId}.day_{day}`) means each brand gets at most one nudge per milestone, so the absolute row count is tiny — 30 brands × 3 milestones = 90 max notifications per day. The structural fix is low-effort but the operational impact at target scale is negligible, hence P3.
    - **Plain English:** Same mailroom pattern as the invite sweep, but for a much smaller pile of mail — at most three letters per brand, and only for the handful of brands that signed up exactly 3, 10, or 30 days ago. The clerk is still walking each letter individually, but there are so few letters that nobody would notice unless the business grew 100× overnight.
    - **Evidence:**
        ```php
        ->chunkById(500, function ($chunk) use ($publisher, $day, $milestone, &$nudged) {
            // ...
            foreach ($chunk as $row) {
                try {
                    // ...
                    $publisher->publish(
                        professionalId: $proId,
                        frontendType: $milestone['severity'],
                        category: 'profile_tasks',
                        title: $milestone['title'],
                        body: $milestone['body'],
                        dedupeKey: "onboarding.nudge.{$proId}.day_{$day}",
                        ctaUrl: '/account/overview',
                        primaryActionLabel: 'Continue setup',
                        retentionConfigKey: 'profile_task',
                    );
                    $nudged++;
                } catch (\Throwable $e) {
                    // ...
                }
            }
        }, 'p.id', 'id');
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CACHE-4** · P3 — `SendWeeklyAnalyticsNotificationJob` per-professional synchronous notification publish without batching
    - **Where:** app/Jobs/Notifications/SendWeeklyAnalyticsNotificationJob.php:87-94
    - **Affects:** Weekly analytics digest delivery to ~1,500 active professionals at target scale. Serial INSERT latency inside the Monday 09:00 UTC cron; no data loss risk.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Collect publish calls per chunk and dispatch a bulk-notification job or call a batch method on `NotificationPublisher`.
        - The chunk size of 200 already bounds per-query work; adding per-chunk publish batching keeps the total number of writes identical but collapses them into fewer round-trips.
    - **Technical:** The job queries professionals in chunks of 200, runs one batched metrics query per chunk against `commerce.orders`, then iterates each professional that has non-zero metrics and calls `NotificationPublisher::publish()`. At 1,500 active professionals the worst case is ~1,500 synchronous publish calls once per week — well within the 300s job timeout, but structurally the same per-row-loop antipattern as the other notification sweeps. The batched-metrics query (one per chunk) is optimal; only the publish side remains unbatched.
    - **Plain English:** Every Monday morning the clerk pulls a list of everyone who earned commission last week, then visits each person's desk individually to hand them a printed summary. At 50 desks this is a pleasant walk; at 1,500 desks the clerk is still walking at lunchtime. Dropping stacks of summaries at each department's mailroom instead of desk-by-desk would finish before the first coffee.
    - **Evidence:**
        ```php
        foreach ($professionals as $professional) {
            try {
                // ...
                $metrics = $metricsByPro->get($professional->id);
                if ($this->notifyProfessional($publisher, $professional, $metrics, $yearWeek)) {
                    $sent++;
                }
            } catch (\Throwable $e) {
                // ...
            }
        }

        // notifyProfessional() calls:
        $publisher->publish(
            professionalId: $professional->id,
            frontendType: 'Info',
            category: 'analytics_weekly',
            title: 'Your weekly analytics',
            body: $body,
            dedupeKey: "analytics.weekly.{$professional->id}.{$yearWeek}",
            ctaUrl: '/account/store?section=analytics',
            retentionConfigKey: 'analytics_weekly',
        );
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#CACHE-5** · P3 — `CheckStreamingLiveStatusJob` loads full `Block` Eloquent models to extract two scalar settings fields
    - **Where:** app/Jobs/Streaming/CheckStreamingLiveStatusJob.php:64-84
    - **Affects:** Streaming live-status polling every 2 minutes. Hydrates full Eloquent models for every block with `live_check_enabled=true` when only `settings->platform` and `settings->handle` are needed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `Block::query()->chunkById(...)` with a `DB::table('site.blocks')->select('id', 'settings')->...` that hydrates only the two JSONB fields needed.
        - Or use `->select(['id', 'settings'])` on the Eloquent query and avoid hydrating relations/timestamps.
    - **Technical:** Category 5 — eager-loaded Eloquent collections that hydrate full models where a lightweight query would do. The job runs every 2 minutes and iterates every active streaming block. At pre-beta scale (tens of blocks) the overhead is negligible; at target scale with 30 brands each potentially running streaming blocks, the total block count is still small (< 200). The `chunkById(500)` bounds memory per chunk, but each chunk still instantiates full `Block` models with all attributes, casts, and relations — `settings` is JSONB-cast, `created_at`/`updated_at` are Carbon-cast, soft-delete checks fire. A `selectRaw` on the JSONB fields would avoid all of that.
    - **Plain English:** Every two minutes the system opens every streaming-enabled block's full personnel file just to read two lines — the platform name and the streamer handle. It's like pulling a heavy filing-cabinet drawer all the way out to read a sticky note on the front. The drawer action is chunked so it never pulls more than 500 files at once, but using a lighter index-card system would be faster and use less energy.
    - **Evidence:**
        ```php
        Block::query()
            ->where('block_group', 'links')
            ->whereRaw("settings->>'live_check_enabled' = ?", ['true'])
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->chunkById(500, function ($blocks) use (&$handlesByPlatform, $streamingPlatforms): void {
                foreach ($blocks as $block) {
                    $settings = is_array($block->settings) ? $block->settings : [];
                    $platform = $settings['platform'] ?? null;
                    $handle = $settings['handle'] ?? null;
                    if (
                        $platform
                        && $handle
                        && in_array($platform, $streamingPlatforms, true)
                    ) {
                        $handlesByPlatform[$platform][] = $handle;
                    }
                }
            });
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#CACHE-6** · P2 — No `Cache::remember` / `cache()->remember` guarded by single-flight lock found in job layer, but missing audit of controller/service hot-read paths
    - **Where:** app/Jobs/ (all files reviewed — no `Cache::remember` calls present); controllers and services outside this file set are unexamined
    - **Affects:** Dashboard and public-site controllers that may use bare `Cache::remember` without `CacheLockService::rememberLocked` — cold-cache stampede risk after deploy or eviction.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Run `rg "Cache::remember\|cache\(\)->remember" app/Http app/Services` and sweep every call site for a missing `CacheLockService::rememberLocked` guard.
        - Apply the canonical replacement: `rememberLocked` + 60s TTL + ±20% jitter + SWR stale serving + push-invalidate on every write path.
    - **Technical:** The job layer is clean — every cache interaction in the provided files uses either `CacheLockService::rememberLocked` (WarmPublicSiteCacheJob), `Cache::deleteMultiple` with proper :stale-key preservation (InvalidateBrandAffiliatesCacheJob), or Redis locks (ProcessImageVariantsJob, ProcessVideoVariantsJob). However, the high-value targets listed in the lens (`ProfessionalAnalyticsController`, `StaffStatsController`, site-analytics ingest paths, `CacheKeyGenerator` call sites) were not included in the provided file set. The commerce analytics rebuild deployed 2026-05-06 proved that bare `Cache::remember` in dashboard controllers caused thundering-herd stampedes on cold cache after every deploy. The job-layer audit alone cannot confirm that the controller/service layer has been brought up to the same standard. This finding is a pointer to complete the sweep.
    - **Plain English:** The engine room (jobs) is spotless — every cache interaction uses the right locking pattern. But we haven't checked the shop floor (the dashboard controllers that serve pages to users). The last time we looked there, we found bare cache calls that would all expire at the same moment after a deploy and cause a stampede of expensive database queries. Think of it like checking all the circuit breakers in the basement but not the outlets upstairs. This finding is a nudge to finish the inspection.
    - **Evidence:**
        ```
        # Job layer: clean
        $ grep -r "Cache::remember\|cache()->remember" app/Jobs/
        (no results in provided files)

        # Controller/service layer: unexamined
        # Scope gap — the files listed as high-value targets in the lens
        # (ProfessionalAnalyticsController, StaffStatsController, etc.)
        # were not provided in this audit batch.
        ```
    - `[DRAFT, confidence: 0.6]`

<!-- ═══ CHUNK: ctrl-prof-a ═══ -->


<!-- ═══ SUB-CHUNK: s1 (app/Http/Controllers/Api/Professional/Brand app/Http/Controllers/Api/Professional/SiteManagement) ═══ -->

- No findings identified in the provided scope.

<!-- ═══ SUB-CHUNK: s2 (app/Http/Controllers/Api/Professional/Analytics app/Http/Controllers/Api/Professional/Store) ═══ -->

- [ ] **#CACHE-1** · P2 — Triplicate brand-partner-link query inside a single cache-miss closure
    - **Where:** app/Http/Controllers/Api/Professional/Analytics/BrandCommerceAnalyticsController.php (in `queryPageViewsByBucket`, `querySiteVisitTotals`, `queryCartEventCounts`)
    - **Affects:** Brand dashboard overview — three independent helper methods each re-fetch the same `brand.brand_partner_links` rows on every cold-cache request.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Hoist the `brand.brand_partner_links` pluck into the `overview()` closure and pass the resulting `$affiliateIds` array into each helper.
        - If the array is empty, short-circuit before calling the helpers so they skip their main queries entirely.
    - **Technical:** Inside `BrandCommerceAnalyticsController::overview()`, the `rememberLocked` callback calls `queryPageViewsByBucket()`, `querySiteVisitTotals()`, and `queryCartEventCounts()`. Each method independently runs `DB::table('brand.brand_partner_links')->where('brand_professional_id', ...)->pluck('affiliate_professional_id')`. At 30 brands × 50 affiliates, this is three identical ~50-row queries instead of one — a minor write-amplification on the read path that compounds with every dashboard cold start. The result set is deterministic within the closure's lifetime and should be computed once.
    - **Plain English:** Imagine three different staff members each walking to the filing cabinet to pull the exact same list of affiliate IDs, one after another, instead of one person making a copy and handing it to the other two. Wastes a few seconds on every dashboard load.
    - **Evidence:**
        ```php
        // queryPageViewsByBucket, lines ~408-413
        $affiliateIds = DB::table('brand.brand_partner_links')
            ->where('brand_professional_id', $brandProfessionalId)
            ->whereNull('deleted_at')
            ->pluck('affiliate_professional_id')
            ->toArray();
        ```
        ```php
        // querySiteVisitTotals, lines ~433-438 — identical block
        $affiliateIds = DB::table('brand.brand_partner_links')
            ->where('brand_professional_id', $brandProfessionalId)
            ->whereNull('deleted_at')
            ->pluck('affiliate_professional_id')
            ->toArray();
        ```
        ```php
        // queryCartEventCounts, lines ~453-458 — identical block
        $affiliateIds = DB::table('brand.brand_partner_links')
            ->where('brand_professional_id', $brandProfessionalId)
            ->whereNull('deleted_at')
            ->pluck('affiliate_professional_id')
            ->toArray();
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CACHE-2** · P2 — Synchronous Shopify API fan-out in `resetToDefaults` across multiple brands
    - **Where:** app/Http/Controllers/Api/Professional/Store/AffiliateProductController.php:279-296
    - **Affects:** Affiliates linked to multiple brands triggering "reset to defaults" — each brand's re-seed is a synchronous external API call serialised in a `foreach`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Dispatch a dedicated `ResetAffiliateDefaultSelectionsJob` per brand-professional-id instead of calling `seedDefaultSelections` on the request thread.
        - Add a short rate-limit so a double-click can't enqueue duplicate resets.
    - **Technical:** When no `brand_professional_id` is passed, `resetToDefaults` fetches all linked brand IDs and iterates them with `$this->catalogService->seedDefaultSelections(...)`. `seedDefaultSelections` reaches out to Shopify (both read and write GraphQL calls), and each brand iteration blocks the loop. At 30 brands × 1–3 linked brands per affiliate, this is 1–3 synchronous Shopify round-trips holding the HTTP request open. A transient Shopify slowdown would push the response past a reasonable timeout and tie up a PHP-FPM worker. The canonical replacement is a chunked/batched fan-out: queue one job per brand and let Horizon process them concurrently.
    - **Plain English:** When an affiliate clicks "reset to defaults," the server makes a phone call to Shopify for every brand they're linked to, one after another, while the affiliate stares at a spinner. If Shopify takes 3 seconds per call and they're linked to 3 brands, that's a 9-second wait.
    - **Evidence:**
        ```php
        $brandIds = DB::table('brand.brand_partner_links')
            ->where('affiliate_professional_id', $pro->id)
            ->whereNull('deleted_at')
            ->pluck('brand_professional_id');

        foreach ($brandIds as $brandId) {
            try {
                $this->catalogService->seedDefaultSelections($pro, (string) $brandId, clearExisting: true);
            } catch (\Throwable $e) {
                report($e);
                Log::warning('Failed to reset default selections for brand', [...]);
            }
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CACHE-3** · P3 — Deprecated `shopSummary` method carries duplicate cache-key logic and live-query infrastructure
    - **Where:** app/Http/Controllers/Api/Professional/Analytics/ProfessionalAnalyticsController.php:259-403
    - **Affects:** Maintenance burden — any change to `CacheKeyGenerator::analyticsSummary()` or the commerce aggregate schema must be mirrored in this dead path.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Audit for remaining in-flight callers (route definition, frontend, mobile).
        - Remove the method and its route once no callers remain.
    - **Technical:** The method is annotated `@deprecated Data is now folded into summary()` and duplicates the caching pattern from `summary()`: it builds its own `$cacheKey` as a raw string (`'analytics:shop:'.$professional->id.':'...`) instead of using `CacheKeyGenerator`, re-implements date-range parsing, and runs independent live queries against `commerce.orders` and `analytics.*` tables. Every future schema or cache-key convention change creates a risk of drift between the two code paths. Dead code also shows up in code search results and confuses new contributors.
    - **Plain English:** This is like keeping an old, disconnected checkout counter behind the new one. It still plugs in, still has a cash register, but nobody uses it — yet every time the store remodels, someone has to remember to dust it. It should just be taken out.
    - **Evidence:**
        ```php
        /**
         * Shop analytics funnel for the authenticated professional (as affiliate).
         *
         * @deprecated Data is now folded into summary() — kept temporarily for in-flight callers.
         */
        public function shopSummary(Request $request): JsonResponse
        {
            // ...
            $cacheKey = 'analytics:shop:'.$professional->id.':'.$from->format('YmdH').':'.$to->format('YmdH').':'
                .($useHourlyBuckets ? 'hour' : 'day').":v{$summaryVersion}";
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#CACHE-4** · P3 — `BrandStoreSettingsController::show()` issues four uncached DB queries on every settings-page load
    - **Where:** app/Http/Controllers/Api/Professional/Store/BrandStoreSettingsController.php:30-64
    - **Affects:** Brand dashboard settings tab — every open of the Store Settings page hits `brand_store_settings`, `core.professionals` (via `$pro->loadMissing('site')`), `professional_integrations` (via `resolveBrandIntegration`), and `brand_profiles` on the request thread.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `show()` response in `CacheLockService::rememberLocked` with a 60s TTL (±20% jitter, SWR).
        - Invalidate the key from `update()` (and `deploy()`) so writes surface immediately.
    - **Technical:** `show()` reads `BrandStoreSettings`, loads the site relationship, resolves the Shopify integration (which touches `ProfessionalIntegration`), and fetches `BrandProfile` — all uncached. This is a low-traffic settings page (one user per brand, opened occasionally), so the absolute DB load is negligible at 30 brands. The value of adding a cache here is consistency: every other analytics/overview controller in the same directory already uses `rememberLocked`, and leaving this one uncached trains contributors that "settings endpoints don't need caching," which becomes a problem if a future settings endpoint becomes hot.
    - **Plain English:** The settings page runs four database lookups every time a brand opens it. At 30 brands this is completely harmless — it's like taking four steps to reach a light switch. The fix is just to add a sticky note so the pattern matches the rest of the house and nobody accidentally copies the "no cache" habit onto a page that actually gets heavy traffic.
    - **Evidence:**
        ```php
        public function show(Request $request): JsonResponse
        {
            $pro = $this->currentProfessional($request);
            $storeSettings = BrandStoreSettings::where('professional_id', $pro->id)->first();   // query 1
            $pro->loadMissing('site');                                                          // query 2 (relationship)
            // ...
            $resolved = $this->catalogService->resolveBrandIntegration($pro);                   // query 3 (ProfessionalIntegration)
            // ...
            $brandProfile = BrandProfile::where('professional_id', $pro->id)->first();          // query 4
            // ...
            return $this->success(new BrandStoreSettingsResource([...]));
        }
        ```
    - `[DRAFT, confidence: 0.8]`

<!-- ═══ CHUNK: ctrl-prof-b-staff ═══ -->

- [ ] **CACHE-1** · P3 — Two-pass foreach reorder loop produces 2N individual UPDATEs per reorder request
    - **Where:** app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php:284-297
    - **Affects:** Affiliate/brand users reordering gallery or content pool media. At pre-beta (~6 items per pool) the overhead is trivial; at scale with many concurrent reorders on the same site, advisory lock contention could add up.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the two-pass foreach with a single `CASE WHEN` bulk update: `UPDATE site_media SET sort_order = CASE id WHEN ? THEN ? ... END WHERE site_id = ? AND id IN (...)`
        - Keep the explicit `$site->touch()` at the end; it already closes the observer-bypass gap.
    - **Technical:** The reorder method intentionally bypasses `SiteMediaObserver` (mass query-builder updates don't fire Eloquent events), then does two passes — one to move everything to a high offset (so no unique-constraint collisions), one to place items at final positions. This produces 2N `UPDATE` statements inside a transaction that already holds an advisory lock. A single `CASE WHEN` update achieves the same ordering in one round-trip. The documented observer bypass + explicit `$site->touch()` pattern remains correct either way.
    - **Plain English:** When someone drags photos into a new order, the system updates each photo's position twice — once to move it out of harm's way, once to its final spot. For 6 photos, that's 12 database writes inside a single locked operation. A single smarter update could do it in one pass. At 30 brands this is invisible; at 300 during a launch spike those extra writes stack up.
    - **Evidence:**
        ```php
        foreach ($finalIds as $index => $id) {
            SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('id', $id)
                ->update(['sort_order' => $offset + $index]);
        }

        foreach ($finalIds as $index => $id) {
            SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('id', $id)
                ->update(['sort_order' => $index]);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **CACHE-2** · P3 — Two-pass foreach reorder loop on link blocks produces 2N individual UPDATEs
    - **Where:** app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffLinkBlockManagementController.php:126-141
    - **Affects:** Staff reordering a professional's custom link blocks. Bounded by typical link block count (~5–10), so impact is negligible at pre-beta scale.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the two-pass foreach with a single `CASE WHEN` bulk update identical to the CACHE-1 recommendation.
    - **Technical:** Same antipattern as CACHE-1 — offset pass followed by final-sort pass, both inside a transaction holding `pg_advisory_xact_lock`. A single `CASE WHEN` update achieves the same ordering atomically without the double-write overhead.
    - **Plain English:** Same double-write pattern as the photo reorder. When support staff reorders a brand's custom links, each link gets moved twice in the database. A single move would be cleaner.
    - **Evidence:**
        ```php
        foreach ($newOrder as $i => $id) {
            Block::query()
                ->where('professional_id', $professional->id)
                ->where('site_id', $site->id)
                ->where('block_group', 'links')
                ->where('block_type', 'link')
                ->where('id', $id)
                ->update(['sort_order' => $offset + $i]);
        }

        foreach ($newOrder as $i => $id) {
            Block::query()
                ->where('professional_id', $professional->id)
                ->where('site_id', $site->id)
                ->where('block_group', 'links')
                ->where('block_type', 'link')
                ->where('id', $id)
                ->update(['sort_order' => $i]);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **CACHE-3** · P3 — Two-pass foreach reorder loop on section blocks produces 2N individual UPDATEs
    - **Where:** app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffSectionManagementController.php:126-141
    - **Affects:** Staff reordering section blocks (gallery, services, shop, booking, bio). Section count per site is typically 5–8, so impact is negligible at pre-beta scale.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the two-pass foreach with a single `CASE WHEN` bulk update per the CACHE-1 recommendation.
    - **Technical:** Identical two-pass offset-then-final pattern as CACHE-1 and CACHE-2. All three controllers share the same reorder implementation shape; a single shared helper or `CASE WHEN` pattern applied consistently would eliminate the duplication and the write amplification in one change.
    - **Plain English:** Same story as photos and links. When staff rearranges the sections on a brand's public page, each section gets written twice. Trivial at current scale; worth tidying while the pattern is fresh.
    - **Evidence:**
        ```php
        foreach ($newOrder as $i => $id) {
            Block::query()
                ->where('professional_id', $professional->id)
                ->where('site_id', $site->id)
                ->where('block_group', 'sections')
                ->where('id', $id)
                ->update(['sort_order' => $offset + $i]);
        }

        foreach ($newOrder as $i => $id) {
            Block::query()
                ->where('professional_id', $professional->id)
                ->where('site_id', $site->id)
                ->where('block_group', 'sections')
                ->where('id', $id)
                ->update(['sort_order' => $i]);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **CACHE-4** · P2 — Booking analytics TTL passed as Carbon instance instead of integer seconds — inconsistent with every other `rememberLocked` call site
    - **Where:** app/Http/Controllers/Api/Professional/Booking/BookingAnalyticsController.php:57-58
    - **Affects:** Dashboard booking analytics overview — a hot read for every professional with Square/Fresha connected in smart mode. If `CacheLockService::rememberLocked` passes the TTL through to its internal lock timeout, a Carbon instance cast to int becomes `1` second, rendering the lock ineffective (lock expires before the query completes, defeating single-flight protection).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `now()->addMinutes(2)` with `120` and `now()->addMinutes(10)` with `600` to match every other call site.
        - Audit `CacheLockService::rememberLocked` to confirm whether its lock-timeout parameter accepts `DateTimeInterface` or expects `int`. If the lock path also needs the Carbon → second conversion, add it defensively.
    - **Technical:** Every other `rememberLocked` call in the codebase passes an integer (60, 30, 5, etc.). Here, `$ttl = $metricsContext['use_hourly'] ? now()->addMinutes(2) : now()->addMinutes(10)` produces a Carbon instance. If `rememberLocked` passes this directly to `Cache::remember()`, Laravel's `Repository::getSeconds()` will correctly compute `diffInSeconds()` — the cache TTL will be ~120 or ~600s. However, if `rememberLocked` uses the same value for its internal Redis lock timeout (which almost certainly expects an int), the lock may expire in 1 second, removing single-flight protection exactly when the query is slowest (cache miss, DB under load). Switching to plain ints eliminates the ambiguity.
    - **Plain English:** Every other cache in the dashboard uses a number like "60 seconds." The booking analytics cache uses a date object like "2 minutes from now," which means two different things depending on where in the code it lands — as a cache lifetime it works fine, but as a lock timeout it silently collapses to 1 second. If the database is slow, the lock expires before the query finishes, and multiple dashboard visitors can accidentally fire the same heavy query at once.
    - **Evidence:**
        ```php
        $ttl = $metricsContext['use_hourly'] ? now()->addMinutes(2) : now()->addMinutes(10);
        $cacheKey = CacheKeyGenerator::bookingAnalytics(
            $professionalId,
            (string) $metricsContext['range_from'],
            (string) $metricsContext['range_to'],
            (string) $metricsContext['group_by']
        );

        return $this->success($this->cacheLock->rememberLocked($cacheKey, $ttl, function () use (...) {
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **CACHE-5** · P2 — Staff booking analytics TTL has the same Carbon-instead-of-int inconsistency
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffBookingController.php:99-100
    - **Affects:** Staff booking analytics inspector — mirror of CACHE-4. Same lock-timeout fragility when staff view a professional's booking dashboard.
    - **Effort:** S (~0.5–1h) — fix alongside CACHE-4 in one pass.
    - **What to do:**
        - Replace `now()->addMinutes(2)` with `120` and `now()->addMinutes(10)` with `600`.
    - **Technical:** Identical pattern to CACHE-4. The staff-side controller copies the same TTL construction; both should be fixed together to keep the two analytics surfaces in lockstep.
    - **Plain English:** The staff view of booking analytics has the same date-object-instead-of-seconds issue as the professional dashboard. Fix both at once.
    - **Evidence:**
        ```php
        $ttl = $metricsContext['use_hourly'] ? now()->addMinutes(2) : now()->addMinutes(10);
        $cacheKey = CacheKeyGenerator::bookingAnalytics(
            $professionalId,
            (string) $metricsContext['range_from'],
            (string) $metricsContext['range_to'],
            (string) $metricsContext['group_by'],
        );

        return $this->success($this->cacheLock->rememberLocked($cacheKey, $ttl, function () use (...) {
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **CACHE-6** · P2 — Global notification broadcast fan-out lacks visible batching — risk of N child jobs or N eager receipt rows
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php:93-95
    - **Affects:** All email subscribers on the target list when staff sends a global notification (policy updates, incidents, feature announcements). At 30 brands × ~50 affiliates = 1,500 subscribers, a naive per-recipient job dispatch would flood Horizon.
    - **Effort:** M (~2–4h) — depends on what `SendStaffBroadcastEmailsJob` actually does internally.
    - **What to do:**
        - Open `SendStaffBroadcastEmailsJob` and verify whether it chunks recipients into batches (e.g., 50 per batch with `->chunk()`) or dispatches one child job / sends one email per recipient in a tight `foreach`.
        - If it's the latter, refactor to chunked dispatch and ensure `NotificationReceipt` rows are created lazily on first read rather than eagerly at fan-out.
    - **Technical:** The controller dispatches a single `SendStaffBroadcastEmailsJob` with the notification ID and a list key. Without seeing the job internals, the canonical concern is that it queries all subscribers for that list and loops — either dispatching N `SendTransactionalNotificationEmailJob` children or inserting N `NotificationReceipt` rows eagerly. The rebuild plan's notification design target is lazy receipt creation (receipt row inserted only when the user reads or dismisses the notification) plus chunked email dispatch. If this job predates that standard, it may still be using eager fan-out.
    - **Plain English:** When Partna staff sends a platform-wide announcement, the system needs to email every subscriber. If the code loops through 1,500 subscribers one at a time — dispatching a separate queue job for each — it'll flood the background worker pipeline. The right approach is to break the list into manageable chunks (say 50 at a time) and only create the "read/unread" tracking rows when someone actually opens the notification, not ahead of time for everyone.
    - **Evidence:**
        ```php
        } elseif ($notification->professional_id === null) {
            // Global: newsletter-style mass email to email_list_key subscribers.
            // Bypasses per-category prefs by design — globals are announcement-class
            // (incidents, policy updates) that should reach the audience regardless.
            SendStaffBroadcastEmailsJob::dispatch($notification->id, $emailListKey);
        }
        ```
    - `[DRAFT, confidence: 0.6]`

<!-- ═══ CHUNK: ctrl-public-internal ═══ -->

- [ ] **#CACHE-1** · P2 — `analytics.booking_events` table is UPDATEd on retry, overwriting original event data and losing audit trail
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicBookingController.php:1158-1210
    - **Affects:** Booking analytics/audit — cannot reconstruct the lifecycle of a booking (accepted → completed → cancelled) because each delivery overwrites `status`, `raw_payload`, and related fields on the single row.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Split into append-only `booking_events` (immutable, one row per delivery) and a separate mutable projection `booking_state` keyed by `square_booking_id` for "current status."
        - Keep the table's `UNIQUE` constraint on `(professional_id, square_booking_id)` on the projection, not the event log, so retries produce additional event rows instead of mutating the original.
    - **Technical:** The `recordBookingAnalyticsAndNotify` method resolves an existing `eventId` via `square_booking_id`, then either inserts or updates a single row in `analytics.booking_events`. The update branch overwrites `raw_payload` (the full validated checkout payload + resolved service shape), `status`, `amount_paid_cents`, and `updated_at`. A booking that transitions from `accepted` → `completed` on a later Square webhook loses the `accepted` snapshot. At pre-beta volumes (single-digit bookings/day/professional) this is harmless; at the 30×50×100 target with retries and status-change webhooks, it destroys the ability to reconcile Square-side lifecycle against local records. Canonical replacement: append-only event log + a mutable projection for "current status."
    - **Plain English:** Think of this like a receipt printer that, instead of printing a new receipt when an order status changes, finds the original receipt and overwrites it with a Sharpie. You lose the history — you can't see that it was "accepted" before it was "completed." For a handful of bookings this doesn't matter; at scale, it makes it impossible to audit what happened when.
    - **Evidence:**
        ```php
        $existingEventId = null;
        if ($bookingId !== '') {
            $existingEventId = DB::table('analytics.booking_events')
                ->where('professional_id', $professionalId)
                ->where('square_booking_id', $bookingId)
                ->value('id');
        }

        $eventId = is_string($existingEventId) && trim($existingEventId) !== ''
            ? trim($existingEventId)
            : (string) Str::uuid();

        // ... builds $attributes including 'raw_payload', 'status', 'amount_paid_cents' ...

        if ($existingEventId) {
            DB::table('analytics.booking_events')
                ->where('id', $eventId)
                ->update($attributes);  // overwrites original state
        } else {
            DB::table('analytics.booking_events')
                ->insert(array_merge($attributes, ['id' => $eventId, 'created_at' => now()]));
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CACHE-2** · P3 — `Cache::has` + `Cache::put` TOCTOU race in `PublicShopifyStorefrontController` where `Cache::add` would be atomic
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicShopifyStorefrontController.php:111-117
    - **Affects:** Storefront token creation dedup — under concurrent requests for a brand whose `storefront_token` is empty, two `CreateStorefrontAccessTokenJob` instances may be dispatched instead of one.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `if (! Cache::has(...))` guard + `Cache::put(...)` with a single `if (Cache::add($jobKey, true, 600))` call.
        - Keep the `Log::info` inside the `if` block — `Cache::add` returning `true` means this is the first claimant.
    - **Technical:** The `storefrontConfig` method checks `Cache::has($jobKey)` (a read), and if false, dispatches the job then writes `Cache::put($jobKey, true, 600)`. Between the read and write, a second concurrent request can also see `has() === false` and also dispatch. The rest of the codebase uses `Cache::add($key, true, TTL)` — Redis `SETNX` — which returns `false` when the key already exists, making the check-and-set atomic. `CreateStorefrontAccessTokenJob` likely has `ShouldBeUnique`, so the double-dispatch is deduplicated at the queue, but the extra Redis write + log noise is avoidable. Impact is negligible at pre-beta (brands rarely hit this concurrently); at the 30-brand target it's still noise-level.
    - **Plain English:** This is like two receptionists both checking a shared calendar, seeing an empty slot, and both booking it — then the system later notices the double-booking and cancels one. We have a tool (`Cache::add`) that locks the slot at the moment of booking so only one receptionist can claim it. The fix is swapping two lines for one that does the check-and-claim in a single step.
    - **Evidence:**
        ```php
        // Storefront token missing — dispatch creation job (with dedup)
        if ($storefrontToken === '') {
            $jobKey = 'storefront-token-job:'.$integration->id;
            if (! Cache::has($jobKey)) {
                Log::info('Storefront token missing, dispatching creation job.', [
                    'integration_id' => (string) $integration->id,
                ]);
                CreateStorefrontAccessTokenJob::dispatch((string) $integration->id);
                Cache::put($jobKey, true, 600);
            }

            return response()->json([
                'status' => 'pending',
                'message' => 'Storefront token is being created. Try again in a few seconds.',
            ], 202);
        }
        ```
    - `[DRAFT, confidence: 0.95]`

<!-- ═══ CHUNK: http-boundary ═══ -->

- [ ] **#CACHE-1** · P3 — `StorePlanSubscriptionRequest::freePlanId` uses `Cache::remember` without single‑flight lock or jitter  
    - **Where:** `app/Http/Requests/Api/Professional/StorePlanSubscriptionRequest.php:44`
    - **Affects:** `/api/professional/subscriptions` endpoint — stampede risk on cold cache after deploy or eviction, bounded by single global key and trivial query.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the lookup in `CacheLockService::rememberLocked` to guarantee single‑flight under cold‑cache spikes.
        - Add ±20 % jitter to the TTL so multiple pods don’t expire the same key simultaneously.
        - If the existing justification is accepted, document the decision in the code and close as intentional risk — but still add jitter.
    - **Technical:** `Cache::remember` without a lock allows every concurrent request to execute the callback when the cache is cold; in a multi‑pod Laravel Horizon worker pool this can still produce a small thundering herd. Although the key is global (no per‑tenant cardinality) and the DB lookup is fast (`Plan::where('plan_key','free')->value('id')`), the canonical `CacheLockService::rememberLocked` with jitter and SWR delivers the same result with zero stampede risk at negligible cost.
    - **Plain English:** Picture a notice board with one phone number pinned on it. If the notice falls down, everyone in the room tries to dial the company at once to get the number again — that’s a tiny scramble because the call is quick, but it’s still unnecessary noise. A lock means the first person holds the phone line, gets the number, and repins it before anyone else dials. The scramble is small here, but using the lock makes the system perfectly quiet for no extra effort.
    - **Evidence:**
        ```php
        private function freePlanId(): string
        {
            // Plain Cache::remember — single global key (no per-tenant cardinality),
            // tiny lookup, 1hr TTL. Stampede impact is bounded; the single-flight
            // CacheLockService machinery would be overkill here.
            return Cache::remember('billing.free_plan_id', 3600, function () {
                return Plan::where('plan_key', 'free')->value('id') ?? '';
            });
        }
        ```
    - `[DRAFT, confidence: 0.7]`
