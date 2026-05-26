- [ ] **CACHE-1** · P1 — `isStorefrontReachable()` cache lacks single-flight locking, TTL jitter, and push invalidation
    - **Where:** app/Services/Professional/Brand/BrandStatusService.php:263-281
    - **Affects:** Every admin page load hitting `/internal/embedded/provision-integration` and every brand onboarding checklist poll — all 30 brands at target scale.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::get` + `Cache::put` with `CacheLockService::rememberLocked('brand_status:storefront_reachable:' . sha1($url), 60, fn() => ...)` — the lock grants single-flight so concurrent requests share one HTTP probe.
        - Add ±20% TTL jitter via the `rememberLocked` jitter parameter to prevent synchronised expiry thundering herds.
        - On the storefront-deployment webhook path, push-invalidate the exact cache key so a freshly deployed storefront flips to reachable instantly rather than waiting up to 15s.
    - **Technical:** The code comment acknowledges this HTTP probe "dominates p95 on hot endpoints" without the cache. The existing `Cache::get`/`Cache::put` pair is a classic cold-cache stampede vector — after a Redis eviction, deploy, or flush, every concurrent request races its own 5-second `Http::get()` to the brand's storefront URL. `CacheLockService::rememberLocked` wraps the probe in a Redis-backed mutex (lock key scoped to the cache key), so only one request pays the HTTP cost. The 15s negative TTL is a good intuition but without push invalidation the dashboard stays stale for up to 15s after a deployment succeeds. The canonical replacement already deployed in commerce analytics (`rememberLocked` + jitter + SWR + push-invalidate on every write) maps directly onto this use case.
    - **Plain English:** Every time a staff member loads the admin dashboard or a brand checks their onboarding progress, the system pings the brand's storefront to see if it's live. The dev team already added a short-lived "sticky note" (cache) so we don't ping over and over. But when that sticky note falls off — after a server restart, a cache clear, or just when the timer runs out — every request that arrives at the same moment sends its own ping. That's like twenty people all calling the same store to ask if they're open, at the exact same second. The fix is a "take a number" system: the first person makes the call, everyone else waits and reads the answer from the board.
    - **Evidence:**
        ```php
        $cacheKey = 'brand_status:storefront_reachable:'.sha1($url);

        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (bool) $cached;
        }

        try {
            $response = Http::withOptions([
                'allow_redirects' => false,
                'timeout' => 5,
                'connect_timeout' => 3,
            ])->get($url);

            $reachable = $response->successful();
        } catch (\Throwable) {
            $reachable = false;
        }

        Cache::put($cacheKey, $reachable, $reachable ? 60 : 15);

        return $reachable;
        ```
    - `[DRAFT, confidence: 0.9]`
