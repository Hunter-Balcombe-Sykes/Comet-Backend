- [ ] **#CCH-1** · P2 — `Cache::lock()` on default store instead of `cache_locks` connection in SquareTokenService
    - **Where:** app/Services/Square/SquareTokenService.php (in `refreshAccessToken` method)
    - **Affects:** Square token refresh under concurrent requests — a `Cache::flush()` during deploy or maintenance releases the lock and allows concurrent OAuth refreshes to Square.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::lock('integration_refresh:'.$integration->id, 30)` with `Cache::store('cache_locks')->lock('integration_refresh:square:'.$integration->id, 30)`.
        - Add `square` namespace to the lock key to avoid theoretical collision with other integration token services.
    - **Technical:** Laravel's `Cache::lock()` uses the default cache store. If the default store shares a Redis DB with data caches, a `Cache::flush()` or `php artisan cache:clear` releases every held lock. The `cache_locks` connection (separate Redis DB) isolates locks from data-store flushes. The lock duration of 30s is appropriate (exceeds the 20s Square HTTP timeout), and the block timeout of 10s is reasonable, so only the connection pinning is off.
    - **Plain English:** Imagine a hotel with key-card locks on every room door. The master reset switch for the card system is on the same circuit as the hallway lights — if maintenance flips the wrong breaker, every door unlocks. The fix moves the lock system onto its own isolated circuit so no routine maintenance accidentally opens every door.
    - **Evidence:**
        ```php
        $lock = Cache::lock('integration_refresh:'.$integration->id, 30);

        try {
            $lock->block(10);
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CCH-2** · P2 — `Cache::lock()` on default store instead of `cache_locks` connection in FreshaTokenService
    - **Where:** app/Services/Fresha/FreshaTokenService.php (in `refreshAccessToken` method)
    - **Affects:** Fresha token refresh under concurrent requests — same flush-vulnerability as SquareTokenService.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Cache::lock('integration_refresh:'.$integration->id, 30)` with `Cache::store('cache_locks')->lock('integration_refresh:fresha:'.$integration->id, 30)`.
        - Add `fresha` namespace to the lock key.
    - **Technical:** Identical anti-pattern to CCH-1. `Cache::lock()` on the default store means locks live on the same Redis DB as cached data. A data-store `Cache::flush()` releases these locks, opening a window where multiple workers refresh the Fresha token concurrently. Moving to the dedicated `cache_locks` connection isolates lock lifecycle from data-cache lifecycle.
    - **Plain English:** Same hotel key-card problem as the Square integration — the lock system shares a circuit with the hallway lights. This is the Fresha wing of the hotel, built with the identical wiring mistake.
    - **Evidence:**
        ```php
        $lock = Cache::lock('integration_refresh:'.$integration->id, 30);

        try {
            $lock->block(10);
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CCH-3** · P2 — NotificationPublisher does not invalidate the NotificationListingService cache on publish
    - **Where:** Write site: app/Services/Notifications/NotificationPublisher.php (in `publish` and `publishMany` methods). Read site: app/Services/Notifications/NotificationListingService.php (in `index` method).
    - **Affects:** Professional dashboard — newly published in-app notifications are invisible in the notification bell dropdown for up to 15 seconds (the listing cache TTL).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `NotificationPublisher::publish()` and `publishMany()`, after successfully inserting a notification row (`$inserted > 0`), call a new invalidation method on `NotificationListingService` for the affected `$professionalId`.
        - Expose a `bustIndexCache($professionalId)` equivalent (currently `private`) or inject `NotificationListingService` into `NotificationPublisher` and call it.
        - Forget both the primary key and `:stale` companion — `bustIndexCache` already does this correctly.
    - **Technical:** The `NotificationListingService::index()` method caches the notification list via `CacheLockService::rememberLocked` with a 15s TTL. `markRead` and `dismiss` both call `bustIndexCache()` to invalidate. But `NotificationPublisher::publish()` and `publishMany()` insert new notification rows without touching the listing cache at all. The result is a guaranteed stale window of up to 15 seconds after every notification publish. For booking-completion notifications and brand invites, this means the bell icon doesn't update until the next poll cycle after TTL expiry — exactly the sort of staleness the push-invalidation requirement is designed to prevent.
    - **Plain English:** When someone sends you a text message, your phone buzzes immediately. But imagine if the Messages app only checked for new texts every 15 seconds — you'd have an annoying delay between "sent" and "delivered." That's what happens here: the notification is saved to the database instantly, but the cached list shown in the dashboard bell doesn't refresh until its 15-second timer runs out. The fix connects the "new notification" event to the "clear the cached list" action so the bell updates right away.
    - **Evidence:**
        ```php
        // NotificationPublisher::publish() — inserts a row but never busts the listing cache:
        $inserted = DB::table('notifications.notifications')->insertOrIgnore([...]);

        if ($inserted > 0 && config('partna.notifications.email_enabled', false)) {
            SendTransactionalNotificationEmailJob::dispatch(...);
        }
        // No equivalent of NotificationListingService::bustIndexCache($professionalId) here.
        ```
        ```php
        // NotificationListingService::index() — read path that caches and needs invalidation on publish:
        return $this->cache->rememberLocked(
            $this->cacheKey($professionalId, $limit, $includeDismissed),
            (int) config('partna.notifications.listing_cache_ttl_seconds', 15),
            fn () => $this->buildIndexPayload($professionalId, $limit, $includeDismissed),
        );
        ```
        ```php
        // NotificationListingService::bustIndexCache — exists, works, but is only called from markRead/dismiss:
        private function bustIndexCache(string $professionalId): void
        {
            $store = app()->environment('testing') ? Cache::store() : Cache::store('redis');
            foreach ([50, 100, 200] as $limit) {
                foreach ([false, true] as $includeDismissed) {
                    $key = $this->cacheKey($professionalId, $limit, $includeDismissed);
                    $store->forget($key);
                    $store->forget($key.':stale');
                }
            }
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#CCH-4** · P3 — NotificationListingService bustIndexCache hardcodes limit values that may miss cache keys
    - **Where:** app/Services/Notifications/NotificationListingService.php (in `bustIndexCache` method)
    - **Affects:** Stale notification listings when a caller uses a `$limit` value not in `[50, 100, 200]` — the cache entry is never invalidated on markRead/dismiss.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either constrain `index()` to only accept limits from a known set (`[50, 100, 200]`) and validate with an enum or constant, or
        - Record the actual `$limit` used at cache-write time (e.g., in a Redis set keyed by professional) and iterate over that set during invalidation instead of a hardcoded array.
    - **Technical:** The `index()` method accepts any `int $limit` and constructs a cache key from it. The `bustIndexCache()` method only forgets keys for `$limit ∈ [50, 100, 200]`. If the frontend or a future internal caller passes a different limit (e.g., 25, 75, 150), the corresponding cache key is never invalidated on markRead/dismiss. The 15s TTL bounds the staleness, but the push-invalidation surface is incomplete. The current frontend likely only uses those three limits, so impact is bounded; flagging as P3 for defense-in-depth.
    - **Plain English:** The notification list cache has a cleanup crew that knows how to clear three specific shelf sizes — 50, 100, and 200 items. If someone puts a notification list on a shelf of a different size (say, 25 items), the cleanup crew walks right past it. The stale list sits there until its 15-second self-destruct timer fires. For now, only those three shelf sizes are in use, so no one notices — but it's a fragile setup that would break silently if a new screen used a different page size.
    - **Evidence:**
        ```php
        // Reader: accepts any int limit — open key space
        public function index(string $professionalId, int $limit, bool $includeDismissed): array
        {
            return $this->cache->rememberLocked(
                $this->cacheKey($professionalId, $limit, $includeDismissed),
                ...
            );
        }
        ```
        ```php
        // Invalidator: only covers 3 specific limits
        private function bustIndexCache(string $professionalId): void
        {
            $store = app()->environment('testing') ? Cache::store() : Cache::store('redis');
            foreach ([50, 100, 200] as $limit) {   // <-- hardcoded, incomplete
                foreach ([false, true] as $includeDismissed) {
                    $key = $this->cacheKey($professionalId, $limit, $includeDismissed);
                    $store->forget($key);
                    $store->forget($key.':stale');
                }
            }
        }
        ```
    - `[DRAFT, confidence: 0.70]`
