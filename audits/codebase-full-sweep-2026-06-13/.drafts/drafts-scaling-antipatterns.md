
<!-- ═══ LENS: scaling-antipatterns | CHUNK: write-paths ═══ -->

- [ ] **#CACHE-1** · P2 — `PostgresEventWriter::writeMany()` breaks batch contract for session pings
    - **Where:** app/Services/Analytics/Writers/PostgresEventWriter.php:120-122
    - **Affects:** Any batched analytics ingest path (future `BufferedIngestor` or multi-event writes); currently latent because `QueuedIngestor` dispatches one event per job.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Collect all session-ping rows and issue a single multi-row `INSERT … ON CONFLICT` statement (or a CTE-based upsert) instead of one `DB::statement()` per session.
        - Use the driver-agnostic `GREATEST`/`MAX` function lookup once per batch, not once per event.
    - **Technical:** `writeMany()` correctly batches visits, clicks, and sections into arrays and calls `insertOrIgnore` once per type, but session pings fall into a `foreach` loop that runs one raw `DB::statement()` per ping. `upsertSession()` issues an `INSERT … ON CONFLICT DO UPDATE` with `GREATEST()` merge semantics — functionally correct but O(N) DB round-trips instead of one. The `AnalyticsEventWriter` interface contract promises batched writes; this loop silently breaks that contract for session events.
    - **Plain English:** Imagine a checkout line where 50 people each hand the cashier one item individually, while the other three lines accept full baskets. The session-ping loop is that one-item-at-a-time line — it works at low traffic but backs up when a burst of visitors all heartbeat at once. Fixing it makes all four lines basket-capable.
    - **Evidence:**
        ```php
        foreach ($sessionEvents as $event) {
            $this->upsertSession($event);
        }
        ```
        ```php
        private function upsertSession(AnalyticsEvent $e): void
        {
            // ...
            DB::connection('pgsql')->statement(
                "INSERT INTO analytics.site_sessions … ON CONFLICT (id) DO UPDATE SET …",
                [ /* bound params */ ]
            );
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CACHE-2** · P2 — `SiteMediaObserver` cascades full site invalidation + purge per image on bulk uploads
    - **Where:** app/Observers/Core/SiteMediaObserver.php:71-83 (touchParentSite)
    - **Affects:** Professional gallery uploads (batch of 10–50 images); every image triggers a full Redis key sweep (~30+ keys) and two job dispatches.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the per-media `touch()` call with a debounced approach — set a Redis flag `site:media:{$siteId}:dirty` with a 1s TTL, and have a separate listener or a short-delay job perform one `invalidateSiteImages()` + one `CloudflareCachePurgeJob` dispatch.
        - Keep the `reevaluateIfRelevant()` call inline (it only matters when the media count crosses the zero/non-zero boundary).
    - **Technical:** `touchParentSite()` calls `$site->touch()`, which triggers `SiteObserver::saved`. That observer runs `invalidateSite()` (`Cache::deleteMultiple` on ~30+ keys), dispatches `CloudflareCachePurgeJob`, and conditionally dispatches `WarmPublicSiteCacheJob`. For an upload of 20 images, this produces 20 `UPDATE sites SET updated_at = …` queries, ~600 Redis DEL commands, and up to 40 job dispatches (only coalesced at the queue level for `ShouldBeUnique` jobs). The canonical replacement is a debounced coalesce: set a dirty flag once, flush once.
    - **Plain English:** Uploading 20 photos to your gallery is like ringing the doorbell 20 times for one visitor — the butler runs to the door, checks, resets the house, and calls for cleanup on every single ring. A smarter doorbell waits one second after the first ring before alerting the butler once.
    - **Evidence:**
        ```php
        private function touchParentSite(SiteMedia $media, string $action): void
        {
            try {
                $site = $media->site;
                if (! $site) { return; }
                $site->touch();
            } catch (\Throwable $e) { /* logged */ }
        }
        ```
        ```php
        // SiteObserver::saved — called on every touch()
        $this->siteCache->invalidateSite($site);
        CloudflareCachePurgeJob::dispatch($handle)->afterCommit();
        // …
        WarmPublicSiteCacheJob::dispatch(strtolower($site->subdomain))->afterCommit();
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#CACHE-3** · P2 — `BlockObserver` triggers full site invalidation per block on bulk reorders
    - **Where:** app/Observers/Core/BlockObserver.php:40-56 (onBlockMutated)
    - **Affects:** Dashboard block reordering (drag-and-drop can fire 20+ saves); each save cascades into a full `invalidateSite` + Cloudflare purge + cache warm.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Same debounce pattern as `SiteMediaObserver`: set a short-lived Redis flag on the block's site, and let a deferred job or a `terminate()` middleware perform one invalidation sweep after the request completes.
        - Alternatively, use `Site::withoutEvents()` in the controller during batch reorders and call `invalidateSite()` once explicitly.
    - **Technical:** `onBlockMutated()` unconditionally calls `$block->site->touch()` for every created/updated/deleted block. A bulk reorder firing 20 Eloquent `saved` events produces 20 `UPDATE sites` queries and 20× the `SiteObserver::saved` cascade (~30 Redis DELs + 2 job dispatches each). `CloudflareCachePurgeJob` is `ShouldBeUnique`, so duplicates coalesce at the queue, but the Redis write amplification and DB UPDATE amplification remain. The canonical replacement is a debounced coalesce via a short-TTL Redis flag or a controller-side batch guard.
    - **Plain English:** Rearranging 20 blocks on your page is like pushing 20 dominoes, each one triggering the same security alarm. Only the first one needs to sound — the rest are noise. A single alarm at the end of the rearrangement is just as safe and much quieter.
    - **Evidence:**
        ```php
        private function onBlockMutated(Block $block, string $action): void
        {
            if (! $block->site) { return; }
            try {
                $block->site->touch();
            } catch (\Throwable $e) { /* … */ }
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#CACHE-4** · P2 — `ServiceObserver` runs full user + site invalidation per service on batch operations
    - **Where:** app/Observers/Core/ServiceObserver.php:77-82 (runHooks)
    - **Affects:** Dashboard service reordering / batch activation; each service mutation fires `invalidateUser` (~15 Redis keys) + `touchParentSite` cascade.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Debounce `invalidateUser` and `touchParentSite` with a short-TTL Redis flag on the user, flushing once per request burst.
        - `reevaluateBooking` can stay inline — it's a lightweight DB read and only matters when crossing the empty/non-empty boundary.
    - **Technical:** `runHooks()` calls `bust()` which invokes `UserCacheService::invalidateUser()` — a `Cache::deleteMultiple` of ~15 keys plus an optional site invalidation — then calls `touchParentSite()` which triggers the full `SiteObserver::saved` cascade. A bulk reorder of 15 services produces 15× this work. `invalidateUser` already has a `bustSite: false` guard passed from `bust()` (since `touchParentSite` follows), so the site invalidation doesn't double-fire, but the per-service pattern still amplifies Redis operations linearly with batch size.
    - **Plain English:** Changing the order of 15 services on your price list currently sends 15 separate "refresh everything" commands to Redis and Cloudflare. It should send one at the end, like hitting "Save" once instead of after every line edit.
    - **Evidence:**
        ```php
        private function runHooks(Service $service): void
        {
            try {
                $pro = $this->bust($service);
                $this->reevaluateBooking($service, $pro);
                $this->touchParentSite($service, $pro);
            } catch (\Throwable $e) { /* … */ }
        }
        ```
        ```php
        // bust() calls:
        $this->userCache->invalidateUser($pro, bustSite: false);
        ```
        ```php
        // touchParentSite calls:
        $pro?->site?->touch();
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#CACHE-5** · P2 — `ServiceCategoryObserver` dispatches per-category site payload invalidation + purge
    - **Where:** app/Observers/Core/ServiceCategoryObserver.php:61-82 (bust)
    - **Affects:** Dashboard category rename/reorder (typically 4–10 categories); each fires `Cache::deleteMultiple` + `invalidateSitePayload` + `CloudflareCachePurgeJob` dispatch.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Consolidate the Redis key deletions into a single `Cache::deleteMultiple` at the end of the request (debounce with a short Redis flag).
        - `CloudflareCachePurgeJob` is `ShouldBeUnique` so it coalesces at the queue, but the dispatch attempts still waste Horizon's dispatch pipeline — debouncing reduces that overhead.
    - **Technical:** `bust()` performs a surgical `Cache::deleteMultiple` of 4 keys, then calls `SiteCacheService::invalidateSitePayload()` (another multi-key delete), and dispatches `CloudflareCachePurgeJob`. While `CloudflareCachePurgeJob` is `ShouldBeUnique` (coalescing duplicates), the Redis operations and job dispatch attempts still scale linearly with the number of categories modified in one save. A category reorder touching 8 categories produces 8× Redis sweeps.
    - **Plain English:** Renaming 8 service categories fires 8 separate "reload the page" signals to Redis and Cloudflare. One signal after the last rename would achieve the same result with 1/8th the work.
    - **Evidence:**
        ```php
        private function bust(ServiceCategory $category): void
        {
            // …
            Cache::deleteMultiple([
                CacheKeyGenerator::professionalDashboardServices($userId),
                CacheKeyGenerator::professionalDashboardServices($userId).':stale',
                CacheKeyGenerator::professionalServices($userId),
                CacheKeyGenerator::professionalServices($userId).':stale',
            ]);
            // …
            app(SiteCacheService::class)->invalidateSitePayload($site);
            CloudflareCachePurgeJob::dispatch($site->subdomain);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CACHE-6** · P3 — `SmartLinkObserver` per-link full user + site invalidation
    - **Where:** app/Observers/Core/SmartLinkObserver.php:31-42 (runHooks)
    - **Affects:** Dashboard smart-link CRUD (typically 1–2 links at a time); each fires `invalidateUser` + `touch()` cascade.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - If bulk smart-link operations become a feature, apply the same debounce pattern. At current usage (single-link CRUD), the overhead is acceptable but the observer pattern should still carry a comment flagging the risk for future bulk paths.
    - **Technical:** `runHooks()` calls `UserCacheService::invalidateUser()` (which deletes ~15 Redis keys plus a potential site invalidation) and `$pro->site?->touch()` (triggering `SiteObserver::saved`). Smart links are typically modified one at a time, so this is the least acute of the observer cascade findings, but it shares the same structural antipattern: the observer assumes single-row mutations while Eloquent events fire per-row regardless of batch context.
    - **Plain English:** Editing one smart link refreshes the entire billboard. That's fine when you're changing one link — it's overkill if you ever need to change 50 at once. The design should note this so a future bulk-editing feature doesn't accidentally trigger 50 billboard rebuilds.
    - **Evidence:**
        ```php
        private function runHooks(SmartLink $link): void
        {
            try {
                $pro = User::query()->with('site')->find($link->user_id);
                if ($pro) {
                    $this->userCache->invalidateUser($pro);
                    $pro->site?->touch();
                }
            } catch (\Throwable $e) { /* … */ }
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#CACHE-7** · P2 — `CustomerObserver` per-customer Redis invalidation on bulk imports
    - **Where:** app/Observers/Core/CustomerObserver.php:26-30 (invalidateCount)
    - **Affects:** CSV customer imports or batch operations; each customer create/update/delete/restore fires two Redis DEL commands.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Debounce with a short-TTL Redis flag per user (e.g. `customer_count:{$userId}:dirty`, 1s TTL) and flush `customerCount` once at the end of the batch.
        - Or use `Cache::decrement` / `Cache::increment` for per-row deltas instead of invalidation — a simple atomic counter adjustment eliminates the need to recompute from the DB entirely.
    - **Technical:** `invalidateCount()` unconditionally calls `Cache::forget($key)` and `Cache::forget($key.':stale')` on every Eloquent event. A CSV import creating 500 customer rows fires 500 × 2 = 1,000 Redis DEL commands. The read path (`UserCacheService::getCustomerCount`) recomputes via `COUNT(*)` on the next miss — a single DB aggregate that would be more efficiently maintained with `Cache::increment`/`decrement` per-row instead of full invalidation per row. The signed-delta approach (increment on create, decrement on delete) keeps the cached count accurate without any recompute.
    - **Plain English:** Importing 500 customers to your mailing list sends 500 "recount everything!" messages to Redis. Instead, the system could just tick a counter up by 1 for each new customer — 500 tiny ticks instead of 500 full recounts. Same result, 1/500th the work.
    - **Evidence:**
        ```php
        private function invalidateCount(Customer $customer): void
        {
            if (! empty($customer->user_id)) {
                $key = CacheKeyGenerator::customerCount((string) $customer->user_id);
                Cache::forget($key);
                Cache::forget($key.':stale');
            }
        }
        ```
    - `[DRAFT, confidence: 0.85]`
