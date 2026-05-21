
<!-- ═══ CHUNK: infra ═══ -->

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

<!-- ═══ CHUNK: svc-prof-stripe ═══ -->

- [ ] **#TXN-1** · P1 — Queue dispatch inside transaction in GDPR data export flow
    - **Where:** app/Services/Professional/DataExport/DataExportService.php:67 (inside the `DB::connection('pgsql')->transaction` closure)
    - **Affects:** GDPR data export dispatcher; if the transaction rolls back after `ExportProfessionalDataJob::dispatch()` writes to Redis, the job fires against a non-existent audit row.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `ExportProfessionalDataJob::dispatch($audit->id)` after the `DB::transaction` block returns.
        - Alternatively wrap it in `DB::afterCommit(fn() => ExportProfessionalDataJob::dispatch($audit->id))` inside the transaction closure.
    - **Technical:** Category 2 — queue dispatch inside transaction. `dispatch(...)` writes to Redis. If the transaction commits the audit row but the Redis write fails (blip), the job never fires and the export silently never starts. If the transaction rolls back after the dispatch (unlikely here since the dispatch is the last statement, but a future refactor could add a validation that throws), the job runs against a non-existent audit—`DataExportAudit::findOrFail($audit->id)` in the job handle would throw and the export dies. Canonical fix: dispatch after commit so the audit row is durable before the job can read it.
    - **Plain English:** Imagine signing a contract, handing it to a courier, and only then checking if the pen actually worked. If the ink was dry (the database row saved) but the courier tripped (Redis had a hiccup), the contract never gets delivered and nobody notices. The fix is to put the contract in the filing cabinet first, THEN hand the copy to the courier.
    - **Evidence:**
        ```php
        return DB::connection('pgsql')->transaction(function () use ($professional, $triggeredBy, $staffId, $sendTo, $recipient) {
            // Lock, dedup check, audit row create...
            $audit = DataExportAudit::create([...]);

            ExportProfessionalDataJob::dispatch($audit->id);

            return $audit;
        });
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#TXN-2** · P1 — Cache write inside transaction on payout refund paths
    - **Where:** app/Services/Stripe/CommissionPayoutRefundService.php:119, 129 (two `$this->bustPayoutCaches($order)` calls inside `DB::transaction` closure in `handleOrderRefund`)
    - **Affects:** Affiliate payout dashboards; if the transaction rolls back after `Cache::forget` fires, every reader for the next TTL sees a stale cache that doesn't match the rolled-back database state.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move both `$this->bustPayoutCaches($order)` calls out of the `DB::transaction` block, after it returns.
        - Or wrap each in `DB::afterCommit(fn() => $this->bustPayoutCaches($order))` inside the transaction.
    - **Technical:** Category 3 — cache invalidation inside transaction. `bustPayoutCaches()` calls `Cache::forget($stateKey)` and `Cache::forget($stateKey.':stale')` for both the primary and SWR-stale cache keys. If the outer transaction rolls back (e.g., a concurrent status change causes a constraint violation on the pending-path order updates), the cache was already cleared. The next reader hits the database, finds the pre-rollback state still there, and re-warms the cache with correct data — so this is a short-duration stale window rather than permanent corruption. Still violates the discipline and surfaces under realistic rollback scenarios.
    - **Plain English:** You're updating a whiteboard and a filing cabinet at the same time. You erase the whiteboard first, then walk to the cabinet. If you trip and drop the papers on the way (the database update fails and rolls back), the whiteboard stays blank while the cabinet still has the old information. Anyone who checks the whiteboard before you put the papers back gets confused. The fix is to file the papers first, THEN erase the whiteboard.
    - **Evidence:**
        ```php
        $clawbackPlan = DB::transaction(function () use ($order, $incrementalRefundCents, $shopifyRefundId): ?array {
            // ...
            if ($payout->status === 'processing') {
                $this->flagMidFlight($payout, $order);
                $this->bustPayoutCaches($order);  // ← cache write inside transaction
                return null;
            }
            // pending path:
            if ($order->status === 'partially_refunded') {
                $this->shrinkItem($payout, $order);
            } else {
                $this->removeItem($payout, $order);
            }
            $this->adjustRollup($order);
            $this->bustPayoutCaches($order);  // ← cache write inside transaction
            return null;
        });
        ```
        And the bust method:
        ```php
        private function bustPayoutCaches(Order $order): void
        {
            // ...
            $stateKey = CacheKeyGenerator::affiliatePayoutState($order->affiliate_professional_id);
            Cache::forget($stateKey);
            Cache::forget($stateKey.':stale');
        }
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#TXN-3** · P1 — Notification job dispatch inside transaction on processing-payout refund path
    - **Where:** app/Services/Stripe/CommissionPayoutRefundService.php:118 (via `$this->flagMidFlight($payout, $order)` inside `DB::transaction` closure)
    - **Affects:** Brand dashboards and email notifications; if the transaction rolls back after the notification publisher writes to the jobs table, the brand gets a "refund flagged for manual review" notification for a payout state change that never persisted.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the `NotificationPublisher::publish()` call out of `flagMidFlight` and into the caller, after the `DB::transaction` block returns.
        - Or wrap the publish call inside `flagMidFlight` with `DB::afterCommit(fn() => ...)`.
    - **Technical:** Category 2 — job dispatch inside transaction. `flagMidFlight()` saves `needs_manual_refund = true` on the payout row, then calls `app(NotificationPublisher::class)->publish(...)`. Based on the `notifyExistingEmailRecipientsBatch` comment elsewhere in the codebase ("Route through NotificationPublisher so the standard pipeline runs: … conditional email dispatch via SendTransactionalNotificationEmailJob"), `publish()` inserts a notification row AND dispatches an email job. If the outer transaction rolls back, the notification row rolls back too, but the job was already queued — it runs and either fails on a missing notification row or sends an email referencing a non-existent state change.
    - **Plain English:** You tell a friend "I'm buying this car" and text them a photo of the keys BEFORE you've actually signed the paperwork. If the deal falls through at the last second, your friend still thinks you bought the car because they got the text. The fix: sign the papers first, THEN text your friend.
    - **Evidence:**
        ```php
        // Inside handleOrderRefund's DB::transaction:
        if ($payout->status === 'processing') {
            $this->flagMidFlight($payout, $order);  // ← this calls publish()
            // ...
        }

        // Inside flagMidFlight:
        private function flagMidFlight(CommissionPayout $payout, Order $order): void
        {
            $wasFlagged = (bool) $payout->needs_manual_refund;
            $payout->forceFill(['needs_manual_refund' => true])->save();

            if (! $wasFlagged) {
                try {
                    app(NotificationPublisher::class)->publish(
                        professionalId: (string) $payout->brand_professional_id,
                        frontendType: 'Warning',
                        category: 'commissions',
                        title: 'Refund flagged for manual review',
                        // ...
                    );
                } catch (\Throwable $notifyEx) { ... }
            }
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#TXN-4** · P2 — Unintended nested transaction via `claimOpenInvite` → `connectBrandToAffiliate`
    - **Where:** app/Services/Professional/Brand/BrandAffiliateInviteService.php:274 and app/Services/Professional/Brand/BrandPartnerLinkService.php:83 (outer and inner `DB::transaction` calls)
    - **Affects:** Affiliate claim-open-invite flow; Laravel converts the inner `connectBrandToAffiliate` transaction into a SAVEPOINT, so an outer rollback also rolls back link creation — which is likely desired, but undocumented.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a comment above `$this->brandPartnerLinks->connectBrandToAffiliate(...)` in `claimOpenInvite` noting the nested-transaction intent.
        - Alternatively pass an `$inTransaction = true` flag to `connectBrandToAffiliate` so it skips its own `DB::transaction` wrapper when already inside one.
    - **Technical:** Category 8 — nested transactions. `claimOpenInvite()` wraps its work in `DB::transaction(...)`. Inside that closure it calls `$this->brandPartnerLinks->connectBrandToAffiliate(...)`, which opens its own `DB::transaction`. Laravel silently converts the inner call into a SAVEPOINT. If the outer transaction rolls back (e.g., the `$invite->save()` after link creation throws), the link creation ALSO rolls back — which is correct behavior (you can't have a link without a corresponding accepted invite row). The composability is intentional but undocumented; a future refactorer might assume `connectBrandToAffiliate` is independently atomic and reorder the calls.
    - **Plain English:** You have a big safe with a smaller lockbox inside it. You lock the lockbox, then lock the safe. If someone breaks the safe open, the lockbox is still locked — but in this case, opening the big safe automatically unlocks the lockbox too. That's fine as long as that's what everyone expects. The fix is just to add a label saying "opening the safe also opens the lockbox, and that's on purpose."
    - **Evidence:**
        ```php
        // claimOpenInvite — outer transaction
        public function claimOpenInvite(Professional $brandProfessional, Professional $affiliate): BrandAffiliateInvite
        {
            return DB::transaction(function () use ($brandProfessional, $affiliate): BrandAffiliateInvite {
                // ...
                $this->brandPartnerLinks->connectBrandToAffiliate($affiliateId, $brandId);
                // ...
                $invite->save();
                return $invite->fresh([...]);
            });
        }

        // connectBrandToAffiliate — inner transaction
        public function connectBrandToAffiliate(string $affiliateProfessionalId, string $brandProfessionalId): BrandPartnerLink
        {
            return DB::transaction(function () use ($affiliateProfessionalId, $brandProfessionalId): BrandPartnerLink {
                // ...
            });
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#TXN-5** · P2 — Unintended nested transaction via `claimInvite` → `connectBrandToAffiliate`
    - **Where:** app/Services/Professional/Brand/BrandAffiliateInviteService.php:310 and app/Services/Professional/Brand/BrandPartnerLinkService.php:83
    - **Affects:** Affiliate token-claim flow; same nested SAVEPOINT pattern as TXN-4 via a different entry point.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a comment above the `connectBrandToAffiliate` call in `claimInvite` documenting the nested-transaction intent.
        - Consistent fix with TXN-4 — pick a strategy (flag or restructure) and apply to both call sites.
    - **Technical:** Category 8 — identical pattern to TXN-4. `claimInvite()` opens a `DB::transaction`, locks the invite row with `lockForUpdate()`, validates, then calls `$this->brandPartnerLinks->connectBrandToAffiliate(...)` which opens its own `DB::transaction` → SAVEPOINT. The locked invite row manipulation and link creation are correctly atomic together; the nested structure is an implementation detail that should be documented.
    - **Plain English:** Same safe-and-lockbox situation as TXN-4, just entered through a different door. The inner box unlocks when you open the safe, which is what we want, but nobody wrote it down.
    - **Evidence:**
        ```php
        // claimInvite — outer transaction
        public function claimInvite(BrandAffiliateInvite $invite, Professional $professional): BrandAffiliateInvite
        {
            return DB::transaction(function () use ($invite, $professional): BrandAffiliateInvite {
                // ...
                $this->brandPartnerLinks->connectBrandToAffiliate((string) $professional->id, $brandProfessionalId);
                // ...
                $lockedInvite->save();
                return $lockedInvite->fresh([...]);
            });
        }

        // connectBrandToAffiliate — inner transaction (same as TXN-4)
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#TXN-6** · P2 — Double-nested transaction in disconnect lifecycle via `voidOrder` and `disconnectBrandFromAffiliate`
    - **Where:** app/Services/Professional/Brand/BrandPartnerLinkLifecycleService.php:57 (outer `DB::transaction`) → app/Services/Stripe/CommissionVoidService.php:voidOrder (inner `DB::transaction`) and app/Services/Professional/Brand/BrandPartnerLinkService.php:disconnectBrandFromAffiliate (inner `DB::transaction`)
    - **Affects:** All three disconnect paths (staff, brand, affiliate-initiated). Up to three levels of SAVEPOINT nesting in the staff-void path.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Document the nested-transaction chain in `disconnect()` with a comment block.
        - Consider extracting the void-operation and link-deletion into methods that accept an `$inTransaction` parameter so the lifecycle service can orchestrate them inside its own single transaction without SAVEPOINTs.
        - Verify `SelectionCleanupService::removeSelectionsForAffiliateBrand()` does not add yet another nesting layer.
    - **Technical:** Category 8 — two levels of nesting. `BrandPartnerLinkLifecycleService::disconnect()` opens `DB::transaction`. Inside: (a) `$this->commissionVoid->voidPendingForAffiliateBrand(...)` → `runVoidLoop()` → `voidOrder()` which opens `DB::transaction` (first SAVEPOINT). (b) `$this->linkService->disconnectBrandFromAffiliate(...)` opens `DB::transaction` (second SAVEPOINT). The work is correctly atomic (you want void + link-delete + settings-sync to succeed or fail together), but the SAVEPOINT chain makes debugging rollback scenarios harder — a failure deep in `voidOrder` rolls back only to the SAVEPOINT, not the top-level transaction, leaving partial disconnect state unless the error propagates upward.
    - **Plain English:** This is like having three nested safe boxes. The biggest one (disconnect) contains two smaller ones (void orders, delete link). You want opening the big one to also open both small ones — which it does. But if a spring breaks in the smallest box, only that box stays locked while the others might already be open. The fix is to document the nesting clearly and consider flattening so there's only one box to open.
    - **Evidence:**
        ```php
        // BrandPartnerLinkLifecycleService::disconnect — outer transaction
        public function disconnect(DisconnectRequest $req): DisconnectResult
        {
            return DB::transaction(function () use ($req): DisconnectResult {
                // ...
                $voidResult = $this->commissionVoid->voidPendingForAffiliateBrand(  // → voidOrder → DB::transaction
                    $req->affiliate->id, $req->brand->id, $voidReason,
                );
                // ...
                $this->linkService->disconnectBrandFromAffiliate(  // → DB::transaction
                    $req->affiliate->id, $req->brand->id,
                );
                // ...
            });
        }

        // CommissionVoidService::voidOrder — inner transaction
        public function voidOrder(Order $order, string $reason, ?string $expectedPayoutId = null): bool
        {
            return DB::transaction(function () use ($order, $reason, $expectedPayoutId): bool {
                // ...
            });
        }

        // BrandPartnerLinkService::disconnectBrandFromAffiliate — inner transaction
        public function disconnectBrandFromAffiliate(...): bool
        {
            return DB::transaction(function () use (...): bool {
                // ...
            });
        }
        ```
    - `[DRAFT, confidence: 0.85]`

<!-- ═══ CHUNK: svc-commerce ═══ -->

- [ ] **#TXN-1** · P1 — Queue dispatch inside DB::transaction without `DB::afterCommit`
    - **Where:** app/Services/Shopify/ShopifyDataResyncService.php:80-95 (inside `DB::transaction` closure)
    - **Affects:** Shopify data resync jobs — a rollback after dispatch leaves a queued SyncShopifyBrandDesignJob that fires against stale/absent data, causing silent drift or dead jobs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the dispatch in `DB::afterCommit(fn() => SyncShopifyBrandDesignJob::dispatch($integrationId))` so the job is only queued after the transaction commits.
        - Remove the misleading comment suggesting the queue defers until commit — Laravel dispatches to Redis immediately, not after commit.
    - **Technical:** `dispatch(...)` in Laravel pushes to the queue driver (Redis) inside the same synchronous call, regardless of transaction outcome. If the surrounding `DB::transaction` rolls back (e.g., a model save fails or a constraint violation occurs), the job has already been dispatched and will execute against state that no longer exists. This can lead to orphaned brand-design syncs or unexpected empty results. The correct Laravel pattern for transactional safety is `DB::afterCommit(fn() => dispatch(...))` or moving the dispatch outside the transaction block.
    - **Plain English:** Imagine you sign a contract, drop it in the mailbox, and then discover the pen ran out of ink. The contract is already on its way even though it’s invalid. Here, the resync method groups several database updates inside one “all-or-nothing” wrapper but tells the brand-design sync to start *before* it knows whether the wrapper succeeded. If any update fails, the sync runs anyway with bad data.
    - **Evidence:**
        ```php
        $diff = DB::connection('pgsql')->transaction(function () use ($integration, $integrationId, $shopData, $lastResyncedAt) {
            $diff = $this->autoFill->resyncFromShopData($integration, $shopData);
            $integration->mergeProviderMetadata(['last_resynced_at' => $lastResyncedAt]);
            // Dispatched inside the transaction on purpose — queues hold them until commit,
            // so a rollback prevents an orphaned brand-design job from firing.
            SyncShopifyBrandDesignJob::dispatch($integrationId);
            return $diff;
        });
        ```
    - `[DRAFT, confidence: 1.0]`

<!-- ═══ CHUNK: svc-rest-models ═══ -->

No transaction boundary violations found in the provided files. All `DB::transaction` / `DB::beginTransaction` calls are free of external I/O, queue dispatches, cache writes, and observer side effects, and comply with the gold-standard discipline.

<!-- ═══ CHUNK: jobs ═══ -->

- [ ] **#TXN-1** · P2 — `CommissionPayoutRefundService::handleOrderRefund` called inside `DB::transaction` — nested transaction + unverified afterCommit discipline
    - **Where:** app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php:231-248
    - **Affects:** Commission payout refund path — refund webhook (refunds/create) processing, linked payout recomputation, Stripe Transfer reversal when a completed payout has a post-hoc refund.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Open `CommissionPayoutRefundService::handleOrderRefund` and verify every external I/O call (Stripe `Transfer::createReversal`, `StripeClient` calls) is explicitly wrapped in `DB::afterCommit(fn() => ...)`.
        - Confirm the service's internal `DB::transaction` is intentional and documented as a SAVEPOINT under the caller's transaction — add a `@see ProcessShopifyOrderUpdatedWebhookJob::handleRefund` cross-reference so the coupling is explicit.
        - If the service does NOT defer Stripe calls via `afterCommit`, refactor per the canonical fix: move the Stripe call outside the transaction with compensating logic, or use `DB::afterCommit` inside the service.
    - **Technical:** The `handleRefund` method wraps a `DB::statement` UPDATE on `commerce.orders` in a `DB::transaction` closure and then immediately calls `app(CommissionPayoutRefundService::class)->handleOrderRefund($order, ...)` inside the same closure. The inline comment acknowledges this: "handleOrderRefund's own DB::transaction nests as a savepoint here, AND the completed-payout path defers the Stripe Refund HTTP call via DB::afterCommit." This is category 1 (potential external I/O) + category 8 (nested transaction / SAVEPOINT). The comment reference "(SCALE-1)" suggests prior awareness, but the service code is not provided for audit — if the `afterCommit` discipline inside the service is incomplete, a Stripe API call holding a row lock inside this transaction would violate the gold standard on a financial path. Tests cannot surface this because a transport failure between the outer transaction commit and the `afterCommit` callback never manifests in-memory (Redis/Stripe mock).
    - **Plain English:** Think of the database transaction as a safety deposit box. You should only put valuables (database rows) inside. The current code opens the box, puts a row inside, and then — while the box is still open — calls a courier service to handle a refund. The engineers left a note saying "don't worry, the courier waits outside." But nobody has independently confirmed that the courier is actually waiting outside. If the courier sneaks into the box, it holds the door open for everyone else, and your website grinds to a halt. The fix is to verify the note is true.
    - **Evidence:**
        ```php
        DB::transaction(function () use ($order, $refundSubtotal, $shopDomain, $shopifyOrderId, $refundId) {
            DB::connection('pgsql')->statement(
                'UPDATE commerce.orders
                SET refund_cents = refund_cents + ?, ...
                WHERE shopify_shop_domain = ? AND shopify_order_id = ?',
                [...]
            );

            $order->refresh();

            // Recompute the linked payout in the same transaction. handleOrderRefund's
            // own DB::transaction nests as a savepoint here, AND the completed-payout
            // path defers the Stripe Refund HTTP call via DB::afterCommit so no row
            // lock spans the network call (SCALE-1).
            if (in_array($order->status, ['refunded', 'partially_refunded'], true)) {
                app(CommissionPayoutRefundService::class)
                    ->handleOrderRefund($order, $refundSubtotal, $refundId !== '' ? $refundId : null);
            }
        });
        ```
    - `[DRAFT, confidence: 0.65]`

<!-- ═══ CHUNK: ctrl-prof-a ═══ -->

- [ ] **#TXN-1** · P2 — Multiple DB writes in Shopify connect flow lack transaction boundary
    - **Where:** app/Http/Controllers/Api/Professional/Brand/ShopifyIntegrationController.php:170-210
    - **Affects:** Brands connecting Shopify; partial writes can leave integration row committed while BrandProfile or auto-filled profile/site data fails.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `updateOrCreate`, `firstOrCreate`, and `fillFromShopData` calls in a single `DB::transaction(...)` so the integration row, brand profile, and auto-filled site/professional data commit or roll back atomically.
        - Move job dispatches outside the transaction (they already are — just ensure they stay outside after the wrap).
    - **Technical:** The `ProfessionalIntegration::updateOrCreate(...)` call at line ~183 commits immediately. `BrandProfile::firstOrCreate(...)` at line ~198 is a separate auto-commit. `ShopProfileAutoFillService::fillFromShopData(...)` at line ~207 may perform additional DB writes. If `fillFromShopData` throws, the integration and brand profile rows are already committed but the auto-filled data is missing — the brand gets a connected integration with stale/null profile fields. Wrapping all three in a single transaction makes the connect operation atomic. Category (6) — transaction scope too narrow.
    - **Plain English:** When a brand connects their Shopify store, the system writes to three different places in the database one after another. If the third write fails, the first two are already permanent and you're left with a half-set-up connection. Think of it like signing a three-page contract but only the first two pages are filed — the third page (the auto-filled profile data from Shopify) goes missing. The fix is to make all three writes happen as one unit: either they all succeed, or none of them do.
    - **Evidence:**
        ```php
        // Line ~183 — auto-committed immediately
        $integration = ProfessionalIntegration::query()->updateOrCreate(
            ['professional_id' => $targetBrandId, 'provider' => ProfessionalIntegration::PROVIDER_SHOPIFY],
            [...]
        );

        // Line ~198 — separate auto-commit
        BrandProfile::firstOrCreate(
            ['professional_id' => $targetBrandId],
            ['setup_complete' => false]
        );

        // Line ~207 — may perform additional DB writes
        if (is_array($shopData) && $shopData !== []) {
            // ...
            app(ShopProfileAutoFillService::class)->fillFromShopData(
                $professional, $site, $brandProfile, $shopData, $integration
            );
        }
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#TXN-2** · P2 — Multi-step claim mutation spreads across three independent commits
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateInviteController.php:288-320
    - **Affects:** Affiliates claiming invites; if account-type transition fails after invite is claimed, the affiliate is in an inconsistent state (invite accepted, account_type still individual).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Determine whether `BrandAffiliateInviteService::claimInvite()` internally uses `DB::transaction`. If it does, the external `transitionService->transition()` and `syncSiteBrandPartnerSettings` calls are outside that boundary — wrap the entire claim + transition + sync sequence in a single outer transaction, or refactor `claimInvite` to accept a post-claim callback that runs inside its transaction.
        - Alternatively, add compensating logic: if `transitionService->transition()` throws `InvalidAccountTypeTransition`, roll back the invite claim (or mark it for manual review).
    - **Technical:** The `claimInvite($invite, $professional)` call at line ~299 likely marks the invite as `accepted` and creates the `BrandPartnerLink`. If that succeeds but the subsequent `$transitionService->transition($professional, AccountType::Partner)` at line ~308 throws `InvalidAccountTypeTransition` (caught as a 422), the invite is already claimed and the link exists, but the professional's `account_type` was never flipped to `partner`. The `syncSiteBrandPartnerSettings` at line ~316 also runs outside any guarantee. Category (6) — transaction scope too narrow. Also category (8) if `claimInvite` internally opens a transaction (SAVEPOINT semantics on nested transaction).
    - **Plain English:** Accepting an affiliate invite requires three steps: mark the invite as accepted, flip the account type, and update the site settings. Right now these three steps happen independently — if step two or three fails, step one has already been saved permanently. It's like stamping "ACCEPTED" on an invitation before checking if the guest actually has a valid ID. The system should either do all three together or undo the first if the later ones fail.
    - **Evidence:**
        ```php
        // Line ~299 — invite claimed, link created (likely in its own transaction)
        $claimedInvite = $inviteService->claimInvite($invite, $professional);

        // Line ~308 — separate mutation, no transaction wrapping
        try {
            $transitionService->transition(
                $professional->fresh() ?? $professional,
                AccountType::Partner
            );
        } catch (InvalidAccountTypeTransition $e) {
            return $this->error($e->getMessage(), 422);
        }

        // Line ~316 — yet another separate mutation
        $site = Site::query()->where('professional_id', $professional->id)->first();
        if ($site) {
            $this->syncSiteBrandPartnerSettings($site, $brandPartnerLinks, (string) $professional->id);
            app(ProfessionalCacheService::class)->invalidateProfessional($professional);
        }
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#TXN-3** · P2 — Brand store settings update splits BrandStoreSettings + Site writes across two auto-commits
    - **Where:** app/Http/Controllers/Api/Professional/Store/BrandStoreSettingsController.php:78-115
    - **Affects:** Brands updating store settings; if the Site settings save fails (e.g. constraint violation, lock timeout), the BrandStoreSettings row is already committed with the new values, creating drift between the two data stores.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap `BrandStoreSettings::updateOrCreate(...)` and `$site->save()` in a single `DB::transaction(...)` so both tables commit or roll back together.
        - Keep the Shopify metafield sync (external I/O) outside the transaction — it already is, just ensure it stays outside.
    - **Technical:** The method writes to `brand.brand_store_settings` via `updateOrCreate` at line ~78-92, then writes to `core.sites.settings` via `$site->save()` at line ~105-115. These are two independent auto-committed writes. If the site save throws (e.g. a JSONB constraint violation on `settings`, or a Postgres lock timeout from a concurrent read), the `BrandStoreSettings` row already has the new `default_commission_rate`, `payout_hold_days`, or `theme_id`, but the site's `settings.design` mirror (which Hydrogen reads) still has the old values. Category (6) — transaction scope too narrow.
    - **Plain English:** The brand's store settings live in two places: a dedicated settings table and a JSON blob on their site record. When a brand changes their commission rate, the code updates the settings table first, then the site record second. If the second update fails, the settings table says 20% but the site still says 15% — and since different parts of the system read from different places, the brand sees inconsistent numbers. The fix is to update both as a single unit.
    - **Evidence:**
        ```php
        // Line ~78-92 — auto-committed immediately
        if (! empty($dbFields) || $hasOxygenToken) {
            $settings = BrandStoreSettings::updateOrCreate(
                ['professional_id' => $pro->id],
                $dbFields
            );
            // ...
        }

        // Line ~105-115 — separate auto-commit, can fail independently
        if (! empty($designUpdates) && $site) {
            $settings = is_array($site->settings) ? $site->settings : [];
            $design = is_array($settings['design'] ?? null) ? $settings['design'] : [];
            foreach ($designUpdates as $key => $value) {
                $design[$key] = $value;
            }
            $settings['design'] = $design;
            $site->settings = $settings;
            $site->save();
        }
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#TXN-4** · P2 — `ProfessionalSectionBlockController::upsert` calls `visibilityService->checkVisibilityRequirements` inside transaction; implementation not visible
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalSectionBlockController.php:167-175
    - **Affects:** Professionals toggling sections to Live; if `checkVisibilityRequirements` does external I/O, cache writes, or opens a nested transaction, it violates gold-standard rules 1/3/8 inside an advisory-locked transaction.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Audit `SectionVisibilityService::checkVisibilityRequirements()` to confirm it performs only DB queries (no HTTP calls, cache writes, queue dispatches, or event dispatches).
        - If it does perform any non-DB side effect, refactor to run the check BEFORE the transaction and pass the boolean result into the closure.
        - The same call site exists in `syncAllowedSections()` at line ~280 — apply the same fix there.
    - **Technical:** The transaction at line ~150 wraps an advisory lock, a `firstOrNew`, and a `save`. At line ~167, `$this->visibilityService->checkVisibilityRequirements(...)` is called and its result is written to `$block->is_enabled` before the save. Without seeing the service implementation (file not in audit scope), I cannot confirm it only does DB reads. If it makes any external HTTP call, writes to cache, or dispatches jobs, those run inside the transaction — holding the advisory lock and a Postgres connection open for the duration. Category (4) or (1) depending on implementation. Confidence is 0.5 because the service is likely DB-only based on its name and context, but the audit scope doesn't include the file to verify.
    - **Plain English:** There's a validation check that runs in the middle of a database save operation. The check asks "does this section have enough data to go live?" If that check happens to reach out to an external service or write to the cache, it would hold the database connection open while waiting — like keeping a bank teller on hold while you call your accountant. The fix is to run the check before starting the database operation, so the database work stays quick and self-contained.
    - **Evidence:**
        ```php
        $block = DB::transaction(function () use ($pro, $site, $data, $blockType, $nextIsLive) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-sections:{$site->id}"]);
            // ...
            // Called inside the transaction — implementation not in audit scope
            [$canBeEnabled] = $this->visibilityService->checkVisibilityRequirements(
                (string) $pro->id,
                (string) $site->id,
                $blockType,
                is_array($data['settings'] ?? null) ? $data['settings'] : null,
            );
            $block->is_enabled = $canBeEnabled;
            $block->save();
            return $block->fresh();
        });
        ```
    - `[DRAFT, confidence: 0.5]`

<!-- ═══ CHUNK: ctrl-prof-b-staff ═══ -->

- [ ] **#TXN-1** · P2 — Log call inside DB::transaction may ship to external sink in production
    - **Where:** app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php:139
    - **Affects:** Poorly — this is a defensive flag. Under default Laravel config (file driver) this is harmless. If the production logging driver is Datadog, Sentry, or any network-shipping channel, this opens a TCP connection while holding the Postgres advisory lock + connection slot. The impacted user is whoever collides with the upload endpoint under load.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `Log::info('SiteMedia row created', ...)` to immediately after the `DB::transaction` block closes, or delete it (the `Log::info('Media upload started', ...)` and `Log::info('Original stored successfully', ...)` already bracket the operation).
        - If structured-log-shipping is detected in production config, audit all remaining `Log::*` calls inside `DB::transaction` closures across the codebase.
    - **Technical:** Category 6. Default Laravel logging writes to a local file descriptor synchronously — safe inside a transaction. But when the logging channel is stack/elastic/sentry/datadog, each `Log::info()` fires an HTTP/TCP round-trip that blocks the Postgres connection for the duration. Under concurrent uploads, this exhausts the connection pool faster than expected. The fix is trivial — the log line is informational and adds no value inside the atomic boundary.
    - **Plain English:** Inside the locked safe where database rows are being written, there's a note being passed through a pneumatic tube to another building. If the tube system is just a local filing cabinet it's fine; but if it's hooked up to an external monitoring service, every note ties up the safe while the tube travels. Moving the note outside the safe costs nothing.
    - **Evidence:**
        ```php
        $media = DB::transaction(function () use ($site, $pool, $maxItems, $request, $mediaType, $file) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ["site-images:{$site->id}"]);
            }
            // ... lockForUpdate, count, create ...
            Log::info('SiteMedia row created', ['media_id' => $media->id, 'media_type' => $mediaType]);
            return $media;
        });
        ```
    - `[DRAFT, confidence: 0.5]`

- [ ] **#TXN-2** · P2 — Mass-update bypasses model observers inside transaction; missed side effects on professional-type change
    - **Where:** app/Http/Controllers/Api/Professional/Account/ProfessionalController.php:97-103 (and identical pattern in StaffProfessionalController.php:221-227)
    - **Affects:** Professionals switching their type to "influencer" — the `disableProfessionalOnlySections()` call runs a query-builder mass-update inside the transaction, bypassing `BlockObserver`. If BlockObserver ever gains a cache-invalidation, event-dispatch, or notification side effect (e.g. touching a parent Site or busting the site-blocks cache), that side effect silently does not fire.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - After the `DB::transaction` block commits, iterate the affected block IDs and call `->save()` (or `->touch()`) on each so the observer fires, OR explicitly replicate the expected side effect (e.g. `site->touch()` + cache bust).
        - Document that query-builder mass-updates skip observers so future maintainers know to check this site.
    - **Technical:** Category 11 — observers only fire on Eloquent lifecycle events (`save()`, `delete()`, etc.), not on query-builder `update()`. The `disableProfessionalOnlySections()` helper runs `Block::query()->...->where('is_active', true)->update(['is_active' => false])` inside the parent transaction. If BlockObserver (or a future one) dispatches a cache-warming job or invalidates a site-blocks cache key on `updated`, none of that runs. The same pattern is already acknowledged in `ProfessionalUploadController::reorder()` where the comment explicitly notes the observer bypass and compensates with `$site->touch()` — that compensation is absent here.
    - **Plain English:** When a user switches account type, some sections get turned off. The code does a bulk "flip all these switches at once" database command inside a locked room. But if any switch should also ring a bell (like saying "hey, the public website needs to refresh"), the bell doesn't ring because the bulk command bypasses the bell-wiring. The fix is to either ring the bells manually after leaving the room, or flip each switch individually so the wiring works.
    - **Evidence:**
        ```php
        DB::transaction(function () use ($professional, $request, $previousProfessionalType): void {
            $professional->fill($request->validated());
            $professional->save();

            $nextProfessionalType = mb_strtolower(trim((string) ($professional->professional_type ?? '')));
            if ($previousProfessionalType !== 'influencer' && $nextProfessionalType === 'influencer') {
                $this->disableProfessionalOnlySections($professional->id);
            }
        });

        // ...

        private function disableProfessionalOnlySections(string $professionalId): void
        {
            // ...
            Block::query()
                ->where('professional_id', $professionalId)
                ->where('block_group', 'sections')
                ->whereIn('block_type', $this->professionalOnlySectionTypes())
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                ]);
        }
        ```
    - `[DRAFT, confidence: 0.6]`

- [ ] **#TXN-3** · P2 — Mass status-update inside transaction bypasses ProfessionalObserver; missed side effects on bulk suspend/reactivate
    - **Where:** app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffProfessionalController.php:185-192
    - **Affects:** Staff compliance sweeps that suspend or reactivate batches of professionals (up to 100 at once). If ProfessionalObserver has side effects — cache invalidation, event dispatch, notification, Cloudflare purge — none fire because the mass `update()` skips Eloquent events.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - After the transaction commits, iterate the `$updated` IDs and call `Professional::find($id)->touch()` or fire a dedicated `ProfessionalStatusChanged` event per ID so cache/observer side effects run.
        - Alternatively, replace the mass query-builder update with a loop of `$pro->status = $status; $pro->save();` inside the transaction — this fires observers synchronously but is safe because the transaction itself provides atomicity and no external I/O is introduced.
    - **Technical:** Category 11. The `bulkUpdateStatus()` method uses `Professional::query()->whereIn('id', $existing)->update(['status' => $status])` inside a `DB::transaction`. Query-builder `update()` does not trigger Eloquent `saving`/`saved` events. If ProfessionalObserver (or a future one) invalidates caches, dispatches a `ProfessionalUpdated` event that feeds the staff dashboard, or pushes status to an external system, none of that executes. The `Log::info()` calls happen correctly outside the transaction, but the Eloquent lifecycle bypass is the gap.
    - **Plain English:** When staff suspend 50 accounts at once, the database rows update correctly inside a locked room, but none of the "account suspended" bells ring — no cache refresh, no event log, no downstream system notification. The room locks, the rows change, and the rest of the building doesn't find out. The fix is to either ring the bells for each account after the room unlocks, or process each account individually inside the room so the existing bell-wiring fires.
    - **Evidence:**
        ```php
        DB::transaction(function () use ($ids, $status, &$updated, &$missing): void {
            $existing = Professional::query()->whereIn('id', $ids)->get(['id'])->pluck('id')->all();
            $missing = array_values(array_diff($ids, $existing));

            if (! empty($existing)) {
                Professional::query()
                    ->whereIn('id', $existing)
                    ->update(['status' => $status]);
                $updated = $existing;
            }
        });
        ```
    - `[DRAFT, confidence: 0.6]`

- [ ] **#TXN-4** · P2 — SiteMedia withoutEvents() inside transaction suppresses observers during flat-replace; intentional but fragile
    - **Where:** app/Http/Controllers/Api/Professional/Account/ProfessionalDocumentController.php:173-177
    - **Affects:** Users uploading a new document (flat-replace of the previous one). The old document's `deleted` observer is suppressed to avoid duplicate section-visibility work. Correct today, but fragile if the observer later gains a side effect unrelated to section visibility (e.g. audit log, analytics event, R2 byte accounting).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a short comment listing exactly which observer side effects are being suppressed and why, so a future engineer adding a new side effect to the `deleted` hook knows they must replicate it here.
        - Consider firing the suppressed side effects explicitly after the transaction commits (or wrapping only the known-duplicate side effect in a guard rather than suppressing the entire event).
    - **Technical:** Category 5 / Category 11 — `SiteMedia::withoutEvents()` is a blunt instrument. The comment says it prevents the `deleted` event and the new row's `saved` event from both triggering section-visibility reevaluation (described as "wasted work"). This is correct for the current observer code. However, `withoutEvents()` suppresses ALL Eloquent events for that delete — if `SiteMediaObserver::deleted` later gains an audit-log entry, a cleanup-job dispatch, or any side effect unrelated to section visibility, this `withoutEvents()` call silently drops it with no compilation error or test failure. The same pattern appears at ProfessionalDocumentController:173.
    - **Plain English:** When replacing an old document with a new one, the code deletes the old row but tells the framework "don't tell anyone about this delete" because the new row's creation will send a similar notification and we don't want double-notifications. This works, but it's like covering someone's mouth — if they later need to also send a different message (like "log this deletion for the audit trail"), that message gets blocked too. A note on the door explaining which messages are being suppressed would prevent future accidents.
    - **Evidence:**
        ```php
        // Suppress the old row's `deleted` observer event during
        // flat-replace — the new row's `saved` event a few lines
        // below will trigger section-visibility reevaluation once.
        // Without this, both events fire post-commit and do the
        // same DB read + check in sequence (wasted work).
        SiteMedia::withoutEvents(function () use ($existing): void {
            $existing->delete();
        });
        ```
    - `[DRAFT, confidence: 0.4]`

- [ ] **#TXN-5** · P2 — Multiple staff reorder transactions use two-pass sort_order update; no external I/O but pattern duplicates state risk
    - **Where:**
        - app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffLinkBlockManagementController.php:68-103
        - app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffSectionManagementController.php:82-117
        - app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php:213-258
    - **Affects:** Any concurrent reorder request for the same professional's links/sections/images. The two-pass pattern (set all to offset+N, then set all to N) has a sub-microsecond window between passes where sort_order values are inflated. Under extreme concurrency, a reader could see partially-ordered rows. Practical risk is near-zero due to the advisory lock, but the second pass is unnecessary complexity.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Collapse to a single-pass update: iterate `$newOrder` and set `sort_order = $i` directly. The advisory lock already serializes writers, so the two-phase offset dance provides no additional safety.
        - This applies to all three reorder methods (staff links, staff sections, professional image uploads).
    - **Technical:** Category 7 (transaction scope / unnecessary complexity) — The two-pass pattern (offset+N then N) is a workaround for databases that can't reorder in-place due to unique constraints. Postgres has deferrable constraints and the advisory lock already guarantees serialized access. The pattern works correctly but adds 2× the UPDATE statements per reorder and a theoretical read-skew window. Not a correctness bug today, but code that confused the original author enough to over-engineer is worth simplifying.
    - **Plain English:** When reordering items, the code moves everything to a temporary shelf first, then moves them to their final positions. It's like taking all the books off the shelf, putting them on a cart with new temporary numbers, then putting them back in order — instead of just shuffling them directly on the shelf. The room is already locked so nobody else can come in during the shuffle. The extra cart trip doesn't break anything but it's twice the work and makes the process harder to follow.
    - **Evidence:**
        ```php
        // Two-pass reorder pattern (representative — StaffLinkBlockManagementController:84-100)
        DB::transaction(function () use ($professional, $site, $ids) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["blocks-links:{$site->id}"]);
            // ... lockForUpdate, validate ...
            $offset = (int) Block::query()->...->max('sort_order') + 1000;

            foreach ($newOrder as $i => $id) {
                Block::query()->...->update(['sort_order' => $offset + $i]);
            }
            foreach ($newOrder as $i => $id) {
                Block::query()->...->update(['sort_order' => $i]);
            }
        });
        ```
    - `[DRAFT, confidence: 0.3]`

<!-- ═══ CHUNK: ctrl-public-internal ═══ -->

- [ ] **#TXN-1** · P2 — Cache invalidation inside BootstrapController's `DB::transaction` closure
    - **Where:** app/Http/Controllers/Api/PublicSite/BootstrapController.php:bootstrap() — inside the `DB::transaction(function () use (...) { ... })` closure
    - **Affects:** All new account creation and account-update flows. On transaction rollback the Professional cache is already invalidated, causing unnecessary cache misses on the next read.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `app(ProfessionalCacheService::class)->invalidateProfessional($professional)` to AFTER the `DB::transaction` block returns.
        - Alternatively, wrap it in `DB::afterCommit(fn() => app(ProfessionalCacheService::class)->invalidateProfessional($professional))`.
    - **Technical:** The `ProfessionalCacheService::invalidateProfessional` call flushes Redis cache entries for the Professional. It sits inside the `DB::transaction` closure. If any DB operation after this point throws (e.g. `ensureFreeSubscription` hits a constraint violation, or `createWelcomeNotification` fails), the transaction rolls back all DB mutations — but the cache has already been cleared. The next read will miss cache, query the DB, and correctly re-warm with the pre-bootstrap state (since the transaction rolled back). This is a "cache churn on rollback" bug rather than a "stale data served" bug, because `invalidateProfessional` only clears — it doesn't write a new value. However, it still violates the gold-standard rule: **no cache writes inside a transaction**, because a rollback path makes the cache operation pointless and in other service patterns (where writes, not invalidations, are involved) the same placement would serve stale data.
    - **Plain English:** Imagine you're filling out a paper form, and halfway through you tell the receptionist "throw away my old file." Then you make a mistake on the form, crumple it up, and start over. The receptionist already threw away your old file — now there's no file at all until you finish the new form. The next person who asks for your file has to go find it in the archive instead of just grabbing it from the front desk. The data is still correct (the archive has the right version), but it's slower for no good reason. The fix is simple: only tell the receptionist to toss the old file AFTER you've handed in the finished form.
    - **Evidence:**
        ```php
        $result = DB::transaction(function () use ($uid, $data, $brandAffiliateInviteService, $brandPartnerLinks, $accountTypeDefaultsService, $resolveProfessionalType, $request, $resolvedSignupCodeBrand, &$brandSignupCodeError) {
            // ... Professional creation/update, Site creation, brand-attach branches,
            //     Shopify integration creation, etc. ...

            app(ProfessionalCacheService::class)->invalidateProfessional($professional);

            // Ensure the professional has a subscription – seed the free plan if none exists
            $this->siteProvisioning->ensureFreeSubscription($professional);

            if ($createdProfessional) {
                $this->createWelcomeNotification($professional);
            }

            return [
                'professional' => new ProfessionalDashboardResource($professional->fresh()),
                'site' => $site->fresh(),
                'shopify_integration_id' => $shopifyIntegrationId,
            ];
        });
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#TXN-2** · P2 — BootstrapController transaction scope is too coarse (Category 6)
    - **Where:** app/Http/Controllers/Api/PublicSite/BootstrapController.php:bootstrap() — the entire account-creation flow is wrapped in a single `DB::transaction`
    - **Affects:** New account creation and account-update flows. Increased lock contention on the `core.professionals`, `core.sites`, and related tables during bootstrap. Harder to debug partial failures because everything is rolled back together.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Split into two transactions: (a) Professional + Site creation, (b) brand-attach + Shopify integration provisioning.
        - Fetch-validate-decode Shopify setup token data before the transaction, not inside it — the `peek()` call is already correctly placed before the closure, but the `create()` of `ProfessionalIntegration` using that data is inside.
        - Consider whether `ensureSidestUpdatesSubscription` and `createWelcomeNotification` need to be atomic with the Professional row — if not, move them after commit.
    - **Technical:** The `DB::transaction` closure spans ~150+ lines and covers: Professional creation/update, `ensureSidestUpdatesSubscription` (EmailSubscription upsert), Site creation with retry, `AccountTypeDefaultsService::applyDefaults`, three possible brand-attach branches (each calling `syncSiteBrandPartnerSettings` which mutates Site settings), brand signup code claim, Shopify integration creation (`ProfessionalIntegration::create`), `ShopProfileAutoFillService::fillFromShopData`, cache invalidation, `ensureFreeSubscription`, and `createWelcomeNotification` (Notification creation). While bootstrap is conceptually "all-or-nothing," this scope means that a failure in the welcome notification (a non-critical concern) rolls back the entire Professional + Site creation, forcing the user to restart from scratch. The transaction also holds row locks on every table touched by the various service calls, increasing deadlock surface under concurrent signups. Narrowing the transaction to just the critical atomic unit (Professional + Site + essential defaults) and moving secondary operations outside would improve resilience and debuggability.
    - **Plain English:** Think of this as packing an entire house into one shipping container — furniture, appliances, decorations, the welcome mat, and the "congratulations" card. If the welcome mat gets snagged on the door, the entire container gets sent back to the warehouse, including the house itself. The fix is to ship the house in one container (the stuff that MUST arrive together), and put the decorations and greeting card in a separate box — if the card gets lost, the house is still delivered and the family can move in.
    - **Evidence:**
        ```php
        $result = DB::transaction(function () use ($uid, $data, ...) {
            // ~150 lines including:
            //   - Professional creation/update + save()
            //   - ensureSidestUpdatesSubscription() — EmailSubscription upsert
            //   - Site creation via createSiteWithRetry()
            //   - AccountTypeDefaultsService::applyDefaults()
            //   - BrandProfile firstOrCreate
            //   - 3 brand-attach branches (invite_token, brand_partner_professional_id, join_brand_handle)
            //     each with claimInvite / connectBrandToAffiliate + syncSiteBrandPartnerSettings
            //   - brand_signup_code resolution + claim
            //   - Shopify ProfessionalIntegration::create()
            //   - ShopProfileAutoFillService::fillFromShopData()
            //   - ProfessionalCacheService::invalidateProfessional()
            //   - SiteProvisioningService::ensureFreeSubscription()
            //   - createWelcomeNotification() — Notification firstOrCreate
            //   - Professional promotion to 'partner' account_type

            return [
                'professional' => new ProfessionalDashboardResource($professional->fresh()),
                'site' => $site->fresh(),
                'shopify_integration_id' => $shopifyIntegrationId,
            ];
        });
        ```
    - `[DRAFT, confidence: 0.85]`
