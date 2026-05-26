
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
