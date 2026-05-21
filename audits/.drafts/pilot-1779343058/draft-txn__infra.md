- [ ] **#TXN-1** · P1 — Event listeners on `AccountTypeTransitionEvent` write to cache and dispatch jobs, which silently execute inside the DB transaction if the event is dispatched within one
    - **Where:** app/Listeners/Accounts/SetTransitionBannerOnTransition.php:28, app/Listeners/Accounts/ToggleStripeRequirementBannerOnTransition.php:28–35, app/Listeners/Accounts/InvalidateProfessionalCacheOnTransition.php:15
    - **Affects:** Account type transitions (individual↔affiliate↔brand). On transaction rollback the cache banner persists for 7 days showing a transition that never committed; the Stripe requirement flag drifts; and `InvalidateBrandAffiliatesCacheJob` fires against state that doesn't exist in the DB.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Dispatch `AccountTypeTransitionEvent` via `DB::afterCommit(fn() => event(...))` in `AccountTypeTransitionService`, OR make all three listeners implement `ShouldQueue` so they run outside the transaction.
        - Add `DB::afterCommit(fn() => Cache::put(...))` guards to `SetTransitionBannerOnTransition` and `ToggleStripeRequirementBannerOnTransition` as belt-and-suspenders.
    - **Technical:** `AccountTypeTransitionEvent` uses `Dispatchable` but not `ShouldQueue` — all registered listeners execute synchronously when the event fires. If `AccountTypeTransitionService` dispatches this event inside a `DB::transaction(...)`, the listeners' `Cache::put()`, `Cache::forget()`, `Cache::deleteMultiple()`, and `InvalidateBrandAffiliatesCacheJob::dispatch()` all run before commit. On rollback, the cache entries persist and the queued job processes against non-existent state. This is the classic observer/event backdoor documented in gold-standard rules 3, 4, and 5. The dispatch site (`AccountTypeTransitionService`) is not in the provided file set, but the listener code is unambiguous — the bug exists *if* the event fires inside a transaction, which is the standard pattern for multi-row account mutations. Confidence is 0.7 because the dispatch wrapper isn't visible.
    - **Plain English:** Imagine you sign a contract (DB commit) but tell the printer to print your new business cards (cache write) while the ink is still wet. If you spill coffee on the contract and tear it up (rollback), the printer already handed out cards with your new title. These three listeners are the printer — they announce the account-type change to the cache and job queue before the DB confirms the change actually happened. The fix is to wait until the ink is dry (after commit) before printing.
    - **Evidence:**
        ```php
        // SetTransitionBannerOnTransition — writes cache unconditionally
        // File: app/Listeners/Accounts/SetTransitionBannerOnTransition.php
        public function handle(AccountTypeTransitionEvent $event): void
        {
            $key = sprintf(self::CACHE_KEY_FMT, (string) $event->professional->id);
            try {
                Cache::put($key, [
                    'from' => $event->from->value,
                    'to' => $event->to->value,
                    'at' => now()->toIso8601String(),
                ], self::TTL_SECONDS);
            } catch (\Throwable $e) { /* ... */ }
        }
        ```
        ```php
        // ToggleStripeRequirementBannerOnTransition — writes or deletes cache
        // File: app/Listeners/Accounts/ToggleStripeRequirementBannerOnTransition.php
        if ($caps->requires_stripe_connect && ! $hasConnected) {
            Cache::put($key, true, self::TTL_SECONDS);
        } else {
            Cache::forget($key);
        }
        ```
        ```php
        // InvalidateProfessionalCacheOnTransition — cache delete + job dispatch
        // File: app/Listeners/Accounts/InvalidateProfessionalCacheOnTransition.php
        public function handle(AccountTypeTransitionEvent $event): void
        {
            $this->cache->invalidateProfessional($event->professional);
        }
        // invalidateProfessional() calls Cache::deleteMultiple() AND
        // app(SiteCacheService::class)->invalidateSite() which dispatches
        // InvalidateBrandAffiliatesCacheJob::dispatch($professionalId)
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#TXN-2** · P3 — `RecordCacheMetrics` listener writes Redis metrics inside any DB transaction that also performs cache operations
    - **Where:** app/Listeners/RecordCacheMetrics.php:35–50
    - **Affects:** Cache hit-rate dashboards — metrics can count cache operations from rolled-back transactions, slightly skewing hit-rate SLO tracking. No user-facing state corruption.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Accept the skew as tolerable for observability, OR
        - Defer the `Redis::hIncrBy` call via `dispatch(fn() => Redis::hIncrBy(...))->afterCommit()` so only committed-transaction cache ops are counted.
    - **Technical:** The listener fires synchronously on `CacheHit`, `CacheMissed`, and `KeyWritten` events. If any cache operation (e.g. `Cache::put()`, `Cache::forget()`) executes inside a `DB::transaction(...)`, the `KeyWritten` event fires synchronously and `RecordCacheMetrics::handle()` performs `Redis::hIncrBy()` + conditional `Redis::expire()` before the transaction commits. On rollback, the metrics bucket records a write that never logically occurred. The impact is a fractional skew in cache hit-rate reporting — functionally harmless but technically a transaction-boundary violation. This is category 3 (cache writes inside transactions) at the lowest severity tier.
    - **Plain English:** Think of a store that counts every time someone puts an item in their cart, instead of counting actual purchases. If someone abandons the cart, the store's "items handled" counter is still incremented. This listener counts Redis cache operations that may have happened inside a rolled-back database transaction — the numbers are slightly inflated but nobody gets charged for abandoned carts. It's a bookkeeping imperfection, not a financial bug.
    - **Evidence:**
        ```php
        // File: app/Listeners/RecordCacheMetrics.php
        public function handle(CacheHit|CacheMissed|KeyWritten $event): void
        {
            $prefix = $this->extractPrefix($event->key);
            if ($prefix === null) { return; }
            $bucket = now('UTC')->format('Y-m-d-H');
            $type = match (true) {
                $event instanceof CacheHit => 'hits',
                $event instanceof CacheMissed => 'misses',
                default => 'writes',
            };
            try {
                $bucketKey = "cache_metrics:{$bucket}";
                $newValue = Redis::hIncrBy($bucketKey, "{$prefix}:{$type}", 1);
                if ($newValue === 1) {
                    Redis::expire($bucketKey, self::BUCKET_TTL_SECONDS);
                }
            } catch (\Throwable $e) { /* ... */ }
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#TXN-3** · P2 — `SiteCacheService::invalidateSite()` dispatches `InvalidateBrandAffiliatesCacheJob` inline — callers not protected by `$afterCommit` observers will fire the job inside their transaction
    - **Where:** app/Services/Cache/SiteCacheService.php (final lines of `invalidateSite()` method)
    - **Affects:** Any controller or service that calls `invalidateSite()` inside a `DB::transaction`. On rollback, the queued job re-reads the stale site and re-poison caches.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the dispatch in `DB::afterCommit(fn() => InvalidateBrandAffiliatesCacheJob::dispatch($professionalId))` so it's safe regardless of the caller's transaction state.
        - Audit callers of `invalidateSite()` outside observers (controllers, `ProfessionalCacheService::invalidateProfessional()`, admin endpoints) to confirm they don't wrap the call in a transaction.
    - **Technical:** The dispatch at the end of `invalidateSite()` is safe when called from observers (`BlockObserver`, `SiteObserver`, `ServiceCategoryObserver`, etc.) because all Partna observers set `$afterCommit = true`, which defers the entire hook method until after commit. However, `invalidateSite()` is also called from `ProfessionalCacheService::invalidateProfessional()` which runs inside the `InvalidateProfessionalCacheOnTransition` event listener — subject to the same transaction-leak risk described in TXN-1. Without `DB::afterCommit()` guarding the dispatch, any non-observer caller inside a transaction will enqueue the job before commit, violating gold-standard rule 2. The fix is a one-line wrapping of the existing dispatch.
    - **Plain English:** This is the same pattern as TXN-1 but through the back door. The cache service tells a worker "go refresh every affiliate's cache now" while the database changes that triggered the refresh haven't been saved yet. If the database write fails, the worker still runs against stale data and fills every affiliate's cache with outdated numbers. Slip a "wait until the save is done" note around that instruction and the problem disappears.
    - **Evidence:**
        ```php
        // File: app/Services/Cache/SiteCacheService.php — invalidateSite() closing lines
        // SCALE-3: replace the inline O(N) per-subdomain dispatch loop with a single
        // job that chunks BrandPartnerLink rows internally (500 per chunk). This avoids
        // dispatching potentially thousands of individual jobs into the queue when a
        // brand edit invalidates a large affiliate roster.
        if ($professionalId !== '') {
            InvalidateBrandAffiliatesCacheJob::dispatch($professionalId);
        }
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#TXN-4** · P2 — `ProfessionalCacheService::getByAuthId()` calls `Cache::forget()` and `Cache::put()` inside its defensive guard, which is unprotected from a caller's outer transaction
    - **Where:** app/Services/Cache/ProfessionalCacheService.php (defensive guard block in `getByAuthId()`)
    - **Affects:** Authenticated requests where a stale/corrupt cache entry is detected. On transaction rollback, the caller's auth-user-id mapping cache is wiped and replaced with a freshly-queried value from a rolled-back transaction.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the cache writes in `DB::afterCommit(fn() => Cache::put(...))` so the mapping is only rewritten if the calling transaction commits.
        - Add a comment noting that the guard path is cold (stale cache is rare) so the risk is low-frequency but the fix is trivial.
    - **Technical:** The defensive guard triggers when the cached `Professional` model's `auth_user_id` doesn't match the lookup key — a belt-and-suspenders check against cache corruption. On mismatch it calls `Cache::forget()` for three keys, re-queries the DB, and writes a fresh mapping via `Cache::put()`. If the caller (typically middleware or a controller action) has wrapped the request in a `DB::transaction`, these cache mutations happen inside that transaction. On rollback, the fresh mapping points to a professional row that may not have been updated, and the old mapping is already deleted. This is a low-likelihood scenario (auth cache is nearly immutable) but the pattern is exactly what gold-standard rule 3 forbids. Fix with `DB::afterCommit()` wrapping.
    - **Plain English:** This is a safety check that rarely fires — it's like a smoke detector. When it does go off, it frantically updates the building directory (cache) while the fire department is still deciding whether to condemn the building (commit vs rollback). If they condemn it, the directory now points people to rooms that don't exist. The fix makes the directory update wait until the building's fate is decided.
    - **Evidence:**
        ```php
        // File: app/Services/Cache/ProfessionalCacheService.php — getByAuthId()
        // Defensive guard: if cache is stale/corrupt, never return another user's profile.
        if ((string) $professional->auth_user_id !== $authUserId) {
            $authIdKey = CacheKeyGenerator::professionalIdByAuthId($authUserId);
            $modelKey = CacheKeyGenerator::professionalModel($id);
            Cache::forget($authIdKey);
            Cache::forget($modelKey);
            Cache::forget($modelKey.':stale');

            $freshId = Professional::query()
                ->where('auth_user_id', $authUserId)
                ->value('id');

            if (! $freshId) {
                return null;
            }

            Cache::put($authIdKey, $freshId, (int) config('partna.cache.ttls.auth_id_lookup'));

            return Professional::query()->with(['site', 'squareIntegration'])->find($freshId);
        }
        ```
    - `[DRAFT, confidence: 0.6]`
