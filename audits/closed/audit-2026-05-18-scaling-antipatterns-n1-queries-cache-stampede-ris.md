# Scaling Antipatterns — N+1 Queries, Cache Stampede Risk Audit — 2026-05-18

**Branch:** development
**Lens:** scaling antipatterns N+1 queries cache stampede risk
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/FeatureFlags/FeatureFlagService.php
- app/Services/Cache/CacheLockService.php
- app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagController.php
- app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagOverrideController.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **#SCALE-1** · P2 — Per-flag Redis amplification: no request-level memoization in `enabled()`
    - **Where:** app/Services/FeatureFlags/FeatureFlagService.php:44–55 (`enabled()` → `loadAll()`)
    - **Affects:** Any request that checks more than one feature flag (middleware + controller combined). Each `feature('a')`, `feature('b')`, `feature('c')` call independently hits Redis for the same `ff:registry` and `ff:pro:{id}` keys — 3 Cache::get calls per `enabled()` call, 9 for 3 flag checks.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add request-level memoization on the service instance: store the loaded `[$registry, $proOverrides, $brandOverrides]` tuple keyed by `"{$pro?->id}:{$brand?->id}"` after the first `loadAll()` call within a request, and return the cached tuple on subsequent calls.
        - The `CacheLockService::rememberLocked` fast path already does a single `Cache::get` on warm keys (no lock overhead), so this is purely eliminating redundant Redis round-trips across the same request lifecycle.
    - **Technical:** `loadAll()` has no in-request guard — each call unconditionally executes `loadRegistry()` + `loadProOverrides()` + `loadBrandOverrides()`, each of which calls `Cache::get` on its key. `rememberLocked` returns immediately on a warm cache (line 82–85 of `CacheLockService`), so no lock contention occurs, but the Redis round-trips still add up. A request checking 5 flags for the same pro+brand context makes 15 identical Cache::get calls against 3 keys. Storing the resolved triple in a `private array $requestCache = []` on the service after the first load reduces this to 3 Cache::get calls per request regardless of flag count.
    - **Plain English:** Every time the app asks "is feature X on for this user?", it walks to three filing cabinets to fetch the same folders it already fetched when it checked feature Y ten milliseconds ago. Adding a sticky note on the desk that says "I already checked these — here's what they said" eliminates all the redundant cabinet trips within the same web request.
    - **Evidence:**
        ```php
        public function enabled(string $key, ?Professional $pro = null, ?BrandProfile $brand = null): bool
        {
            try {
                [$registry, $proOverrides, $brandOverrides] = $this->loadAll($pro, $brand);
                return $this->resolveFromArrays($key, $registry, $proOverrides, $brandOverrides, $pro);
            } catch (Throwable $e) { ...
        }

        private function loadAll(?Professional $pro, ?BrandProfile $brand): array
        {
            $registry = $this->loadRegistry();
            $proOverrides = $pro !== null ? $this->loadProOverrides($pro->id) : [];
            $brandOverrides = $brand !== null ? $this->loadBrandOverrides($brand->id) : [];
            return [$registry, $proOverrides, $brandOverrides];
        }
        ```

- [ ] **#SCALE-2** · P2 — Cache-invalidation failures silently ignored; caller receives 201/204 with no stale-cache signal
    - **Where:** app/Services/FeatureFlags/FeatureFlagService.php:108–116 (`setOverride` catch block), 131–138 (`clearOverride` catch block)
    - **Affects:** Staff admins setting or clearing overrides during a Redis blip. The DB write succeeds and returns success, but the flag read path serves the old value for up to 5 minutes (primary TTL) or up to 50 minutes (stale TTL via SWR in `CacheLockService`).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Surface the stale-cache condition to the caller by throwing a specific exception (e.g. `CacheInvalidationFailedException`) that the controller catches and converts into a `207 Multi-Status` or a `meta.warnings` array in the response body.
        - Alternatively, add an `X-Cache-Stale: 1` response header from the controller when `setOverride` / `clearOverride` catch a Redis error — the UI can surface a toast: "Override saved, may take a few minutes to propagate."
        - Note: because `CacheLockService` uses stale-while-revalidate with a 10× TTL multiplier (50 minutes for a 5-minute primary), the stale window is longer than it appears from TTL alone.
    - **Technical:** `setOverride` and `clearOverride` use a try/catch around `forgetPro` / `forgetBrand` that only logs `Log::warning('feature_flags.invalidation_failed', ...)`. The HTTP response is already formed by the controller before or after the call — there is no mechanism for the service to signal partial success. Given `CacheLockService`'s SWR writes a `:stale` key at 10× the primary TTL (up to ~50 min), a Redis blip during invalidation could leave the stale value served for nearly an hour before natural expiry — meaningfully longer than the base 5-minute TTL implies.
    - **Plain English:** Imagine you update a setting in an admin panel, get a green "Saved!" confirmation, but the feature stays at its old value for up to an hour. The database was updated correctly, but the quick-reference cheat-sheet wasn't cleared, and nobody told you the cheat-sheet was out of date. The solution is for the system to say "Saved, but the change may take up to an hour to appear" rather than silently implying everything is live immediately.
    - **Evidence:**
        ```php
        try {
            if ($scope->brandId !== null) {
                $this->forgetBrand($scope->brandId);
            } else {
                $this->forgetPro($scope->professionalId);
            }
        } catch (Throwable $e) {
            Log::warning('feature_flags.invalidation_failed', [
                'error' => $e->getMessage(),
                'flag_key' => $key,
                'scope_brand_id' => $scope->brandId,
                'scope_professional_id' => $scope->professionalId,
            ]);
        }
        // Response is already 201/204 — no indication to the caller.
        ```

- [ ] **#SCALE-3** · P2 — `resolveFromDb()` degraded path issues per-flag queries; N×3 DB hits vs. `allForFromDb()`'s flat 3
    - **Where:** app/Services/FeatureFlags/FeatureFlagService.php:188–221 (`resolveFromDb`), compared with 156–186 (`allForFromDb`)
    - **Affects:** All requests during a Redis outage. N flags checked in a request → 3N queries instead of 3. A request checking 5 flags issues 15 DB queries during the cache failure that already signals DB pressure.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `enabled()`'s catch block call to `resolveFromDb()` with a call to `allForFromDb()`, store the result on a request-scoped property, and resolve the single key from the in-memory map — exactly how `allFor()`'s degraded path already works.
        - This unifies the two degraded paths and eliminates the asymmetry. The batched 3-query load is already implemented in `allForFromDb()` so no new DB code is needed.
    - **Technical:** `resolveFromDb()` queries the registry for a single key (`->where('key', $key)`) plus one `first()` each for the pro and brand override rows — 3 queries per flag. `allForFromDb()` fetches all registry rows + all pro override rows + all brand override rows in 3 queries total, then resolves every key from arrays. The two degraded paths are structurally inconsistent: `allFor()` degrades correctly; `enabled()` degrades with N+1 semantics. Under a real Redis outage, the per-flag path amplifies DB load at exactly the moment the DB can least afford it.
    - **Plain English:** When the fast cache breaks down, the system falls back to asking the database directly. For bulk lookups it's sensible — one trip, fetch everything. For single lookups it's wasteful — one trip per question. During a cache outage with a hundred feature checks happening, the single-lookup path sends a hundred separate database questions. The fix is to make the single-lookup path piggyback on the same "one trip, remember everything" approach the bulk path already uses.
    - **Evidence:**
        ```php
        // enabled() fallback — per-flag queries (3 per flag):
        private function resolveFromDb(string $key, ?Professional $pro, ?BrandProfile $brand): bool
        {
            $registry = FeatureFlag::query()->whereNull('deleted_at')->where('key', $key)->get()...;
            $proOverrides = [];
            if ($pro !== null) {
                $row = FeatureFlagOverride::where('flag_key', $key)...->first();
            }
            $brandOverrides = [];
            if ($brand !== null) {
                $row = FeatureFlagOverride::where('flag_key', $key)...->first();
            }
            return $this->resolveFromArrays($key, $registry, $proOverrides, $brandOverrides, $pro);
        }

        // allFor() fallback — 3 queries total for all keys:
        private function allForFromDb(?Professional $pro, ?BrandProfile $brand): array
        {
            $registry = FeatureFlag::query()->whereNull('deleted_at')->get()...;
            // ... one query each for pro/brand overrides, then loop for all keys
        }
        ```

## P3 — Nice to have

- [ ] **#SCALE-4** · P3 — `flush()` docblock claims to flush all FF cache keys but only flushes the registry
    - **Where:** app/Services/FeatureFlags/FeatureFlagService.php:145–148
    - **Affects:** Staff or engineers who call `flush()` during incident response expecting a complete reset. Per-professional and per-brand override caches (`ff:pro:{id}`, `ff:brand:{id}`, and their `:stale` variants) are untouched and persist for up to 50 minutes via SWR.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Rename the docblock to accurately state: "Flushes only the registry cache key. Pro/brand override keys expire naturally (up to 50 min via SWR). Call `forgetPro($id)` / `forgetBrand($id)` to invalidate individual scopes."
        - Optionally rename the method to `flushRegistry()` and make `flush()` an alias that is honest about what it does — but `flushRegistry()` already exists as a named method, so the cleanest fix is updating the docblock on `flush()` to say it delegates to `flushRegistry()`.
    - **Technical:** The docblock reads "Flush all FF cache keys. Useful in tests and admin operations." The body is `$this->flushRegistry()`, which only deletes `ff:registry` and `ff:registry:stale`. Because `CacheLockService` writes a `:stale` copy at 10× the primary TTL, a pro/brand override key written 4 minutes ago could remain live for up to 46 more minutes after `flush()` is called. The misleading docblock is a latent incident-response hazard.
    - **Plain English:** There's a "Clear All Caches" command that, despite its name, only clears the master list of feature flags. Each user's personal copy stays in place for up to 50 minutes. The fix is just correcting the label so engineers during an incident aren't tricked into thinking a full reset happened when it didn't.
    - **Evidence:**
        ```php
        /** Flush all FF cache keys. Useful in tests and admin operations. */
        public function flush(): void
        {
            $this->flushRegistry();
        }
        ```
