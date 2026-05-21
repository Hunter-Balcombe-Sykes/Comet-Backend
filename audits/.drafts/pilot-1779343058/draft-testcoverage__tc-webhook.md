- [ ] **#TEST-1** · P0 — StripePlatformWebhookController has zero tests; handles payment_intent lifecycle + charge refunds + disputes
    - **Where:** app/Http/Controllers/Api/Webhooks/Stripe/StripePlatformWebhookController.php (entire class)
    - **Affects:** Affiliate payouts — `payment_intent.succeeded` marks payouts completed, `payment_intent.payment_failed` fails them, `charge.refunded` persists Stripe's authoritative fee/transfer reversal values, `charge.dispute.created` flags for ops. Every one of these mutates real money state with no test safety net.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Add `tests/Feature/Webhooks/Stripe/StripePlatformWebhookControllerTest.php` with at least: signature pass/fail, malformed payload, same event_id twice dedup, `payment_intent.succeeded` happy path (payout → completed) + payout-not-found path, `payment_intent.payment_failed` happy path, `charge.refunded` clawback-reconciliation path, `charge.dispute.created` flagging path.
        - Add a v2 thin-endpoint test covering `v2.core.account.updated` dispatch to `SyncStripeAccountStatusJob` and `v2.core.account.closed` nulling connect state.
    - **Technical:** The controller's `__invoke()` and `thin()` handlers both use `dedupeOrAck` from `ValidatesStripeWebhookPayload`, then dispatch to `CommissionPayoutService::markPaymentIntentSucceeded` / `markPaymentIntentFailed`, `CommissionPayoutRefundService` (via charge.refunded), and manual dispute flagging. The Stripe billing controller (`StripeWebhookControllerEndToEndTest`) already demonstrates the pattern — same shape of tests is needed here. Without coverage, a refactor that breaks payout completion (e.g., metadata key rename from `sidest_payout_id`) ships silently.
    - **Plain English:** This controller is the cash register for affiliate payouts — it receives Stripe's confirmation that money has been collected from the brand's card and marks the payout as "done" or "failed." It also handles refunds and disputes. There are zero tests for any of these paths. If a developer changes a single metadata key name, every payout can silently stop completing and no alarm goes off until affiliates notice they aren't being paid.
    - **Evidence:**
        ```php
        // app/Http/Controllers/Api/Webhooks/Stripe/StripePlatformWebhookController.php
        // No corresponding test file exists in tests/Feature/Webhooks/Stripe/
        // for this controller. The billing controller (StripeWebhookController) has
        // StripeWebhookControllerEndToEndTest.php; the connect controller has
        // StripeConnectWebhookControllerEndToEndTest.php. The platform controller has none.
        public function __invoke(Request $request): JsonResponse
        {
            $event = $this->constructStripeEvent(
                $request,
                (string) config('services.stripe.platform_webhook_secret'),
            );
            // ...
            match ($event->type) {
                'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event->data->object),
                'payment_intent.payment_failed' => $this->handlePaymentIntentFailed($event->data->object),
                'charge.refunded' => $this->handleChargeRefunded($event->data->object),
                'charge.dispute.created' => $this->handleChargeDisputeCreated($event->data->object),
                // ...
            };
        }
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#TEST-2** · P1 — DB::shouldReceive mock on the transaction boundary in app-uninstall cache-release test violates real-DB integration rule
    - **Where:** tests/Feature/Webhooks/Shopify/ShopifyAppUninstalledWebhookControllerTest.php:365–370
    - **Affects:** Test reliability — the mock replaces the entire `DB::transaction()` call, so the test never exercises the controller's actual transaction closure (token nulling, metadata update, brand-profile transition). A regression inside that closure passes this test.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `DB::shouldReceive('transaction')->once()->andThrow(...)` with a real DB path that throws, e.g. seed a constraint that causes the inner `update()` to fail, or throw from a model event listener that triggers during the real transaction.
        - Assert the cache key is released using `Cache::has()` after the exception, as the test already does — keep that assertion; just make the failure path exercise the real transaction layer.
    - **Technical:** The memory rule states integration tests must hit real Supabase Postgres and never mock the DB layer. `DB::shouldReceive('transaction')` intercepts the call before any SQL reaches Postgres, so the `finally` block's `Cache::forget` in the controller is tested, but the transaction body (ProfessionalIntegration::update, BrandProfile::update) is completely bypassed. A silent NPE or type error inside the closure would not be caught.
    - **Plain English:** This test simulates the database crashing by putting a fake wall in front of the database door — the test never actually opens the door. If the code behind the door (the part that clears the access token and marks the brand as disconnected) has a bug, this test won't find it because it never runs that code. The fix is to trigger a real database error that the real code has to handle.
    - **Evidence:**
        ```php
        // tests/Feature/Webhooks/Shopify/ShopifyAppUninstalledWebhookControllerTest.php
        DB::shouldReceive('transaction')->once()->andThrow(new \RuntimeException('simulated db failure'));
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#TEST-3** · P1 — Secondary Shopify webhook controllers (orders/edited, orders/cancelled, refunds/create) have HMAC tests but no re-delivery dedup tests
    - **Where:** tests/Feature/Webhooks/Shopify/SecondaryWebhookHmacTest.php (entire file, only HMAC coverage)
    - **Affects:** Webhook idempotency for three order-lifecycle topics — a re-delivered webhook that passes HMAC but should be caught by the Redis `Cache::add` dedup gate silently re-processes if the gate regresses.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a dataset-driven test in `SecondaryWebhookHmacTest.php` (or a sibling `SecondaryWebhookDedupTest.php`) that posts the same payload with the same `X-Shopify-Webhook-Id` twice against each of `orders/edited`, `orders/cancelled`, and `refunds/create`, asserting the second response is `duplicate: true` and exactly one job dispatch.
        - Mirror the pattern from `ShopifyOrderWebhookControllerTest.php` (`it('orders/paid — second delivery with same X-Shopify-Webhook-Id returns duplicate=true')`).
    - **Technical:** All three controllers extend `HandlesShopifyWebhook` which calls `claimShopifyWebhookEvent` (a `Cache::add` atomic lock) before dispatching. The primary controllers (`orders/paid`, `orders/updated`) have this dedup path tested in their dedicated test files. The secondary controllers were added later and received HMAC coverage via the dataset sweep (commit `d546754d`) but never got corresponding dedup tests. A refactor to the trait that breaks the `Cache::add` call would be caught by the primary controller tests but not by these three.
    - **Plain English:** When Shopify delivers the same webhook twice (network hiccup, timeout, etc.), the system has a "seen it" note it writes to Redis. The first delivery processes; the second sees the note and says "already handled, skip." We've tested this note works for the main "order paid" webhook, but not for the three supporting webhooks (order edited, cancelled, refunded). If someone changes the code that writes the note, these three would silently re-process duplicate deliveries.
    - **Evidence:**
        ```php
        // tests/Feature/Webhooks/Shopify/SecondaryWebhookHmacTest.php
        // Only two tests exist, both HMAC-only:
        it('secondary webhook returns 401 on bad HMAC and dispatches nothing', function (string $method, string $path) { ... });
        it('secondary webhook returns 401 on missing HMAC header', function (string $method, string $path) { ... });
        
        // Versus the tested controllers — ShopifyOrderWebhookControllerTest.php:
        it('orders/paid — second delivery with same X-Shopify-Webhook-Id returns duplicate=true', function () { ... });
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#TEST-4** · P1 — StripeConnectWebhookController lacks a re-delivery idempotency test (same event_id twice → second is no-op)
    - **Where:** tests/Feature/Webhooks/Stripe/StripeConnectWebhookControllerEndToEndTest.php (missing test)
    - **Affects:** Re-delivered `account.updated` or `checkout.session.completed` events silently re-process if the `billing.webhook_events` dedup guard is ever weakened.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a test in `StripeConnectWebhookControllerEndToEndTest.php` that posts a valid `account.updated` event twice with the same Stripe event ID, asserts the second returns 200, and asserts `webhook_events` row count is 1.
        - Mirror the pattern from `StripeWebhookControllerEndToEndTest.php` line ~"same event_id arriving twice processes only once".
    - **Technical:** The controller calls `$this->dedupeOrAck($event, ...)` from `ValidatesStripeWebhookPayload`, which uses `firstOrCreate` on `billing.webhook_events.stripe_event_id`. The billing controller had this tested from the start; the connect controller's test file covers signature validation, malformed payloads, account mismatch, status transitions, deauthorization, checkout mode branching, and delete-on-failure — but not the basic "deliver same event twice, assert dedup" invariant. The delete-on-failure test confirms the row is removed on handler exception, but does not confirm the row prevents re-processing on success.
    - **Plain English:** The Stripe billing webhook has a test that proves "same event twice → only processed once." The Stripe Connect webhook (which handles account status changes and payment method setup) uses the exact same deduplication code but has no equivalent test. It's like having two identical locks on two doors and only testing one of them.
    - **Evidence:**
        ```php
        // StripeWebhookControllerEndToEndTest.php — has this test:
        it('stripe billing — same event_id arriving twice processes only once', function () { ... });
        
        // StripeConnectWebhookControllerEndToEndTest.php — no equivalent test exists.
        // The dedup code path:
        // app/Http/Controllers/Api/Webhooks/Stripe/StripeConnectWebhookController.php
        $webhookEvent = $this->dedupeOrAck($event, $request->getContent());
        if ($webhookEvent instanceof JsonResponse) {
            return $webhookEvent;
        }
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#TEST-5** · P2 — ShopifyThemePublishedWebhookController inline Cache::add dedup path is untested
    - **Where:** tests/Feature/Webhooks/Shopify/SecondaryWebhookHmacTest.php (only HMAC coverage for themes/publish)
    - **Affects:** Duplicate `themes/publish` webhook deliveries — a low-risk no-op controller, but a regression on the dedup gate would mean the controller runs its full body twice, doubling the log writes without consequence.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a test in `SecondaryWebhookHmacTest.php` (or a sibling file) that posts the same payload with the same `X-Shopify-Webhook-Id` twice, asserting second response is `duplicate: true`.
    - **Technical:** Unlike the order webhook controllers that use the `HandlesShopifyWebhook` trait, `ShopifyThemePublishedWebhookController` inlines its own `Cache::add` dedup gate. This path is structurally untested — the HMAC dataset sweep added signature coverage but the dedup branch (`if (! Cache::add(...))`) has no test exercising it. Low risk because the controller is a deliberate no-op, but the pattern should be consistent.
    - **Plain English:** The "theme published" webhook has its own custom deduplication code written directly in the controller rather than using the shared helper. The test file only checks that bad signatures are rejected; it never checks that duplicate deliveries are caught. Low risk because this webhook is intentionally a no-op, but inconsistencies in test coverage across webhook controllers erode confidence.
    - **Evidence:**
        ```php
        // app/Http/Controllers/Api/Webhooks/Shopify/ShopifyThemePublishedWebhookController.php
        if ($webhookId !== '') {
            $dedupeKey = "shopify:webhook:themes-publish:{$webhookId}";
            if (! Cache::add($dedupeKey, true, (int) config('partna.cache.ttls.webhook_idempotency'))) {
                return $this->success(['received' => true, 'duplicate' => true]);
            }
        }
        
        // tests/Feature/Webhooks/Shopify/SecondaryWebhookHmacTest.php
        // themes/publish is in the dataset but only HMAC is tested
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#TEST-6** · P2 — Five Shopify install-chain jobs with `failed()` handlers have no dedicated test files
    - **Where:** app/Jobs/Shopify/CreateShopifyCollectionsJob.php, CreateShopifyMetafieldsJob.php, CreateShopifySalesChannelJob.php, CreateStorefrontAccessTokenJob.php, SetShopifySetupCompleteJob.php
    - **Affects:** Observability — when any of these jobs exhaust retries, their `failed()` handler writes a state key (`collections_state`, `metafield_definitions_state`, `sales_channel_state`, `storefront_token_state`) to `provider_metadata` that the setup-status API reads. If that write silently breaks, the brand's setup wizard shows stale state forever.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Shopify/CreateShopifyCollectionsJobTest.php`, `CreateShopifyMetafieldsJobTest.php`, `CreateShopifySalesChannelJobTest.php`, `CreateStorefrontAccessTokenJobTest.php`, `SetShopifySetupCompleteJobTest.php`.
        - Each should, at minimum, assert that calling `$job->failed(new RuntimeException('test'))` sets the appropriate `*_state` key to `'failed'` on the integration row.
        - Mock the Shopify HTTP boundary (ShopifyAdminClient) for the `handle()` path tests as the existing `CreateShopifyAffiliateDiscountJobTest.php` and `BackfillBrandHasEnabledVariantsJobTest.php` already do.
    - **Technical:** `CreateShopifyAffiliateDiscountJob` and `BackfillBrandHasEnabledVariantsJob` both have dedicated test files that assert their `failed()` handlers. The five sibling jobs in the install chain were written in the same pattern but never received equivalent coverage. All five dispatch the next job in the chain and all five handle domain/regex validation at the top — untested branching logic that could return early or set `failed` state without a test asserting the outcome.
    - **Plain English:** The Shopify install process runs a chain of about eight jobs in sequence — register metafields, create the sales channel, create collections, install the discount, and so on. Each job says "if I fail after all retries, mark my step as 'failed' so the brand sees which step broke." Three of the eight have tests proving they do that; five don't. If a future change accidentally removes the "mark as failed" line from any of those five, no test will catch it, and the brand's dashboard will show "still installing" forever.
    - **Evidence:**
        ```php
        // Example from one of the five — CreateShopifySalesChannelJob.php
        public function failed(\Throwable $e): void
        {
            $integration = ProfessionalIntegration::find($this->integrationId);
            $integration?->mergeProviderMetadata(['sales_channel_state' => 'failed']);
        }
        // No tests/Feature/Shopify/CreateShopifySalesChannelJobTest.php exists
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#TEST-7** · P2 — ReconcileStuckShopifyIntegrationsJob has no test coverage for its auto-heal logic
    - **Where:** app/Jobs/Shopify/ReconcileStuckShopifyIntegrationsJob.php (entire class)
    - **Affects:** Stuck-integration auto-healing — this daily sweep is the safety net for lost `app/uninstalled` webhooks. A regression that breaks the 401-detection branch silently stops healing, and integrations that should be disconnected stay connected with dead tokens forever.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Shopify/ReconcileStuckShopifyIntegrationsJobTest.php`.
        - Test at minimum: 401 response → token cleared + disconnected_at set + brand_status → Disconnected; 200 + matching domain → row left alone; 500 → row left alone (transient); shop_domain_mismatch → disconnected; network timeout → row left alone.
        - Fake the Shopify HTTP calls with `Http::fake()`; seed real integration rows in the test DB.
    - **Technical:** The job iterates up to 200 integrations calling the Shopify Admin API per integration. Its `validateAccessToken` method classifies responses into three buckets: healthy (leave alone), revoked (heal), transient (leave alone). None of these classification branches are tested. The companion `ReconcileStuckShopifyIntegrationsJobTest` does not exist. The `failed()` handler also has no test.
    - **Plain English:** This daily background job is the janitor that finds Shopify stores whose connection has been broken without our knowledge (Shopify's "app uninstalled" notification sometimes gets lost). It calls Shopify to check if each store's access token still works. If the token is dead, it cleans up the connection locally. None of this "check and clean" logic has tests. A bug here means broken stores pile up silently — no alarms, no fix.
    - **Evidence:**
        ```php
        // app/Jobs/Shopify/ReconcileStuckShopifyIntegrationsJob.php
        // Entire class — ~180 lines of resolution logic — zero tests
        private function validateAccessToken(ProfessionalIntegration $integration): array
        {
            // 401 → revoked (heal), 5xx/network → transient (leave), 
            // 2xx + matching domain → healthy, 2xx + mismatched → heal
            // None of these branches are tested.
        }
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#TEST-8** · P2 — ProcessShopifyShopUpdateJob has no dedicated test file
    - **Where:** app/Jobs/Shopify/ProcessShopifyShopUpdateJob.php (entire class)
    - **Affects:** Brand profile auto-resync path — the controller that receives `shop/update` is now a deliberate no-op (commit `4ca5e131`), but this job class still exists with full mutation logic. If it's ever re-dispatched (manual ops, future feature flag), it writes display_name and dispatches SyncShopifyBrandDesignJob with no test asserting correct behavior.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either add `tests/Feature/Shopify/ProcessShopifyShopUpdateJobTest.php` asserting the `resyncFromShopData` + brand-design-dispatch path, OR delete the job class entirely since its only dispatcher (`ShopifyShopUpdateWebhookController`) no-ops.
    - **Technical:** The job calls `ShopProfileAutoFillService::resyncFromShopData()` and conditionally dispatches `SyncShopifyBrandDesignJob` (throttled to once/hour via `Cache::add`). Both mutations are real — the dead code could be revived by a future change and would then ship untested. The commit message on `ShopifyShopUpdateWebhookController` explicitly says the auto-resync was removed because it was overwriting brand edits, so the job class is effectively dead code that still carries mutation risk.
    - **Plain English:** This is a job that used to run every time a brand changed their Shopify settings, automatically pulling those changes into Partna. The trigger was disabled because it was overwriting changes brands made directly in Partna. But the job itself still exists, fully functional, just never called. If someone reconnects the trigger in the future without writing tests, it'll silently overwrite brand data again. Either test it or delete it.
    - **Evidence:**
        ```php
        // app/Jobs/Shopify/ProcessShopifyShopUpdateJob.php
        // Full mutation job — no test file exists
        public function handle(): void
        {
            // ...
            app(ShopProfileAutoFillService::class)->resyncFromShopData($integration, $this->payload);
            // ...
            SyncShopifyBrandDesignJob::dispatch((string) $integration->id);
        }
        
        // The only dispatcher now no-ops:
        // app/Http/Controllers/Api/Webhooks/Shopify/ShopifyShopUpdateWebhookController.php
        protected function dispatchWebhookJob(...): void
        {
            Log::info('Shopify shop/update webhook ignored — auto-resync disabled.', [...]);
        }
        ```
    - `[DRAFT, confidence: 0.9]`
