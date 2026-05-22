- [ ] **#WHK-1** · P0 — Refund webhook mutates `refund_cents` before checking idempotency, allowing double-add on concurrent replay
    - **Where:** app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php:244-270 (handleRefund)
    - **Affects:** All refunds/create Shopify webhooks; brand payout balances, clawback calculations, and commission ledger integrity. Every replayed refund silently double-adds to `refund_cents`.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Move `insertEventIfNew` BEFORE the `UPDATE refund_cents = refund_cents + ?` inside the same DB transaction, so the unique constraint on `shopify_event_id` gates the financial mutation atomically.
        - Alternatively, change the UPDATE to a cumulative recompute from `order_events` rather than an in-place increment, so replay is naturally idempotent.
    - **Technical:** The `DB::transaction` block at lines 249-271 executes the `refund_cents += ?` UPDATE plus the `CommissionPayoutRefundService::handleOrderRefund` call, then commits. The `insertEventIfNew` call at line 295 runs *outside* that transaction. Two concurrent workers processing the same `refunds/create` webhook both see the pre-increment `refund_cents`, both add `$refundSubtotal`, both commit, then only one succeeds on the `shopify_event_id` unique constraint. The financial damage is already committed. Canonical fix: wrap the update, refund service call, AND event insert in one transaction, or use a `SELECT ... FOR UPDATE` lock on the order row before mutating.
    - **Plain English:** Imagine two cashiers both counting the same stack of refund receipts at the same time — each adds the refund to the running total without checking whether the other already did. The register shows double the real refund amount. That's what happens when Shopify sends the same "refund created" message twice and two workers process it simultaneously.
    - **Evidence:**
        ```php
        // Lines 249-271: financial mutation commits inside transaction
        DB::transaction(function () use ($order, $refundSubtotal, $shopDomain, $shopifyOrderId, $refundId) {
            DB::connection('pgsql')->statement(
                'UPDATE commerce.orders
                SET refund_cents = refund_cents + ?,
                    status = CASE
                        WHEN (refund_cents + ?) >= gross_cents THEN ? ELSE ? END,
                    updated_at = ?
                WHERE shopify_shop_domain = ? AND shopify_order_id = ?',
                [$refundSubtotal, $refundSubtotal, 'refunded', 'partially_refunded', now()->toDateTimeString(), $shopDomain, $shopifyOrderId],
            );
            $order->refresh();
            if (in_array($order->status, ['refunded', 'partially_refunded'], true)) {
                app(CommissionPayoutRefundService::class)
                    ->handleOrderRefund($order, $refundSubtotal, $refundId !== '' ? $refundId : null);
            }
        });

        // Lines 293-295: idempotency check happens AFTER the mutation commits
        $this->insertEventIfNew($order->id, $refundEventType, $this->shopifyEventId, $metadata, $refundCreatedAt);
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#WHK-2** · P1 — Shopify order webhook job retry budget is ~100s vs Shopify's 48-hour retry window
    - **Where:** app/Jobs/Shopify/ProcessShopifyOrderWebhookJob.php:30-34
    - **Affects:** Every orders/paid webhook that hits a transient failure lasting >100s (DB pool exhaustion, Redis blip, Cloudflare timeout). After 3 local retries the job is permanently dead, but Shopify keeps retrying for 48 hours — all remaining retries are ignored because no handler exists.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Increase `$tries` to at least 10 and widen `$backoff` to cover hours — e.g. `[10, 30, 60, 120, 300, 600, 1800, 3600, 7200]`.
        - Add a dead-letter-queue recovery command that re-enqueues `failed()` jobs whose vendor retry window is still open, keyed on `shopify_event_id` so replay is safe.
    - **Technical:** Shopify's documented retry policy is 19 attempts over 48 hours. The job has `$tries = 3` with `$backoff = [10, 30, 60]` — a total retry span of ~100 seconds. After exhaustion, `failed()` fires a log line but does not re-queue. The controller (not shown) likely returned 200 on dispatch success, so Shopify sees a successful delivery and stops retrying. The order is permanently lost. This is a Category 8 mismatch against the gold standard's requirement that the job's retry budget respects the vendor's retry window.
    - **Plain English:** The courier will knock on your door 19 times over two days to deliver a package. But you've instructed your staff to stop answering after the third knock in the first two minutes. After that, the courier keeps knocking, but nobody comes to the door — and the courier eventually gives up, assuming nobody's home. The package is lost.
    - **Evidence:**
        ```php
        public int $tries = 3;

        public array $backoff = [10, 30, 60];

        public int $timeout = 30;
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#WHK-3** · P1 — Shopify order-updated webhook job has same ~100s retry budget mismatch
    - **Where:** app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php:28-32
    - **Affects:** Orders/updated, orders/edited, orders/cancelled, and refunds/create webhooks. Transient failures >100s silently drop cancellation and refund events, causing stale order status and incorrect commission accrual.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply the same `$tries`/`$backoff` increase as WHK-2.
        - Since this job handles non-idempotent financial mutations (refund_cents increment), ensure the dead-letter recovery path re-checks event idempotency before replay.
    - **Technical:** Identical Category 8 mismatch: `$tries = 3`, `$backoff = [10, 30, 60]`, `$timeout = 30`. Shopify retries these webhook topics on the same 19-attempt/48-hour schedule. A cancelled order that fails processing 3 times in 100 seconds will never be marked cancelled in our system, but Shopify stops retrying because our controller returned 200. The order stays in its prior state indefinitely.
    - **Plain English:** Same courier, same door, different package — this one contains a "cancel this order" notice. If the staff stops answering after 100 seconds, the cancellation never gets recorded, and the order looks live forever.
    - **Evidence:**
        ```php
        public int $tries = 3;

        public array $backoff = [10, 30, 60];

        public int $timeout = 30;
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#WHK-4** · P2 — Raw Shopify payload is not archived; only PII-stripped snapshots stored
    - **Where:** app/Jobs/Shopify/ProcessShopifyOrderWebhookJob.php:302-316 (buildSafeShopifyData) and app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php:458-483 (buildSafeShopifyData)
    - **Affects:** Forensic replay and debugging of any Shopify webhook processing incident. If a bug causes incorrect commission calculation, the raw payload that would let ops reproduce it is gone — only the stripped version survives.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `raw_payload` JSONB column to `commerce.order_events` and store `$this->orderPayload` verbatim when inserting the event row.
        - Continue storing the PII-stripped `shopify_data` on the `orders` table for operational queries — but the events table must carry the unredacted original as the authoritative event log.
    - **Technical:** Gold standard Category 9 requires that the full vendor payload is stored verbatim in the events table for replay and forensic use. Both `ProcessShopifyOrderWebhookJob` and `ProcessShopifyOrderUpdatedWebhookJob` call `buildSafeShopifyData()` which strips customer PII (name, email, phone, addresses) before persisting. The `order_events.metadata` column stores only summary fields (`shopify_order_id`, `financial_status`, `refund_id`, `refund_subtotal_cents`). The raw `$this->orderPayload` array is available at job construction time but never written to any table.
    - **Plain English:** You keep the receipt summary but throw away the original receipt. If there's a dispute about what was actually purchased, you can't go back to the source — you can only look at your own summary, which might have a mistake in it. Keeping the original lets you replay and verify.
    - **Evidence:**
        ```php
        // ProcessShopifyOrderWebhookJob.php:148-149 — only safe snapshot stored
        $shopifyData = $this->buildSafeShopifyData($this->orderPayload);

        // buildSafeShopifyData returns PII-free subset only
        private function buildSafeShopifyData(array $payload): array
        {
            $safe = [
                'id' => Arr::get($payload, 'id'),
                'name' => Arr::get($payload, 'name'),
                'financial_status' => Arr::get($payload, 'financial_status'),
                // ... no customer, billing_address, shipping_address, note fields
            ];
            return array_filter($safe, fn ($v) => $v !== null);
        }

        // insertOrderEvent stores only summary metadata, not the raw payload
        private function insertOrderEvent(...): void
        {
            (new OrderEvent)->forceFill([
                // ...
                'metadata' => $metadata,  // only ['shopify_order_id' => ..., 'financial_status' => ...]
            ])->save();
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#WHK-5** · P2 — Synchronous Shopify Admin API call inside order webhook job with 30s timeout risks lost orders on API degradation
    - **Where:** app/Jobs/Shopify/ProcessShopifyOrderWebhookJob.php:130-133
    - **Affects:** Orders/paid webhook processing when Shopify Admin API is slow. If `fetchCommissionOverridesForProducts` hangs or times out, the entire order is dropped after 3 retries — even though the order itself could have been persisted without metafield overrides.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Fall back to brand/platform default commission rates when the metafield fetch fails, rather than throwing and failing the entire job. Log a warning so ops can detect persistent metafield API issues.
        - Alternatively, increase `$timeout` to at least 60s and add a per-call HTTP timeout via `ShopifyAdminClient` that returns an empty override map on timeout rather than throwing.
    - **Technical:** The `$timeout = 30` applies to the entire job. The `fetchCommissionOverridesForProducts` call is a synchronous HTTP round-trip to the Shopify Admin API. If this call takes >15s (network degradation, Shopify rate-limiting), there's very little headroom for the remaining work (upsert, event insert, cache invalidation, contact capture). The call is not wrapped in a try/catch, so any `ShopifyTransportException` or timeout propagates to fail the job. The order itself was valid — the metafield overrides are a nice-to-have optimisation for per-product commission rates, not a prerequisite for writing the order row.
    - **Plain English:** Before writing down an order, the system calls the supplier to ask "do you have any special pricing for these items?" If the supplier doesn't pick up the phone, the entire order gets thrown away — even though the shelf price would have been fine. A better approach: use the shelf price if the supplier is busy, and note that they couldn't be reached.
    - **Evidence:**
        ```php
        // Line 130-133: synchronous Shopify API call, no try/catch
        $overrideMap = ($integration && ! empty($productGids))
            ? $catalogService->fetchCommissionOverridesForProducts($integration, $productGids)
            : [];

        // Lines 38-40: 30s timeout for entire job including this API call
        public int $tries = 3;
        public array $backoff = [10, 30, 60];
        public int $timeout = 30;
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#WHK-6** · P2 — Shopify order-updated job `failed()` does not clear `shopify_event_id` or re-enqueue, so retries across the 48h window are silently dropped
    - **Where:** app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php:53-65 (failed method) and the 3-retry cap
    - **Affects:** orders/cancelled and refunds/create webhooks specifically — these carry state transitions that cannot be derived from other events. A lost cancellation means the order stays "approved" permanently.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `failed()`, re-dispatch a fresh copy of the job with the same parameters if the vendor retry window is still open (e.g. `now()->diffInHours($occurredAt) < 48`). The idempotency guards in the job (LWW WHERE, `insertEventIfNew`) make re-dispatch safe.
        - Accept and explicitly document that after 48h, the webhook is genuinely dead and needs manual reconciliation.
    - **Technical:** The `failed()` method at line 53 only calls `report($e)` and logs — it does not re-queue. Combined with `$tries = 3`, this means after ~100s of local retries, the webhook is permanently dead even though Shopify will continue retrying for ~47.5 more hours. This is distinct from WHK-2/3 because `ProcessShopifyOrderUpdatedWebhookJob` handles destructive state transitions (cancelled, refunded) that have no compensating event — unlike orders/paid which can be recovered from a reconciler scan, a missed cancellation cannot.
    - **Plain English:** The cancellation notice arrives, the staff tries to process it three times in two minutes, then throws it in the trash. The sender keeps faxing the cancellation for two more days, but the fax machine is unplugged. The order stays "active" forever.
    - **Evidence:**
        ```php
        public function failed(\Throwable $e): void
        {
            report($e);

            $shopifyOrderId = (string) (Arr::get($this->payload, 'id') ?? Arr::get($this->payload, 'order_id', ''));

            Log::error('ProcessShopifyOrderUpdatedWebhookJob exhausted all retries', [
                'professional_id' => $this->professionalId,
                'topic' => $this->topic,
                'shopify_order_id' => $shopifyOrderId,
                'error' => $e->getMessage(),
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#WHK-7** · P3 — `ProcessShopifyOrderWebhookJob::handle` slow-path warning at 15s is too close to the 30s timeout
    - **Where:** app/Jobs/Shopify/ProcessShopifyOrderWebhookJob.php:44-53
    - **Affects:** Operations visibility — by the time the warning fires at 15s, only 15s remain before Horizon SIGKILLs the job. Response time is minimal.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Lower the warning threshold to 10s (33% of timeout) or add a second warning at 20s with "critical" severity.
        - Extract the synchronous `fetchCommissionOverridesForProducts` call to a pre-flight step with its own timeout measurement, so the alert distinguishes "Shopify API slow" from "DB write slow."
    - **Technical:** The `handle()` method wraps `process()` and logs a warning at >15s. But `$timeout = 30`, so only 15s remain for the job to finish after the warning fires — often too late for an ops response. The gold standard's observability expectation is that slow-path alerts give enough runway for intervention before the hard timeout kills the worker.
    - **Plain English:** The fire alarm goes off when the kitchen is already full of smoke. You want the alarm to go off when the toast starts burning, not when the flames reach the ceiling.
    - **Evidence:**
        ```php
        $durationMs = (int) round((microtime(true) - $start) * 1000);
        if ($durationMs > 15_000) {
            Log::warning('ProcessShopifyOrderWebhookJob slow processing', [
                'order_id' => (string) Arr::get($this->orderPayload, 'id', ''),
                'brand_professional_id' => $this->brandProfessionalId,
                'duration_ms' => $durationMs,
                'attempt' => $this->attempts(),
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.65]`
