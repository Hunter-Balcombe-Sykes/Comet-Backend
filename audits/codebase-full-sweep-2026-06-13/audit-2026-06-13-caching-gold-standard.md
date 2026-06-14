# Caching: Gold-Standard Adherence Audit — 2026-06-13

**Branch:** development
**Lens:** Caching: gold-standard adherence — measures every cache read/write against the `CacheLockService` / `JitteredTtl` gold standard: single-flight lock, TTL jitter, stale-while-revalidate, push invalidation, version-token busting, lock connection hygiene, bounded TTLs, and centralised key generation.
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-5`
**Source files audited:**
- `app/Services/Cache/UserCacheService.php`
- `app/Services/Cache/CacheLockService.php`
- `app/Services/Cache/Concerns/JitteredTtl.php`
- `app/Services/FeatureFlags/FeatureFlagService.php`
- `app/Http/Middleware/IdempotencyKey.php`
- `app/Http/Middleware/Auth/VerifySupabaseJwt.php`
- `app/Services/Site/*`, `app/Services/PublicSite/*`, `app/Services/Accounts/*`
- `app/Services/Notifications/*`, `app/Services/Analytics/*`, `app/Services/Streaming/*`
- `app/Observers/*`, `app/Jobs/*`, `app/Http/Controllers/Api/*`
- `config/cache.php`

**Adjudication notes — dropped findings:**

- **CCH-2 (draft) — IdempotencyKey lock on default store:** Dropped. `config/cache.php` line 80 sets `'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'cache_locks')` on the `redis` store. Laravel's Redis cache driver routes all `Cache::lock()` calls through that dedicated connection automatically — no data-store flush can release it. The inline comment in `IdempotencyKey.php` (lines 80–82) confirms this is the intended design.

- **CCH-3 (draft) — VerifySupabaseJwt outage-throttle lock on default store:** Dropped for identical reasons. `VerifySupabaseJwt::jwksOutage()` comment (lines 424–430) explicitly calls out the `lock_connection` isolation. Confirmed correct by `config/cache.php`.

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 3 complete

---

## P3 — Nice to have

- [ ] **CCH-1** · P3 — Bare `Cache::put` in auth-ID mismatch repair path bypasses `rememberLockedNullable`
    - **Where:** `app/Services/Cache/UserCacheService.php:186` (inside the `if (string) $professional->auth_user_id !== $authUserId` branch of `getByAuthId`)
    - **Affects:** The rare defensive mismatch-repair path: concurrent requests hitting a corrupt auth-ID mapping each run their own DB query and independently re-write the mapping without a single-flight lock.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the bare `Cache::put($authIdKey, $freshId, ...)` with a call to `$this->cacheLock->rememberLockedNullable($authIdKey, ..., fn() => User::query()->where('auth_user_id', $authUserId)->value('id'))` to restore single-flight semantics on the repair write.
        - Since the old keys are already forgotten above this line, the `rememberLockedNullable` call will always miss and run the closure — single-flight simply means only one concurrent caller executes the DB query.
        - Note: `rememberLockedNullable` intentionally omits jitter (by design for negative-cache lookups), so no jitter change is needed.
    - **Technical:** The normal fast-path for `getIdByAuthId` goes through `rememberLockedNullable`, which acquires a `Cache::lock` before computing. The mismatch-repair branch below it forgets the keys and then writes back with a bare `Cache::put($authIdKey, $freshId, (int) config('partna.cache.ttls.auth_id_lookup'))`. If N concurrent requests all arrive with the same corrupt cache entry simultaneously, they all branch into the mismatch path, all run a DB query (`User::where('auth_user_id', ...)->value('id')`), and all write the same value. The writes are idempotent (same `$freshId`, same key), so no data integrity risk exists. The waste is N redundant DB reads in an already-rare event. Wrapping the repair write in `rememberLockedNullable` collapses N queries to one and aligns the repair path with the gold standard.
    - **Plain English:** Imagine a filing clerk who notices a mislabelled folder and looks up the correct label in the master index — but there's no rule stopping five clerks from doing this at the same time. They'll all look up the same thing and all write the same correct label, which wastes their time but produces the right result. Posting a "repair in progress" sign (the lock) means only one clerk does the lookup and the others wait for the answer.
    - **Evidence:**
        ```php
        $freshId = User::query()
            ->where('auth_user_id', $authUserId)
            ->value('id');

        if (! $freshId) {
            return null;
        }

        Cache::put($authIdKey, $freshId, (int) config('partna.cache.ttls.auth_id_lookup'));
        ```

- [ ] **CCH-2** · P3 — Double-jitter on `FeatureFlagService` integer TTL path
    - **Where:** `app/Services/FeatureFlags/FeatureFlagService.php:262–273` (`jitteredTtl()` method), called from `loadRegistry()` and `loadProOverrides()`
    - **Affects:** Feature flag cache TTL spread: the effective TTL range for a 300s base becomes approximately 192–432s instead of the intended 240–360s, as two independent jitter operations compound.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove the `random_int(-self::TTL_JITTER_SECONDS, self::TTL_JITTER_SECONDS)` pre-jitter from the int return path of `jitteredTtl()` — return `self::BASE_TTL_SECONDS` directly on the int branch and let `CacheLockService::writeWithJitter` apply the single canonical ±20% jitter.
        - Keep the `Carbon` return path (which does need the explicit `$secondsUntilExpiry < $base` cap) unchanged — `CacheLockService` honours `DateTimeInterface` TTLs verbatim and does not re-jitter them, so that path is correct.
        - The existing docblock on `jitteredTtl()` already describes the intended contract ("Returns an int (seconds) for the normal path so CacheLockService applies its ±20% jitter") — the implementation just doesn't match it on the int branch.
    - **Technical:** `CacheLockService::writeWithJitter` calls `self::applyJitter($ttl)` (±20% uniform) on every integer TTL it receives. `FeatureFlagService::jitteredTtl()` pre-applies `random_int(-60, 60)` to the 300s base before passing the result to `rememberLocked`. The two independent draws compound: if `random_int` draws +60 (base → 360s) and `applyJitter` then draws +20% (→ 432s), or if `random_int` draws -60 (base → 240s) and `applyJitter` draws -20% (→ 192s), the range is ~2.2× wider than either layer alone intends. No functional breakage occurs — feature flag TTLs are wide enough that 192s vs 432s makes no operational difference — but the double-jitter is inconsistent with the architecture principle of one centralised jitter layer and makes the effective TTL window harder to reason about.
    - **Plain English:** The service is shuffling a deck of cards, handing it to a second shuffler, and calling the result "one shuffle." Both shufflers are doing their job correctly, but doing it twice creates a wider range of outcomes than the rules intended. The fix is to only shuffle once — let the central shuffler (CacheLockService) do its job and skip the first shuffle.
    - **Evidence:**
        ```php
        private function jitteredTtl(?Carbon $nearestExpiry = null): Carbon|int
        {
            $base = self::BASE_TTL_SECONDS + random_int(-self::TTL_JITTER_SECONDS, self::TTL_JITTER_SECONDS);
            // ...
            return $base;  // int path — then rememberLocked calls applyJitter() again
        }

        // Called as:
        $this->cacheLock->rememberLocked(
            self::REGISTRY_KEY,
            $this->jitteredTtl(),  // already jittered int, jittered again by writeWithJitter
            function (): array { ... },
        );
        ```

- [ ] **CCH-3** · P3 — Unjittered 24-hour literal TTL on idempotency response cache
    - **Where:** `app/Http/Middleware/IdempotencyKey.php:16` (`const TTL_SEC = 86_400`) and `:117–122` (the `Cache::put` call)
    - **Affects:** Consistency with the fleet-wide jitter convention. Thundering-herd risk is negligible in practice: idempotency keys are scoped to `{version}:{userId}:{route}:{idempotency-uuid}`, so two keys never share an expiry by construction.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `use App\Services\Cache\Concerns\JitteredTtl;` and the `use JitteredTtl;` declaration to `IdempotencyKey`.
        - Change the `Cache::put` TTL argument to `self::applyJitter(self::TTL_SEC)`.
        - This is a hygiene fix only — no operational urgency.
    - **Technical:** `Cache::put($cacheKey, [...], self::TTL_SEC)` writes a hard-coded 86 400-second TTL. Because each key encodes a UUID v4 idempotency value, expiry is inherently staggered — simultaneous cold misses across different users do not share a key and cannot produce a thundering herd. The gold standard asks that every literal int TTL passed to `Cache::put` be routed through `JitteredTtl::applyJitter` for consistency and to guard against the edge case of a fleet-wide replay storm after a cache clear, but the risk here is low enough that this is a P3 consistency finding only.
    - **Plain English:** Every cache write in the system is given a small random delay in its expiry time so they don't all expire at exactly the same moment. This particular cache skips that random delay. Because each cache entry is tied to a unique user request, the risk of everything expiring at once is nearly zero — this is a code-consistency fix, not an urgent safety fix.
    - **Evidence:**
        ```php
        private const TTL_SEC = 86_400;  // 24h response cache

        // ...

        Cache::put($cacheKey, [
            'v' => 1,
            'status' => $response->getStatusCode(),
            'body' => $response->getContent(),
            'headers' => $this->captureHeaders($response),
        ], self::TTL_SEC);
        ```

