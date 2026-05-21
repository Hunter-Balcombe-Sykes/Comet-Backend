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
