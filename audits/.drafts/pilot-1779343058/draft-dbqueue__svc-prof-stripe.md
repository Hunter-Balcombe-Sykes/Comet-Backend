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
