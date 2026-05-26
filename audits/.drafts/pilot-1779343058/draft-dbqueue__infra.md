- [ ] **#SCALE-1** · P1 — N+1 lazy-load in CommissionMovementObserver::notifyBrandSale  
    - **Where:** app/Observers/Core/CommissionMovementObserver.php:204  
    - **Affects:** Every commission-earned event that fires a brand-sale notification — at the target ~1M orders/year this adds ~2M extra queries/year.  
    - **Effort:** S (~0.5–1h)  
    - **What to do:**  
        - Eager-load the `affiliateProfessional` relation on the `CommissionMovement` model via `protected $with` or load it in the caller before `save()`.  
        - If the model changes are in a job, add `with('affiliateProfessional')` to the query that creates the movements.  
    - **Technical:** (Category 1) `$entry->affiliateProfessional` triggers a lazy-load `belongsTo` query each time the observer fires — one extra `SELECT` per approved commission entry. The observer itself cannot eager-load after `save`, so the caller (e.g. `ProcessShopifyOrderWebhookJob`) must pre‑load the relation before the model is persisted.  
    - **Plain English:** When a sale earns a commission, the notification code grabs the affiliate’s name by asking the database “who is this?” individually for each commission. It’s like calling the front desk every time you want to read a name tag, instead of picking up the whole list once.  
    - **Evidence:**  
        ```php
        $affiliateName = $entry->affiliateProfessional?->display_name ?? 'An affiliate';
        ```  
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SCALE-2** · P1 — N+1 lazy-load in BlockObserver::onBlockMutated  
    - **Where:** app/Observers/Core/BlockObserver.php:53-75  
    - **Affects:** Any batch write of blocks (bulk reorder, CSV import) — each block triggers an extra `SELECT` from `sites`.  
    - **Effort:** S (~0.5–1h)  
    - **What to do:**  
        - Call `$block->loadMissing('site')` at the start of `onBlockMutated` to avoid per‑event lazy loading.  
    - **Technical:** (Category 1) The observer checks `$block->site` before acting; if the `site` relation isn’t pre‑loaded, Eloquent issues a fresh query for every block. A bulk reorder of 50 links would issue 50 extra `SELECT`s. The fix is a single `loadMissing` call inside the observer.  
    - **Plain English:** When someone reorders their page links, the system asks “which site is this?” for every link one‑by‑one instead of looking it up together. It’s like checking the office building address for every piece of mail you deliver in the same building.  
    - **Evidence:**  
        ```php
        if (! $block->site) {
            return;
        }
        ```  
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SCALE-3** · P1 — N+1 lazy-load in SiteMediaObserver  
    - **Where:** app/Observers/Core/SiteMediaObserver.php:73, 89, 101 (touchParentSite, bustHydrogenCaches, reevaluateIfRelevant)  
    - **Affects:** Bulk media operations (multi-upload, batch processing) — each media row processed triggers a lazy‑loaded `site` query.  
    - **Effort:** S (~0.5–1h)  
    - **What to do:**  
        - Add `$media->loadMissing('site')` at the start of each relevant method, or pre‑load `site` on the models before they are saved in batch.  
    - **Technical:** (Category 1) All three methods access `$media->site` without a prior eager‑load. During a bulk status change (e.g. `PROCESSING`→`READY` for 100 uploads) this can cause 100 individual site look‑ups. A single `loadMissing` call inside the observer avoids this.  
    - **Plain English:** When many new images finish processing, the system updates them and — behind the scenes — asks the database “which site does this belong to?” for each one, when it could simply ask once per batch.  
    - **Evidence:**  
        ```php
        private function touchParentSite(SiteMedia $media, string $action): void
        {
            try {
                $site = $media->site;
                if (! $site) {
                    return;
                }
                $site->touch();
            ...
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SCALE-4** · P1 — Unbounded DELETE in PruneNotifications  
    - **Where:** app/Console/Commands/PruneNotifications.php:32-36  
    - **Affects:** Nightly pruning of expired notifications. At target scale (~40K notifications/day, 30‑day keep) potentially 1.2M rows deleted in a single transaction.  
    - **Effort:** S (~0.5–1h)  
    - **What to do:**  
        - Replace `$q->delete()` with a batched deletion loop (e.g., `while (DB::table('core.notifications')->where(…)->limit(10000)->delete()) {}`).  
    - **Technical:** (Category 2) A single `DELETE` on a million‑row table holds a long‑running transaction, generates heavy WAL, and can cause table‑level bloat and replication lag. Batching the delete in 10k‑row chunks keeps each transaction short and avoids full‑table lock contention.  
    - **Plain English:** Right now the nightly cleanup tries to shred up to a million old messages in one go. That’s like blocking the post office while a single truck unloads a year’s worth of mail — it slows everything down. Doing it in smaller boxes keeps the flow moving.  
    - **Evidence:**  
        ```php
        $deleted = $q->delete(); // relies ON DELETE CASCADE to remove receipts
        $this->info("Deleted {$deleted} notifications.");
        ```  
    - `[DRAFT, confidence: 0.95]`

- [ ] **#SCALE-5** · P2 — Unbounded memory in BackfillHasEnabledVariantsCommand  
    - **Where:** app/Console/Commands/BackfillHasEnabledVariantsCommand.php:69-71 and the subsequent foreach  
    - **Affects:** One‑off backfill; a brand with 10 000+ products would load the entire catalog into PHP memory, risking an OOM.  
    - **Effort:** M (~2–4h)  
    - **What to do:**  
        - Refactor to use Shopify’s cursor‑based pagination and process products one page at a time (or queue per‑product jobs) instead of fetching the whole catalog as an array.  
    - **Technical:** (Category 2) `$catalogService->fetchBrandCatalog($brand)` appears to return the full product list. For large stores this creates a huge in‑memory array, and the subsequent `foreach` keeps all products alive. Switching to a paginated cursor and streaming the results would keep memory constant.  
    - **Plain English:** If a store has thousands of products, this command tries to load the entire warehouse inventory into its arms at once — it’s bound to drop some. It’s safer to carry one shelf at a time.  
    - **Evidence:**  
        ```php
        $catalog = $catalogService->fetchBrandCatalog($brand);
        // …
        foreach ($catalog as $product) {
        ```  
    - `[DRAFT, confidence: 0.8]`

- [ ] **#SCALE-6** · P2 — Shopify rate‑limit disregard in BackfillHasEnabledVariantsCommand  
    - **Where:** app/Console/Commands/BackfillHasEnabledVariantsCommand.php (write loop)  
    - **Affects:** When iterating over a large catalog, one GraphQL mutation per product floods Shopify’s API bucket, causing 429 errors and failed writes.  
    - **Effort:** S (~0.5–1h)  
    - **What to do:**  
        - Check `X-Shopify-Shop-Api-Call-Limit` header after each call, and sleep / exponential backoff when nearing the limit.  
    - **Technical:** (Category 5) The `writeHasEnabledVariants` call is made per product in a tight loop. Shopify enforces a points‑based rate limit; without any throttling or backoff, a burst of 500+ calls will be rejected. Add a throttle that pauses for a few seconds when the remaining call budget drops below ~10%.  
    - **Plain English:** The backfill sends a “save this” request to Shopify for every product without pausing. That’s like refreshing your browser continuously — Shopify will temporarily block us. We need a polite pause between batches.  
    - **Evidence:**  
        ```php
        try {
            $result = $catalogService->writeHasEnabledVariants($integration, $gid, $hasEnabled);
        ```  
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SCALE-7** · P2 — Shopify rate‑limit disregard in ReconcileSmartCollectionRulesCommand  
    - **Where:** app/Console/Commands/ReconcileSmartCollectionRulesCommand.php:117-146 (integration loop)  
    - **Affects:** Running against 200 brands fires 600–1000 GraphQL calls in a tight sequence, likely exhausting the bucket.  
    - **Effort:** S (~0.5–1h)  
    - **What to do:**  
        - Add rate‑limit header checks and backoff between brands, or dispatch per‑brand work to a queued job that throttles itself.  
    - **Technical:** (Category 5) Each brand requires multiple GraphQL calls; a full sweep hits Shopify’s rate limiter. Implement a small delay between brands or a job with retry that respects the bucket.  
    - **Plain English:** Same story — we talk to Shopify too fast when updating collection rules for all brands at once. A short rest between stores fixes it.  
    - **Evidence:**  
        ```php
        foreach ($integrations as $integration) {
            foreach (self::COLLECTIONS as $title => $desiredRules) {
                $result = $this->reconcileCollection(…);
            }
        }
        ```  
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SCALE-8** · P2 — Shopify rate‑limit disregard in MigrateMetafieldNamespaceCommand  
    - **Where:** app/Console/Commands/MigrateMetafieldNamespaceCommand.php (product page loop + writes)  
    - **Affects:** One‑time namespace migration; large stores cause 429 errors.  
    - **Effort:** S (~0.5–1h)  
    - **What to do:**  
        - Add rate‑limit header handling and backoff; consider batching multiple metafield writes into fewer GraphQL calls (Shopify’s `metafieldsSet` already accepts arrays — verify it’s used optimally).  
    - **Technical:** (Category 5) The command pages through products and issues mutations. Without respecting the `X-Shopify-Shop-Api-Call-Limit` header, a store with 5 000 products will hit the throttle. Introduce a backoff loop that reads the bucket and sleeps when needed.  
    - **Plain English:** Migrating metafield data product‑by‑product can overwhelm Shopify’s front desk. Adding a few seconds of politeness keeps the door open.  
    - **Evidence:**  
        ```php
        $response = $client->graphql(…) // inside pagination
        ```  
    - `[DRAFT, confidence: 0.8]`

- [ ] **#SCALE-9** · P2 — Noisy‑neighbour shared queue for Square/Fresha syncs  
    - **Where:** app/Observers/Core/ServiceObserver.php:115-127  
    - **Affects:** All brands using Square or Fresha integrations share the `integrations` queue; one brand’s CSV import of 500 services can starve other brands’ syncs.  
    - **Effort:** M (~2–4h)  
    - **What to do:**  
        - Add per‑brand job throttling (e.g. `Redis::throttle('sync:brand:'.$brandId)->allow(5)->every(60)`) or isolate noisy‑tenant queues.  
    - **Technical:** (Category 7) All `PushServiceToSquareJob` / `PushServiceToFreshaJob` jobs land on the same queue without any tenant‑scoped concurrency limit. At 200 brands, a heavy import from one brand can saturate the available workers, delaying critical syncs for others. A per‑brand job middleware would enforce fair scheduling.  
    - **Plain English:** If one brand does a huge data import, the workers that sync other brands’ services get stuck waiting. It’s like one customer at the bank taking an hour — everyone else lines up. We can give each brand a fair share of the teller’s time.  
    - **Evidence:**  
        ```php
        PushServiceToSquareJob::dispatch($serviceId, $action)
            ->delay($this->syncDispatchDelay());
        ```  
    - `[DRAFT, confidence: 0.7]`

- [ ] **#SCALE-10** · P2 — Shopify rate‑limit disregard in ReconcileShopifyOrders  
    - **Where:** app/Console/Commands/ReconcileShopifyOrders.php (pagination loop)  
    - **Affects:** Daily reconciliation sweep; 200 brands with many orders each may burst Shopify’s 2 requests/s REST limit.  
    - **Effort:** S (~0.5–1h)  
    - **What to do:**  
        - Add a small `usleep` between pages, or check Shopify’s `X-Shopify-Shop-Api-Call-Limit` header and pause when near the limit.  
    - **Technical:** (Category 5) The command loops over pages of orders without any throttling. Multiple integrations processed sequentially can cause sustained bursts. A half‑second sleep between pages keeps the command within allowed limits.  
    - **Plain English:** Fetching thousands of order updates page after page can hammer Shopify’s control panel. A brief pause between pages prevents us from being locked out.  
    - **Evidence:**  
        ```php
        do {
            $response = $shopifyClient->rest(…);
            $pageInfo = $this->extractNextPageInfo(…);
        } while ($pageInfo !== null);
        ```  
    - `[DRAFT, confidence: 0.8]`
