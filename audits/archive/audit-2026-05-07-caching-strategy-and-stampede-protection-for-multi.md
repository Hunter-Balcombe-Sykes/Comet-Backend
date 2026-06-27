`★ Insight ─────────────────────────────────────`
**Stale-while-revalidate (SWR) invariant:** In this codebase, `CacheLockService::rememberLocked` always writes a `:stale` companion key at 10× the primary TTL. This creates a hard contract: **every bust path must delete both `$key` and `$key.':stale'` atomically**, or the SWR path will silently short-circuit the invalidation. The codebase correctly enforces this for `professionalModel` but inconsistently for several other hot keys — the root cause of multiple findings below.

**Adjudication note on CACHE-3/6 from DeepSeek:** Commit `3e8dcf7` applied jitter to *read* paths; it did not fix the synchronous mass-delete on *write* paths. The CACHE-3 finding remains open.
`─────────────────────────────────────────────────`

# Caching Strategy and Stampede Protection Audit — 2026-05-07

**Branch:** development-v2
**Lens:** caching strategy and stampede protection for multi-tenant Laravel SaaS
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Cache/AnalyticsCacheService.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/CacheLockService.php
- app/Services/Cache/ProfessionalCacheService.php
- app/Services/Cache/SiteCacheService.php
- app/Observers/Core/BlockObserver.php
- app/Observers/Core/CustomerObserver.php
- app/Observers/Core/ServiceObserver.php
- app/Observers/Core/SiteObserver.php
- app/Observers/Professional/ProfessionalObserver.php
- app/Observers/Retail/BrandStoreSettingsObserver.php
- app/Http/Middleware/AddPublicCacheHeaders.php
- config/cache.php
- config/database.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#CACHE-1** · P1 — `invalidateSite` deletes primary `siteBlocks` keys but not their `:stale` copies, serving stale link and section content for up to 2.5 hours after edits
    - **Where:** app/Services/Cache/SiteCacheService.php — `invalidateSite()` initial `$keys` array; `getSiteLinkBlocks()` SWR write path
    - **Affects:** Public-site visitors who load a page within 2.5 hours of a site owner editing or deleting a link or section block. The stale-while-revalidate fast path in `CacheLockService::rememberLocked` returns the pre-edit block list immediately, bypassing the lock and the fresh DB read.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `invalidateSite`, after adding `siteBlocks($site->id, 'links')` and `siteBlocks($site->id, 'sections')` to `$keys`, also add their `:stale` twins: `CacheKeyGenerator::siteBlocks($site->id, 'links').':stale'` and `CacheKeyGenerator::siteBlocks($site->id, 'sections').':stale'`.
        - As a follow-up audit step, verify every key in `$keys` that is populated via `rememberLocked`: only those using `rememberLockedNullable` (which has no SWR) are safe to bust without a `:stale` companion. Currently `siteImagesView` variants and `professionalModel` already bust `:stale`; `siteBlocks` was the gap.
    - **Technical:** `getSiteLinkBlocks` calls `CacheLockService::rememberLocked` with `PAYLOAD_TTL_SECONDS` (900 s). `writeWithJitter` unconditionally writes `{key}:stale` at `900 × STALE_TTL_MULTIPLIER (10) = 9000 s` (2.5 h). When `BlockObserver` or `SiteObserver` fires `invalidateSite`, the primary `siteBlocks` key is deleted but the stale copy survives. On the next request, `rememberLocked` sees a primary miss, checks the stale key — still live — attempts a non-blocking lock; if the lock is contested it returns stale immediately. The primary refills but the stale entry retains the old data until 9000 s elapses. The inconsistency is visible within the same `invalidateSite` method: `professionalModel` explicitly appends both `$modelKey` and `$modelKey.':stale'`; `siteBlocks` was not given the same treatment when SWR was introduced in commit `3e8dcf7`.
    - **Plain English:** When a site owner saves a change — adding a new link, editing a section — we erase the cached version of their page so visitors see the update immediately. But our cache system also keeps a "backup photo" of that data for 2.5 hours as a safety net in case the main cache is rebuilding. The bug is that the save erases the main copy but not the backup. So for up to 2.5 hours after the save, visitors might see the old version from the backup. It's like updating the restaurant menu board but leaving an old laminated copy pinned next to it — customers keep reading the old one.
    - **Evidence:**
        ```php
        // getSiteLinkBlocks — writes a :stale companion via rememberLocked
        return $this->cacheLock->rememberLocked(
            CacheKeyGenerator::siteBlocks($siteId, 'links'),
            self::PAYLOAD_TTL_SECONDS,
            fn () => Block::query()
                ->where('site_id', $siteId)
                ->where('block_group', 'links')
                ->active()
                ->orderBy('sort_order')
                ->get()
                ->toArray()
        );

        // invalidateSite — busts primary but NOT :stale for siteBlocks
        $keys = [
            CacheKeyGenerator::publicSitePayload($site->subdomain),
            CacheKeyGenerator::siteBlocks($site->id, 'links'),     // primary only — :stale survives
            CacheKeyGenerator::siteBlocks($site->id, 'sections'),  // primary only — :stale survives
            CacheKeyGenerator::siteImages($site->id),
        ];

        // professionalModel correctly busts both — the pattern that was missed for siteBlocks:
        $modelKey = CacheKeyGenerator::professionalModel($professionalId);
        $keys[] = $modelKey;
        $keys[] = $modelKey.':stale';
        ```

- [ ] **#CACHE-2** · P1 — `getPublicSitePayload` (95% of traffic) uses a manual lock with no stale-while-revalidate; every 15-minute expiry forces all concurrent visitors into a blocking queue, and a slow rebuild returns null (flash 404) for a valid published site
    - **Where:** app/Services/Cache/SiteCacheService.php — `getPublicSitePayload()` entire method (~lines 72–170)
    - **Affects:** Every public-site visitor whose request lands during the cache-rebuild window after the 15-minute primary TTL expires. Under concurrent load, visitors queue on the 5-second lock block. If the rebuild exceeds 5 seconds (slow DB, complex enrichment), waiting requests receive `null`, which the controller returns as a 404 — for a live, published site.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Refactor `getPublicSitePayload` to delegate to `CacheLockService::rememberLocked` (or `rememberLockedNullable` for the site-not-found sentinel path), inheriting TTL jitter, SWR last-good copies, and the lock-timeout fallback to direct compute.
        - Preserve the `MISS_SENTINEL` negative-cache pattern for unpublished/missing sites — handle that branch before calling into `rememberLocked`, or use `rememberLockedNullable` with a short null-TTL.
        - Add `publicSitePayload($subdomain).':stale'` to the bust list in `invalidateSite` and any other bust path.
        - Note: the lock-timeout null return (the secondary bug) is automatically resolved by migrating to `rememberLocked`, which falls through to `$callback()` as a last resort instead of returning null.
    - **Technical:** The manual `Cache::lock('site:fill:{subdomain}', 10)->block(5)` provides correct single-flight rebuild but has two gaps versus `CacheLockService::rememberLocked`. (1) It never writes `{key}:stale`, so on every primary TTL expiry all concurrent requests must queue rather than reading last-good — unlike `getSiteLinkBlocks` and `professionalModel` which serve stale instantly. (2) The `LockTimeoutException` catch re-reads cache and returns `null` if still empty; `rememberLocked` instead calls `$callback()` as a last resort so the user never receives nothing. This is the highest-traffic read path in the system; applying the same hardened pattern already used by `getSiteLinkBlocks` closes both gaps in one refactor.
    - **Plain English:** Imagine a shop whose hours are posted on a whiteboard that gets erased and rewritten every 15 minutes. While the board is blank and one staff member is rewriting it, every new customer has to stand in line and wait — rather than glancing at yesterday's hours taped on the wall. If the rewrite takes longer than 5 seconds, the people at the back of the line are just sent away with "sorry, no information" — even though the shop is perfectly open. Our other caches avoid this by keeping that backup copy on the wall. This one — the busiest cache in the entire system — doesn't, and it's the only one that can actively return a "not found" error for a real, live page.
    - **Evidence:**
        ```php
        // Manual lock — no :stale companion is ever written
        $fillLock = Cache::lock('site:fill:'.$subdomain, 10);
        try {
            $fillLock->block(5);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            // Another process is (or was) filling the cache.
            // Return whatever is now in cache, or null if it's still a miss.
            $warm = Cache::get($key);
            if ($warm === self::MISS_SENTINEL) {
                return null;
            }
            return is_array($warm) ? $warm : null;  // ← null on timeout, no direct-compute fallback
        }

        // Rebuild writes only the primary key — no :stale companion:
        Cache::put($key, $data, $this->jitteredPayloadTtl());

        // CacheLockService::rememberLocked always writes both, and falls through on timeout:
        Cache::put($key, $value, $jitteredTtl);
        Cache::put($staleKey, $value, $staleTtl);  // ← companion this method never writes
        // ...and on LockTimeoutException: return $callback();  ← fallback this method lacks
        ```

## P2 — Should fix

- [ ] **#CACHE-4** · P2 — `invalidateProfessional` and `CustomerObserver` bust primary keys for services and customer count but leave `:stale` copies alive, keeping dashboard data stale for up to 5 hours after writes
    - **Where:** app/Services/Cache/ProfessionalCacheService.php — `invalidateProfessional()` key list; app/Observers/Core/CustomerObserver.php — `invalidateCount()`
    - **Affects:** Dashboard users who edit a service (name, price, visibility) or whose customer count changes. The active-services list — which is also the public-facing services endpoint — and the customer count can reflect pre-edit data for up to 5 hours through the SWR backup.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `invalidateProfessional`, append `:stale` twins for `professionalServices`, `professionalDashboardServices`, and `customerCount` — mirroring the treatment `professionalModel` already receives in the same method.
        - In `CustomerObserver::invalidateCount`, add a second `Cache::forget` for `CacheKeyGenerator::customerCount(...).':stale'`.
    - **Technical:** `getActiveServices`, `getDashboardServices`, and `getCustomerCount` all use `CacheLockService::rememberLocked` with `DateTimeInterface` TTLs (30 min, 30 min, 15 min). `writeWithJitter` computes stale TTL as `(seconds_until_deadline) × STALE_TTL_MULTIPLIER (10)` — so stale copies live ≈ 5 h for services and ≈ 2.5 h for customer count. `invalidateProfessional` calls `Cache::deleteMultiple` on an array that includes the primary keys for all three but never their `:stale` twins. `CustomerObserver::invalidateCount` uses `Cache::forget` for the primary only. Commit `3e8dcf7` added `rememberLocked` to these read paths (applying SWR) but did not update the invalidation side to match. The inconsistency is visible within the same `invalidateProfessional` method: `professionalModel` is the only key there that explicitly appends `.':stale'`.
    - **Plain English:** When a professional edits a service or a customer is added, we clear the related cache so everything updates. But — same root problem as CACHE-1 — we only clear the main copy, not the 5-hour backup. A professional who renames a service could see the old name persist in their public services list for up to 5 hours. We already do this correctly for the login-session model (explicitly clearing both copies, right in the same function) but forgot to apply the same logic to services and customer counts.
    - **Evidence:**
        ```php
        // getActiveServices — rememberLocked writes :stale at ~5h TTL
        return $this->cacheLock->rememberLocked(
            CacheKeyGenerator::professionalServices($professionalId),
            now()->addMinutes(30),  // stale TTL = 1800 * 10 = 18000 s ≈ 5 h
            fn () => Service::query()...
        );

        // invalidateProfessional — busts primaries only, :stale copies survive
        $keys = [
            ...
            CacheKeyGenerator::professionalServices($professional->id),           // no :stale
            CacheKeyGenerator::professionalDashboardServices($professional->id),  // no :stale
            CacheKeyGenerator::customerCount($professional->id),                  // no :stale
            // professionalModel correctly does both — the missed pattern:
            $modelKey,
            $modelKey.':stale',
        ];

        // CustomerObserver — primary only, no :stale bust
        private function invalidateCount(Customer $customer): void
        {
            if (! empty($customer->professional_id)) {
                Cache::forget(CacheKeyGenerator::customerCount((string) $customer->professional_id));
                // missing: Cache::forget(CacheKeyGenerator::customerCount(...).':stale')
            }
        }
        ```

- [ ] **#CACHE-3** · P2 — `invalidateSite` synchronously wipes all connected affiliate payload caches at once, creating a write-amplified thundering herd on every brand site edit
    - **Where:** app/Services/Cache/SiteCacheService.php — `invalidateSite()` connected-subdomains block (~lines 540–555)
    - **Affects:** Every affiliate whose public payload cache is linked to the editing brand via `BrandPartnerLink`. A single brand site save simultaneously cold-misses all connected affiliate caches. Under traffic, all those cold misses contend for simultaneous DB reads against `PublicSitePayload`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the connected-affiliate cache deletion into a queued job (e.g., `InvalidateConnectedAffiliateCachesJob`) dispatched with a per-subdomain random 0–30 s delay, matching the jitter pattern used in `ServiceObserver::syncDispatchDelay`.
        - Alternatively, introduce a brand-version token that affiliates embed in their payload cache key; a brand edit increments the token rather than deleting N keys, collapsing N DEL operations into one INCR.
    - **Technical:** The final block of `invalidateSite` executes two queries — `BrandPartnerLink` to find connected affiliate IDs, then `Site` to map them to subdomains — and adds `publicSitePayload($connectedSubdomain)` for each to the `$keys` array, which is then flushed in a single `Cache::deleteMultiple`. For a brand with N affiliates this atomically cold-misses N caches. Each cold miss triggers the `Cache::lock('site:fill:{subdomain}', 10)` queue in `getPublicSitePayload` (the manual lock that currently lacks SWR, per CACHE-2). The jitter applied to individual cache write TTLs spreads organic expiry across the fleet, but synchronous mass-deletion bypasses TTL jitter entirely — one brand write forces N lock-acquire races to happen at the same instant. This becomes more severe as brand affiliate programs grow; CACHE-2 makes each individual miss more resilient but does not reduce the N-simultaneous-miss problem.
    - **Plain English:** When a brand updates their page, we immediately clear the cached version of every affiliate page that shows that brand's products. If a brand has 100 affiliates, 100 cache entries are deleted at the exact same millisecond. The next visitor to any of those 100 affiliate pages triggers a database rebuild — all 100 at once. It's like a fire drill that sends everyone out of every office in a building simultaneously, then they all try to re-enter through the same revolving door. The fix is to spread those cache rebuilds over a short window so they trickle back one at a time.
    - **Evidence:**
        ```php
        $connectedProfessionalIds = BrandPartnerLink::query()
            ->where('brand_professional_id', $professionalId)
            ->pluck('affiliate_professional_id')
            ->all();

        $connectedSubdomains = Site::query()
            ->whereIn('professional_id', $connectedProfessionalIds)
            ->pluck('subdomain')
            ->filter(fn ($subdomain): bool => is_string($subdomain) && trim($subdomain) !== '')
            ->map(fn ($subdomain): string => strtolower((string) $subdomain))
            ->all();

        foreach ($connectedSubdomains as $connectedSubdomain) {
            $keys[] = CacheKeyGenerator::publicSitePayload($connectedSubdomain);
        }

        Cache::deleteMultiple(array_values(array_unique($keys)));  // N simultaneous cold misses
        ```

## P3 — Nice to have

- [ ] **#CACHE-6** · P3 — `invalidateAnalytics` key-enumeration loop uses a fixed `$end = today` for every iteration, leaving historical date-range cache entries undeleted until their 5-minute TTL expires naturally
    - **Where:** app/Services/Cache/AnalyticsCacheService.php — `invalidateAnalytics()` lines 67–81
    - **Affects:** Analytics dashboard users who view historical date ranges with a non-today end date (e.g., "Jan 1 – Jan 31"). After new data triggers invalidation, those specific cache keys are not explicitly deleted and remain stale for up to 5 minutes. No data is lost; the TTL is the real backstop.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `$endStr = $end->format('Ymd')` inside the loop to `$endStr = $date->format('Ymd')` to generate single-day keys — **only if** the UI queries single-day ranges. If the UI queries arbitrary multi-day ranges, the key-enumeration approach is inherently incomplete and the correct fix is to add a per-professional version token to `analyticsVisits`/`analyticsClicks` keys (mirroring `analyticsSummaryVersion` for summaries) and embed it when reading in `getVisitStats`/`getClickStats`.
        - Do **not** drop the key-enumeration loop and rely solely on `Cache::increment(analyticsSummaryVersion(...))`: the version token only makes `analyticsSummary` keys unreachable (those embed the version in the key); `analyticsVisits` and `analyticsClicks` keys do not use a version token and have no other explicit invalidation path besides this loop.
    - **Technical:** `$end` is assigned `Carbon::now()` before the loop and never mutated. Each iteration produces `CacheKeyGenerator::analyticsVisits($professionalId, $date->format('Ymd'), $end->format('Ymd'))` where `$end` is always today. A key for a historical range — e.g., `analytics:visits:{pro}:20260101:20260131` — is never generated by the loop. The version-token increment at the top of the method correctly busts all `analyticsSummary` keys (those are keyed separately and read the token at access time), but `analyticsVisits` and `analyticsClicks` are plain date-range keys with no version component; the loop is their only explicit invalidation. The 5-minute `rememberLocked` TTL on `getVisitStats` and `getClickStats` is the real safety net and bounds the practical stale window regardless.
    - **Plain English:** When fresh analytics data arrives and we want to clear the chart cache, we build a list of cache entries to delete. There's a small variable-scoping bug: we capture "today's date" once at the start, then accidentally use it as the end date for every single key we generate — even when we're supposed to be generating a key for, say, a range ending January 31st. So we only explicitly clear ranges that end today; ranges like "last month" clear themselves when their 5-minute timer runs out. This is a minor inefficiency, not a data issue — charts correct themselves within 5 minutes regardless.
    - **Evidence:**
        ```php
        $keys = [];
        $end = Carbon::now();  // captured once before the loop — never changes

        for ($i = 0; $i < 90; $i++) {
            $date = $end->copy()->subDays($i);
            $start = $date->format('Ymd');
            $endStr = $end->format('Ymd');  // ← always today; should vary with $date for arbitrary ranges

            $keys[] = CacheKeyGenerator::analyticsVisits($professionalId, $start, $endStr);
            $keys[] = CacheKeyGenerator::analyticsClicks($professionalId, $start, $endStr);
        }
        ```

`★ Insight ─────────────────────────────────────`
**The SWR bust gap is a systemic pattern, not isolated bugs:** CACHE-1, CACHE-2, and CACHE-4 all share the same root cause — `rememberLocked` was introduced to read paths incrementally (commits `3e8dcf7`, `fdb7655`) without a corresponding sweep of all invalidation paths. A useful convention going forward: any method that calls `rememberLocked` should have a `// busts: {key} + {key}:stale` comment at its call site, making the invalidation contract visible at the write path rather than relying on developers to hunt through cache services.

**Analytics invalidation architecture:** The two-tier approach (`analyticsSummaryVersion` for summaries + key enumeration for raw stats) is sound but the implementation diverged. The cleanest long-term fix for CACHE-6 is to apply the same version-token pattern to visit/click stat keys, eliminating the fragile key-enumeration loop entirely.
`─────────────────────────────────────────────────`
