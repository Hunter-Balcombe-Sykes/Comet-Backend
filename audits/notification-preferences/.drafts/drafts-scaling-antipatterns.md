- [ ] **#CACHE-1** · P2 — NotificationPublisher::loadResolvedMap lacks single‑flight lock; stampede risk on cold cache  
    - **Where:** app/Services/Notifications/NotificationPublisher.php:186‑194  
    - **Affects:** Email‑delivery path for all professionals. During fan‑out events (brand status change, etc.) many workers can recompute the same preferences map simultaneously.  
    - **Effort:** S (~0.5–1 h)  
    - **What to do:**  
        - Wrap the cache‑miss / compute / cache‑put block in `CacheLockService::rememberLocked` (or an equivalent Redis lock) so only one worker computes the map while others wait.  
        - Keep the same TTL; the `CacheLockService` already adds jitter and SWR semantics.  
    - **Technical:** The method uses a plain cache‑aside pattern:  
        `$cached = Cache::get($key); if (is_array($cached)) return $cached; $map = compute…; Cache::put(…)`.  
        Under cold cache after a deploy or a mass eviction, all concurrent calls to `resolveEmailEnabled` for the same professional will bypass the cache, causing N identical three‑table scans. At scale (30 brands × 50 affiliates) a single `FanOutBrandStatusNotificationJob` may trigger 50+ parallel lookups, amplifying load. The canonical replacement is `CacheLockService::rememberLocked`, already used elsewhere in the codebase.  
    - **Plain English:** A group of waiters all check the reservation book at once. If the book is empty, each one runs to the office to fetch a fresh copy, all returning with the same list. That is a stampede. With a lock, only one waiter goes to the office; the others wait and use his copy. Fixing this prevents unnecessary database trips when many people need the same information at the same time.  
    - **Evidence:**  
        ```php
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        $map = self::computeResolvedMap($professionalId);

        try {
            Cache::put($key, $map, self::CACHE_TTL_SECONDS);
        } catch (\Throwable $e) { … }
        ```  
    - `[DRAFT, confidence: 0.9]`  

- [ ] **#CACHE-2** · P2 — Cache facade used in NotificationPublisher without explicit Redis store; file‑driver fallback would break performance and cross‑worker sharing  
    - **Where:** app/Services/Notifications/NotificationPublisher.php:186 (`Cache::get`), 194 (`Cache::put`), 205 (`Cache::forget`), 213‑214 (`Cache::add`, `Cache::increment`)  
    - **Affects:** Preferences cache for all notification email sends. If the default store ever flips to `file`, every Horizon worker maintains an independent local cache, defeating sharing and causing repeated DB queries.  
    - **Effort:** S (~0.5 h)  
    - **What to do:**  
        - Append `->store('redis')` to every `Cache` call in this service.  
        - Consider a helper method that always returns the Redis store to keep the code DRY.  
    - **Technical:** The application’s caching architecture is designed for Redis, but these calls rely on the default store. In local development or a mis‑configured production environment the default could be `file`, which is per‑worker and not shared. This would cause the `loadResolvedMap` cache to be local, leading to both duplicate computation and stale reads across workers. Explicitly pinning to `redis` makes the intent clear and resilient to configuration changes.  
    - **Plain English:** The cache is meant to be a shared whiteboard that every team member can read and update. If someone accidentally replaces it with personal notepads, nobody sees what others wrote, and everyone starts re‑doing the same work. Pinning the cache to Redis ensures it stays the shared whiteboard.  
    - **Evidence:**  
        ```php
        // loadResolvedMap
        $cached = Cache::get($key);
        …
        Cache::put($key, $map, self::CACHE_TTL_SECONDS);

        // forget()
        Cache::forget(self::cacheKey($professionalId));

        // bumpGlobalVersion()
        Cache::add(self::GLOBAL_VERSION_KEY, 1, null);
        Cache::increment(self::GLOBAL_VERSION_KEY);
        ```  
    - `[DRAFT, confidence: 0.8]`  

- [ ] **#CACHE-3** · P2 — Cache facade used in NotificationListingService without explicit Redis store; busting a local file cache leaves other workers stale  
    - **Where:** app/Services/Notifications/NotificationListingService.php:136‑139  
    - **Affects:** The notification‑index cache for the dashboard bell. After a user marks a notification as read, the associated cache keys are deleted — but if the store is `file`, only the local worker’s copy disappears; other workers still serve the old unread count.  
    - **Effort:** S (~0.5 h)  
    - **What to do:**  
        - Add `->store('redis')` to each `Cache::forget` call inside `bustIndexCache`.  
        - Optionally wire `CacheLockService`’s Redis store so the same pinning applies across the whole service.  
    - **Technical:** `bustIndexCache` iterates the small, known set of (limit, dismissed) keys and calls `Cache::forget($key)`. Without a Redis‑specific store, a `file` driver would only remove the local filesystem copy; other Horizon workers or web servers would continue to serve cached (and now stale) notification lists. This can make the “mark as read” action appear ineffective until the natural TTL expires.  
    - **Plain English:** When you mark a notification as read, the app needs to update the cache so the dashboard shows the new count. If the cache is kept on individual notepads instead of the shared whiteboard, other parts of the app still see the old number. Pinning to Redis ensures the dashboards all stay in sync.  
    - **Evidence:**  
        ```php
        foreach ([50, 100, 200] as $limit) {
            foreach ([false, true] as $includeDismissed) {
                $key = $this->cacheKey($professionalId, $limit, $includeDismissed);
                Cache::forget($key);
                Cache::forget($key.':stale');
            }
        }
        ```  
    - `[DRAFT, confidence: 0.8]`
