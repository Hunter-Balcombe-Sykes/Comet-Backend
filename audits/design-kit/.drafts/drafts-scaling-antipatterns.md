- [ ] **CACHE-1** · P2 — Unlocked `Cache::remember` on handle-resolve hot key risks thundering herd
    - **Where:** app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:79–97
    - **Affects:** Public profile endpoint (95 % of traffic); every request does a handle → professional lookup that, on cache miss, hits the database with no single-flight lock.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::remember` with `$this->cache->rememberLocked` to add single-flight locking and jittered TTL to prevent stampede.
        - Remove the manual `Cache::forget` on the resolve key when a deleted race occurs — `rememberLocked` handles cache population atomically.
    - **Technical:** The resolve cache is a short-TTL (30 s) Redis key that maps a handle to a professional ID and site metadata. Without a lock, a cold cache after a deploy or eviction lets every concurrent request for the same handle query the database simultaneously, each rebuilding the same result. The canonical replacement is `CacheLockService::rememberLocked`, which combines a Redis lock with jittered TTL (±20 %) and stale-while-revalidate (SWR). This ensures only one worker queries the DB for a given handle, while others wait briefly for the lock or serve a stale value.
    - **Plain English:** Imagine every visitor to a professional's page has to check a sign-in book at the front desk. Right now, when the book is missing (cache is empty), every visitor rushes to the database to look up the professional, overloading it. The fix is like having a single receptionist who checks the database once and tells everyone else the result — no stampede.
    - **Evidence:**
        ```php
        $resolved = Cache::remember(
            "handle.resolve:{$handleLc}",
            self::RESOLVE_CACHE_TTL,
            function () use ($handleLc) {
                $pro = User::query()->where('handle_lc', $handleLc)->first();
                if (! $pro) {
                    return ['not_found' => true];
                }
                $site = Site::query()->where('user_id', $pro->id)->first();

                return [
                    'pro_id' => $pro->id,
                    'site_id' => $site?->id,
                    'updated_at_ts' => $site?->updated_at?->timestamp
                        ?? $pro->updated_at?->timestamp
                        ?? 0,
                ];
            }
        );
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **CACHE-2** · P3 — Negative cache for not-found subdomains lacks single-flight and TTL jitter
    - **Where:** app/Services/Cache/SiteCacheService.php:84–87
    - **Affects:** Public site payload cache for subdomains with no published site; concurrent requests from bots or scanners can generate parallel DB queries.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the negative-cache write path in the same per-subdomain fill lock used for positive payloads, so only one worker queries the view on a cold miss.
        - Apply the same ±20 % jitter to the TTL to spread expiration and avoid synchronized re-checks.
    - **Technical:** The `buildPayloadFromDb` method writes a `MISS_SENTINEL` to the primary and stale cache keys without acquiring the `site:fill:` lock. If multiple requests for a nonexistent subdomain arrive simultaneously, each will bypass the lock, query `PublicSitePayload`, and write the same sentinel. While the view query is cheap, a burst of random subdomain scans (e.g., from a bot) could multiply that load unnecessarily. Adding a lock would collapse concurrent misses to a single DB query. Jitter would prevent all not-found keys from expiring at the same instant.
    - **Plain English:** When someone visits a page that doesn’t exist, the system puts up a "404" sign. But if many people check the same nonexistent page at the same time (like a bot scanning for weaknesses), the system builds the sign over and over, each time going to the database. The fix is to have the first visitor build the sign while others wait, and to make sure all "404" signs don’t expire at the same moment.
    - **Evidence:**
        ```php
        Cache::put($key, self::MISS_SENTINEL, now()->addSeconds(self::MISS_PRIMARY_TTL_SECONDS));
        Cache::put($staleKey, self::MISS_SENTINEL, now()->addSeconds(self::MISS_PRIMARY_TTL_SECONDS * self::PAYLOAD_STALE_TTL_MULTIPLIER));
        ```
    - `[DRAFT, confidence: 0.8]`
