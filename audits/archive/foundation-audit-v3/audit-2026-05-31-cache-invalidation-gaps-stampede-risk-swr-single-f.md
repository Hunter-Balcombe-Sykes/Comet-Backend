`★ Insight ─────────────────────────────────────`
**Tool calls revealed two critical DeepSeek errors:**
1. **CACHE-1 hallucinated**: `RecordCacheMetrics::handle()` calls `Redis::expire($bucketKey, 172800)` (48h) on first write — the job's lack of explicit DEL is intentional and correct.
2. **CACHE-2 misread**: `CloudflareCachePurgeJob` implements `ShouldBeUnique` with `uniqueFor = 120` and a handle-based `uniqueId()` — the same deduplication as `WarmPublicSiteCacheJob`. The "double CF purge" claim is factually wrong.
`─────────────────────────────────────────────────`

Both DeepSeek CACHE-1 and CACHE-2 are dropped. The `UserUploadController::index` filter space (`['gallery','content']` × `['image','video','all']`) exactly matches `siteImagesViewVariants` today (CACHE-3 confirmed as P3). And the `rememberLocked`/nullable contract violation in `getByAuthId` is a real missed finding.

# Cache Correctness Audit — 2026-05-31

**Branch:** development
**Lens:** Cache invalidation gaps, stampede risk, SWR/single-flight correctness, stale reads, KV/Redis/HTTP cache layering, thundering-herd on cold profile reads when 10k pages expire together
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Cache/CacheLockService.php
- app/Services/Cache/CacheKeyGenerator.php
- app/Services/Cache/Concerns/JitteredTtl.php
- app/Services/Cache/SiteCacheService.php
- app/Services/Cache/UserCacheService.php
- app/Listeners/RecordCacheMetrics.php
- app/Jobs/Cache/AggregateCacheMetricsJob.php
- app/Jobs/Cache/WarmPublicSiteCacheJob.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Observers/Core/SiteObserver.php
- app/Observers/Core/ServiceObserver.php
- app/Observers/Core/BlockObserver.php
- app/Observers/Core/SiteMediaObserver.php
- app/Observers/Core/CustomerObserver.php
- app/Observers/Core/ServiceCategoryObserver.php
- app/Observers/User/UserObserver.php
- app/Services/PublicSite/IndividualProfilePayloadBuilder.php
- app/Services/PublicSite/SitepageDataResolverService.php
- app/Services/PublicSite/PublicSiteResolver.php
- app/Http/Controllers/Api/User/Uploads/UserUploadController.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [ ] **#CACHE-1** · P2 — `getByAuthId` uses `rememberLocked` for a nullable callback; null results bypass the fast path and drain a Redis lock + DB round-trip on every auth request
    - **Where:** app/Services/Cache/UserCacheService.php:156–159
    - **Affects:** Any authenticated request whose JWT belongs to a user deleted after their ID was written to the `pro:map:auth:{uid}` cache. For the full duration of the `auth_id_lookup` TTL (~30 min) every HTTP request from that token acquires a blocking Redis lock, queries Postgres, stores null, and repeats — never serving from cache.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$this->cacheLock->rememberLocked(...)` at line 156 with `$this->cacheLock->rememberLockedNullable(...)`.
        - Pass `nullTtl: now()->addSeconds(30)` (matching the pattern in `getIdByAuthId`) so the "user not found" result is cached as a sentinel for a short window and all subsequent calls within that window return null immediately without touching Redis locks or Postgres.
        - No other changes needed — the `if (! $professional)` guard above already handles the null return value correctly.
    - **Technical:** `CacheLockService::rememberLocked` is documented as accepting only closures that return non-null values. Its fast-path check is `if ($cached !== null) return $cached`. Laravel's Redis driver stores PHP-serialised null (`N;`) when `Cache::put($key, null, $ttl)` is called, but `Cache::get($key)` returns `null` for both a stored null and a missing key — making the fast path structurally unable to detect a cached null. The SWR stale-key check (`if ($stale !== null)`) has the same property. The net effect: when `User::find($id)` returns null (user deleted), the result is written to Redis but is undetectable on every subsequent call. Each request falls through to the cold-miss path, acquires a 10-second blocking lock, and re-queries Postgres — effectively a per-request DB hit for the lifetime of the `auth_id_lookup` cache entry (~30 min). `rememberLockedNullable` stores a `__cache_lock_null_sentinel__` string sentinel that survives the `!== null` check, breaking the loop. The correct method for all nullable lookups is already used in `getIdByAuthId`, `getIdByHandle`, and `getPayloadById` on the lines immediately surrounding the bug.
    - **Plain English:** Imagine your receptionist keeps a list of who's allowed in the building. When someone's pass is revoked, you delete their name from the HR system but not from the receptionist's list. Now every time that person's badge is scanned, the receptionist checks the main system, finds nothing, writes a sticky note that says "not found" — but the sticky note is written in invisible ink. So the next badge scan repeats the whole process. The fix is to use normal ink so the note stays readable: "this person was checked, they're gone, stop asking."
    - **Evidence:**
        ```php
        // UserCacheService::getByAuthId — line 156
        $professional = $this->cacheLock->rememberLocked(          // ← wrong method
            CacheKeyGenerator::professionalModel($id),
            (int) config('partna.cache.ttls.professional_model'),
            fn () => User::query()->with(['site'])->find($id),     // ← can return null
        );
        ```
        ```php
        // CacheLockService::rememberLocked — fast path that can never match a stored null
        $cached = Cache::get($key);
        if ($cached !== null) {   // ← Cache::get() returns null for BOTH missing AND null-valued keys
            return $cached;
        }
        ```
        ```php
        // Correct pattern already used three lines above in the same file
        return $this->cacheLock->rememberLockedNullable(
            CacheKeyGenerator::userIdByAuthId($authUserId),
            (int) config('partna.cache.ttls.auth_id_lookup'),
            fn () => User::query()->where('auth_user_id', $authUserId)->value('id'),
            nullTtl: now()->addSeconds(30),
        );
        ```

---

## P3 — Nice to have

- [ ] **#CACHE-2** · P3 — `siteImagesViewVariants` hardcodes the filter space with no enforcement link to the controller's allowlists
    - **Where:** app/Services/Cache/CacheKeyGenerator.php:110–123
    - **Affects:** Dashboard image gallery clients that filter by pool or media type. If a new pool value or media-type value is added to `UserUploadController::index` without updating `siteImagesViewVariants`, the corresponding filtered-view cache keys are never enumerated by `invalidateSite`, leaving stale gallery data visible until the per-key TTL expires (currently 30 seconds, but jitter makes this variable).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the accepted pool values (`['gallery', 'content']`) and media-type values (`['image', 'video', 'all']`) into shared constants on a central class (e.g., `SiteMedia::GALLERY_POOLS` and `SiteMedia::MEDIA_TYPE_FILTERS`) and reference those constants from both `UserUploadController::index` and `siteImagesViewVariants`.
        - Add a unit test asserting that `siteImagesViewVariants()` returns exactly the Cartesian product of those constants, so an addition to either allowlist fails the test until the other side is updated.
    - **Technical:** `siteImagesViewVariants()` returns `[null, 'gallery', 'content'] × ['image', 'video', 'all']`. `UserUploadController::index` validates `pool` against `['gallery', 'content']` (inline `in_array`) and `media_type` against `['image', 'video', 'all']` (inline `in_array`) at lines 109 and 114. The two lists currently match. The only enforcement mechanism is the code comment "Keep this aligned with the filter-input space accepted in `UserUploadController::index`". Comments erode; a shared constant or a test assertion doesn't. The stale-variant risk is bounded (30s TTL) but the maintenance trap is permanent without structural enforcement.
    - **Plain English:** The system has nine pre-labelled boxes for storing filtered image results. If a developer adds a tenth filter option to the dashboard but forgets to add a tenth box label, writes that should empty all boxes will miss the tenth one. Right now the boxes and filters happen to match, but only because a comment asks humans to keep them aligned — not because anything enforces it automatically.
    - **Evidence:**
        ```php
        // CacheKeyGenerator::siteImagesViewVariants — hardcoded, no reference to controller allowlists
        public static function siteImagesViewVariants(): array
        {
            $variants = [];
            foreach ([null, 'gallery', 'content'] as $pool) {
                foreach (['image', 'video', 'all'] as $mediaType) {
                    $variants[] = [$pool, $mediaType];
                }
            }
            return $variants;
        }
        ```
        ```php
        // UserUploadController::index — separate duplicate of the same allowlists
        $mediaTypeFilter = in_array($rawMediaType, ['image', 'video', 'all'], true) ? $rawMediaType : 'image';
        // ...
        if (in_array($candidate, ['gallery', 'content'], true)) {
            $pool = $candidate;
        }
        ```

- [ ] **#CACHE-3** · P3 — MISS_SENTINEL TTL writes skip the `JitteredTtl` trait used everywhere else in the cache layer
    - **Where:** app/Services/Cache/SiteCacheService.php:93–95
    - **Affects:** Subdomain lookups for non-existent handles. A bot scanner that requests many bogus subdomains within a short window creates a set of negative-cache entries that all expire at the same wall-clock second, producing a synchronised wave of DB lookups 30 seconds later. The lookup is a cheap indexed read, but the pattern is inconsistent with the rest of the caching layer.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the two `now()->addSeconds(...)` calls in the MISS_SENTINEL branch of `buildPayloadFromDb` with `self::applyJitter(self::MISS_PRIMARY_TTL_SECONDS)` and `self::applyJitter(self::MISS_PRIMARY_TTL_SECONDS * self::PAYLOAD_STALE_TTL_MULTIPLIER)`.
        - The `JitteredTtl` trait is already used on the class (`use JitteredTtl`) so `self::applyJitter()` is already available — no additional imports needed.
    - **Technical:** `CacheLockService::writeWithJitter` and `SiteCacheService::writePayloadWithStale` both apply ±20% jitter to integer TTLs via `JitteredTtl::applyJitter`. The negative-cache path in `buildPayloadFromDb` uses bare `now()->addSeconds()` calls, bypassing jitter entirely. `MISS_PRIMARY_TTL_SECONDS = 30` means all MISS_SENTINEL entries written in a 30-second burst expire within the same 30-second window. The `JitteredTtl` trait is already mixed into `SiteCacheService` and `self::applyJitter()` is callable — the fix is a two-line change.
    - **Plain English:** When someone types a made-up website address, the system writes a "this doesn't exist" memo and holds it for exactly 30 seconds. If 500 fake addresses are checked in a burst, all 500 memos expire at exactly the same moment 30 seconds later — like setting every parking meter on the block to expire at noon simultaneously. The rest of the caching system already randomises its timers to avoid this; this one path was missed.
    - **Evidence:**
        ```php
        // SiteCacheService::buildPayloadFromDb — MISS_SENTINEL branch skips applyJitter
        Cache::put($key, self::MISS_SENTINEL, now()->addSeconds(self::MISS_PRIMARY_TTL_SECONDS));
        Cache::put($staleKey, self::MISS_SENTINEL, now()->addSeconds(self::MISS_PRIMARY_TTL_SECONDS * self::PAYLOAD_STALE_TTL_MULTIPLIER));
        ```
        ```php
        // JitteredTtl already on the class and used in writePayloadWithStale — one call away
        private function writePayloadWithStale(string $key, mixed $value): void
        {
            $base = (int) config('partna.cache.ttls.public_payload');
            Cache::put($key, $value, self::applyJitter($base));
            Cache::put($key.':stale', $value, self::applyJitter($base * self::PAYLOAD_STALE_TTL_MULTIPLIER));
        }
        ```
