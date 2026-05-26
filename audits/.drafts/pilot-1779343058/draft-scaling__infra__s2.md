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
