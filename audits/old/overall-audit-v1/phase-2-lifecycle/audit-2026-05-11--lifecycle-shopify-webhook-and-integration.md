`★ Insight ─────────────────────────────────────`
The `ReconcileShopifyOrders` Artisan command exists and IS scheduled (daily at 03:00 UTC, env-overridable). DeepSeek's LIFE-2 is a hallucinated finding — drop it. However, reading the command exposes a real finding DeepSeek missed: the reconciler fetches with `status=any` but no `financial_status=paid` filter, meaning refunded orders could be re-processed and have their `status` reset to `'approved'` by the upsert's hardcoded `status = 'approved'` clause.
`─────────────────────────────────────────────────`

# Lifecycle Audit — 2026-05-11

**Branch:** development
**Lens:** Lifecycle correctness: race-safety, idempotency, anchor decoupling, reconcile loops, vendor resilience, observability discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php
- app/Jobs/Shopify/ProcessShopifyOrderWebhookJob.php
- app/Jobs/Shopify/ProcessShopifyShopUpdateJob.php
- app/Jobs/Shopify/RegisterShopifyWebhooksJob.php
- app/Jobs/Shopify/BackfillBrandHasEnabledVariantsJob.php
- app/Jobs/Shopify/CreateShopifyAffiliateDiscountJob.php
- app/Jobs/Shopify/CreateShopifyCollectionsJob.php
- app/Jobs/Shopify/CreateShopifyMetafieldsJob.php
- app/Jobs/Shopify/CreateShopifySalesChannelJob.php
- app/Jobs/Shopify/CreateStorefrontAccessTokenJob.php
- app/Jobs/Shopify/SetShopifySetupCompleteJob.php
- app/Jobs/Shopify/SyncShopifyBrandDesignJob.php
- app/Jobs/Shopify/Gdpr/ExportCustomerDataJob.php
- app/Jobs/Shopify/Gdpr/RedactCustomerJob.php
- app/Jobs/Shopify/Gdpr/RedactShopJob.php
- app/Console/Commands/ReconcileShopifyOrders.php
- app/Services/Shopify/BrandDesignImporter.php
- app/Services/Shopify/BrandSignupService.php
- app/Services/Shopify/ShopifyDataResyncService.php
- app/Services/Shopify/ShopifySetupTokenService.php
- app/Services/Shopify/ShopifyShopResolver.php
- app/Services/Shopify/ShopifyTeardownService.php
- app/Services/Shopify/ShopProfileAutoFillService.php
- app/Services/Shopify/Client/ShopifyAdminClient.php
- app/Services/Shopify/Client/ShopifyBudgetTracker.php
- app/Services/Shopify/Client/ShopifyBulkOperationLock.php
- app/Services/Shopify/Client/ShopifyCostTracker.php
- app/Services/Shopify/Client/ShopifyMetrics.php
- app/Http/Controllers/Api/Webhooks/FreshaCatalogWebhookController.php
- app/Http/Controllers/Api/Webhooks/SquareCatalogWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyAppUninstalledWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyGdprWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyOrdersCancelledWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyOrdersEditedWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyOrdersUpdatedWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyOrderWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyRefundsCreateWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyShopUpdateWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyThemePublishedWebhookController.php
- app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php
- app/Http/Controllers/Api/Webhooks/StripeWebhookController.php
- app/Http/Controllers/Api/Shopify/ShopifyAppOAuthController.php
- app/Http/Controllers/Api/Professional/ShopifyIntegration/ShopifyIntegrationController.php
- routes/console.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 4 complete

---

## P1 — Fix before pilot launch

- [ ] **#LIFE-1** · P1 — `handleRefund` increments `refund_cents` before idempotency check, double-counting on webhook retry
    - **Where:** app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php — `handleRefund()` method
    - **Affects:** `commerce.orders.refund_cents` for every brand's refund analytics. A duplicate `refunds/create` webhook delivery silently inflates the refund counter, corrupting commission rollup and payout calculations for the affected order.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Gate the `refund_cents` UPDATE inside a check that the event was actually new. Either: (a) execute `insertEventIfNew` first and only proceed with the UPDATE when it doesn't catch a `UniqueConstraintViolationException`, or (b) rewrite the refund accumulation as an idempotent absolute sum: `SET refund_cents = (SELECT COALESCE(SUM(refund_subtotal_cents), 0) FROM commerce.order_events WHERE order_id = ? AND event_type IN ('refunded','partially_refunded'))` keyed from already-persisted events.
        - The canonical pattern from `#STRIPE-3` applies here: the idempotency guard (event insert) must run **before** any side-effect (balance mutation).
    - **Technical:** The `refunds/create` path in `handleRefund()` executes a raw `UPDATE commerce.orders SET refund_cents = refund_cents + ?` unconditionally for existing orders. Immediately after, `insertEventIfNew` catches `UniqueConstraintViolationException` to provide idempotency for the event row — but the cumulative-add has already fired. Shopify documents at-least-once delivery: duplicate `refunds/create` events are a normal production scenario. At 1M orders/year with a ~5% refund rate and even a 0.1% duplicate-delivery rate, this produces ~500 silently inflated refund_cents values per year. The `orders/updated` LWW path in `snapshotUpdate` does write `refund_cents` as an absolute value from the payload, but it only fires when a subsequent `orders/updated` event arrives with a newer `shopify_updated_at` — not guaranteed. The canonical fix is check-then-mutate, matching `#STRIPE-3` (`35c6f31`).
    - **Plain English:** Imagine a store's return counter: the cashier's job is to (1) log the return in a ledger, then (2) hand back the money. This code hands back the money first and logs it second. If the same return slip arrives twice (which the payment system explicitly warns can happen), the cashier refunds twice before checking the ledger. Moving the ledger stamp to step 1 means any duplicate slip gets caught at the desk before any money changes hands.
    - **Evidence:**
        ```php
        // handleRefund() — the UPDATE fires unconditionally for existing orders:
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

        // ... derive $refundEventType, build $metadata ...

        // Idempotency check happens AFTER the side-effect — too late on retry:
        $this->insertEventIfNew($order->id, $refundEventType, $this->shopifyEventId, $metadata, $refundCreatedAt);
        ```

---

## P2 — Should fix

- [ ] **#LIFE-2** · P2 — `ReconcileShopifyOrders` lacks `financial_status=paid` filter, reconciler can reset `refunded` orders to `approved`
    - **Where:** app/Console/Commands/ReconcileShopifyOrders.php — `reconcileIntegration()` method
    - **Affects:** Order status integrity for any order that was refunded while a `refunds/create` webhook was missed. The reconciler would fetch the refunded order (with a newer `shopify_updated_at`), pass it to `ProcessShopifyOrderWebhookJob`, and the upsert's hardcoded `status = 'approved'` would overwrite the `refunded`/`partially_refunded` status.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'financial_status' => 'paid'` to the Shopify REST query params in `reconcileIntegration()`. This restricts the reconciler to its intended purpose — backstopping missed `orders/paid` events — and excludes refunded/voided/pending orders from reprocessing.
        - Separately verify that `ProcessShopifyOrderWebhookJob::upsertOrder()` should preserve `status` from the existing row rather than hardcoding `'approved'` in the `DO UPDATE SET` clause; for now the filter fix is sufficient.
    - **Technical:** The reconciler was designed as a backstop for missed `orders/paid` webhooks (Phase 3 comment, `routes/console.php` line 163). It fetches with `status=any` (open/closed/cancelled) but no `financial_status` filter. `ProcessShopifyOrderWebhookJob::upsertOrder()` hardcodes `status = 'approved'` in both the INSERT and the `DO UPDATE SET`. If a refund webhook was missed, the refunded order will have an older `shopify_updated_at` in the DB than Shopify reports (the refund updated it); the LWW guard (`WHERE EXCLUDED.shopify_updated_at > commerce.orders.shopify_updated_at`) passes, and the upsert resets `status = 'approved'` and `shopify_updated_at` to the current value — permanently obscuring the refund until the next `orders/updated` corrects it. Adding `financial_status=paid` excludes `refunded`, `partially_refunded`, `voided`, `pending`, and `authorized` orders from reconcile scope, matching the original webhook trigger the reconciler replaces.
    - **Plain English:** The daily catch-up sweep was built to find orders the system missed hearing about. But it's currently set to look at ALL orders — including returns and unpaid baskets. When it finds a returned order and re-processes it, it marks it as fully paid again, erasing the return record until something else corrects it. Adding a single filter — "only look at paid orders" — keeps the sweep focused on what it was built for.
    - **Evidence:**
        ```php
        // ReconcileShopifyOrders::reconcileIntegration() — no financial_status filter:
        $params = [
            'limit' => 250,
            'updated_at_min' => $since->toIso8601String(),
            'status' => 'any',
        ];

        // ProcessShopifyOrderWebhookJob::upsertOrder() — hardcoded in DO UPDATE:
        // ON CONFLICT (...) DO UPDATE SET
        //     status = 'approved',  ← overwrites 'refunded' / 'partially_refunded'
        //     ...
        // WHERE EXCLUDED.shopify_updated_at > commerce.orders.shopify_updated_at
        ```

- [ ] **#LIFE-3** · P2 — `ShopifyIntegrationController` uses inline `Validator::make` across all seven actions instead of Form Request classes
    - **Where:** app/Http/Controllers/Api/Professional/ShopifyIntegration/ShopifyIntegrationController.php — `status()`, `connect()`, `disconnect()`, `token()`, `registerWebhooks()`, `retrySetup()`, `resolveShop()`
    - **Affects:** Validation surface consistency. The inline pattern bypasses the authorization-before-validation ordering that Form Requests enforce and makes validation rules harder to test independently.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract each action's rules into a dedicated Form Request class (e.g. `ConnectShopifyIntegrationRequest`, `DisconnectShopifyIntegrationRequest`) under `app/Http/Requests/Api/Professional/ShopifyIntegration/`.
        - Type-hint the Form Request in the controller method signature; the `authorize()` method in each request should call through to `resolveTargetBrandProfessionalId`.
        - This mirrors the pattern from `a11feb2` (Stripe controller refactor). Glob of `app/Http/Requests/` confirms no ShopifyIntegration Form Requests exist.
    - **Technical:** Commit `a11feb2` ("refactor(stripe): extract remaining inline validates to Form Request classes") established Form Requests as the canonical Partna pattern and was specifically motivated by the same inline `Validator::make` antipattern. `ShopifyIntegrationController` is the only remaining financial/settings-surface controller still using inline validation. The risk is subtle: `Validator::make($request->all(), ...)` runs validation before authorization in the controller body; a Form Request's `authorize()` runs before `rules()`, ensuring that validation error details are never returned to a caller who doesn't have access.
    - **Plain English:** Every other important part of the API uses a printed order form — a standard checklist that runs access checks before it asks for your details. The Shopify settings controller is still using handwritten notes. Moving to the printed form keeps the process consistent, makes it easier to test in isolation, and ensures we verify who you are before we tell you what fields you got wrong.
    - **Evidence:**
        ```php
        // status() action — representative of all seven:
        $validator = Validator::make($request->query(), [
            'brand_professional_id' => ['sometimes', 'uuid'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
        ```

- [ ] **#LIFE-4** · P2 — Shopify webhook jobs omit `shopify_event_id` from success-path log context, breaking Nightwatch end-to-end tracing
    - **Where:** app/Jobs/Shopify/ProcessShopifyOrderWebhookJob.php — `process()` final `Log::info`; app/Jobs/Shopify/ProcessShopifyOrderUpdatedWebhookJob.php — all `Log::info`/`Log::warning` calls in `handleUpdated`, `handleEdited`, `handleCancelled`, `handleRefund`
    - **Affects:** Incident response. During a webhook storm or a data discrepancy investigation, operators cannot follow a single delivery from controller receipt (`X-Shopify-Event-Id` header) through to the completed job log line — the correlation key is absent on the success path.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'shopify_event_id' => $this->shopifyEventId` (or `$this->shopifyEventId !== '' ? $this->shopifyEventId : null`) to every `Log::info` and `Log::warning` call in both jobs.
        - The failure logs already include `shopify_event_id` (the `failed()` handler sets a good example); replicate the same key on the success paths.
        - The canonical pattern is `Log-with-context` (`35c6f31`): `brand_professional_id` + operation discriminator + the vendor correlation key on every log line.
    - **Technical:** `ProcessShopifyOrderWebhookJob::failed()` correctly logs `'shopify_event_id' => $this->shopifyEventId`, establishing that the field is available on the job. The success-path `Log::info` at the bottom of `process()` omits it. Nightwatch groups events by structured context keys; without `shopify_event_id`, a webhook delivery that takes the slow path (metafield fetch, LWW conflict) cannot be correlated to the inbound controller log that received the same `X-Shopify-Event-Id` header. At ~40K daily webhook deliveries across 200 brands, losing the correlation key means post-incident replay requires cross-referencing Redis dedup keys and DB rows manually.
    - **Plain English:** Every delivery truck has a tracking number on the outside, and every warehouse receipt has it written down inside when the package arrives. When a package goes missing, you match the two. Right now, the warehouse staff write the tracking number on the failed-delivery slips but not on the successful ones — so if something quietly goes wrong after a successful receipt, there's no number to look up.
    - **Evidence:**
        ```php
        // ProcessShopifyOrderWebhookJob — success log missing shopify_event_id:
        Log::info('ProcessShopifyOrderWebhookJob: processed', [
            'order_id' => $orderId,
            'brand_professional_id' => $this->brandProfessionalId,
            'affiliate_id' => (string) $affiliate->id,
            'commission_cents' => $totalCommissionCents,
            // shopify_event_id absent — present on failed() but not here
        ]);

        // failed() — correctly includes it:
        Log::error('ProcessShopifyOrderWebhookJob exhausted all retries', [
            'brand_professional_id' => $this->brandProfessionalId,
            'shopify_event_id' => $this->shopifyEventId,
            'shopify_order_id' => (string) Arr::get($this->orderPayload, 'id', ''),
            'error' => $e->getMessage(),
        ]);
        ```

- [ ] **#LIFE-5** · P2 — Fresha and Square webhook HMAC validation are unverified placeholder stubs; correct algorithm unknown
    - **Where:** app/Http/Controllers/Api/Webhooks/FreshaCatalogWebhookController.php — `isValidSignature()`; app/Http/Controllers/Api/Webhooks/SquareCatalogWebhookController.php — `isValidSignature()`
    - **Affects:** If either feature flag is enabled (`partna.features.fresha_sync`, `partna.features.square_sync`), a third party could inject forged catalog-sync or authorization-revocation events. The Fresha implementation explicitly copies Square's algorithm; Fresha's actual signature mechanism is undocumented in the codebase.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Consult Fresha's webhook documentation and implement the correct signature verification (header name, algorithm, and signing payload). Remove the `NOTE: Update this method` comments once the correct algorithm is confirmed.
        - For Square: the `candidateUrls` loop that guesses multiple URL variants is a code smell — Square's docs specify the exact notification URL that must be used; pin it to `config('services.square.webhook_notification_url')` and fail-closed when the config is absent.
        - Given that both booking integrations are dropped from the roadmap (per `project_booking_dropped.md`), the safest short-term fix is to delete both controllers and their routes, eliminating the attack surface entirely.
    - **Technical:** Category 6 (vendor-integration hygiene). Both controllers contain developer `NOTE:` comments acknowledging the algorithm is unverified. The Fresha controller explicitly states it "mirrors the Square HMAC-SHA256 pattern" — which is Fresha's undocumented guess, not Fresha's spec. Both controllers are guarded by feature flags that default to `false`, which reduces immediate risk. The Square implementation tries six candidate URL variants per delivery, which is another signal the correct URL is unknown at dev time. Per the `verbatim vendor error capture` pattern, vendor authentication mechanisms must be implemented from vendor-published specs, not inferred from structural similarities with other vendors.
    - **Plain English:** The side entrance to the building has a keypad lock, but nobody knows what the correct code is — so someone guessed and posted a sticky note saying "try the same code as the front door." Until we look up the real code from the building manual (or remove the side entrance entirely since we decided not to use it), anyone who knows enough to guess might get in.
    - **Evidence:**
        ```php
        // FreshaCatalogWebhookController::isValidSignature():
        /**
         * Validate the webhook signature from Fresha.
         *
         * NOTE: Update this method based on Fresha's actual webhook signature mechanism.
         * Currently mirrors the Square HMAC-SHA256 pattern.
         */
        $expectedSignature = base64_encode(
            hash_hmac('sha256', $notificationUrl.$rawBody, $signatureKey, true)
        );
        return hash_equals($expectedSignature, $signature);

        // SquareCatalogWebhookController::isValidSignature():
        // NOTE: Update this hashing logic based on actual Square docs.
        // This mirrors Square's approach: HMAC-SHA256 of (notification_url + raw_body) with the signature key.
        foreach ($candidateUrls as $notificationUrl) {
            $expected = base64_encode(hash_hmac('sha256', $notificationUrl.$body, $key, true));
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }
        ```
