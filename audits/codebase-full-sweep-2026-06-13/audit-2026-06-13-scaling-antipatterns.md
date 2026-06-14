# Scaling Antipatterns Audit — 2026-06-13

**Branch:** development
**Lens:** Scaling antipatterns: write amplification, rebuild-on-write, weak caching
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-5`
**Source files audited:**
- `app/Services/Analytics/Writers/PostgresEventWriter.php`
- `app/Jobs/Analytics/RecordAnalyticsEventJob.php`
- `app/Services/Analytics/AnalyticsCacheService.php`
- `app/Services/Cache/SiteCacheService.php`
- `app/Services/Cache/UserCacheService.php`
- `app/Observers/Core/SiteMediaObserver.php`
- `app/Observers/Core/BlockObserver.php`
- `app/Observers/Core/ServiceObserver.php`
- `app/Observers/Core/ServiceCategoryObserver.php`
- `app/Observers/Core/SmartLinkObserver.php`
- `app/Observers/Core/CustomerObserver.php`
- `app/Observers/Core/SiteObserver.php`
- `app/Jobs/Cache/WarmPublicSiteCacheJob.php`
- `app/Jobs/Cloudflare/CloudflareCachePurgeJob.php`
- `app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php`
- `app/Observers/Core/IntegrationConnectionObserver.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 6 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **#CACHE-1** · P2 — `PostgresEventWriter::writeMany()` breaks batch contract for session pings
    - **Where:** `app/Services/Analytics/Writers/PostgresEventWriter.php:66-68` (loop) and `:235-269` (`upsertSession`)
    - **Affects:** Analytics ingest correctness under the future `BufferedIngestor` path; currently latent because `QueuedIngestor` dispatches one event per job. If a multi-event batch is ever funnelled through `writeMany()`, every session-ping event becomes a separate DB round-trip.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Collect all session-ping rows into a batch array, compute `$greatest` once, and issue a single `INSERT … ON CONFLICT DO UPDATE` using a CTE or parameterised multi-row VALUES clause instead of one `DB::statement()` per ping.
        - Move the `GREATEST`/`MAX` driver-sniff (`$greatest = DB::connection('pgsql')->getDriverName() === 'sqlite' ? 'MAX' : 'GREATEST'`) outside the loop — it hits the driver layer on every call.
    - **Technical:** `writeMany()` correctly batches page-views, link-clicks, and section-views into arrays and calls `insertOrIgnore` once per type. Session pings fall into a `foreach ($sessionEvents as $event) { $this->upsertSession($event); }` loop that issues one raw `DB::statement()` per ping, breaking the single-round-trip contract the interface promises. The `AnalyticsEventWriter` contract exists precisely so a future `BufferedIngestor` can call `writeMany()` with 50 events and pay one DB round-trip; this loop silently degrades that to 50 for the session type. The fix is a PostgreSQL-native multi-row upsert: build a VALUES list of all ping rows, then execute one `INSERT INTO analytics.site_sessions … ON CONFLICT (id) DO UPDATE SET last_seen_at = GREATEST(…), duration_seconds = GREATEST(…) WHERE site_sessions.site_id = EXCLUDED.site_id`.
    - **Plain English:** The system is designed to hand the database a full basket of events at once — one trip to the counter for 50 items. Page views, clicks, and section views all get that basket treatment. But session heartbeats each get their own separate trip — one item at a time, 50 trips for 50 heartbeats. This is fine today (each job carries one heartbeat), but if the ingest pipeline is ever upgraded to bundle events together, session heartbeats will silently become 50× more expensive than every other event type.
    - **Evidence:**
        ```php
        foreach ($sessionEvents as $event) {
            $this->upsertSession($event);
        }
        ```
        ```php
        private function upsertSession(AnalyticsEvent $e): void
        {
            // …
            $greatest = DB::connection('pgsql')->getDriverName() === 'sqlite' ? 'MAX' : 'GREATEST';

            DB::connection('pgsql')->statement(
                "INSERT INTO analytics.site_sessions … ON CONFLICT (id) DO UPDATE SET
                    last_seen_at = {$greatest}(site_sessions.last_seen_at, EXCLUDED.last_seen_at),
                    duration_seconds = {$greatest}(site_sessions.duration_seconds, EXCLUDED.duration_seconds)
                 WHERE site_sessions.site_id = EXCLUDED.site_id",
                [ /* bound params */ ]
            );
        }
        ```

- [ ] **#CACHE-2** · P2 — `SiteMediaObserver` cascades full Redis invalidation per image on bulk uploads
    - **Where:** `app/Observers/Core/SiteMediaObserver.php:53-69` (`touchParentSite`)
    - **Affects:** Professional gallery uploads. A batch of 20 images fires 20 `UPDATE sites SET updated_at=…` queries and 20 full Redis multi-key delete sweeps (~30 keys each, ~600 Redis DEL commands total). Both `CloudflareCachePurgeJob` and `WarmPublicSiteCacheJob` are `ShouldBeUnique` so they coalesce to one actual enqueue each — the job storm is already handled. The unguarded amplification is the DB and Redis write load.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Introduce a short-lived Redis dirty flag per site (e.g. `site:dirty:{siteId}` with a 1–2s TTL set by `Cache::add`). If the flag is already set, skip `site->touch()` — the first image in the burst already fired the invalidation.
        - Alternatively, disable Eloquent events on the `Site` model within the upload controller action (`Site::withoutEvents(fn() => …)`) and call `SiteCacheService::invalidateSite()` once explicitly after the batch completes.
        - Keep `reevaluateIfRelevant()` inline — it's a lightweight boundary check and must run per image.
    - **Technical:** `touchParentSite()` calls `$site->touch()`, which fires `SiteObserver::saved`. That observer always calls `$this->siteCache->invalidateSite($site)` (a `Cache::deleteMultiple` over ~30 keys covering payload, blocks, images, email brand, handle resolve, alias variants) and dispatches `CloudflareCachePurgeJob` and `WarmPublicSiteCacheJob`. Both downstream jobs implement `ShouldBeUnique` with `$uniqueFor = 120`, so only the first dispatch within the 2-minute window lands in the queue — duplicate dispatches are silently dropped. The unchecked amplification is therefore: N DB UPDATEs + N × ~30 Redis DEL commands. At 20 images that's 600 Redis operations where 30 would suffice.
    - **Plain English:** Uploading 20 photos to your gallery currently sends a "refresh the entire page cache" signal 20 separate times — one for each photo as it saves. The system is smart enough to not actually repeat the Cloudflare cleanup 20 times (it deduplicates that), but it does wipe and rewrite the Redis cache 20 times in a row for what is logically one bulk upload. The fix is to notice that the signal has already been sent and skip the remaining 19.
    - **Evidence:**
        ```php
        private function touchParentSite(SiteMedia $media, string $action): void
        {
            try {
                $site = $media->site;
                if (! $site) {
                    return;
                }
                $site->touch();
            } catch (\Throwable $e) { /* logged */ }
        }
        ```
        ```php
        // SiteObserver::saved — called on every touch()
        $this->siteCache->invalidateSite($site);              // ~30 Redis DELs
        CloudflareCachePurgeJob::dispatch($handle)->afterCommit();   // ShouldBeUnique — coalesces
        WarmPublicSiteCacheJob::dispatch(…)->afterCommit();          // ShouldBeUnique — coalesces
        ```

- [ ] **#CACHE-3** · P2 — `BlockObserver` triggers full Redis invalidation per block on bulk reorders
    - **Where:** `app/Observers/Core/BlockObserver.php:44-62` (`onBlockMutated`)
    - **Affects:** Dashboard block reordering (drag-and-drop sending N individual PATCH requests or a controller loop over Eloquent models). Each `saved` event fires one `UPDATE sites` + one `invalidateSite()` sweep. A reorder touching 20 blocks produces 20 DB UPDATEs and ~600 Redis DEL commands.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - For batch reorders in the controller, wrap the Eloquent update loop in `Site::withoutEvents(fn() => …)` and call `SiteCacheService::invalidateSite($site)` once after the loop.
        - Or apply the same Redis dirty-flag debounce as CACHE-2: `Cache::add("site:dirty:{$siteId}", 1, 1)` — skip `touch()` if already set.
    - **Technical:** `onBlockMutated()` unconditionally calls `$block->site->touch()` on every create/update/delete event. The observer comment correctly documents the delegation chain ("`touch()` advances `sites.updated_at`, which fires `SiteObserver::saved`"), but it does not guard against burst contexts. Unlike `WarmPublicSiteCacheJob` and `CloudflareCachePurgeJob` (both `ShouldBeUnique`), the Redis `invalidateSite()` sweep has no deduplication — it runs on every `SiteObserver::saved` invocation. A 20-block reorder queues 2 actual jobs (coalesced) but runs the Redis sweep 20 times.
    - **Plain English:** Rearranging 20 blocks on your page sends the same "wipe the cache" command to Redis 20 times in rapid succession. The external Cloudflare cleanup is smart enough to only happen once, but the internal Redis wipe happens all 20 times. Wrapping the reorder operation so the wipe only fires once at the end would be just as correct and 20× cheaper on Redis.
    - **Evidence:**
        ```php
        private function onBlockMutated(Block $block, string $action): void
        {
            if (! $block->site) {
                return;
            }

            try {
                $block->site->touch();
            } catch (\Throwable $e) {
                // touch() failure means Redis invalidation AND Cloudflare purge are both
                // skipped (they run via SiteObserver::saved). Log with full context …
            }
        }
        ```

- [ ] **#CACHE-4** · P2 — `ServiceObserver` fires full user + site invalidation per service on batch mutations
    - **Where:** `app/Observers/Core/ServiceObserver.php:68-82` (`runHooks`)
    - **Affects:** Dashboard service reordering or bulk status toggling. Each service `saved` event calls `invalidateUser()` (a `Cache::deleteMultiple` over ~13 user-level keys) plus `touchParentSite()` which cascades through `SiteObserver::saved` for another ~30-key Redis sweep. A reorder of 15 services produces 15 × ~43 Redis DEL commands plus 15 DB UPDATEs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply a Redis dirty flag per user (`Cache::add("user:dirty:{$userId}", 1, 1)`) at the start of `runHooks()` — skip the full `bust()` + `touchParentSite()` cascade if already set, only run `reevaluateBooking()` inline (it is boundary-only and safe per-row).
        - Or apply `withoutEvents` in the bulk reorder controller path and call `UserCacheService::invalidateUser()` + `SiteCacheService::invalidateSite()` once after the loop.
    - **Technical:** `bust()` passes `bustSite: false` to `invalidateUser()` (preventing double-bust since `touchParentSite()` immediately follows), which is the correct single-bust sequencing for a single-row mutation. However, for N-row batches the pattern is still N × (`invalidateUser` + `touchParentSite`) even though all rows belong to the same user and site. The `reevaluateBooking()` call also runs N times, but it is a lightweight boundary check and safe. The observable cost is N × ~43 Redis DEL commands and N DB UPDATEs.
    - **Plain English:** Saving a new order for 15 services on your price list sends 15 separate "clear the user cache" and "clear the site cache" commands, each wiping the same 40+ Redis keys. The second through fifteenth wipes are pure waste — the same keys that were cleared on the first wipe aren't going to fill back up in the 1ms between saves. A single wipe at the end of the reorder would achieve exactly the same result.
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
        // touchParentSite() calls:
        $pro?->site?->touch();
        ```

- [ ] **#CACHE-5** · P2 — `ServiceCategoryObserver` issues one DB SELECT + multi-key Redis sweep per category on reorder
    - **Where:** `app/Observers/Core/ServiceCategoryObserver.php:40-85` (`bust`)
    - **Affects:** Dashboard category rename/reorder (typically 4–10 categories). Each save fires one `User::query()->with('site')->find()` DB query, then a 4-key `Cache::deleteMultiple`, then `invalidateSitePayload()` (~12-key DEL sweep), then a `CloudflareCachePurgeJob::dispatch()` (coalesces via `ShouldBeUnique`). A reorder of 8 categories produces 8 DB SELECTs, 8 × ~16 Redis DEL commands.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply the same Redis dirty-flag guard used in the other observers: `Cache::add("user:dirty:{$userId}", 1, 1)` — on a second or later call for the same user within the batch, skip the DB SELECT and the Redis sweep since the first call already invalidated all relevant keys.
        - Alternatively, convert the observer's `bust()` to accept an already-resolved `$site` passed from the model's relation (`$category->user` eager-loaded at observer registration), avoiding the per-save DB round-trip.
    - **Technical:** Unlike `ServiceObserver` which calls `$pro->site->touch()` (reusing the already-loaded Eloquent model), `ServiceCategoryObserver::bust()` calls `User::query()->with('site')->find($userId)` — a raw DB query — on every save. This is documented as intentional (surgical invalidation to avoid double-busting the `professionalModel` key), but it adds an uncached DB round-trip per category row in a batch. The Redis amplification is the same structural pattern as CACHE-3 and CACHE-4: N × per-key sweeps for a single user/site pair that hasn't changed between saves.
    - **Plain English:** Renaming 8 categories in sequence makes 8 separate trips to the database to fetch the same user profile, then wipes the same 16 Redis keys 8 times in a row. The first trip and the first wipe are necessary. The other 7 of each are duplicates. A flag that says "we already did this in the last second" eliminates the redundant work.
    - **Evidence:**
        ```php
        private function bust(ServiceCategory $category): void
        {
            // …
            try {
                Cache::deleteMultiple([
                    CacheKeyGenerator::professionalDashboardServices($userId),
                    CacheKeyGenerator::professionalDashboardServices($userId).':stale',
                    CacheKeyGenerator::professionalServices($userId),
                    CacheKeyGenerator::professionalServices($userId).':stale',
                ]);
            } catch (\Throwable $e) { /* … */ }

            try {
                $pro = User::query()->with('site')->find($userId);  // DB hit on every save
                $site = $pro?->site;
                if ($site) {
                    app(SiteCacheService::class)->invalidateSitePayload($site);
                    CloudflareCachePurgeJob::dispatch($site->subdomain);
                }
            } catch (\Throwable $e) { /* … */ }
        }
        ```

- [ ] **#CACHE-7** · P2 — `CustomerObserver` runs two Redis DEL commands per customer row on bulk imports
    - **Where:** `app/Observers/Core/CustomerObserver.php:34-40` (`invalidateCount`)
    - **Affects:** CSV customer imports or any batch operation that creates/restores multiple customers. A 500-row import fires 1,000 Redis DEL commands (`Cache::forget` × 2 per row), each triggering a subsequent `COUNT(*)` query on the next cache miss.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::forget` with `Cache::increment` on `created`/`restored` and `Cache::decrement` on `deleted`. The counter stays live and accurate without ever touching the DB — the `COUNT(*)` recompute on miss is eliminated entirely.
        - If the signed-delta approach adds complexity (e.g. restores after a bulk soft-delete could produce negative transient counts), fall back to the dirty-flag debounce: `Cache::add("customer_count:dirty:{$userId}", 1, 1)` and flush once per second per user.
    - **Technical:** `invalidateCount()` calls `Cache::forget($key)` and `Cache::forget($key.':stale')` unconditionally on every Eloquent lifecycle event. `UserCacheService::getCustomerCount()` recomputes via `DB::table('site.customers')->where('user_id',…)->whereNull('deleted_at')->count()` on the next miss. For a 500-row import: 1,000 Redis DEL commands, then the next `getCustomerCount()` call hits the DB for a single `COUNT(*)`. Using `Cache::increment`/`decrement` instead turns each row event into one atomic Redis `INCRBY 1` — 500 operations against a single counter key, no DB reads, no stale window, and no deferred `COUNT(*)` query. This is the canonical pattern for cardinality caches where the event delta is always ±1.
    - **Plain English:** Every time a customer is added to your list, the system throws away the cached total and recounts from scratch on the next request. Import 500 customers and you throw it away and rebuild it 500 times. A smarter design would just tick the counter up by 1 for each new customer — your cached total stays accurate without any database recounts, and the total cost drops from "500 recounts" to "500 tiny increments."
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

---

## P3 — Nice to have

- [ ] **#CACHE-6** · P3 — `SmartLinkObserver` carries unchecked per-link full cache invalidation
    - **Where:** `app/Observers/Core/SmartLinkObserver.php:37-55` (`runHooks`)
    - **Affects:** Negligible at current usage (smart links edited one at a time). Becomes a structural risk if a bulk smart-link refresh feature is added (e.g. a "refresh all platform links" cron that re-saves all links for a user).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a comment to `runHooks()` flagging that `invalidateUser()` + `site->touch()` runs per-row and will amplify under any future bulk-save path.
        - When a bulk refresh path is added, apply the same Redis dirty-flag guard used in CACHE-2 through CACHE-5.
    - **Technical:** `runHooks()` calls `UserCacheService::invalidateUser($pro)` (13-key Redis DEL sweep with `bustSite: true`, which also calls `invalidateSite()`) and `$pro->site?->touch()` on every `saved`/`deleted`/`restored` event. `invalidateUser()` with `bustSite: true` is the full sweep — it calls `SiteCacheService::invalidateSite()` as its final step, and then `touchParentSite` calls it again via `SiteObserver::saved`. This is a structural double-bust risk in addition to the per-row amplification: if a bulk path ever runs, each smart-link write fires two full `invalidateSite()` sweeps (once via `invalidateUser(bustSite:true)` and once via `site->touch()` → `SiteObserver`). At single-link CRUD the overhead is immaterial; noting it here so the next engineer to add a bulk path doesn't accidentally inherit 2N sweeps.
    - **Plain English:** Editing a single smart link refreshes the entire billboard — that's correct and acceptable. The code just doesn't protect against a future scenario where 50 links are refreshed at once, which would refresh the billboard 100 times (50 from one path, 50 more from a second path triggered by the first). A note in the code makes this risk visible to the next developer before it becomes a production problem.
    - **Evidence:**
        ```php
        private function runHooks(SmartLink $link): void
        {
            try {
                $pro = User::query()->with('site')->find($link->user_id);
                if ($pro) {
                    $this->userCache->invalidateUser($pro);     // bustSite: true (default)
                    $pro->site?->touch();                        // also triggers SiteObserver → invalidateSite
                }
            } catch (\Throwable $e) { /* … */ }
        }
        ```
