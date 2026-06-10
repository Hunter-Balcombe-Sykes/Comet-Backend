`★ Insight ─────────────────────────────────────`
- `Cache::memo()->remember()` provides within-request memoization but falls through to a raw `Cache::remember()` on Redis miss — no lock, no jitter, no stale copy. This is architecturally invisible because the API surface looks like the locked version, but concurrent workers still stampede on an external Shopify API call. DeepSeek missed both usages entirely.
- `FanOutBrandStatusNotificationJob` already uses `Bus::batch()` with `chunkById(500)` and `BATCH_CHUNK_SIZE=200` — it's one of the best-implemented fan-out patterns in the codebase. DeepSeek's CACHE-5 was a false positive driven by the observer dispatch site alone, without reading the job.
- The "Rebuild*AggregatesJob" booking files DeepSeek named as "known suspects" don't exist — the Glob returned nothing. They were either never created or already cleaned up alongside the booking-dropped decision.
`─────────────────────────────────────────────────`

# Caching & Write-Amplification Audit — 2026-05-11

**Branch:** development
**Lens:** Scaling antipatterns: write amplification, rebuild-on-write, weak caching
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Cache/CacheLockService.php
- app/Services/Cache/SiteCacheService.php
- app/Services/Cache/AnalyticsCacheService.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/ProfessionalCacheService.php
- app/Services/Store/BrandCatalogService.php
- app/Services/Store/AffiliateProductCatalogService.php
- app/Observers/Core/SiteObserver.php
- app/Observers/Core/ServiceObserver.php
- app/Observers/Core/BrandProfileObserver.php
- app/Observers/Core/BrandPartnerLinkObserver.php
- app/Observers/Core/BrandStoreSettingsObserver.php
- app/Observers/Core/CustomerObserver.php
- app/Observers/Core/SiteMediaObserver.php
- app/Observers/Core/CommissionMovementObserver.php
- app/Observers/Core/CommissionPayoutObserver.php
- app/Observers/Core/ProfessionalIntegrationObserver.php
- app/Observers/Core/BrandAffiliateInviteObserver.php
- app/Observers/Professional/ProfessionalObserver.php
- app/Observers/Retail/BrandStoreSettingsObserver.php
- app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php
- app/Jobs/Cache/InvalidateConnectedAffiliateCachesJob.php
- app/Jobs/Cache/WarmPublicSiteCacheJob.php
- app/Jobs/Cache/AggregateCacheMetricsJob.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **#CACHE-1** · P2 — `SiteObserver::saved` duplicates the `BrandPartnerLink` lookup and dispatches 2N jobs on subdomain changes
    - **Where:** app/Observers/Core/SiteObserver.php (saved → cascadeAffiliateKvSync) + app/Services/Cache/SiteCacheService.php (invalidateSite affiliate loop)
    - **Affects:** Brand operators changing their subdomain. `invalidateSite` does one `BrandPartnerLink` + one `Site::whereIn` query, then dispatches N `InvalidateConnectedAffiliateCachesJob`. Immediately after, `cascadeAffiliateKvSync` issues an identical `BrandPartnerLink` query and dispatches N `SyncSubdomainToKvJob`. At 50 affiliates that's 2 identical DB reads and 100 Redis `RPUSH` calls from the HTTP request thread on subdomain change.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Refactor `SiteObserver::saved` to collect `affiliate_professional_id` from `BrandPartnerLink` once (from the result `invalidateSite` already queries) and pass the slice to `cascadeAffiliateKvSync` as a parameter, eliminating the second DB query.
        - Alternatively, return the queried affiliate IDs from `invalidateSite` so the observer can reuse them for the KV cascade.
        - Add a `$connectedAffiliateIds` parameter to `cascadeAffiliateKvSync` so it uses the pre-fetched list when called from the observer context.
    - **Technical:** On a site save where `wasRecentlyCreated || wasChanged('subdomain')`, both `SiteCacheService::invalidateSite` and `SiteObserver::cascadeAffiliateKvSync` independently call `BrandPartnerLink::query()->where('brand_professional_id', $professionalId)->pluck('affiliate_professional_id')`. These are structurally identical queries issued back-to-back within the same request. Beyond the duplicate DB read, two separate snapshots of the affiliate list are taken with a small time window between them — a concurrent affiliate invite created in that window would get cache-invalidated but not KV-synced (or vice versa). The fix is a single read passed to both. On every non-subdomain-change site save, only the N `InvalidateConnectedAffiliateCachesJob` dispatches run; those have jitter and are correct by design.
    - **Plain English:** When a brand renames their website address, the app makes the same "fetch all connected affiliates" database call twice in a row — once to clear their cached pages and once to update the routing table. It's like mailing two identical address books to the post office separately instead of making one copy and handing both departments the same sheet. The fix is to look up the affiliate list once and share the result with both tasks.
    - **Evidence:**
        ```php
        // SiteObserver.php — invalidateSite always runs, affiliate lookup inside:
        $this->siteCache->invalidateSite($site);

        // SiteObserver.php — cascadeAffiliateKvSync runs on create/subdomain-change, identical query:
        private function cascadeAffiliateKvSync(string $brandProfessionalId): void
        {
            BrandPartnerLink::query()
                ->where('brand_professional_id', $brandProfessionalId)
                ->pluck('affiliate_professional_id')
                ->each(function (string $affiliateId): void {
                    SyncSubdomainToKvJob::dispatch($affiliateId);
                });
        }

        // SiteCacheService::invalidateSite — identical BrandPartnerLink query:
        $connectedProfessionalIds = BrandPartnerLink::query()
            ->where('brand_professional_id', $professionalId)
            ->pluck('affiliate_professional_id')
            ->all();
        ```

- [ ] **#CACHE-2** · P2 — `CacheLockService::writeWithJitter` jitters the primary TTL but uses a fixed stale TTL — synchronized `:stale` expiry across the fleet
    - **Where:** app/Services/Cache/CacheLockService.php (`writeWithJitter`, the `int` branch)
    - **Affects:** Every `rememberLocked`-backed key with an int TTL: professional model (60s), services (1800s), site blocks (900s), analytics visits/clicks, customer count. When all pods cold-fill keys simultaneously after a Redis flush or deploy, primary keys spread across a ±20% window but every `:stale` copy lands at exactly the same second 10× later. When that second arrives, every SWR fast path simultaneously returns stale and races for the recompute lock.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply an independent `mt_rand` draw to `$staleTtl` in `writeWithJitter`, e.g. `$staleTtl = (int) round($ttl * self::STALE_TTL_MULTIPLIER * (0.8 + mt_rand(0, 4000) / 10000.0))`.
        - Keep the jitter distribution proportional to the stale window (±20% of the stale TTL), not the primary TTL, so the spread is meaningful.
    - **Technical:** `$staleTtl = $ttl * self::STALE_TTL_MULTIPLIER` has no jitter. When a 60s primary key is written with jitter across the fleet (48–72s), all `:stale` copies are written at the same wall-clock second and expire together at `now + 600s`. The thundering herd the primary jitter prevents re-emerges on the stale key 10 minutes later. Adding a second `mt_rand` draw costs one integer operation per write and spreads the stale expiry window from a single second to a ~240s spread at the 60s TTL, or a ~3600s spread at the 1800s service TTL.
    - **Plain English:** Every key in the system has a primary copy (short-lived) and a backup copy (long-lived, served while the primary refreshes). The primary copies are set to expire at slightly different times so they don't all demand refresh simultaneously. But the backup copies all expire at exactly the same moment — 10 minutes after they were written. The fix is to give backup copies the same random "grace period" the primary copies already have.
    - **Evidence:**
        ```php
        // CacheLockService.php — writeWithJitter
        $jitteredTtl = (int) round($ttl * (0.8 + mt_rand(0, 4000) / 10000.0));
        $staleTtl = $ttl * self::STALE_TTL_MULTIPLIER;  // ← no jitter

        Cache::put($key, $value, $jitteredTtl);
        Cache::put($staleKey, $value, $staleTtl);        // ← synchronized expiry across all pods
        ```

- [ ] **#CACHE-3** · P2 — `SiteCacheService::writePayloadWithStale` uses a fixed stale TTL — synchronized `:stale` expiry on the highest-traffic key in the system
    - **Where:** app/Services/Cache/SiteCacheService.php (`writePayloadWithStale`)
    - **Affects:** `site:payload:{subdomain}:stale` — the public site payload cache that serves 95% of traffic. Same thundering-herd risk as CACHE-2 but on the hottest key in the system. Note: commit `ea994cf` added the SWR fast path to `getPublicSitePayload`; the stale TTL jitter gap was not addressed in that commit.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply the same jitter to `$staleTtl` in `writePayloadWithStale`: `$staleTtl = (int) round($baseTtl * self::PAYLOAD_STALE_TTL_MULTIPLIER * (0.8 + mt_rand(0, 4000) / 10000.0))`.
        - Align the jitter formula with `CacheLockService::writeWithJitter` so both paths are hardened symmetrically. If CACHE-2 is fixed first, consider extracting the jitter logic to a shared static helper both classes call.
    - **Technical:** `writePayloadWithStale` correctly jitters the primary TTL via `$this->jitteredPayloadTtl()` but computes stale TTL as a fixed multiple of the config value: `(int) config('...public_payload') * self::PAYLOAD_STALE_TTL_MULTIPLIER`. Every process that cold-fills the public site payload cache (e.g. after the `SiteObserver` calls `invalidateSite` on a brand edit) writes a `:stale` key expiring at the same absolute second. At 30 brands with multiple server pods, a burst of brand edits in quick succession could create a coordinated expiry cluster. At pre-beta scale (1–2 pods) the risk is low; at pilot launch with multiple pods and active brand operators, the stale copies for popular sites could expire together and create a short latency spike.
    - **Plain English:** The public site cache — the most important cache in the app — stores both a fresh copy and a backup copy of every site's data. The fresh copy expires at a randomly-spread time to avoid traffic spikes. But the backup copy still expires at exactly the same second on every server. When that moment arrives on a popular site, every simultaneous visitor who hits an expired primary triggers the site reload at once. Adding the same random spread to the backup copy eliminates this last synchronized expiry point.
    - **Evidence:**
        ```php
        // SiteCacheService.php — writePayloadWithStale
        private function writePayloadWithStale(string $key, mixed $value): void
        {
            $staleTtl = (int) config('partna.cache.ttls.public_payload') * self::PAYLOAD_STALE_TTL_MULTIPLIER;

            Cache::put($key, $value, $this->jitteredPayloadTtl());       // ← primary: jittered
            Cache::put($key.':stale', $value, $staleTtl);                // ← stale: fixed, no jitter
        }
        ```

- [ ] **#CACHE-4** · P2 — `Cache::memo()->remember` in catalog services bypasses `CacheLockService` — no single-flight lock on hot Shopify API calls
    - **Where:** app/Services/Store/AffiliateProductCatalogService.php:192 (`fetchActiveCatalog`) · app/Services/Store/BrandCatalogService.php:374 (`fetchBrandCatalog`)
    - **Affects:** `brandActiveCatalog` key: served to every affiliate loading a brand's product catalog (highest-concurrency catalog path). `brandAdminCatalog` key: brand admins managing their catalog (lower concurrency but same Admin API cost). `Cache::memo()` provides within-request memoization only; on a cache miss across concurrent workers, all workers simultaneously execute the Shopify API callback — no lock prevents this.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace both `Cache::memo()->remember(...)` calls with `CacheLockService::rememberLocked(...)`, injecting `CacheLockService` into `AffiliateProductCatalogService` and `BrandCatalogService`.
        - For `fetchActiveCatalog` (5-minute TTL): add `±20%` jitter automatically via `rememberLocked`. Consider adding a `:stale` benefit for this key — a 50-minute stale window means an affiliate loading the catalog during a Shopify API hiccup still sees last-known-good product data rather than an error.
        - The `Cache::memo()` in-request deduplication benefit is preserved: `rememberLocked` checks `Cache::get($key)` at the top of every call, so repeated calls within the same request still hit Redis in-memory rather than re-acquiring the lock.
    - **Technical:** `Cache::memo()` creates an in-memory `Illuminate\Cache\MemoCache` store that is request-scoped. Its `remember()` method first checks the in-memory store; on an in-memory miss it calls `Cache::remember()` on the underlying Redis driver, which has no distributed locking. Under concurrent load — multiple affiliates loading the same brand's product catalog simultaneously after the 5-minute TTL expires — all workers miss both the in-memory and Redis caches and call `queryStorefrontCatalog` concurrently. `queryStorefrontCatalog` is a Shopify Storefront API paginated request; concurrent calls on the same brand consume Shopify's per-storefront rate limit bucket together, risking 429 responses. `CacheLockService::rememberLocked` serialises the rebuild to one worker while others wait up to 5 seconds (or return stale via SWR), reducing concurrent Shopify calls from N to 1.
    - **Plain English:** When the product catalog cache expires, the current system sends everyone who's looking at that brand's catalog to Shopify at the same moment to fetch a fresh copy. If 20 affiliates all load the catalog at the same second, Shopify receives 20 identical requests instead of one. Shopify has rate limits, so this can cause errors for some affiliates. Every other hot cache in the app already uses a "one person fetches, everyone else waits" approach — the catalog cache just needs the same treatment.
    - **Evidence:**
        ```php
        // AffiliateProductCatalogService.php:192 — no lock, no jitter, no stale
        return Cache::memo()->remember(
            CacheKeyGenerator::brandActiveCatalog($brandProfessionalId),
            now()->addMinutes(5),
            fn () => $this->queryStorefrontCatalog($brandProfessionalId),
        );

        // BrandCatalogService.php:374 — same pattern on Admin API call
        return Cache::memo()->remember(
            CacheKeyGenerator::brandAdminCatalog((string) $brand->id),
            (int) config('partna.cache.ttls.brand_admin_catalog'),
            fn () => $this->queryAdminCatalog($brand),
        );
        ```

---

## P3 — Nice to have

- [ ] **#CACHE-5** · P3 — `ServiceObserver::runHooks` issues one `Professional` DB query per service save — batching concern on bulk imports
    - **Where:** app/Observers/Core/ServiceObserver.php (`runHooks` → `bust`)
    - **Affects:** Bulk service imports (CSV upload, migration scripts). Each saved/deleted/restored service triggers `Professional::with('site')->find()` + `ProfessionalCacheService::invalidateProfessional()` + 2× `reevaluateEnabled` for `booking` and `services` sections, all from the Eloquent observer. 50 services from the same professional = 50 identical DB queries and 50 `deleteMultiple` calls on overlapping key sets. Note: Square/Fresha sync job dispatches are guarded by `config('partna.features.square_sync/fresha_sync', false)` which are both `false` in current config (booking/Square/Fresha dropped per `project_booking_dropped.md`), so those paths are dead.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Collect changed service IDs in a static accumulator during the request lifecycle and flush them via `dispatchAfterResponse` to a single `BatchServiceChangeJob` that groups by professional, does one `Professional::with('site')->findMany`, one `invalidateProfessional` per unique professional, and one round of `reevaluateEnabled` per professional.
        - Alternatively, guard `runHooks` with a per-professional debounce: if the same `professional_id` was already busted in this request, skip subsequent busts (mark in a static map).
        - Remove the dead `reevaluateBooking` → `'booking'` section re-evaluation path since booking is dropped; keep only `'services'`.
    - **Technical:** At pre-beta scale (brands editing services one at a time via UI), the 50-query-per-import scenario doesn't occur. The risk is a migration script, a CSV importer, or a future bulk-update endpoint that calls `Service::save()` in a loop without observer suppression. Each call to `bust()` issues `Professional::with('site')->find()` — the `with('site')` relation means 2 queries per call (professional + site), so 50 services = 100 queries for a single professional's import. The `invalidateProfessional` call runs `Cache::deleteMultiple` with a ~12-key set; 50 consecutive calls on overlapping keys are idempotent but redundant. The observer's `$afterCommit = true` flag means these run after each individual row's commit in a transaction-less import loop.
    - **Plain English:** When a brand uploads a list of 50 services at once, the system treats each row as a completely separate event. For each one, it looks up the professional from the database, clears their cache, and re-checks which website sections should be visible — 50 times, with nearly identical work each time. It's like sending 50 individual text messages saying "your voicemail is full" instead of one message saying "you have 50 new messages." At current traffic levels this is harmless; it becomes wasteful if a bulk-import tool is ever built on top of the same save path.
    - **Evidence:**
        ```php
        // ServiceObserver.php — runHooks called per-save with no batching
        private function runHooks(Service $service, string $action): void
        {
            try {
                $pro = $this->bust($service);           // Professional::with('site')->find() + invalidateProfessional
                $this->reevaluateBooking($service, $pro); // reevaluateEnabled('booking') + reevaluateEnabled('services')

                if ($this->shouldDispatchSquareSync($pro)) {   // always false — feature flag off
                    $this->dispatchSquareSync($service->id, $action);
                }
                if ($this->shouldDispatchFreshaSync($pro)) {   // always false — feature flag off
                    $this->dispatchFreshaSync($service->id, $action);
                }
            } catch (\Throwable $e) { /* ... */ }
        }
        ```

- [ ] **#CACHE-6** · P3 — `AnalyticsCacheService::invalidateAnalytics` uses a 90-day explicit deletion loop for visits/clicks — partial version-token migration leaves two distinct invalidation strategies in the same method
    - **Where:** app/Services/Cache/AnalyticsCacheService.php (`invalidateAnalytics`)
    - **Affects:** Every analytics write that calls `invalidateAnalytics`. `affiliateCommerceAnalytics` (v3) and `brandCommerceAnalytics` (v4) keys are busted via `bumpAnalyticsVersion` (single `INCR`); `analyticsVisits` and `analyticsClicks` keys are busted via a 90-day explicit `deleteMultiple` loop (180 keys per call); `affiliateProjections` keys are busted via an explicit `Cache::forget` loop. The visits/clicks keys use no version token, so the `INCR`-based fast path can't cover them. `analyticsSummary` (q3) keys are not version-token-embedded either and are not explicitly deleted in this method — verify whether that path is still populated post-Phase-4.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Embed the dynamic version token into `CacheKeyGenerator::analyticsVisits` and `analyticsClicks` (matching the pattern used in `affiliateCommerceAnalytics` v3 / `brandCommerceAnalytics` v4): call `Cache::get(self::analyticsSummaryVersion($professionalId), 0)` and include it in the key string.
        - Once visits/clicks keys embed the version token, replace the 90-day deletion loop with a single `bumpAnalyticsVersion($professionalId)` call — the old keys become unreachable and expire naturally on their TTL.
        - Note: `affiliateProjections` uses a static schema-version prefix (`v1`) in the key, not the dynamic runtime counter. The explicit `Cache::forget` loop for projections is therefore NOT redundant and must remain until `affiliateProjections` is migrated to the dynamic-token pattern too.
        - Verify whether `analyticsSummary` (q3) keys are still populated post-Phase-4; if so, add them to either the version-token scheme or the explicit deletion loop.
    - **Plain English:** The analytics cache has two different housekeeping strategies running side by side: one clever "change the version number so old keys become invisible" trick (used for commerce analytics), and one manual "go delete every key from the last 90 days individually" approach (used for visit and click stats). Both work correctly, but having two strategies in the same method is confusing for future maintainers and means any future improvements to the clever approach don't automatically help the manual approach. The goal is to bring visits/clicks onto the version-number trick so invalidation collapses to a single atomic operation.
    - **Evidence:**
        ```php
        // AnalyticsCacheService.php — invalidateAnalytics: two strategies co-existing
        $this->bumpAnalyticsVersion($professionalId); // ← single INCR busts v3/v4 commerce keys

        // affiliateProjections: explicit forget (necessary — uses static schema v1, not dynamic token)
        foreach ($projectionVariants as $w) {
            Cache::forget(CacheKeyGenerator::affiliateProjections($professionalId, $w));
            Cache::forget(CacheKeyGenerator::affiliateProjections($professionalId, $w).':stale');
        }

        // visits/clicks: 90-day loop (necessary today because keys lack version token)
        for ($i = 0; $i < 90; $i++) {
            $date = Carbon::now()->subDays($i)->format('Ymd');
            $keys[] = CacheKeyGenerator::analyticsVisits($professionalId, $date, $date);
            $keys[] = CacheKeyGenerator::analyticsClicks($professionalId, $date, $date);
        }
        Cache::deleteMultiple(array_values(array_unique($keys)));
        ```

`★ Insight ─────────────────────────────────────`
- The `Cache::memo()->remember()` antipattern (CACHE-4) is particularly deceptive because it *looks* like CacheLockService usage at a glance — same `->remember()` method name, same key/TTL/closure signature. The key behavioral difference is that `Cache::memo()` returns a `MemoCache` instance whose `remember()` delegates to the bare Laravel `Cache::remember()` (no distributed lock). This is worth a code-review comment wherever it's intentionally used.
- The partial version-token migration (CACHE-6) is a common pattern when refactoring cache invalidation incrementally — the existing explicit loops are correct safety nets that you can't remove until every consumer of those keys is also migrated to embed the version token. Removing the 90-day loop prematurely without migrating the key format would silently break analytics cache invalidation.
`─────────────────────────────────────────────────`
