
<!-- ═══ LENS: caching-gold-standard | CHUNK: read-paths ═══ -->

- [ ] **CCH-1** · P2 — Unjittered Cache::put in UserCacheService defensive auth_id mismatch path
    - **Where:** app/Services/Cache/UserCacheService.php (inside getByAuthId, when auth_id mismatch detected)
    - **Affects:** Cached auth-user-id lookups after a rare cache corruption repair; risks a small thundering herd when many sessions expire simultaneously.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply jitter to the TTL before writing: `Cache::put($authIdKey, $freshId, JitteredTtl::applyJitter((int) config('partna.cache.ttls.auth_id_lookup')));`
        - Alternatively, route the write through `CacheLockService::rememberLocked` with the same key.
    - **Technical:** The `Cache::put` call uses a literal integer TTL, bypassing the gold‑standard fleet‑wide jitter that spreads expiry across workers. After a mismatch‑triggered repair, all cache entries would expire at the exact same second, risking a synchronised miss under load. `CacheLockService` and the `JitteredTtl` trait provide ±20% jitter automatically; direct `Cache::put` with a raw int TTL skips that protection.
    - **Plain English:** Imagine a room full of alarm clocks all set to 3:00 PM. At exactly 3:00 they all ring at once, causing chaos. Adding a random offset of a few seconds makes them ring at slightly different times, so you never get a stampede. This code sets the exact same alarm for every user without the random offset.
    - **Evidence:**
        ```php
        Cache::put($authIdKey, $freshId, (int) config('partna.cache.ttls.auth_id_lookup'));
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **CCH-2** · P2 — Lock on default cache store in IdempotencyKey middleware, bypassing cache_locks separation
    - **Where:** app/Http/Middleware/IdempotencyKey.php (inside handle method)
    - **Affects:** Idempotency lock integrity if `Cache::flush()` is called on the data store.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `Cache::lock(...)` to `Cache::store('cache_locks')->lock(...)`.
        - Verify that `lock_connection` in `config/cache.php` points to a dedicated Redis connection for locks.
    - **Technical:** The gold standard mandates that lock keys live on the `cache_locks` Redis connection, so a data‑store `Cache::flush()` never releases held locks. The current code constructs the lock with the default cache instance; if the default lock store is the data store, a flush would prematurely release the idempotency lock, allowing concurrent identical requests to execute the handler.
    - **Plain English:** This is like keeping the key to a safe in the same drawer as your valuables. If someone empties the drawer, the safe pops open. Locks should be stored in a separate, dedicated lockbox.
    - **Evidence:**
        ```php
        $lock = Cache::lock($this->lockKey($version, $userId, $route, $key), self::LOCK_SEC);
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **CCH-3** · P2 — Lock on default cache store in VerifySupabaseJwt outage throttle
    - **Where:** app/Http/Middleware/Auth/VerifySupabaseJwt.php (private method jwksOutage)
    - **Affects:** JWKS outage reporting throttle; a cache flush could reset the throttle and flood observability.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::lock('jwt:jwks-failure-reported', 60)` with `Cache::store('cache_locks')->lock('jwt:jwks-failure-reported', 60)`.
    - **Technical:** Same lock hygiene issue as IdempotencyKey — the lock must be pinned to the `cache_locks` connection so that data‑cache flushes cannot release it. Without this, a `Cache::flush()` during a JWKS outage would reset the throttle and potentially flood Nightwatch with repeated reports.
    - **Plain English:** Another lock that should be kept in a separate lockbox, not mixed with regular keys. A shelf‑clearing would accidentally turn off the alarm.
    - **Evidence:**
        ```php
        $lock = Cache::lock('jwt:jwks-failure-reported', 60);
        if ($lock->get()) {
            report($outage);
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **CCH-4** · P3 — Double‑jitter on FeatureFlagService cache TTLs via pre‑jittered integer passed to CacheLockService
    - **Where:** app/Services/FeatureFlags/FeatureFlagService.php (method loadRegistry, inside `$this->cacheLock->rememberLocked(..., $this->jitteredTtl(), ...)`)
    - **Affects:** Feature flag cache TTL distribution; may cause wider‑than‑expected expiry windows but no functional breakage.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the service‑level jitter (`random_int(±60)`) from `jitteredTtl` when it returns an integer; rely solely on `CacheLockService`’s built‑in ±20% jitter.
        - Alternatively, change `jitteredTtl` to return the raw base TTL and let `rememberLocked` apply jitter.
    - **Technical:** `CacheLockService::writeWithJitter` applies its own ±20% jitter to every integer TTL. Pre‑applying an extra layer of randomisation via `jitteredTtl` means the resulting TTL is jittered twice, creating an unpredictable range. The intended design is one layer of jitter, centrally controlled.
    - **Plain English:** Adding a second random twist to a lottery machine that already randomises the numbers makes the outcome harder to predict but doesn’t break anything. Better to let the machine do its one job and remove the extra twist.
    - **Evidence:**
        ```php
        private function jitteredTtl(?Carbon $nearestExpiry = null): Carbon|int
        {
            $base = self::BASE_TTL_SECONDS + random_int(-self::TTL_JITTER_SECONDS, self::TTL_JITTER_SECONDS);
            // ...
            return $base;  // int path
        }

        // Then called:
        $this->cacheLock->rememberLocked(
            self::REGISTRY_KEY,
            $this->jitteredTtl(),  // pre‑jittered int
            ...
        );
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **CCH-5** · P3 — Unjittered TTL on idempotency response cache
    - **Where:** app/Http/Middleware/IdempotencyKey.php (inside handle method, when storing the response)
    - **Affects:** Cached idempotent API responses; low risk of synchronised expiry but inconsistent with fleet‑wide jitter practice.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the TTL with `JitteredTtl::applyJitter(self::TTL_SEC)` before calling `Cache::put`.
    - **Technical:** The response cache is written with a hard‑coded 86 400‑second TTL. According to the gold standard, every integer TTL passed to `Cache::put` should be jittered via `JitteredTtl::applyJitter` to avoid all entries expiring at the same moment after a fleet‑wide deployment or cache clear.
    - **Plain English:** Even though each key is unique, the alarm clocks are still set to the exact same 24‑hour countdown. A tiny random offset costs nothing and keeps everything tidy.
    - **Evidence:**
        ```php
        Cache::put($cacheKey, [
            'v' => 1,
            'status' => $response->getStatusCode(),
            'body' => $response->getContent(),
            'headers' => $this->captureHeaders($response),
        ], self::TTL_SEC);
        ```
    - `[DRAFT, confidence: 0.8]`

<!-- ═══ LENS: caching-gold-standard | CHUNK: write-paths ═══ -->

No findings identified in the audited files.
