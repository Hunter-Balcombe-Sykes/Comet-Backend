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
