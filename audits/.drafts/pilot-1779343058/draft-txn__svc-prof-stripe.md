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
