
<!-- ═══ CHUNK: infra ═══ -->

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

<!-- ═══ CHUNK: svc-prof-stripe ═══ -->

- [ ] **#SCALE-1** · P1 — Unbounded `->get()` on orders during payout batch creation holds row locks across arbitrary row counts
    - **Where:** app/Services/Stripe/CommissionPayoutService.php (createPayoutBatchTransactional method)
    - **Affects:** Daily sweep that creates commission payouts; every brand–affiliate pair with approved orders past their grace window. At 3K orders/day, a single pair could accumulate hundreds of orders in one sweep.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace `->lockForUpdate()->get()` with `->chunk(200, function ($chunk) use (&$payout, ...) { ... })` so the lock is released between chunks.
        - Batch-insert `CommissionPayoutItem` rows within each chunk using `insert()` instead of per-row `create()`.
    - **Technical:** The current code loads every approved+unstamped order for a (brand, affiliate, currency) tuple into memory under `SELECT ... FOR UPDATE`. At scale, a busy brand–affiliate pair could accumulate hundreds of orders per payout window. Holding a row lock across all of them blocks concurrent refund webhooks and the next sweep. The memory footprint is also unbounded — PHP must hydrate every Order model simultaneously. The canonical replacement is chunked processing with `chunk(n)` so locks are held only for the batch duration, and memory stays bounded to `n` rows.
    - **Plain English:** Imagine counting cash from a register by taking every single bill out at once and locking the drawer so nobody else can touch it until you're done. If the register has 50 bills, that's quick. If it has 3,000 bills, you're standing there for a long time and nobody else can make change. The fix is to count 200 bills at a time, close the drawer between batches, and let other people in.
    - **Evidence:**
        ```php
        $orders = Order::query()
            ->where('status', 'approved')
            ->whereNull('payout_id')
            ->where('refund_cents', 0)
            ->where('brand_professional_id', $brandId)
            ->where('affiliate_professional_id', $affiliateId)
            ->where('currency_code', $currency)
            ->where(function ($q) use ($cutoff) {
                $q->where('payout_eligible_at', '<=', now())
                    ->orWhere(function ($q2) use ($cutoff) {
                        $q2->whereNull('payout_eligible_at')
                            ->where('occurred_at', '<=', $cutoff);
                    });
            })
            ->lockForUpdate()
            ->get();
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#SCALE-2** · P1 — Unbounded `->get()` on orders during payout revalidation holds row locks across arbitrary row counts
    - **Where:** app/Services/Stripe/CommissionPayoutService.php (revalidatePayoutOrders method)
    - **Affects:** Every `ExecuteCommissionPayoutJob` run — the revalidation step runs before every Stripe PI create. At 10K daily payout jobs, this path fires 10K times/day.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace `->lockForUpdate()->get()` with `->chunk(200, ...)` so revalidation locks and validates in bounded batches.
        - Keep the partition logic (`$validOrders` / `$staleOrders`) but apply it per chunk, accumulating aggregates.
    - **Technical:** Same unbounded `get()` + `lockForUpdate()` pattern as `createPayoutBatchTransactional`, but on the *revalidation* path — every payout job locks every order linked to its payout. At scale, a large payout batch (hundreds of orders aggregated over a week for a busy brand) holds FOR UPDATE locks across all rows for the duration of the PHP loop. This blocks concurrent refund webhooks and the next sweep's batch creation. The canonical fix is chunked processing, same as SCALE-1.
    - **Plain English:** Before sending money to Stripe, the system double-checks every order is still valid — but it grabs all of them at once, locks the drawer, and won't let go. Same register-counting problem as creating the batch, just at a different step. The fix is identical: count in small handfuls.
    - **Evidence:**
        ```php
        $orders = Order::query()
            ->where('payout_id', $payout->id)
            ->lockForUpdate()
            ->get();
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#SCALE-3** · P1 — StripeRowGenerator issues one Stripe API call per payout during export with no backoff
    - **Where:** app/Services/Stripe/StripeRowGenerator.php:forPayouts, yieldBrand, yieldAffiliate
    - **Affects:** Async commission export pipeline. At the scale target, a long-tenured brand or high-volume affiliate can accumulate tens of thousands of payouts — the export issues that many Stripe API calls, saturating the Stripe rate-limit budget and causing cascading failures.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Introduce exponential backoff with jitter around Stripe's `Stripe-Should-Retry` semantics.

[User Cancelled]
    - **Technical:** The generator yields one normalised row per payout, and each yield does `$this->stripe->paymentIntents->retrieve(...)` or `$this->stripe->charges->retrieve(...)`. At 50K payouts for a mature tenant, that's 50K Stripe API calls in a single job execution. Stripe's per-second rate limit (100/sec for most APIs) will throttle this hard — the job will either slow to a crawl or fail entirely. The canonical replacement is a rate-limited iterator with `usleep()` + jitter between calls, combined with `Stripe-Should-Retry` header inspection.
    - **Plain English:** Imagine calling a supplier to check on 50,000 individual orders, one phone call at a time, as fast as you can dial. The supplier's switchboard can handle about 100 calls per second before it starts dropping them. By call #200 you're getting busy signals. The fix is to wait a tiny bit between calls — like hanging up for half a second after every few calls, and waiting longer if you get a busy signal.
    - **Evidence:**
        ```php
        private function yieldBrand(CommissionPayout $payout): \Generator
        {
            if (! $payout->payment_intent_id) {
                return;
            }
            $pi = $this->stripe->paymentIntents->retrieve($payout->payment_intent_id, [
                'expand' => ['latest_charge.refunds'],
            ]);
            // ... yields per-payout, no backoff between calls
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#SCALE-4** · P2 — CommissionPayoutService inserts payout items one row at a time instead of batch-inserting
    - **Where:** app/Services/Stripe/CommissionPayoutService.php (createPayoutBatchTransactional)
    - **Affects:** Every payout batch creation — at 10K daily payout jobs, this is hundreds of thousands of individual INSERT statements per day.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Collect rows into an array and use `CommissionPayoutItem::insert($rows)` instead of per-row `create()`.
    - **Technical:** The loop `foreach ($orders as $order) { CommissionPayoutItem::create([...]); }` fires one INSERT per order. With hundreds of orders per batch and thousands of batches per day at the scale target, this is hundreds of thousands of unnecessary round-trips to Postgres. Eloquent's `insert()` accepts an array of arrays and issues a single multi-row INSERT, reducing round-trips 200× in a typical batch. The transaction wraps all of them anyway, so atomicity is unchanged.
    - **Plain English:** Writing one check at a time when you could write a single cheque for the total. The system visits the database 200 separate times to record items for a single payout, when it could hand over all 200 rows in one trip. At scale, those extra trips add up to noticeable lag.
    - **Evidence:**
        ```php
        foreach ($orders as $order) {
            CommissionPayoutItem::create([
                'payout_id' => $payout->id,
                'order_id' => $order->id,
                'amount_cents' => $order->commission_cents,
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#SCALE-5** · P2 — Unbounded `->get()` on SiteMedia during account purge loads all media into memory
    - **Where:** app/Services/Professional/AccountDeletionService.php:purgeMediaArtifacts
    - **Affects:** Daily `PurgeSoftDeleted` command — every hard-deleted account with media. At 200 brands in target steady state, churn is low but a single brand with a large media library (gallery + design placeholders + documents) can spike memory.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->get()` with `->chunk(100, fn ($chunk) => ...)` so media rows are processed in bounded batches.
    - **Technical:** `SiteMedia::query()->withTrashed()->where('site_id', $site->id)->get()` materialises every media row for a site into a PHP array. A brand that uploaded 500+ images to their gallery will spike worker memory during the nightly purge. The `foreach` body dispatches async `DeleteMediaArtifactsJob` for videos and synchronously deletes images/documents — no value in holding all rows at once. `->chunk(100)` keeps peak memory at ~100 rows and doesn't change the async dispatch behaviour.
    - **Plain English:** When closing down a store for good, the cleanup crew takes every single item off every shelf at once before throwing anything away — instead of taking one shelf at a time. For a store with hundreds of items, that's a lot of armfuls. The fix is to carry out one shelf's worth, bin it, then go back for the next shelf.
    - **Evidence:**
        ```php
        $mediaItems = SiteMedia::query()
            ->withTrashed()
            ->where('site_id', $site->id)
            ->get();
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SCALE-6** · P2 — StripeTransactionFetcher issues up to 25 Stripe API calls per dashboard request without rate-limit awareness
    - **Where:** app/Services/Stripe/StripeTransactionFetcher.php:forBrand, forAffiliate
    - **Affects:** Dashboard transaction views for brands and affiliates. At 200 brands, concurrent dashboard usage by multiple accounts could exhaust the Stripe API rate budget.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Gate concurrent Stripe fetches with a per-request concurrency limiter (e.g., `Semaphore` or a Redis-backed token bucket) so multiple dashboard users don't collectively burst past Stripe's rate limit.
        - Consider caching individual PaymentIntent/Charge responses for short TTLs (30s) keyed by ID.
    - **Technical:** `forBrand()` calls `$this->scopedPayouts(...)` which returns up to 25 payouts, then iterates and calls `$this->stripe->paymentIntents->retrieve(...)` for each. That's up to 25 Stripe API calls in a single synchronous HTTP request. Stripe's PaymentIntent retrieve endpoint is rate-limited (100/sec in test mode). Two concurrent dashboard users viewing different pages could issue 50 calls — fine. Ten concurrent users across 200 brands at peak hours could burst 250 calls in a second — exceeding the limit. The canonical fix is either short-lived caching of PI data (the financial state doesn't change second-to-second) or a local rate limiter.
    - **Plain English:** Every time someone opens their transaction history, the system calls Stripe once for each payout row on the page — up to 25 phone calls per page view. Two people browsing is fine. Twenty people browsing at the same time on a busy morning could overwhelm the switchboard. The fix is either to write down what Stripe said for a minute so the next person reads the note instead of calling, or to put a traffic light on the outbound calls.
    - **Evidence:**
        ```php
        foreach ($payouts as $payout) {
            if (! $payout->payment_intent_id) { continue; }
            $pi = $this->stripe->paymentIntents->retrieve($payout->payment_intent_id, [
                'expand' => ['latest_charge.refunds'],
            ]);
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#SCALE-7** · P2 — ExportProfessionalDataJob dispatched inside DB transaction without afterCommit
    - **Where:** app/Services/Professional/DataExport/DataExportService.php:dispatch
    - **Affects:** GDPR data export flow. The job can be picked up by a queue worker before the `data_export_audit` row is committed — the job queries the row immediately and fails with a not-found error, wasting a queue attempt.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Append `->afterCommit()` to `ExportProfessionalDataJob::dispatch($audit->id)` or move the dispatch outside the `DB::transaction()` closure.
    - **Technical:** The `DB::connection('pgsql')->transaction(...)` closure creates the `DataExportAudit` row and dispatches `ExportProfessionalDataJob` in the same closure. With a Redis queue, `dispatch()` pushes the job to Redis instantly — before the outer transaction commits. The worker picks up the job, does `DataExportAudit::find($auditId)`, and the row isn't visible yet. The job fails, Horizon retries it (consuming a retry slot), and it succeeds on retry after the transaction commits. Under scale (many concurrent exports), these spurious retries waste queue capacity and delay exports. `->afterCommit()` defers the dispatch until the transaction succeeds.
    - **Plain English:** The system writes a work order and puts it in the outbox before actually saving the work order to the filing cabinet. The worker grabs the slip, opens the cabinet, and the folder isn't there yet — so it throws the slip in the "try again" pile. A few minutes later it tries again and finds the folder. At low volume this is harmless; at high volume the "try again" pile grows and real work gets delayed.
    - **Evidence:**
        ```php
        return DB::connection('pgsql')->transaction(function () use ($professional, $triggeredBy, $staffId, $sendTo, $recipient) {
            // ...
            $audit = DataExportAudit::create([...]);
            ExportProfessionalDataJob::dispatch($audit->id);
            return $audit;
        });
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SCALE-8** · P3 — Logging raw Supabase HTTP response body on auth deletion failure
    - **Where:** app/Services/Professional/AccountDeletionService.php:deleteSupabaseAuthUser
    - **Affects:** Nightwatch / log indexing pipeline. If Supabase returns a large HTML error page (e.g., a 502 gateway error page from their edge), the full body hits the log index.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Truncate `$response->body()` to e.g. 500 chars before passing to `Log::error`, or log only the status code + a truncated preview.
    - **Technical:** `Log::error(...)` receives `'body' => $response->body()`. Supabase's Admin API can return HTML error pages (Cloudflare 502, load-balancer error pages) that are hundreds of KB. At one purge per day this is negligible; but the pattern is unbounded — any future loop over tenant auth deletions would flood the log pipeline. Truncating keeps the forensic value (first 500 chars of an error page are enough to diagnose) without the risk of blowing up log indices.
    - **Plain English:** When the system calls Supabase to delete a user and Supabase is having a bad day, it might send back a long error page. The system stuffs the entire error page into its diary. One long diary entry is fine. If this ever runs in a loop (deleting many users at once), the diary would overflow. The fix is to tear off just the first paragraph of the error page and log that.
    - **Evidence:**
        ```php
        Log::error('Supabase auth user deletion failed', [
            'auth_user_id' => $authUserId,
            'status' => $response->status(),
            'body' => $response->body(),
        ]);
        ```
    - `[DRAFT, confidence: 0.70]`

<!-- ═══ CHUNK: svc-commerce ═══ -->

- [ ] **SCALE-1** · P1 — Catalog queries bypass Shopify’s rate‑limit / cost‑budget enforcement  
    - **Where:** app/Services/Store/AffiliateProductCatalogService.php:845 (within `queryAdminCatalog`)  
    - **Affects:** Every affiliate catalog load (brand‑active catalog + fallback) — at 3 K orders/day and dozens of affiliates per brand, raw calls can drain the Shopify bucket and starve all brands.  
    - **Effort:** M (~2–4h)  
    - **What to do:**  
        - Replace the raw `Http::post(…)` with `$client->graphql(…)` (the same `ShopifyAdminClient` already used everywhere else in `BrandCatalogService`).  
        - Let the client’s `preAcquireBudget` / `reconcileFromResponse` / throttle‑retry loop govern the call so the bucket stays healthy.  
    - **Technical:** `queryAdminCatalog` builds its own URL, headers, and timeout and calls Shopify Admin directly without going through the `ShopifyAdminClient` that every other GraphQL call in the codebase uses. That client maintains per‑shop token‑bucket state, logs cost metrics, and honours the THROTTLED‑retry contract. Skipping it means 200 brands’ catalog reads can burst 429 errors with no local defence.  
    - **Plain English:** Imagine every room in a hotel has its own thermostat, but one room has a space heater plugged directly into the mains without the central energy‑budget system. That room can draw so much power that the whole floor trips the breaker. The catalog query is that space heater — it should respect the same thermostat as every other Shopify call.  
    - **Evidence:**  
        ```php
        $response = Http::timeout(20)
            ->acceptJson()
            ->withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
            ])
            ->post($url, [
                'query' => $query,
                'variables' => $variables,
            ]);
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **SCALE-2** · P1 — Collection‑product lists fetched without caching, causing repeat Shopify API pagination per request  
    - **Where:** app/Services/Store/BrandCatalogService.php:504‑530 (`fetchCollectionProducts`)  
    - **Affects:** Every affiliate catalogue request that includes collection‑based data (favourites, default collections) — triggers fresh paginated GraphQL calls that can consume Shopify budget at scale.  
    - **Effort:** M (~2–4h)  
    - **What to do:**  
        - Wrap `fetchCollectionProducts` (or the call site in `AffiliateProductCatalogService::fetchCollectionGids`) with a short‑TTL cache keyed on `(brand_id, collection_gid)`.  
        - Bust that cache on any metafield or product‑visibility change (the surrounding code already handles busting for the main catalog).  
    - **Technical:** `fetchCollectionProducts` paginates through every product in a collection with repeated GraphQL calls (`do … while` loop). The upstream `resolveCollectionGid` is cached, but the actual product list is not. At the target scale, many affiliates hitting `/affiliate/catalog` concurrently will re‑fetch the same collection GIDs repeatedly, multiplying Shopify API load and risking bucket exhaustion.  
    - **Plain English:** Every time a customer walks into the store, we send someone to the warehouse to count all the items on a specific shelf — even though the shelf hasn’t changed since the last customer. A simple memo on the wall (“favourites for brand X are these 20 items”) would stop all those trips.  
    - **Evidence:**  
        ```php
        do {
            $variables = ['id' => $collectionGid, 'first' => self::PRODUCTS_PER_PAGE];
            if ($cursor !== null) {
                $variables['after'] = $cursor;
            }

            $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::COLLECTION_PRODUCTS, $variables);
            // … edges parsed …
        } while ($hasNextPage && $cursor !== null);
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **SCALE-3** · P1 — Favourite‑collection GIDs fetched without caching, adding a second uncached Shopify round‑trip per request  
    - **Where:** app/Services/Store/AffiliateProductCatalogService.php:340‑360 (`fetchCollectionGids`)  
    - **Affects:** The same affiliate‑catalogue surface as SCALE‑2 — the “favourites” membership used for filtering and affiliate selections.  
    - **Effort:** M (~2–4h)  
    - **What to do:**  
        - Cache the return value of `fetchCollectionGids` for a short TTL (e.g. 5 min), keyed on `(brand_id, metadata_key)`, and invalidate when the underlying collection changes.  
    - **Technical:** `fetchCollectionGids` calls `resolveCollectionGid` (cached) and then `fetchCollectionProducts` (uncached, see SCALE‑2). Since it is invoked inside `getCatalogWithSelections` (hot path), each affiliate load fires a fresh collection‑products pagination just to retrieve GIDs that rarely change.  
    - **Plain English:** Same warehouse‑shelf problem, for a different shelf. The “favourites” list is read every time a shopper looks at the catalog, even though the list of favourite products essentially never changes minute‑to‑minute.  
    - **Evidence:**  
        ```php
        private function fetchCollectionGids(ProfessionalIntegration $integration, string $metadataKey): array
        {
            // …
            $collectionGid = $this->brandCatalogService->resolveCollectionGid($integration, $handle);
            if (! $collectionGid) {
                return [];
            }
            $products = $this->brandCatalogService->fetchCollectionProducts($integration, $collectionGid);
            return array_map(fn (array $p) => $p['gid'] ?? '', $products);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **SCALE-4** · P2 — Image‑variant job dispatched to the default queue, risking head‑of‑line blocking  
    - **Where:** app/Services/Media/BrandDesignMediaService.php:292‑297 (`dispatchVariantJob`)  
    - **Affects:** Logo and placeholder uploads; CPU‑intensive image processing can congest the same queue that processes Shopify orders, webhooks, and payouts.  
    - **Effort:** S (~0.5–1h)  
    - **What to do:**  
        - Add `->onQueue('media')` to the `ProcessImageVariantsJob::dispatch` call (and the video equivalent if it exists).  
        - Configure `config/horizon.php` with a dedicated `media` supervisor whose capacity doesn’t starve the `default` queue under burst upload traffic.  
    - **Technical:** Only `dispatch` is called without a queue hint, so Laravel places it on the `default` connection’s default queue. At the scale target a brand re‑uploading a logo during a peak checkout window would delay commission‑webhook processing — a classic noisy‑neighbour scenario at the queue level.  
    - **Plain English:** Instead of having a separate lane for heavy trucks (image processing) and fast cars (order processing), we put both in the same lane. A single slow truck can cause a traffic jam for every car behind it.  
    - **Evidence:**  
        ```php
        ProcessImageVariantsJob::dispatch(
            originalPath: $originalPath,
            imageId: $imageId,
            basePath: $basePath,
            siteId: $siteId,
        );
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **SCALE-5** · P2 — Per‑window brand analytics use potentially large `WHERE IN` with a full set of affiliate IDs  
    - **Where:** app/Services/Analytics/AnalyticsService.php:274‑282 (`brandWindowedViews`) and similar methods  
    - **Affects:** Brand‑side analytics dashboard; at 200 brands × 50 affiliates = 10 K distinct affiliate IDs the `IN` clause can grow long, slow query planning, and consume memory on the cache‑rebuild boundary.  
    - **Effort:** M (~2–4h)  
    - **What to do:**  
        - Replace `pluck` + `whereIn` with a sub‑select or JOIN that pushes the filter into the query executor.  
        - If the list remains, apply a chunk‑based `whereIn` with `array_chunk($affiliateIds, 500)` to bound the IN clause length.  
    - **Technical:** The current code materialises the full affiliate list with `pluck` (no limit) then passes it to `whereIn`. While the results are cached, a cold rebuild for a brand with 500 affiliates sends a SQL statement containing a 500‑element IN list, which can hit PostgreSQL’s per‑statement parameter limit and cause the planner to degrade to a sequential scan.  
    - **Plain English:** When we need to sum sales for a big retailer, we first write down the names of every single cashier (up to thousands) and then hand that long list to the database — when we could just tell the database “only look at rows for this store” and let it do the work.  
    - **Evidence:**  
        ```php
        $affiliateIds = DB::table('commerce.brand_affiliate_rollup')
            ->where('brand_professional_id', $brandId)
            ->distinct()
            ->pluck('affiliate_professional_id');
        // later …
        $query = DB::table('analytics.site_visits')->whereIn('professional_id', $affiliateIds);
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **SCALE-6** · P2 — Shopify cost‑tracker learns query‑cost ratios globally, not per shop  
    - **Where:** app/Services/Shopify/Client/ShopifyCostTracker.php:22‑24 (`key` method)  
    - **Affects:** Pre‑acquisition budget estimates for every GraphQL call; a cost ratio learned from a shop with a small catalog may under‑reserve for a shop with a huge catalog, causing avoidable THROTTLED retries.  
    - **Effort:** S (~0.5–1h)  
    - **What to do:**  
        - Include `$shopDomain` in the Redis key, e.g. `shopify:cost:{$shopDomain}:{$queryHash}`.  
        - Continue to expire keys after inactivity to avoid unbounded key growth.  
    - **Technical:** The `estimate` method uses `Redis::lrange` on a key that contains only the query hash, so samples from all shops are pooled. Shopify’s actual cost for a query like `products(first: 50)` varies with the number of variants and metafields present; a shop with 1 000 variants per product will burn more points than one with 1 variant, but the global average will obscure that, causing the local bucket to under‑budget and hit THROTTLED more often.  
    - **Plain English:** We’re measuring fuel consumption by averaging across every type of car — a tiny hatchback and a heavy truck look the same on paper, so we sometimes give the truck too little fuel and it stalls. Better to track each car (each shop) separately.  
    - **Evidence:**  
        ```php
        private function key(string $queryHash): string
        {
            return "shopify:cost:{$queryHash}";
        }
        ```
    - `[DRAFT, confidence: 0.8]`

<!-- ═══ CHUNK: svc-rest-models ═══ -->

- [ ] **#SCALE-1** · P2 — LiveStatusInjector issues per-block Redis GET on every site render (N+1 Redis round-trips)
    - **Where:** app/Services/Streaming/LiveStatusInjector.php:64-65
    - **Affects:** Every public site page render that passes through `injectIntoPayload()` — including cache-hit renders where the payload was just unpacked. At 200 brands each averaging 3 streaming link blocks, that's ~600 Redis GETs per polling cycle across the fleet; at ~10K daily site visits it's fine today but the linear coupling between block count and Redis calls means a single brand adding 20 streaming blocks multiplies cost for every visitor to every brand's page.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Bulk-read the live status keys with `Redis::mget()` before iterating blocks — one round-trip for all keys instead of one per block.
        - Pre-compute the `LIVE_KEY_PREFIX` list from the already-loaded block settings array so the `mget` call needs no extra query.
    - **Technical:** The `array_map` callback in `injectIntoBlocks` calls `Redis::get()` for every block whose settings carry `live_check_enabled=true`. This is a text-book N+1 against Redis — sub-millisecond per call, but the cumulative latency grows linearly with the number of streaming blocks on the page. Since `LiveStatusInjector` runs *after* `SiteCacheService::getPublicSitePayload()` (the docblock says "never stored in the cache itself"), this penalty is paid even on cache-hit renders. A single `mget` amortises the cost to one network hop.
    - **Plain English:** Every time someone visits a Partna profile page, the server checks if each "Live on Twitch" link is actually live by asking Redis one link at a time. If a profile has 10 streaming links, that's 10 separate questions to Redis instead of asking all 10 in one call. It's like checking each light bulb in your house by walking to the switchboard 10 times instead of once. Redis answers fast, but the extra trips add up as more creators add streaming links.
    - **Evidence:**
        ```php
        $redisKey = self::LIVE_KEY_PREFIX."{$platform}:{$handle}";
        $block['settings']['is_live'] = Redis::get($redisKey) === '1';
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SCALE-2** · P2 — LiveStatusPoller::filterStaleHandles issues per-handle Redis TTL call (N+1 Redis round-trips)
    - **Where:** app/Services/Streaming/LiveStatusPoller.php:160-167
    - **Affects:** Every polling cycle for every streaming platform. At 200 brands with an average of 2–3 streaming handles each, plus individual affiliates with streaming links, the handle population reaches ~500+. Each poll cycle calls `Redis::ttl()` 500+ times sequentially.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Use Redis pipelining or a Lua script to batch the TTL checks into one round-trip.
        - Accept the handles array, pipeline all TTL calls, then filter on the returned array.
    - **Technical:** The `array_filter` callback calls `Redis::ttl()` once per handle. At 500 handles and a 2-minute poll interval, this is 500 sequential Redis commands every 120 seconds — well within Redis capacity but a wasteful use of PHP-worker wall-clock time. Pipelining collapses this into one network hop and lets Redis answer all TTLs in a single pass. The cold-handle demotion mechanism (tiered TTLs) is the right architecture; the read path just needs batching.
    - **Plain English:** The live-status poller checks whether each streaming handle's Redis data is "stale" before deciding to call Twitch or Kick. It does this by asking Redis "how much time is left on this key?" — one handle at a time. With 500 streamers using Partna, that's 500 tiny questions instead of one "check all 500 please" request. It's the difference between texting each friend individually versus sending one group message.
    - **Evidence:**
        ```php
        return array_values(array_filter($handles, function (string $handle) use ($platform): bool {
            $key = self::LIVE_KEY_PREFIX."{$platform}:{$handle}";
            $ttl = Redis::ttl($key);
            // -2 = key doesn't exist, -1 = no TTL, any value <= threshold = stale
            return $ttl < self::TTL_SKIP_THRESHOLD;
        }));
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#SCALE-3** · P2 — SquareApiClient retry loop sleeps inside PHP worker with `usleep`, blocking queue throughput under rate-limit pressure
    - **Where:** app/Services/Square/SquareApiClient.php:153-161
    - **Affects:** Any background job that calls the Square API during a rate-limit event. A single 429 with `Retry-After: 5` sleeps the Horizon worker for 5 seconds per retry, up to 3 retries = 15 seconds of idle worker time. At 200 brands each with periodic catalog syncs, a Square outage or rate-limit burst could occupy every worker in the `default` queue with sleeping processes.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the in-process sleep with a `release($delay)` back to the queue — let the job re-enter the queue after the `Retry-After` window instead of tying up a worker.
        - Apply `$tries` and `$backoff` on the calling job so repeated 429s decay rather than looping forever.
    - **Technical:** The `while (true)` loop with `usleep($wait * 1000)` holds the PHP-FPM or Horizon worker process in a sleeping state. In Horizon, each worker is a dedicated OS process; a sleeping worker is unavailable to process other jobs. Under rate-limit pressure from Square, multiple sync jobs can all sleep simultaneously — this is a classic connection-pool / worker-exhaustion pattern. The canonical Laravel fix is `$this->release($delay)` inside the job's `handle()`, which returns the job to the queue and frees the worker. The `maxRetries = 3` guard is good but the sleep happens per-retry, not per-job.
    - **Plain English:** When Square says "slow down," the API client puts the worker to sleep right where it stands — like a cashier who stops serving the line to wait for a manager instead of stepping aside. While that worker naps, it can't process anyone else's job. If Square is slow for many brands at once, all the available workers could end up napping simultaneously. The fix is to have the worker put the job back in the queue with a "come back in X seconds" note rather than sleeping on the job.
    - **Evidence:**
        ```php
        while (true) {
            $response = $this->makeRequest($token, $method, $path, $query, $body);

            if ($response->status() === 429 && $attempt < $maxRetries) {
                $wait = max(1000, ((int) ($response->header('Retry-After') ?? 1)) * 1000);
                usleep($wait * 1000);
                $attempt++;
                continue;
            }
            break;
        }
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#SCALE-4** · P2 — FreshaApiClient retry loop has the same in-process `usleep` worker-blocking pattern
    - **Where:** app/Services/Fresha/FreshaApiClient.php:161-169
    - **Affects:** Same worker-exhaustion risk as SCALE-3, applied to the Fresha API path. Under a Fresha rate-limit event, any job calling the Fresha API sleeps the Horizon worker inside the retry loop.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply the same `release($delay)` pattern described in SCALE-3.
        - Consider extracting a shared `VendorApiClient` base class so the retry/policy logic is maintained once (applies to both Square and Fresha clients, and any future vendor integration).
    - **Technical:** Identical architecture to SCALE-3 — a `while (true)` loop with in-process `usleep`. The code is a near-copy of `SquareApiClient::request()`. At the 200-brand scale target, even if Fresha adoption is low, the pattern means every Fresha-connected brand's sync job can occupy a worker during rate-limit windows. Extracting the retry logic into a shared trait or base class would prevent this anti-pattern from recurring in future vendor integrations.
    - **Plain English:** Same problem as the Square client — the worker naps on the job instead of putting the task back in the queue. This is the second copy of the same pattern, which means a future third vendor integration (e.g., a booking platform) would likely copy it a third time. Better to fix both and extract the shared logic now.
    - **Evidence:**
        ```php
        while (true) {
            $response = $this->makeRequest($token, $method, $path, $query, $body);

            if ($response->status() === 429 && $attempt < $maxRetries) {
                $wait = max(1000, ((int) ($response->header('Retry-After') ?? 1)) * 1000);
                usleep($wait * 1000);
                $attempt++;
                continue;
            }
            break;
        }
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#SCALE-5** · P3 — CloudflareDnsService::upsertCname makes redundant PATCH call when CNAME content already matches
    - **Where:** app/Services/Cloudflare/CloudflareDnsService.php:82-98
    - **Affects:** Every call to `upsertCname` where the DNS record already exists with the correct target. The method unconditionally issues a PATCH to update the `proxied` flag even when it may already be correct. At 200 brands each going through Hydrogen storefront setup, this doubles Cloudflare API call volume for DNS operations.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Before patching, fetch the current record with a GET to inspect the existing `proxied` state, or include `proxied` in the `findRecord` response so the caller can skip the PATCH when state is already correct.
        - Alternatively, always include `content` in the PATCH alongside `proxied` so the call is idempotent and the redundant-PATCH concern goes away — a PATCH with the same values is a cheap no-op at Cloudflare's edge.
    - **Technical:** `findRecord` returns only `['id', 'type', 'name', 'content']` — it omits `proxied`. The `upsertCname` method therefore can't know whether the existing record's `proxied` flag matches the desired value, so it unconditionally issues a PATCH. Cloudflare's DNS API rate limit is ~30 requests/second per zone; at 200 brands this isn't a limit-breaker today, but it's a wasteful API call that adds latency to every subdomain provisioning operation.
    - **Plain English:** When setting up a brand's storefront subdomain, the service checks if the DNS record already exists. If it does and the target is correct, it still phones Cloudflare to say "make sure the proxy setting is right" — even though it might already be right. It's like calling your internet provider to confirm your plan hasn't changed every time you pay the bill. Unnecessary, adds a bit of lag, and uses up your polite-phone-call budget.
    - **Evidence:**
        ```php
        if ($existing !== null) {
            if ($existing['content'] === $target) {
                // Check proxied state — requires a fresh fetch as findRecord doesn't return it.
                $response = Http::withToken($this->apiToken)
                    ->patch($this->zonesUrl("/dns_records/{$existing['id']}"), [
                        'proxied' => $proxied,
                    ]);
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#SCALE-6** · P3 — EmailSubscription saved hook dispatches one job per subscription; bulk imports produce N jobs with no batching
    - **Where:** app/Models/Core/Notifications/EmailSubscription.php:98-112
    - **Affects:** Any bulk operation that creates or updates many EmailSubscription rows (CSV import, GDPR data port, brand migration). Each saved row dispatches a `SyncCustomerMarketingOptInJob` — 10K rows = 10K Redis job-push commands.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Gate the dispatch behind a `wasRecentlyCreated` or `isDirty('status')` check so unchanged rows don't fire the job.
        - For bulk paths, provide a `saveQuietly()` or a batch-aware flag that skips the per-row dispatch and lets the bulk importer run one reconciliation job at the end.
    - **Technical:** The `saved` hook fires on every `save()`, including rows where `status` didn't change. A bulk CSV import of 10K rows would push 10K jobs to the `mail` queue — each doing a Customer lookup. The `afterCommit` guard prevents the job from firing on rolled-back transactions, which is good, but the hook fires per-row regardless. Adding `isDirty('status')` would cut the dispatch to only rows that actually changed state.
    - **Plain English:** Every time an email subscription row is saved — even if nothing changed — a small background task is created to sync some cached data. In normal use (one person subscribing at a time) this is fine. But if the team imports a spreadsheet of 10,000 subscribers, the system creates 10,000 tiny tasks all at once. Most of them are unnecessary because the status didn't change. Adding a "only if something actually changed" check stops the flood.
    - **Evidence:**
        ```php
        static::saved(function (self $subscription) {
            if ($subscription->list_key === 'marketing' && $subscription->professional_id && $subscription->email) {
                $professionalId = (string) $subscription->professional_id;
                $email = (string) $subscription->email;
                $isSubscribed = $subscription->status === 'subscribed';

                DB::afterCommit(function () use ($professionalId, $email, $isSubscribed) {
                    \App\Jobs\Notifications\SyncCustomerMarketingOptInJob::dispatch(
                        $professionalId,
                        $email,
                        $isSubscribed,
                    );
                });
            }
        });
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **#SCALE-7** · P3 — SendTransactionalNotificationEmailJob dispatched without visible `$tries`/`$backoff` exposure at the call site
    - **Where:** app/Services/Notifications/NotificationPublisher.php:141-146
    - **Affects:** Email delivery reliability under Resend API outage. Without `$tries` and `$backoff` visible in the dispatch path (or on the job class itself), a Resend 5xx could retry immediately rather than with exponential backoff, creating a retry storm on the `mail` queue during an outage.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Verify that `SendTransactionalNotificationEmailJob` has `$tries = 3` and `$backoff = [30, 120, 300]` (or similar) on the job class itself. If not, add them.
        - Add a `failed()` handler that logs the failure via `report()` so Nightwatch surfaces persistent email delivery failures.
    - **Technical:** The job dispatch call sites in `publish()` and `publishMany()` use `->onQueue('mail')` but don't chain `->tries()` or `->backoff()`. If the job class doesn't define these as properties, Laravel's default is 1 try with no backoff — a single Resend 500 would permanently drop the notification email. The job class file (`app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php`) is not in the audit scope, so this finding is a flag to verify, not a confirmed defect. At ~40K daily notifications, even a 1% email failure rate drops 400 emails/day silently.
    - **Plain English:** When the system sends notification emails, each one is a background task. If the email provider has a hiccup, the task should retry a few times with pauses in between. I can't see from this code whether the retry settings are configured on the task itself — they're not set at the point where the task is created. This is a "please check" flag: if the task doesn't have retry settings, a brief email-provider outage would permanently lose every notification email that was attempted during that window.
    - **Evidence:**
        ```php
        if ($inserted > 0 && config('partna.notifications.email_enabled', false)) {
            SendTransactionalNotificationEmailJob::dispatch(
                $notificationId,
                $category,
                $professionalId,
            )->onQueue('mail');
        }
        ```
    - `[DRAFT, confidence: 0.6]`

<!-- ═══ CHUNK: jobs ═══ -->

- [ ] **SCALE-1** · P1 — `VoidExpiredPayoutsJob::fireGraceWarnings` materialises unbounded `->get()` of all pending payouts in a 30-day window
    - **Where:** app/Jobs/Stripe/VoidExpiredPayoutsJob.php:149-159
    - **Affects:** Stripe payout grace-warning path; at 10K daily payouts a 30-day window can hold thousands of rows, all loaded into PHP memory before the tiered filter runs.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace `->get()` with `->chunkById(500)` and run the tiered-publish loop once per chunk.
        - Accumulate `grace_notifications_sent` writes inside each chunk's DB transaction (the current per-row `save()` inside the foreach is already safe; just move the chunk boundary up).
    - **Technical:** Laravel's `->get()` hydrates every matching Eloquent model into a Collection in a single allocation. The 30-day `whereBetween('void_at', ...)` window grows linearly with payout volume. At the scale target — ~10K daily payouts, many held in `pending` status while affiliates are nudged toward Stripe Connect — this query returns thousands of rows, each a fully-hydrated `CommissionPayout` model with casts and relations. The subsequent `foreach` tier-filter and `$publisher->publish()` loop then processes them all with a single memory footprint. `chunkById(500)` keeps PHP memory flat regardless of volume.
    - **Plain English:** Imagine opening a filing cabinet drawer and pulling out every single invoice from the last 30 days in one armful before sorting through them on your desk. As the business grows from 10 invoices a day to 3,000, that armful becomes a forklift. Chunking means you pull out a small stack at a time, process it, and put it back before grabbing the next — your desk never overflows.
    - **Evidence:**
        ```php
        $allCandidates = CommissionPayout::query()
            ->where('status', 'pending')
            ->whereBetween('void_at', [$windowStart, $windowEnd])
            ->where(function ($q) use ($brandSideCodes) {
                $q->whereIn('failure_code', $brandSideCodes)
                    ->orWhereDoesntHave('affiliateProfessional', fn ($a) => $a->where('stripe_connect_status', 'active'));
            })
            ->get();
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **SCALE-2** · P2 — `CheckStreamingLiveStatusJob` omits queue assignment — lands on `default` alongside web traffic and cache work
    - **Where:** app/Jobs/Streaming/CheckStreamingLiveStatusJob.php (no constructor or `onQueue` call)
    - **Affects:** All jobs on the `default` queue while a Twitch/Kick poll cycle runs (up to 90s timeout).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$this->onQueue('integrations');` in a constructor (or a dedicated `streaming` queue).
        - Set `$tries = 2` with a short backoff so a transient Twitch outage doesn't park the job on a retry chain.
    - **Technical:** Laravel queues default to `default` when no queue is specified. `CheckStreamingLiveStatusJob` polls external APIs — Twitch and Kick — with a 90s timeout. At scale, dozens of streaming blocks can exist across 200 brands, each triggering API round-trips. When this job occupies the `default` queue, it blocks cache-warming jobs (`WarmPublicSiteCacheJob`) and cache-invalidation jobs (`InvalidateBrandAffiliatesCacheJob`) that also land on `default`. A single slow poll cycle backs up unrelated work. The `integrations` queue already hosts Shopify, Cloudflare, and Fresha jobs — external-API work belongs there.
    - **Plain English:** This is like having a delivery driver who also answers the phone. When they're out on a 90-second delivery, nobody answers the phone. Moving the streaming checks to the same queue that handles Shopify and Cloudflare calls keeps the main phone line free.
    - **Evidence:**
        ```php
        class CheckStreamingLiveStatusJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 1;
            public int $backoff = 0;
            public int $timeout = 90;

            public function handle(LiveStatusPoller $poller): void
            {
                // no constructor, no onQueue() — falls to 'default'
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **SCALE-3** · P2 — `ProcessImageVariantsJob` loads full original image into PHP memory via `Storage::get()`
    - **Where:** app/Jobs/ProcessImageVariantsJob.php:150-154
    - **Affects:** Image processing workers. A single 20 MB brand-design photo costs 20 MB of PHP heap; 10 concurrent image jobs cost 200 MB.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `$disk->get()` + `file_put_contents()` with `$disk->readStream()` + `stream_copy_to_stream()`, matching the pattern already used in `ProcessVideoVariantsJob`.
    - **Technical:** `Storage::get()` reads the entire file into a string in PHP memory. For R2/S3-backed disks, this also means the full object passes through the HTTP response buffer before hitting `file_put_contents`. `ProcessVideoVariantsJob` already uses the correct pattern: `$stream = $disk->readStream($this->originalPath);` followed by `stream_copy_to_stream($stream, $dest)`. This streams chunks directly to the temp file without materialising the full file in PHP. At the scale target, brand design assets and product images can be multi-megabyte, and Horizon may run multiple image workers concurrently — memory pressure adds up.
    - **Plain English:** The video processing pipeline streams the file like a garden hose — bytes flow from storage to disk without filling a bucket first. The image pipeline fills the entire bucket before pouring it out. For a small glass of water that's fine. For a 20 MB firehose, the bucket overflows. Switch the image pipeline to use the same hose approach.
    - **Evidence:**
        ```php
        $content = $disk->get($this->originalPath);
        if (! file_put_contents($localTmp, $content)) {
            throw new \RuntimeException('Failed to write original to temp file.');
        }
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **SCALE-4** · P2 — `ReconcileStuckShopifyIntegrationsJob` fires sequential Shopify Admin API calls with no per-request delay
    - **Where:** app/Jobs/Shopify/ReconcileStuckShopifyIntegrationsJob.php:91-107
    - **Affects:** Shopify Admin API rate-limit budget. At the `BATCH_LIMIT` of 200, a single run can burn through 200 REST HEAD requests in a tight loop.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a 100–200ms `usleep` between iterations so the loop doesn't burst at wire speed.
        - Optionally check `X-Shopify-Shop-Api-Call-Limit` response headers and pause if the bucket is low.
    - **Technical:** Shopify's Admin REST API enforces a leaky-bucket rate limit (typically 40 requests/second per shop, but the reconcile job iterates across *different* shops, so the per-shop limit isn't the primary risk). The global app-level limit across all shops is higher but not infinite. A tight `foreach` loop issuing 200 HEAD requests sequentially with no artificial delay can saturate the local HTTP client connection pool and, in edge cases, trigger Shopify's abuse-detection heuristics. The job already has a wall-clock guard (80% of 600s timeout), but that only caps total runtime — it doesn't shape the request rate. A small `usleep(100000)` costs at most 20s across 200 iterations and keeps the request pattern friendly.
    - **Plain English:** This is like a telemarketer who dials 200 numbers in rapid succession without pausing between calls. Even though each call goes to a different person, the phone company notices the burst and may flag the caller. Adding a short breath between dials costs 20 seconds across all 200 calls but keeps the system happy.
    - **Evidence:**
        ```php
        foreach ($candidates as $integration) {
            if (microtime(true) - $start > $softDeadlineSeconds) {
                $deadlineReached = true;
                break;
            }

            $inspected++;
            $check = $this->validateAccessToken($integration);
            // no delay between validateAccessToken calls
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **SCALE-5** · P3 — `AggregateCacheMetricsJob::hGetAll` loads entire hourly Redis hash into PHP memory unboundedly
    - **Where:** app/Jobs/Cache/AggregateCacheMetricsJob.php:31
    - **Affects:** The hourly metrics aggregation job. Memory use scales with the number of cache prefixes across all tenants.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `hGetAll` with `hScan` iterating in small batches, or split the hash into per-prefix keys (`cache_metrics:{bucket}:{prefix}`) and scan keys instead of fields.
    - **Technical:** `Redis::hGetAll($bucketKey)` returns every field-value pair in the hash in a single Redis response. `RecordCacheMetrics` increments `HINCRBY` on fields like `site:hits`, `block:misses`, etc. At 200 brands × ~50 affiliates each, with multiple cache prefixes per site (`public_site_payload`, `brand_design`, `public_profile`, etc.), the per-hour hash could contain thousands of fields. While this is unlikely to exceed PHP's memory limit at current scale, the hash grows linearly with tenant count and the job has no bound on it. `hScan` with a small count per iteration keeps the Redis response size and PHP allocation constant.
    - **Plain English:** This job opens a drawer and counts every paper inside at once. Right now there are maybe 100 papers. But the drawer fills up as Partna adds more brands and affiliates. Switching to counting papers one handful at a time means the job runs the same speed whether there are 100 papers or 10,000.
    - **Evidence:**
        ```php
        $bucket = now('UTC')->subHour()->format('Y-m-d-H');
        $bucketKey = "cache_metrics:{$bucket}";

        $raw = Redis::hGetAll($bucketKey);
        ```
    - `[DRAFT, confidence: 0.75]`

<!-- ═══ CHUNK: ctrl-prof-a ═══ -->

- [ ] **SCALE-1** · P2 — ProfessionalGalleryController::index() returns an unpaginated list of all active gallery images
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalGalleryController.php:23-31
    - **Affects:** Any professional with a large gallery — response can grow to hundreds or thousands of images, causing memory pressure, slow JSON serialisation, and frontend render lag.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Paginate the gallery endpoint (`->paginate(50)`) or cap with a configurable limit.
        - Return a paginated resource that includes `current_page`, `last_page`, and `total`.
    - **Technical:** The `->get()` without any `limit` or pagination loads every active `SiteMedia` row for the site. At the scale target (200 brands × 50 affiliates) each professional could upload many images; even modest galleries of 200 images produce a large JSON payload that is serialised on every dashboard visit. Adding pagination keeps the response bounded and cacheable.
    - **Plain English:** Imagine every time you open your phone's photo gallery it tries to load every photo you've ever taken in one go. That works fine with 10 photos but gets painfully slow with 500. We need to show photos in pages, not all at once.
    - **Evidence:**
        ```php
        $images = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_GALLERY)
            ->where('is_active', true)
            ->with('mediaVariants')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();          // ← no pagination, unbounded
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **SCALE-2** · P2 — ProfessionalServiceController::index() returns an unpaginated list of all services
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalServiceController.php:47-64 (fallback path)
    - **Affects:** Professionals with many services — the dashboard services list can become slow to load, and the response payload can grow large.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Paginate the fallback query (the hot path is cached, but the cached query still materialises all rows).
        - Ensure the cache key used by `getDashboardServices` respects a sensible max-items limit or paginates.
    - **Technical:** The uncached path (when filters or grouping are applied) issues `Service::query()->… ->get()` with no `paginate()`. At the scale target a professional could have hundreds of services (especially with Sync-from-vendor flows). The hot path uses `getDashboardServices` which may also return an unbounded collection — fetch-and-cache of all rows puts memory pressure on Redis and the PHP process.
    - **Plain English:** Loading every product in your catalogue at once, even when you only want to see the first page, is like a restaurant bringing every dish from the kitchen when you only asked for the menu. We should deliver pages, not the whole catalogue.
    - **Evidence:**
        ```php
        $services = $servicesQuery->orderBy('sort_order')->orderBy('created_at')->get();
        // no paginate() — unbounded result set
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **SCALE-3** · P3 — ProfessionalServiceCategoryController::index() returns an unpaginated list of all categories
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalServiceCategoryController.php:17-29
    - **Affects:** Professionals with many service categories — the response can include dozens of categories, but typically the cardinality is low.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the query with `->paginate(50)` or add a `limit(100)` for safety.
    - **Technical:** A `->get()` without pagination is harmless today because most professionals have <20 categories. At the scale target (200 brands, each with potentially many custom categories from bulk imports) the number could grow, so a paginated response future-proofs the endpoint.
    - **Plain English:** This is like listing all folders in a filing cabinet at once. Most people have only a few folders, but as the cabinet grows, it's safer to show them page by page.
    - **Evidence:**
        ```php
        $categories = $q->orderBy('sort_order')->orderBy('created_at')->get();
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **SCALE-4** · P3 — BrandAffiliateController::snapshot() loads all CommissionPayouts for an affiliate without pagination
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateController.php:146-149
    - **Affects:** Brand dashboard affiliate detail modal — as an affiliate accumulates years of payouts, the response payload can grow to hundreds of rows.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->get()` with a limited query (`->latest()->take(100)`) or paginate the recent-payouts section.
        - The `recentPayouts` slice already limits to 5, but the full collection is still hydrated from the database.
    - **Technical:** The `CommissionPayout::query()->… ->get()` loads every payout for the brand-affiliate pair. At the scale target an affiliate active for years will have hundreds of payout rows. The frontend only displays the last 5, so loading all rows is wasted database, network, and memory work.
    - **Plain English:** It's like asking the bank for your last 5 transactions and they print out your entire history since you opened the account just to cross off all but the last 5. Wasteful — just ask for the recent ones directly.
    - **Evidence:**
        ```php
        $payouts = CommissionPayout::query()
            ->where('brand_professional_id', $brandId)
            ->where('affiliate_professional_id', $affiliateId)
            ->get();   // ← unbounded, then sliced in memory
        ```
    - `[DRAFT, confidence: 0.9]`

<!-- ═══ CHUNK: ctrl-prof-b-staff ═══ -->

- [ ] **SCALE-1** · P3 — `SquareIntegrationController::syncServicesNow` makes a synchronous, full-catalog Square API call on the request thread
    - **Where:** app/Http/Controllers/Api/Professional/SquareIntegration/SquareIntegrationController.php:241-267
    - **Affects:** Professional-initiated manual Square sync — the request thread holds a PHP-FPM worker for the duration of the Square round-trip.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the inline `$syncService->syncFromSquare($pro, fullSync: true)` call with a queued job (e.g., `SyncSquareCatalogDeltaJob` with `fullSync: true`) so the worker returns immediately.
        - Return a `202 Accepted` with a polling endpoint so the frontend can surface progress instead of blocking the user.
    - **Technical:** Category 3 — Connection pool & transaction scoping. The controller calls `$syncService->syncFromSquare($pro, fullSync: true)` inline, which performs a full Square API catalog pull synchronously. At the scale target (200 brands), a single brand with 500+ catalog items experiences a multi-second request that ties up one of the limited PHP-FPM workers. The endpoint is user-initiated and infrequent, but the blocking shape is unnecessary — the `connect()` method in the same controller already demonstrates the correct async pattern by dispatching `SyncSquareCatalogDeltaJob`. The fix is to unify the manual-refresh path onto the same async dispatch.
    - **Plain English:** Imagine a support hotline where the agent stays on the phone while a file downloads from a slow third-party server. The agent can't help anyone else during that time. Moving the download to a background job lets the agent hang up and call back when it's done. Same fix here — fire off the sync and let the user check back later instead of staring at a spinner.
    - **Evidence:**
        ```php
        // app/Http/Controllers/Api/Professional/SquareIntegration/SquareIntegrationController.php:256-266
        try {
            $stats = $syncService->syncFromSquare($pro, fullSync: true);
        } catch (SquareApiException $e) {
            [$message, $status] = $this->buildSquareErrorMessage($e);
            // ...
            return $this->error($message, $status);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **SCALE-2** · P3 — `FreshaIntegrationController::syncServicesNow` makes a synchronous, full-catalog Fresha API call on the request thread
    - **Where:** app/Http/Controllers/Api/Professional/FreshaIntegration/FreshaIntegrationController.php:229-248
    - **Affects:** Professional-initiated manual Fresha sync — same blocking shape as SCALE-1.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the inline `$syncService->syncFromFresha($pro, fullSync: true)` with a queued job (reuse `SyncFreshaCatalogDeltaJob` with `fullSync: true`).
        - Return `202 Accepted` with a polling mechanism consistent with the Square fix.
    - **Technical:** Category 3 — identical pattern to SCALE-1 but for Fresha. The `connect()` method already dispatches `SyncFreshaCatalogDeltaJob` async; `syncServicesNow` should do the same instead of blocking the request thread. Impact is low (infrequent manual action) but the inconsistency with the connect path is a maintenance smell.
    - **Plain English:** Same phone-support analogy — the agent waits on hold for Fresha's server instead of queuing the work and moving on to the next call.
    - **Evidence:**
        ```php
        // app/Http/Controllers/Api/Professional/FreshaIntegration/FreshaIntegrationController.php:240-245
        try {
            $stats = $syncService->syncFromFresha($pro, fullSync: true);
        } catch (FreshaApiException $e) {
            [$message, $status] = $this->buildFreshaErrorMessage($e);
            return $this->error($message, $status);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **SCALE-3** · P3 — `StaffShopifyEventReplayController::invoke` makes a synchronous Shopify REST call followed by a synchronous job dispatch on the request thread
    - **Where:** app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffShopifyEventReplayController.php:143-174
    - **Affects:** Staff replaying a single Shopify webhook event — worker held for Shopify round-trip + order-processing job.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Dispatch `ProcessShopifyOrderWebhookJob` asynchronously (`dispatch()` not `dispatchSync()`) and return `202 Accepted`.
        - Surface the existing `dispatchSync` dedup-safe design to the async path — the job's unique index on `shopify_event_id` already guarantees idempotency regardless of sync vs async dispatch.
    - **Technical:** Category 3 — the controller fetches the order from Shopify synchronously (`$this->shopifyClient->rest(...)`), then calls `ProcessShopifyOrderWebhookJob::dispatchSync(...)`. Together these hold a worker for Shopify's response time (500ms–5s) plus the job's DB writes. The endpoint is staff-only and rate-limited to 3/min per event, so blast radius is tiny. The deduplication guarantees (unique partial index on `shopify_event_id`, LWW upsert on `commerce.orders`) are connection-agnostic and work identically on async dispatch.
    - **Plain English:** A staff member clicks "replay webhook" and their browser tab hangs until Shopify responds AND the order finishes saving. Moving the save to a background job lets the staff member get immediate confirmation and move on.
    - **Evidence:**
        ```php
        // app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffShopifyEventReplayController.php:161-174
        ProcessShopifyOrderWebhookJob::dispatchSync(
            brandProfessionalId: (string) $professional->id,
            orderPayload: $orderPayload,
            shopifyEventId: $shopifyEventId,
            source: 'manual',
        );
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **SCALE-4** · P3 — CSV subscriber exports run unbounded cursor queries without a row limit
    - **Where:** app/Http/Controllers/Api/Professional/Notifications/ProfessionalEmailSubscriptionController.php:162-174 and app/Http/Controllers/Api/Staff/StaffSite/StaffEmailSubscriberController.php:274-286
    - **Affects:** Brand or staff exporting a subscriber list — a brand with 50K+ subscribers ties up a worker for the full export duration.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a reasonable `->limit(50000)` or `->take(config('partna.exports.subscribers.max_rows', 100000))` to the export query so a runaway list doesn't hold a worker indefinitely.
        - Consider dispatching large exports to a background job that emails a download link (matching the existing `CommissionExportService` pattern).
    - **Technical:** Category 2 — unbounded result sets. Both `export()` methods use `$query->cursor()` (good for memory) but the query has no `limit` or `take`. At the scale target, a heavily-marketed brand could accumulate 50K+ subscribers. `cursor()` streams rows one-by-one so memory is ~constant, but the PHP-FPM worker is occupied for the entire streaming duration, which could be 30–60s for a large list. This blocks other requests that share the worker pool. The fix is a configurable row cap or dispatching to a job for large lists.
    - **Plain English:** A brand with 50,000 email subscribers clicks "Export." Their browser starts downloading a CSV, and behind the scenes, one of the server's limited request-handling slots is tied up for a full minute streaming every row. Capping the export or moving it to a background job prevents one big download from hogging a slot other users need.
    - **Evidence:**
        ```php
        // app/Http/Controllers/Api/Professional/Notifications/ProfessionalEmailSubscriptionController.php:162-173
        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'full_name', 'status', 'subscribed_at', 'unsubscribed_at']);
            foreach ($query->cursor() as $row) {   // ← no ->limit()
                fputcsv($out, [...]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **SCALE-5** · P3 — `StaffServiceManagementController::reorderLayout` issues N+1 individual UPDATE statements inside a transaction with an advisory lock
    - **Where:** app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffServiceManagementController.php:199-234
    - **Affects:** Staff reordering a professional's service layout — for 100+ services the transaction holds locks across 100+ individual queries.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the per-row `update()` loop with a single bulk-upsert (e.g., a CTE or multi-row `UPDATE ... FROM (VALUES ...)` statement) so the DB executes one query instead of N.
        - As a lower-cost stopgap, ensure `statement_timeout` is set on the transaction so a stuck lock doesn't cascade.
    - **Technical:** Category 1 — N+1 pattern (write-side). The `reorderLayout` method opens a transaction with `pg_advisory_xact_lock`, then iterates `foreach ($payload['categories'] as $catBlock)` and `foreach ($catBlock['service_ids'] as $i => $serviceId)` issuing one `UPDATE` per category and one `UPDATE` per service. For a professional with 10 categories and 100 services, this is 110 individual `UPDATE` statements inside a single serialized transaction. Each query round-trips to Postgres, and the advisory lock blocks any other layout mutation for the same professional. Impact is bounded (staff-only, single-professional scope, max maybe 200-300 services), but the code shape is fragile if the per-professional cap ever rises.
    - **Plain English:** Rearranging someone's service menu fires off one database update per item — if they have 100 services, that's 100 separate round trips, all while holding an "under construction" sign that blocks anyone else from touching the same menu. Bundling all the updates into a single batch is both faster and shorter-holding.
    - **Evidence:**
        ```php
        // app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffServiceManagementController.php:219-232
        foreach ($payload['categories'] as $catBlock) {
            $catId = $catBlock['id'] ?? null;
            if ($catId !== null) {
                ServiceCategory::query()->where(...)->where('id', $catId)
                    ->update(['sort_order' => $categorySort++]);
            }
            foreach ($catBlock['service_ids'] as $i => $serviceId) {
                Service::query()->where(...)->where('id', $serviceId)
                    ->update(['category_id' => $catId, 'sort_order' => $i]);
            }
        }
        ```
    - `[DRAFT, confidence: 0.70]`

<!-- ═══ CHUNK: ctrl-public-internal ═══ -->

- [ ] **SCALE-1** · P2 — Initial Shopify provisioning jobs all fired onto the default queue  
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedSetupController.php (provisionShopifyIntegration, foreach dispatch block)  
    - **Affects:** Brand onboarding pipeline — 6 jobs per new brand, potentially 1200 jobs at once for a cohort of 200 brands.  
    - **Effort:** S (~0.5–1h)  
    - **What to do:**
        - Add `->onQueue('shopify')` to every `::dispatch()` inside the provisioning loop.
        - Ensure the `shopify` queue has its own supervisor with enough workers in `config/horizon.php` so it doesn't starve the default queue.
    - **Technical:** Without a queue assignment, each job (RegisterShopifyWebhooksJob, CreateStorefrontAccessTokenJob, …) lands on the `default` Redis queue. Six jobs × many concurrent brand provisions can temporarily choke the queue, delaying other critical jobs like payments and webhooks. A dedicated `shopify` queue isolates this burst.
    - **Plain English:** Think of the queue as a single checkout lane. Six heavyweight tasks are being pushed through for every new store that signs up. If lots of stores sign up at once, that lane gets blocked for everyone else. Giving these tasks their own lane keeps the main checkout moving.
    - **Evidence:**
        ```php
        foreach ($jobs as $jobClass) {
            try {
                $jobClass::dispatch((string) $integration->id);
            } catch (\Throwable $e) {
                Log::warning('Failed to dispatch embedded integration setup job', [...
            }
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **SCALE-2** · P2 — Square catalog webhook falls back to inline vendor sync when queue dispatch fails  
    - **Where:** app/Http/Controllers/Api/Webhooks/SquareCatalogWebhookController.php (__invoke, catch block after dispatch failure)  
    - **Affects:** Square webhook ingestion — under a queue outage (e.g. Redis down), every `catalog.version.updated` webhook blocks a worker for the duration of a Square API call instead of acknowledging fast.
    - **Effort:** M (~2–4h)  
    - **What to do:**
        - Remove the inline `syncFromSquare()` fallback; just log an error and return 200. Square will auto-retry the webhook when the queue recovers.
        - If absolutely necessary, fire a lightweight async job (e.g. a null-safe stub) and let the regular retry mechanism handle the rest.
    - **Technical:** When `SyncSquareCatalogDeltaJob::dispatch()` throws (e.g. Redis connection lost), the current code performs a synchronous Square catalog sync within the same HTTP request, holding the webhook worker open for several seconds. This creates a backpressure storm if many webhooks arrive during a queue degradation — the server threads become a bottleneck, amplifying the outage.
    - **Plain English:** Normally when a catalog update happens, we quickly acknowledge it and hand off the work to a background queue. But if that handoff fails (say, the queue is temporarily unavailable), the system tries to do the heavy work right there on the spot, making the person waiting (Square) wait. That clogs up the processing line and causes a pile-up. Instead, we should just say “got it, I’ll try again later” and rely on the built-in retry.
    - **Evidence:**
        ```php
        try {
            SyncSquareCatalogDeltaJob::dispatch($merchantId, null, false);

            return $this->success(['received' => true, 'queued' => true]);
        } catch (\Throwable $dispatchError) {
            // ... logs and then inline sync:
            $stats = $syncService->syncFromSquare($professional, fullSync: false);
            return $this->success([
                'received' => true,
                'queued' => false,
                'synced_inline' => true,
                ...
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **SCALE-3** · P2 — Bulk affiliate-product-selection purge job dispatched without a dedicated queue  
    - **Where:** app/Http/Controllers/Api/Webhooks/Shopify/ShopifyAppUninstalledWebhookController.php (after the DB transaction, `PurgeAffiliateProductSelectionsJob::dispatch`)  
    - **Affects:** Deletion of affiliate product selections after a brand uninstalls the Shopify app. One job per uninstall, but the job locks and deletes many rows.  
    - **Effort:** S (~0.5–1h)  
    - **What to do:**
        - Dispatch `PurgeAffiliateProductSelectionsJob` onto a `shopify` or `purging` queue: `->onQueue('shopify')`.  
        - Ensure that queue’s workers are configured so it won’t block other jobs if a large purge slows it down.
    - **Technical:** The webhook controller fires this job onto the default queue after a successful uninstall transaction. The job (from its own comments) chunks deletes to avoid long row-locks, but it can still be resource-heavy. Without isolation, a large purge could starve other critical jobs on the same queue. A dedicated queue keeps the impact contained.
    - **Plain English:** When a brand removes the app, a cleanup job is spawned to delete all their saved product preferences. This job is put into the same “fast lane” (the default queue) as payment confirmations and other time-sensitive tasks. If the cleanup is slow, it can hold up the entire lane. Moving it to its own lane prevents that traffic jam.
    - **Evidence:**
        ```php
        $result = DB::transaction(function () use ($shopDomain) { ... });
        // after commit
        PurgeAffiliateProductSelectionsJob::dispatch($result['professional_id']);
        ```
    - `[DRAFT, confidence: 0.8]`

<!-- ═══ CHUNK: http-boundary ═══ -->

No scaling findings detected in the provided file set. The Form Requests, Resources, and middleware under audit do not contain N+1 patterns, unbounded reads, connection-scoping issues, queue misconfiguration, vendor rate-limit bursts, scheduler stampedes, noisy-neighbour risks, migration hazards, backpressure problems, index hygiene issues, or memory-pressure patterns that would surface at the 200-brand / 1M-order/year target.

<!-- ═══ CHUNK: migrations ═══ -->

- [ ] **#SCALE-1** · P1 — Index creation on `site.site_media` (hot) without `CONCURRENTLY` in `20260411000000_add_custom_product_photos.sql`
    - **Where:** supabase/migrations/20260411000000_add_custom_product_photos.sql:10-12
    - **Affects:** All concurrent upload / media operations on `site_media`; migration blocks writes for the duration of the index build.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace with `CREATE INDEX CONCURRENTLY IF NOT EXISTS …` run outside a transaction block.
        - Ensure subsequent deploys use `CONCURRENTLY` for any `site_media` index creation.
    - **Technical:** `site_media` is a write-heavy table at scale (gallery uploads, variant rows). A plain `CREATE INDEX` acquires a `SHARE` lock on the table, preventing all concurrent `INSERT`/`UPDATE`/`DELETE`. Postgres must scan the entire table with that lock held; at hundreds of thousands of rows the write block lasts minutes, dropping upload requests.
    - **Plain English:** Building this index without the “non-blocking” switch is like closing the warehouse for an hour while you rearrange the shelves — every incoming delivery truck sits outside until you’re done.
    - **Evidence:**
        ```sql
        CREATE INDEX IF NOT EXISTS site_media_product_gid_idx
            ON site.site_media (site_id, product_gid)
            WHERE product_gid IS NOT NULL;
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#SCALE-2** · P1 — `site_media` unique indexes rebuilt without `CONCURRENTLY` in `20260414100000`
    - **Where:** supabase/migrations/20260414100000_site_media_design_pool.sql:70-76
    - **Affects:** All writes to `site_media` while the unique indexes are created — gallery, design, content, product uploads stall.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Convert each `CREATE UNIQUE INDEX` to `CREATE UNIQUE INDEX CONCURRENTLY` in a separate, non-transactional migration.
        - Consider batching `DROP INDEX` first (drops are quick) and then add new indexes with `CONCURRENTLY` later.
    - **Technical:** Unique index creation must verify uniqueness across every row, requiring a full-table scan and an `ACCESS EXCLUSIVE` lock on the table. Without `CONCURRENTLY`, the `CREATE UNIQUE INDEX` blocks all concurrent write activity on `site_media` — uploads, soft-deletes, reorders. At scale this can cause a backlog of file-processing jobs.
    - **Plain English:** This is like locking the building while you check every box for a duplicate label — nobody can bring in a new box until you’re finished, and the queue piles up outside.
    - **Evidence:**
        ```sql
        CREATE UNIQUE INDEX site_media_site_pool_sort_active_uq
            ON site.site_media (site_id, pool, sort_order)
            WHERE deleted_at IS NULL
              AND is_active = true
              AND pool IN ('gallery', 'content', 'product', 'brand_gallery');

        CREATE UNIQUE INDEX site_media_design_logo_uq
            ON site.site_media (site_id)
            WHERE pool = 'design'
              AND alt_text = 'logo'
              AND deleted_at IS NULL;
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#SCALE-3** · P1 — Index creation on `commerce.commission_ledger_entries` (hot) without `CONCURRENTLY` in `20260416000000`
    - **Where:** supabase/migrations/20260416000000_add_commission_grace_period.sql:33-41
    - **Affects:** All commission ledger writes (accruals, payouts, reversals) during index build — webhook ingress backs up.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace with `CREATE INDEX CONCURRENTLY` for both `idx_cle_voidable` and `idx_professionals_grace_period`.
    - **Technical:** `commission_ledger_entries` is the single highest-throughput commerce table; every Shopify webhook and payment sweep writes to it. Adding an index without `CONCURRENTLY` locks the table, halting those writes. At the scale target (1M orders/year, ~10k daily payout jobs) a multi-second index build translates to hundreds of stalled jobs.
    - **Plain English:** The cash-register drawer is jammed open while maintenance is being done — no transactions can go through until the drawer closes.
    - **Evidence:**
        ```sql
        CREATE INDEX IF NOT EXISTS idx_cle_voidable
            ON commerce.commission_ledger_entries (affiliate_professional_id, status, created_at)
            WHERE status = 'pending' AND payout_id IS NULL;

        CREATE INDEX IF NOT EXISTS idx_professionals_grace_period
            ON core.professionals (stripe_grace_period_ends_at)
            WHERE stripe_connect_status != 'active'
              AND stripe_grace_period_ends_at IS NOT NULL;
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#SCALE-4** · P1 — `CHECK` constraint added to `core.professionals` without `NOT VALID` in `20260421000000`
    - **Where:** supabase/migrations/20260421000000_add_about_to_professionals.sql:10-12
    - **Affects:** All professional create/update operations (bootstrap, onboarding, profile edits) during the constraint validation scan.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Use the two-step pattern: `ADD CONSTRAINT … NOT VALID`, then `VALIDATE CONSTRAINT` in a separate transaction.
        - Apply the same approach for any future `CHECK` on hot tables.
    - **Technical:** `ALTER TABLE … ADD CONSTRAINT CHECK (jsonb_typeof(about) = 'object')` immediately validates every existing row against the expression, acquiring an `ACCESS EXCLUSIVE` lock. While `core.professionals` holds only ~10k rows today, at 200 brands × 50 affiliates it will be 10x that — and every row is touched on every update. The lock blocks all professional mutations for the duration of the scan.
    - **Plain English:** Imagine closing every individual’s locker to check that a new safety label is already stuck on the inside — nobody can open a locker until the inspection finishes.
    - **Evidence:**
        ```sql
        ALTER TABLE core.professionals
            ADD CONSTRAINT professionals_about_is_object
            CHECK (jsonb_typeof(about) = 'object');
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#SCALE-5** · P1 — Index creation on `site.site_media` covering index rebuilt without `CONCURRENTLY` in `20260421010000`
    - **Where:** supabase/migrations/20260421010000_add_caption_to_site_media.sql:25-28
    - **Affects:** All media upload/update operations during the index rebuild — public site payload view also reads this index.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Perform the index swap with `DROP INDEX CONCURRENTLY` then `CREATE INDEX CONCURRENTLY` (non-transactional) to avoid blocking writes.
    - **Technical:** The migration drops the old covering index and creates a new one on `site_media`. The `CREATE INDEX` locks the table for a full scan; `site_media` sees frequent inserts from upload endpoints (`ProfessionalUploadController`) and variant jobs. Without `CONCURRENTLY`, every in-flight upload 500s during the index build.
    - **Plain English:** Replacing the warehouse’s loading-dock map requires shutting down the loading dock — every truck from that point waits until the new map is hung.
    - **Evidence:**
        ```sql
        DROP INDEX IF EXISTS site.site_media_site_active_sort_covering_idx;

        CREATE INDEX site_media_site_active_sort_covering_idx
            ON site.site_media (site_id, sort_order)
            INCLUDE (alt_text, caption, media_type, pool)
            WHERE deleted_at IS NULL AND is_active = true;
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#SCALE-6** · P1 — BRIN indexes created on analytics event tables without `CONCURRENTLY` in `20260506000000`
    - **Where:** supabase/migrations/20260506000000_create_orders_schema.sql:379-387
    - **Affects:** Ingestion of `site_visits`, `link_clicks`, and `cart_events` across all public storefronts — every page view, click, and cart add stalls.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Rebuild these three `CREATE INDEX` statements as `CREATE INDEX CONCURRENTLY` in separate non-transactional migration files.
    - **Technical:** `analytics.site_visits` will grow to ~90M rows at scale; `link_clicks` and `cart_events` similarly. Building a BRIN index still requires a full sequential scan of the table under a `SHARE` lock. Without `CONCURRENTLY`, the lock blocks every public-facing storefront event ingest — the entire analytics pipeline stalls until the index is built.
    - **Plain English:** The visitor counter freezes for several minutes while the database reorganises its filing cabinet — every new visitor, click, and cart add gets put on hold.
    - **Evidence:**
        ```sql
        CREATE INDEX IF NOT EXISTS idx_site_visits_occurred_brin
            ON analytics.site_visits USING BRIN(occurred_at)
            WITH (pages_per_range = 64);

        CREATE INDEX IF NOT EXISTS idx_link_clicks_occurred_brin
            ON analytics.link_clicks USING BRIN(occurred_at)
            WITH (pages_per_range = 64);

        CREATE INDEX IF NOT EXISTS idx_cart_events_occurred_brin
            ON analytics.cart_events USING BRIN(occurred_at)
            WITH (pages_per_range = 64);
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#SCALE-7** · P2 — Multiple index creations on commerce hot tables without `CONCURRENTLY` in later migrations
    - **Where:** 
        - supabase/migrations/20260420220000_add_analytics_ledger_occurred_at_indexes.sql
        - supabase/migrations/20260428000000_payout_grace_and_app_fee.sql
        - supabase/migrations/20260510000000_add_commission_payouts_lifecycle_columns.sql
        - supabase/migrations/20260513500000_add_payout_eligible_at_to_orders.sql
        - supabase/migrations/20260513700000_add_pi_and_status_columns_to_payouts.sql
    - **Affects:** Payout processing, webhook reconciler, and dashboard queries that touch `commission_ledger_entries`, `commission_payouts`, and `orders` during index builds.
    - **Effort:** M (~2–4h) — requires splitting each migration into `CONCURRENTLY` steps.
    - **What to do:**
        - For each index creation on a hot commerce table, adopt the `CONCURRENTLY` + no-transaction-wrapper pattern.
        - Audit pending deploy migrations with rules analogous to `supabase/migrations/CONVENTIONS.md` §6.
    - **Technical:** These tables are central to the payout pipeline (~10k daily payout jobs, ~3k daily Shopify webhooks). Each uncaring `CREATE INDEX` locks the target table, stalling the nightly batch, webhook handlers, and any concurrent Stripe status updates. The blast radius is smaller than `commission_ledger_entries` or `site_media`, but repeated across multiple migrations it accumulates to avoidable downtime.
    - **Plain English:** Every time you add a new filing tab without telling the office to keep working, the whole room stops for a few minutes. Multiply that by five tabs, and it adds up to a noticeable pause.
    - **Evidence:**
        ```sql
        -- Example from 20260420220000
        CREATE INDEX IF NOT EXISTS idx_cle_brand_occurred_at
            ON commerce.commission_ledger_entries (brand_professional_id, occurred_at);
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#SCALE-8** · P2 — Missing `lock_timeout` / `statement_timeout` on schema changes against hot tables
    - **Where:** Multiple early migration files (e.g. 20260411000000, 20260414100000, 20260416000000, 20260421010000, 20260506000000) — none set session-level timeouts.
    - **Affects:** Every ALTER TABLE / CREATE INDEX on a hot table risks an indefinite lock queue if a long-running transaction is open, blocking all subsequent writes until the queued migration completes or fails.
    - **Effort:** S (~0.5–1h) — add `SET LOCAL lock_timeout = '2s'` and `SET LOCAL statement_timeout = '30s'` to the top of each target migration.
    - **What to do:**
        - Prepend `SET LOCAL lock_timeout = '3s'; SET LOCAL statement_timeout = '60s';` (or comparable) before every `ALTER TABLE` / `CREATE INDEX` in future migrations.
        - Retroactively update existing migration templates; at minimum, enforce for all new files via a PR checklist.
    - **Technical:** Postgres waits indefinitely for locks by default. A single open transaction holding a `ROW EXCLUSIVE` lock (e.g. a long-running analytics aggregate) will cause `ALTER TABLE … ADD INDEX` to queue behind it, and all subsequent writes to that table queue behind the DDL. A lock timeout makes the migration fail fast instead of wedging the entire system.
    - **Plain English:** It’s like asking a technician to wait outside a room until the current meeting ends, without setting a timer — if the meeting runs 30 minutes, the technician (and everyone else with an appointment) just stands there.
    - **Evidence:**
        ```sql
        -- No lock_timeout or statement_timeout set
        BEGIN;
        ALTER TABLE brand.brand_profiles
            ADD COLUMN IF NOT EXISTS setup_complete boolean NOT NULL DEFAULT false;
        COMMIT;
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#SCALE-9** · P3 — Index creation on medium‑growth alias tables without `CONCURRENTLY` in `20260519100000`
    - **Where:** supabase/migrations/20260519100000_handle_alias_lifecycle.sql:142-149
    - **Affects:** Handle/subdomain alias lookups — currently low-volume table, but at 200 brands with frequent renames it could grow.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Use `CREATE INDEX CONCURRENTLY` for the two `expires_at` partial indexes on `professional_handle_aliases` and `site_subdomain_aliases`.
    - **Technical:** While these tables hold modest row counts today, a brand rename generates multiple alias rows; multiply by 200 brands and occasional subdomain changes, and the tables reach thousands of rows. The `CREATE INDEX` locks the table briefly; using `CONCURRENTLY` is a low-effort hardening that avoids a surprise write block if the table grows faster than anticipated.
    - **Plain English:** It’s a small filing cabinet today, but the same rule applies: if you ever need to add a new tab, you don’t want to lock everyone out while you do it.
    - **Evidence:**
        ```sql
        CREATE INDEX IF NOT EXISTS professional_handle_aliases_expires_at_idx
            ON site.professional_handle_aliases (expires_at)
            WHERE expires_at IS NOT NULL;
        ```
    - `[DRAFT, confidence: 0.70]`
