# Caching Foundation Audit — 2026-05-07

**Branch:** development
**Lens:** Caching strategy and stampede protection for multi-tenant Laravel SaaS — verify the implementation against the 2026 gold standard, surface code-level gaps, additive improvements, and items to explicitly defer.
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6` (final landed 21:21) + research synthesis from web sources

> **★ Systemic root-cause note (added after adjudicator landed):** CACHE-1, CACHE-2, and CACHE-6 all share the same root cause — `CacheLockService::rememberLocked` was introduced to read paths incrementally (commits `3e8dcf7`, `fdb7655`) without a corresponding sweep of all invalidation paths. **Convention going forward:** any method that calls `rememberLocked` should have a `// busts: {key} + {key}:stale` comment at its call site, making the invalidation contract visible at the read path rather than relying on developers to hunt through cache services.
**Source files audited:**
- app/Services/Cache/SiteCacheService.php
- app/Services/Cache/CacheLockService.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/ProfessionalCacheService.php
- app/Services/Cache/AnalyticsCacheService.php
- app/Observers/ (all)
- app/Http/Middleware/AddPublicCacheHeaders.php
- config/cache.php
- config/database.php
- composer.json (caching-relevant deps)

## Progress

- **P0 Blockers:** 0 of 0 — foundation is solid; nothing blocks beta
- **P1 High:** 2 of 2 ✅
- **P2 Medium:** 7 of 7 ✅
- **P3 Low:** 5 of 6 (open: GS-8)
- **Deferred / Overkill:** 1 remaining (DEFER-2 — trigger: 50 req/sec sustained)
- **Already Gold Standard:** 11 confirmed (no action needed)

---

## P1 — Fix before pilot launch

- [x] **#CACHE-1** · P1 — `invalidateSite` does not bust `:stale` copies for site block caches → 2.5 hours of stale blocks after every edit
    - **Where:** app/Services/Cache/SiteCacheService.php:786-787 (the bust list inside `invalidateSite()` at line 780)
    - **Affects:** Any site visitor who loads a page within 2.5 hours of a block edit (link or section change). The SWR fast path returns the pre-edit block list.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$keys[] = CacheKeyGenerator::siteBlocks($site->id, 'links').':stale';` and the same for `'sections'` inside `invalidateSite()`.
        - Audit every other key busted by `invalidateSite()` that flows through `CacheLockService::rememberLocked` and add `:stale` busts for them too.
        - Consider a helper `bustWithStale(string $key): array` that returns `[$key, $key.':stale']` so the asymmetry can't recur.
    - **Technical:** `getSiteLinkBlocks()` calls `CacheLockService::rememberLocked()`, which writes a stale-while-revalidate copy at `{key}:stale` with a 10× TTL multiplier (900s × 10 = 2.5h). `invalidateSite()` deletes only the primary `siteBlocks` key, so the SWR path in `rememberLocked` finds the stale copy still live and returns it immediately — the primary key refills on the next lock-acquire, but the first request after invalidation sees the old data. Critically, the same method correctly busts `:stale` for `professionalModel` (line 806) and `siteImages` variants (line 797), so `siteBlocks` was simply missed in the parallel structure.
    - **Plain English:** When a site owner edits a link or section and saves, we clear the main cache copy so visitors see the change. But we keep a backup copy (the "stale" version) alive for 2.5 hours as a safety net. The bug is that the save only clears the main copy, not the backup. So the very next visitor after a save might still see the old version via the backup. It's like erasing a whiteboard but leaving the photo of the old whiteboard pinned next to it — people glance at the photo first.
    - **Evidence:**
        ```php
        // SiteCacheService.php:786-806 — invalidateSite() bust list
        $keys = [
            CacheKeyGenerator::publicSitePayload($site->subdomain),
            CacheKeyGenerator::siteBlocks($site->id, 'links'),     // primary only — MISSING :stale
            CacheKeyGenerator::siteBlocks($site->id, 'sections'),  // primary only — MISSING :stale
            CacheKeyGenerator::siteImages($site->id),
        ];
        // ...
        $keys[] = $variantKey.':stale';  // siteImages variant DOES bust :stale
        // ...
        $keys[] = $modelKey;
        $keys[] = $modelKey.':stale';    // professionalModel DOES bust :stale
        ```

- [x] **#CACHE-2** · P1 — Public site payload cache (95% of traffic) lacks SWR; every TTL expiry triggers a blocking lock queue
    - **Where:** app/Services/Cache/SiteCacheService.php:99-184 (entire `getPublicSitePayload()` method)
    - **Affects:** Every public site visitor whose request lands during the ~200-500ms rebuild window after the 15-minute cache expiry. Under concurrent load, visitors either block for up to 5s or get a null response (see CACHE-4).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Refactor `getPublicSitePayload` to use `CacheLockService::rememberLocked` instead of the manual `Cache::lock('site:fill:...')` pattern, so it automatically gets `:stale` last-good copies and TTL jitter.
        - Or: add a manual `:stale` write next to every primary `Cache::put`, matching the SWR contract used by `getSiteLinkBlocks` and `professionalModel`.
        - Ensure `invalidateSite` deletes both the primary and `:stale` key for `site:payload:{subdomain}` (currently only primary is busted).
    - **Technical:** The manual lock in `getPublicSitePayload` (line 99) provides single-flight rebuild (only one worker queries `PublicSitePayload`), but it never writes a `:stale` extension. When the 15-minute primary TTL expires, `Cache::get($key)` returns null and there is no stale fallback — every concurrent request enters the lock queue. `CacheLockService::rememberLocked` solves exactly this by writing a `:stale` copy at 10× TTL and returning it immediately on primary miss, letting one worker recompute asynchronously. This is the system's hottest read path; it should use the same hardened pattern as the other hot caches. Migrating it also opens the door to comparing `CacheLockService::rememberLocked` to native `Cache::flexible()` (Laravel 11+) — see GS-7.
    - **Plain English:** Imagine a busy restaurant where the menu board is wiped clean every 15 minutes and rewritten by hand. While the one person rewriting it holds the only pen, every new customer stands in line waiting — some give up and leave. The fix is to keep yesterday's menu taped next to the board. When the board is blank, customers read yesterday's menu instantly while someone quietly updates the board. That's what our other caches do; our busiest one doesn't.
    - **Evidence:**
        ```php
        // SiteCacheService.php:99 — manual lock, no :stale write
        $fillLock = Cache::lock('site:fill:'.$subdomain, 10);
        try {
            $fillLock->block(5);
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            $warm = Cache::get($key);
            if ($warm === self::MISS_SENTINEL) {
                return null;  // flash 404 — see CACHE-4
            }
            return is_array($warm) ? $warm : null;
        }
        // ... rebuild and put with no stale copy:
        Cache::put($key, $data, $this->jitteredPayloadTtl());

        // Compare: getSiteLinkBlocks goes through the hardened path
        return $this->cacheLock->rememberLocked(
            CacheKeyGenerator::siteBlocks($siteId, 'links'),
            self::PAYLOAD_TTL_SECONDS,
            fn () => Block::query()/*...*/
        );
        ```

---

## P2 — Should fix

- [x] **#CACHE-3** · P2 — `invalidateSite` cascade-busts all connected affiliate payloads synchronously, creating cross-tenant thundering herd on brand edits
    - **Where:** app/Services/Cache/SiteCacheService.php:828-846 (the connected-affiliate enumeration block inside `invalidateSite`)
    - **Affects:** Every affiliate whose site is linked to a brand via `BrandPartnerLink`. A single brand site save (publish toggle, settings change, block edit) simultaneously cold-misses all affiliate public payload caches.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the connected-affiliate cache deletion into a queued job (e.g., `InvalidateConnectedAffiliateCachesJob`) dispatched with a per-subdomain random 0–30s delay — **mirror the existing `ServiceObserver::syncDispatchDelay()` pattern at `app/Observers/Core/ServiceObserver.php:140`** (the CACHE-12 fix already in place for bulk service imports).
        - Or: introduce a brand-version token that affiliates embed in their payload cache key; a brand edit increments the token rather than deleting N keys, collapsing N `DEL` operations into one `INCR`.
    - **Technical:** The final loop in `invalidateSite` resolves all `BrandPartnerLink` rows where `brand_professional_id` matches, finds each affiliate's site subdomain, and deletes `site:payload:{subdomain}` for every one. A brand with 150 affiliates triggers 150 simultaneous `DEL` commands. When those 150 sites next receive traffic, every request cold-misses simultaneously and contends for the `site:fill:{subdomain}` lock (which currently lacks SWR — see CACHE-2). This is a write-amplified stampede: one brand edit → N affiliate cache misses → N lock queues. CACHE-2's fix makes each individual miss more resilient but does not reduce the N-simultaneous-miss problem. The `syncDispatchDelay` jitter pattern from `ServiceObserver` is the canonical in-codebase precedent for this fix.
    - **Plain English:** When a brand updates their page, we immediately clear the cached version of every affiliate who displays that brand's products. For a big brand with 150 affiliates, that's 150 cache entries deleted at the exact same instant. The next time any of those 150 affiliate pages get a visitor, they all rush to rebuild at once — like 150 people all trying to get through one door at the same time. We should spread those rebuilds out so they happen gracefully.
    - **Evidence:**
        ```php
        // SiteCacheService.php:828-846
        $connectedProfessionalIds = BrandPartnerLink::query()
            ->where('brand_professional_id', $professionalId)
            ->pluck('affiliate_professional_id')
            ->all();

        $connectedSubdomains = Site::query()
            ->whereIn('professional_id', $connectedProfessionalIds)
            ->pluck('subdomain')
            ->all();

        foreach ($connectedSubdomains as $connectedSubdomain) {
            $keys[] = CacheKeyGenerator::publicSitePayload($connectedSubdomain);
        }

        Cache::deleteMultiple(array_values(array_unique($keys)));
        ```

- [x] **#CACHE-4** · P2 — `getPublicSitePayload` returns null on lock timeout during active rebuild, producing flash 404s under load
    - **Where:** app/Services/Cache/SiteCacheService.php:108-118 (the `LockTimeoutException` catch block)
    - **Affects:** Public site visitors whose request arrives while another worker is rebuilding the payload and the rebuild takes longer than 5 seconds (slow DB, complex enrichment). They get a null/404 instead of the site.
    - **Effort:** S (~0.5–1h) — folds into CACHE-2 if you migrate to `rememberLocked`
    - **What to do:**
        - In the `LockTimeoutException` catch block, after re-checking the cache, fall through to a direct `$callback()` execution (the same inline rebuild) instead of returning null — matching the fallback behavior in `CacheLockService::rememberLocked`.
        - Or: refactor to use `CacheLockService::rememberLocked` (CACHE-2), which already handles this by computing as a last resort.
    - **Technical:** The `Cache::lock()->block(5)` call throws `LockTimeoutException` when the lock holder hasn't released within 5 seconds. The catch block re-reads the cache and returns null if still empty — but the lock holder is literally in the middle of building the payload and about to populate the cache. `CacheLockService::rememberLocked` handles the same scenario by calling the closure directly: "return whatever is now cached, or fall through to compute as a last resort so the user never gets nothing back." The manual implementation is missing that fallback, so a slow DB query under peak load turns valid sites into transient 404s.
    - **Plain English:** If the cache is expired and someone else is already rebuilding it but takes more than 5 seconds (maybe the database is slow), our code just shrugs and says "page not found." Meanwhile, the other worker is a second away from finishing the rebuild. We should wait just a bit longer or do the rebuild ourselves rather than show a broken page. Our other cache helper already handles this gracefully — this one doesn't.
    - **Evidence:**
        ```php
        // SiteCacheService.php:108-118
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            $warm = Cache::get($key);
            if ($warm === self::MISS_SENTINEL) {
                return null;  // ← flash 404, no fallback to direct compute
            }
            return is_array($warm) ? $warm : null;
        }
        ```

- [x] **#CACHE-6** · P2 — `invalidateProfessional` and `CustomerObserver` bust primary keys for services and customer count but leave `:stale` copies alive — dashboard data stays stale up to 5 hours after writes
    - **Where:** app/Services/Cache/ProfessionalCacheService.php:304-326 (`invalidateProfessional` key list); app/Observers/Core/CustomerObserver.php:34-37 (`invalidateCount`)
    - **Affects:** Dashboard users who edit a service (name, price, visibility) or whose customer count changes. The active-services list — which is also the public-facing services endpoint — and the customer count can reflect pre-edit data for up to 5 hours via the SWR stale-copy fast path.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `invalidateProfessional`, append `:stale` twins for `professionalServices`, `professionalDashboardServices`, and `customerCount` — mirroring the treatment `professionalModel` already receives in the same method (line 322).
        - In `CustomerObserver::invalidateCount`, add a second `Cache::forget` for `CacheKeyGenerator::customerCount(...).':stale'`.
        - Apply the convention from the systemic note at the top of this file: add `// busts: {key} + {key}:stale` comments at every `rememberLocked` call site to make this gap visible at the read path.
    - **Technical:** `getActiveServices`, `getDashboardServices`, and `getCustomerCount` all use `CacheLockService::rememberLocked` with `DateTimeInterface` TTLs (30 min, 30 min, 15 min). `writeWithJitter` computes stale TTL as `(seconds_until_deadline) × STALE_TTL_MULTIPLIER (10)` — so stale copies live ≈ 5h for services and ≈ 2.5h for customer count. `invalidateProfessional` calls `Cache::deleteMultiple` on an array that includes the primary keys for all three but never their `:stale` twins. `CustomerObserver::invalidateCount` uses `Cache::forget` for the primary only. Same systemic root cause as CACHE-1: commit `3e8dcf7` added `rememberLocked` to these read paths (applying SWR) but did not update the invalidation side to match. The inconsistency is visible *within the same `invalidateProfessional` method* — `professionalModel` is the only key there that explicitly appends `.':stale'`.
    - **Plain English:** When a professional edits a service or a customer is added, we clear the related cache so everything updates. Same root problem as the site-blocks bug (CACHE-1): we only clear the main copy, not the 5-hour backup. A professional who renames a service could see the old name persist in their public services list for up to 5 hours. We already do this correctly for the login-session cache (clearing both copies, right in the same function) but forgot to apply the same logic to services and customer counts.
    - **Evidence:**
        ```php
        // ProfessionalCacheService.php:304-326 — invalidateProfessional bust list
        $keys = [
            // ...
            $modelKey,
            $modelKey.':stale',                                                   // ← professionalModel does both
            CacheKeyGenerator::professionalServices($professional->id),           // ← MISSING :stale
            CacheKeyGenerator::professionalDashboardServices($professional->id),  // ← MISSING :stale
            CacheKeyGenerator::customerCount($professional->id),                  // ← MISSING :stale
        ];

        // CustomerObserver.php:34-37 — invalidateCount
        private function invalidateCount(Customer $customer): void
        {
            if (! empty($customer->professional_id)) {
                Cache::forget(CacheKeyGenerator::customerCount((string) $customer->professional_id));
                // missing: Cache::forget(CacheKeyGenerator::customerCount(...).':stale')
            }
        }
        ```

- [x] **#GS-1** · P2 — Centralize and lint cache key construction (no raw `Cache::*` calls outside `Services/Cache/`)
    - **Where:** Cross-cutting — currently ~103 `Cache::*` callsites scattered across `app/Services/`, `app/Http/Controllers/`, `app/Jobs/` (per the explore agent's map)
    - **Affects:** Multi-tenant data isolation. The single highest-severity bug class in tenant SaaS is a cache key without a tenant prefix → cross-tenant leak.
    - **Effort:** S (~0.5–1h to add the lint; M to migrate stragglers)
    - **What to do:**
        - Add a CI grep rule (mirror your `BrandAccessService` capability lint at `.github/workflows/ci.yml`) that fails on `\bCache::(put|remember|rememberForever|forget|add)\b` outside `app/Services/Cache/`, `app/Services/*Token*Service.php` (idempotency-allowed), and a small allowlist.
        - Formalize `CacheKeyGenerator` as the *only* path for key construction. Add a `CacheKey::for($tenant, $resource, ...$dims)` convenience facade if useful.
        - Migrate the ~28 controller-level cache calls (mostly webhook idempotency + analytics version tokens) to go through cache services.
    - **Technical:** You already have 80% of this discipline via `CacheKeyGenerator` (35 patterns, all tenant-namespaced). What's missing is structural enforcement: a rogue `Cache::put('user_count', $n)` in a controller is a single git push away from a cross-tenant leak. CI grep is cheap; PR review is not catching these. Webhooks legitimately need raw `Cache::add()` for idempotency — allowlist them by file.
    - **Plain English:** We have a rule that every cached piece of data must include the tenant ID in its name, so one customer can't accidentally see another's data. Right now that rule is enforced by code review, not by a robot. We should add the robot. It's like having a bouncer check IDs at the door instead of trusting the bartender to remember.
    - **Evidence:**
        ```yaml
        # .github/workflows/ci.yml — pattern from existing BrandAccessService lint
        - name: No raw Cache calls outside cache services
          run: |
            ! git grep -n -E '\bCache::(put|remember|rememberForever|forget|add)\b' \
              -- 'app/' \
              ':!app/Services/Cache/' \
              ':!app/Http/Controllers/Api/Webhooks/' \
              ':!app/Services/*Token*'
        ```

- [x] **#GS-2** · P2 — Add `CACHE_STORE=failover` config so a Redis outage degrades to "no cache" instead of "site down"
    - **Where:** config/cache.php (no `failover` driver block today; default `'redis'`)
    - **Affects:** Whole-site availability when Redis is unreachable (eviction storm, cluster failover, network partition).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `failover` store to `config/cache.php` chaining `redis → array`.
        - Set `CACHE_STORE=failover` in production env.
        - Test by `redis-cli -p $port debug sleep 60` and confirming the app stays up (degraded perf, no errors).
        - Make sure `CacheLockService` still works on the array fallback (locks are scoped to the lock connection, separate Redis DB — so a cache-Redis outage shouldn't affect locks; verify).
    - **Technical:** Laravel 12 ships a native `failover` cache driver. Today, a Redis outage causes every `Cache::get` to throw `Predis\ClientException`. With failover → array, the app silently degrades to per-worker in-memory cache (zero hit rate across workers but no exceptions). Pair with a Nightwatch alert on the failover-rate metric so the outage is still visible.
    - **Plain English:** If Redis goes down, the whole site currently goes down with it. We can configure a backup that falls back to "no cache at all" — slower but functional. It's the same idea as a generator kicking in when the power fails.
    - **Evidence:**
        ```php
        // config/cache.php — add this store
        'failover' => [
            'driver' => 'failover',
            'stores' => ['redis', 'array'],
        ],
        ```

- [x] **#GS-3** · P2 — Centralize TTL constants in `config/cache.php` (currently scattered as magic numbers)
    - **Where:** `app/Services/Cache/SiteCacheService.php:25` (15m), `AnalyticsCacheService.php:23` (5m), `ProfessionalCacheService.php:27` (30m), `:41` (60s), `:161` (60s); webhook controllers (24h); `BrandCatalogService` (~10m)
    - **Affects:** Operational agility — currently any TTL change requires a code edit + redeploy. Cannot tune for a degraded Redis or a hot-key incident.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'ttls' => [...]` array to `config/cache.php` (or `config/sidest.php` since that's where partna feature config lives).
        - Replace each magic number with `config('sidest.cache.ttls.public_payload')`, `config('sidest.cache.ttls.analytics_short')`, etc.
        - Document in a comment what each tier is for (hot read vs warm read vs idempotency lock).
    - **Technical:** Pure refactor with no behavior change. The benefit shows up later: when Redis memory is hot, you can drop public payload TTL via env var and config:cache without redeploy. Pre-beta this is low-impact; post-beta it's an SRE knob you'll wish you had.
    - **Plain English:** Right now the rules for "how long do we cache X" are written in different files all over the codebase. We should put them in one config file so we can adjust them quickly without editing code. It's like keeping the thermostat settings in one panel instead of having to crawl through the attic to change each one.
    - **Evidence:**
        ```php
        // SiteCacheService.php:25
        public const PAYLOAD_TTL_SECONDS = 900;  // 15m hardcoded

        // AnalyticsCacheService.php:23
        private const VISIT_STATS_TTL_SECONDS = 300;  // 5m hardcoded

        // ProfessionalCacheService.php:27,41,161
        private const AUTH_LOOKUP_TTL_SECONDS = 1800;  // 30m
        private const MODEL_TTL_SECONDS = 60;          // 60s
        ```

- [x] **#GS-5** · P2 — Add cache hit/miss metrics surfaced to Nightwatch (and later Pulse)
    - **Where:** New: a wrapper around `CacheLockService` (or a Laravel cache event listener) that increments counters tagged by key prefix
    - **Affects:** Observability — you currently can't answer "is `pro:model:*` actually hot?" or "did the deploy regress hit rate?"
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Listen on `Illuminate\Cache\Events\CacheHit`, `CacheMissed`, `KeyWritten` and bucket by key prefix (regex split on the first `:`).
        - Send to Nightwatch as custom metrics, or to a Redis sorted set polled by an hourly stats job.
        - Set an SLO: hot-key prefixes (site:payload, pro:model) should be ≥ 90% hit rate; alert when below.
    - **Technical:** Laravel emits `CacheHit` / `CacheMissed` events natively. A 30-line listener, registered in `AppServiceProvider::boot()`, gives you per-prefix counters with negligible overhead. This is the prerequisite to any future cache tuning — you can't optimize what you can't see.
    - **Plain English:** Right now we have no idea how often the cache is actually saving a database query. Adding a counter is cheap and tells us whether the cache is working. It's like the gas gauge on a car — you don't strictly need it, but driving without one is a bad idea.
    - **Evidence:** Not yet implemented — net new code.

---

## P3 — Nice to have

- [x] **#CACHE-5** · P3 — `invalidateAnalytics` key-enumeration loop uses fixed `$end = today` for every iteration, leaving historical date-range keys for visits/clicks undeleted until their 5-minute TTL
    - **Where:** app/Services/Cache/AnalyticsCacheService.php:67-81
    - **Affects:** Analytics dashboard users viewing historical date ranges (e.g., "Jan 1 – Jan 31"). After new data triggers invalidation, those specific cache keys are not explicitly deleted and remain stale for up to 5 minutes.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `$endStr = $end->format('Ymd')` inside the loop to `$endStr = $date->format('Ymd')` — **only if** the UI queries single-day ranges. If the UI queries arbitrary multi-day ranges, the key-enumeration approach is inherently incomplete and the correct fix is to add a per-professional version token to `analyticsVisits`/`analyticsClicks` keys (mirroring `analyticsSummaryVersion` for summaries) and embed it when reading in `getVisitStats`/`getClickStats`.
        - **Do NOT drop the key-enumeration loop and rely solely on the version-token increment.** The version token only makes `analyticsSummary` keys unreachable (those embed the version in the key); `analyticsVisits` and `analyticsClicks` keys do **not** use a version token and have no other explicit invalidation path besides this loop. (My initial synthesis got this wrong — adjudicator caught the error.)
    - **Technical:** `$end` is captured as `Carbon::now()` before the loop and never mutated. Each iteration produces `analyticsVisits($pro, $date->format('Ymd'), $end->format('Ymd'))` where `$end` is always today. A key for a historical range like `analytics:visits:{pro}:20260101:20260131` is never generated by the loop. The version-token increment at the top of the method correctly busts all `analyticsSummary` keys (those are keyed separately and read the token at access time), but `analyticsVisits` and `analyticsClicks` are plain date-range keys with no version component — the loop is their only explicit invalidation. The 5-minute `rememberLocked` TTL on `getVisitStats`/`getClickStats` is the real safety net and bounds the practical stale window regardless. Low priority because of that safety net; correctness gap because the loop's intent doesn't match its behavior.
    - **Plain English:** When fresh analytics data arrives and we want to clear the chart cache, we build a list of cache entries to delete. There's a small variable-scoping bug: we capture "today's date" once at the start, then accidentally use it as the end date for every single key we generate — even when generating a key for "all of last month." So we only explicitly clear ranges that end today; ranges like "last month" clear themselves when their 5-minute timer runs out. Minor inefficiency, not a data issue — charts correct themselves within 5 minutes regardless.
    - **Evidence:**
        ```php
        // AnalyticsCacheService.php:67-81
        $keys = [];
        $end = Carbon::now();  // captured once, never changes

        for ($i = 0; $i < 90; $i++) {
            $date = $end->copy()->subDays($i);
            $start = $date->format('Ymd');
            $endStr = $end->format('Ymd');  // bug: should be $date->format('Ymd')

            $keys[] = CacheKeyGenerator::analyticsVisits($professionalId, $start, $endStr);
            $keys[] = CacheKeyGenerator::analyticsClicks($professionalId, $start, $endStr);
        }
        ```

- [x] **#GS-6** · P3 — Add `Cache::memo()` decorator to hot read paths (Laravel 12.9+)
    - **Where:** New: dispatchers in `BrandCatalogService`, `SiteCacheService::hydrateSitePayload`, controllers that read pro-info more than once per request
    - **Affects:** Per-request throughput on hot endpoints — collapses N Redis round-trips for the same key into 1.
    - **Effort:** S (~0.5–1h to identify hot paths; trivial to apply)
    - **What to do:**
        - Identify request paths that read the same cache key 2+ times (e.g., `pro:id:{X}` read by Controller, then by Service, then by Resource).
        - Wrap those reads with `Cache::memo()->remember(...)` instead of `Cache::remember(...)`.
        - `Cache::memo()` is a per-request decorator that cleared between requests automatically — safe to use broadly.
    - **Technical:** Drop-in win added in Laravel 12.9. Works alongside existing Redis cache. Best ROI on paths where multiple services hydrate from the same key independently.
    - **Plain English:** Sometimes within a single request we ask the cache for the same thing multiple times. We can answer the second/third/fourth ask from a tiny in-memory copy instead of going back to Redis. It's like remembering the answer to a question your boss already asked instead of looking it up again.
    - **Evidence:** Not yet implemented — net new usage of native Laravel API.

- [ ] **#GS-8** · P3 — Add Cloudflare Cache Rules on `/api/public/*` to absorb reads at edge
    - **Where:** Cloudflare dashboard (Cache Rules) + verification of `routes/api/publicSite.php` for absence of session middleware
    - **Affects:** Latency for repeat visitors and geo-distributed users hitting public site shells, design tokens, store config endpoints.
    - **Effort:** S (~1h, blocked on session-middleware audit)
    - **What to do:**
        - Audit `routes/api.php` and `routes/api/publicSite.php`: confirm public routes don't run session/CSRF middleware (Cloudflare won't cache responses with `Set-Cookie`).
        - Add a Cloudflare Cache Rule: hostname matches API + path starts with `/api/public/`, set "Eligible for cache: yes", `Edge TTL: 60s`, `Browser TTL: respect existing headers`.
        - The existing `AddPublicCacheHeaders` middleware already sets `s-maxage=900` and varies by `X-Site-Subdomain` — Cloudflare will pick those up.
        - Validate with `curl -I` that responses include `cf-cache-status: HIT` after the second request.
    - **Technical:** Default Cloudflare doesn't cache JSON — needs explicit Cache Rule. Once in place, Cloudflare absorbs reads before they hit Redis, eliminating both Redis hits and cold-cache stampedes for repeat regional visitors. Free tier supports 10 cache rules, Pro 25.
    - **Plain English:** Cloudflare can hold a copy of our public pages in 300 cities worldwide. A visitor in Tokyo hitting our site never has to reach our server — they get the cached copy from a server next door. We have to explicitly turn this on for our API; it's off by default. The only catch: the public routes can't send cookies, or Cloudflare refuses to cache them.
    - **Evidence:**
        ```php
        // app/Http/Middleware/AddPublicCacheHeaders.php — already CDN-friendly
        $response->headers->set('Cache-Control', 'public, max-age=900, s-maxage=900');
        $response->headers->set('Vary', 'X-Site-Subdomain, Accept-Encoding');
        ```

- [x] **#GS-9** · P3 — Add ETag / 304 middleware on heavy public reads (mobile + Hydrogen revalidation)
    - **Where:** New: lightweight middleware applied to `/api/public/*` GET routes
    - **Affects:** Bandwidth + perceived latency for clients that revalidate (mobile apps, Hydrogen worker, browser back/forward cache).
    - **Effort:** S (~1h with `werk365/etagconditionals`, or 30 lines custom)
    - **What to do:**
        - Add middleware that hashes the response body, sets `ETag: "<hash>"`, and returns 304 on `If-None-Match` match.
        - Apply only to public GETs (auth-d responses already `private, no-store`).
        - Confirm the hash is stable (sort JSON keys) so the same payload always generates the same ETag.
    - **Technical:** Saves bytes on hot/unchanged payloads but adds the cost of computing the hash on every cache hit. Worth it when payloads are >5KB and clients revalidate (Hydrogen worker is the main case for you). Less valuable for first-time visitors.
    - **Plain English:** Some clients (like the Shopify storefront and mobile apps) ask "has this changed since last time?" instead of always downloading the full page. We can answer "no, same as before" with a tiny response. It only helps repeat visitors, but they're our most common case.
    - **Evidence:** Not yet implemented — net new middleware.

- [x] **#GS-10** · P3 — Wire phpredis serializer (igbinary) + LZ4 compression when payload size warrants
    - **Where:** config/database.php — Redis connection options; trigger when median cached payload exceeds ~10KB or Redis memory crosses ~1GB
    - **Affects:** Redis memory footprint + network I/O. Roughly 3× compression ratio with igbinary alone, more with LZ4 on top.
    - **Effort:** S (~1h plus phpredis rebuild check)
    - **What to do:**
        - Confirm the production phpredis build supports `igbinary` and `lz4` (Laravel Cloud should — verify via `phpinfo()`).
        - Add to `config/database.php` Redis options: `'serializer' => Redis::SERIALIZER_IGBINARY, 'compression' => Redis::COMPRESSION_LZ4`.
        - Test thoroughly: a serializer change invalidates all existing cache entries; deploy with a flush or schedule a rolling expiry.
    - **Technical:** Pure deferral until you have a real signal. PHP's default `serialize()` is verbose (JSON-like with PHP-specific markup); igbinary is binary-packed. LZ4 is fast and gives modest ratios; ZSTD gives higher ratios for slightly more CPU. CPU cost matters less than wire/memory cost above the 10KB-payload threshold.
    - **Plain English:** Redis stores our cached data in a verbose format by default. We can switch to a denser format that takes ~3× less space, but the payoff only matters once we're storing a lot of data. Not worth the deploy risk yet.
    - **Evidence:** Trigger-based — defer until cache memory or payload size warrants.

- [x] **#GS-11** · P3 — Confirm `getPublicSitePayload` rebuild doesn't run inside session middleware (CDN-blocking `Set-Cookie`)
    - **Where:** routes/api/publicSite.php (verify), config/session.php (current `'driver' => 'redis'`)
    - **Affects:** Any future Cloudflare/edge caching on public routes (see GS-8) — `Set-Cookie` on a response makes Cloudflare bypass cache.
    - **Effort:** S (~30min, mostly verification)
    - **What to do:**
        - Confirm `/api/public/*` routes are in the `api` middleware group, not `web` (which adds session + CSRF).
        - If any public route accidentally inherits `StartSession`, exclude it.
        - Test: `curl -I https://api.partna.dev/api/public/site-by-slug?slug=test` and confirm no `Set-Cookie` header.
    - **Technical:** Pre-requisite for GS-8. Most likely already correct since this is an API-first app — but worth one-line verification rather than discovering it after enabling Cloudflare cache and getting 0% hit rate.
    - **Plain English:** Cloudflare refuses to cache pages that have cookies attached. Most of our public pages don't have cookies, but it's worth double-checking before we turn on Cloudflare caching. Five-minute audit.
    - **Evidence:** Verification task — no current code change needed.

---

## Deferred / Overkill For Now

- [ ] **#DEFER-2** · DEFER — Laravel Pulse (cache hit/miss dashboards)
    - **Where:** Would install as a separate Laravel package + dashboard
    - **Affects:** Cache observability
    - **Effort:** S (~1–2h install) + ongoing UI to monitor
    - **Why deferred:** Your traffic doesn't yet warrant the dashboard overhead. Nightwatch covers exception/slow-route monitoring and a basic event-listener-based hit/miss counter (GS-5) is sufficient for pre-beta.
    - **Adoption trigger:** Sustained traffic crosses ~50 req/sec, OR cache-related incidents become hard to debug from logs alone.
    - **Plain English:** A Laravel-specific dashboard for cache stats, slow queries, and exceptions. We have Nightwatch for similar purposes already.

---

## Already Gold Standard

These are confirmed-correct foundations — no action needed. Listed for confidence and onboarding.

- ✅ **Single-flight stampede protection** with blocking lock + double-check after acquire + fallback timeout — `app/Services/Cache/CacheLockService.php:71-157` (`rememberLocked()`)
- ✅ **TTL jitter ±20%** on integer TTLs to prevent synchronized expiry stampedes — `CacheLockService.php:180`
- ✅ **Stale-while-revalidate** with 10× TTL stale window and non-blocking refresh on stale hit — `CacheLockService.php:53` (`STALE_TTL_MULTIPLIER`), used by all `rememberLocked()` paths AND by `getPublicSitePayload` via inline SWR (CACHE-2 fix, commit ea994cf)
- ✅ **Isolated lock connection** — locks live in Redis DB 3, cache in DB 1, so `Cache::flush()` cannot accidentally release in-flight locks — `config/database.php:197-206`
- ✅ **Push-invalidation via observers** with `$afterCommit = true` — no cache-vs-transaction race — `app/Observers/SiteObserver.php:13`, `ProfessionalObserver.php`, `ServiceObserver.php`, etc.
- ✅ **Tenant-namespaced cache keys** centralized in `CacheKeyGenerator` (~35 patterns, all include `professional_id` or `site_id`) — `app/Services/Cache/CacheKeyGenerator.php`
- ✅ **No `Cache::tags()` usage** anywhere in the codebase — verified via grep. (Tags have known memory leaks and were dropped from Laravel 10 docs; your absence is correct.)
- ✅ **Versioned bulk invalidation** for analytics summaries via `Cache::increment` of a version counter — `AnalyticsCacheService.php:83` (the modern alternative to tags)
- ✅ **Public HTTP caching** with allowlisted public GETs, `s-maxage=900`, `Vary: X-Site-Subdomain, Accept-Encoding`, and `private,no-store` for auth/sensitive paths — `app/Http/Middleware/AddPublicCacheHeaders.php`
- ✅ **Webhook idempotency via `Cache::add()`** with TTL on `shopify_event_id`/`stripe_event_id` — stops duplicate processing without a DB write — `app/Http/Controllers/Api/Webhooks/`
- ✅ **Cache corruption guard** that detects "got someone else's profile in this key" and flushes both primary + stale — defensive coding for the worst-case bug class — `app/Services/Cache/ProfessionalCacheService.php:169`

---

## Suggested Bundled Sessions

Items grouped by shared touchpoint — if you're editing a file for one finding, knock out the related ones in the same session.

### Bundle A — Systemic SWR-bust sweep + `SiteCacheService.php` consolidation
All five findings here share the same root cause (see top-of-file note). One focused session knocks them all out by (a) refactoring `getPublicSitePayload` to use `rememberLocked` and (b) auditing every other `rememberLocked` call site for missing `:stale` busts.
- **#CACHE-1** (siteBlocks `:stale` bust)
- **#CACHE-6** (services + customer count `:stale` bust)
- **#CACHE-2** (public payload SWR)
- **#CACHE-4** (lock timeout flash 404 — auto-resolved by CACHE-2 fix)
- **#CACHE-3** (cross-tenant herd jitter — uses `ServiceObserver::syncDispatchDelay` pattern)

### Bundle B — Operational hardening
All small, all changes to config/CI without behavior change.
- **#GS-1** (CacheKey CI lint) ✅
- **#GS-2** (CACHE_STORE=failover) ✅
- **#GS-3** (centralized TTLs) ✅

---

## Pipeline Note

The dual-worker pipeline took ~17 min wall time on the adjudicator stage (slow Anthropic API stream over Starlink), but landed at 21:21 with the file `audit-2026-05-07-caching-strategy-and-stampede-protection-for-multi.md`. **This document has been updated with the adjudicator's refinements:** (1) added new finding #CACHE-6 (services + customer count `:stale` bug — a real P2 the initial synthesis missed), (2) corrected the technical analysis on #CACHE-5 (the version-token does NOT cover visit/click stat keys, only summary keys), (3) referenced the existing `ServiceObserver::syncDispatchDelay()` jitter pattern as the precedent for #CACHE-3's fix, (4) added the systemic SWR-bust root-cause note at the top.
