- [ ] **#TEST-1** · P0 — CommissionAdjustmentService has no test coverage for a staff-initiated financial mutation path
    - **Where:** app/Services/Stripe/CommissionAdjustmentService.php (entire file); closest test file: tests/Feature/Stripe/ (none found for this service)
    - **Affects:** Staff adjusting brand↔affiliate commissions; idempotency-key dedup against duplicates; analytics cache invalidation on both sides of the relationship.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Add `tests/Feature/Stripe/CommissionAdjustmentServiceTest.php` covering: happy-path post (positive + negative amount), duplicate reference → `DuplicateAdjustmentException`, zero-amount rejection, same-professional rejection, and assertion that `AnalyticsCacheService::invalidateAnalytics` is called for both brand and affiliate IDs.
        - Add a test asserting that the `23505` unique-violation catch path works — seed a row with the same `idempotency_key` and assert the exception is thrown with the correct reference.
    - **Technical:** `CommissionAdjustmentService::post` writes a `CommissionMovement` with `entry_type='adjustment'` inside a DB transaction. The unique index on `idempotency_key` (`commission_ledger_entries_idempotency_uq`) is the dedup source of truth. The try/catch on `QueryException` with code `23505` converts PostgreSQL's unique violation into the domain `DuplicateAdjustmentException`. Without a test, any refactor of the exception handling or the analytics cache invalidation (both brand + affiliate sides) is a blind regression risk — a staff admin could post duplicate adjustments silently succeeding, or analytics caches not getting busted.
    - **Plain English:** This is the tool staff use to manually fix commission amounts — like crediting an affiliate when an order was misattributed. It has a built-in safety rail that prevents accidentally applying the same correction twice (the "reference" acts like a cheque number). There are zero tests for it. If a future code change breaks the duplicate-safety rail, staff could apply the same correction twice without knowing, effectively paying an affiliate double. The code also tells the analytics dashboard to refresh, and without tests we can't guarantee that happens either.
    - **Evidence:**
        ```php
        // app/Services/Stripe/CommissionAdjustmentService.php:64-65
        try {
            DB::transaction(function () use (...) {
                (new CommissionMovement)->forceFill([...])->save();
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw new DuplicateAdjustmentException($reference, previous: $e);
            }
            throw $e;
        }
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#TEST-2** · P1 — CommissionExportService::dispatch async export pipeline has no test coverage
    - **Where:** app/Services/Stripe/CommissionExportService.php (entire file); closest test file: tests/Feature/Stripe/ExportsTest.php (covers sync ExportService, not this async dispatcher)
    - **Affects:** Brand and affiliate users requesting async commission exports; dedup of in-flight exports; accurate payout counts in audit rows.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Stripe/CommissionExportServiceTest.php` covering: happy-path dispatch → audit row created + `ExportChunkJob` dispatched, empty-result path → `ExportFinalizerJob` dispatched directly, duplicate-dispatch within dedup window → `CommissionExportInProgressException` thrown, missing recipient email → `NoRecipientEmailException` thrown, and `lockForUpdate` serialisation of two concurrent dispatch calls.
        - Assert that `payouts_total` on the audit row matches the actual count from the scoped query.
    - **Technical:** `CommissionExportService::dispatch` uses `DB::transaction` with `lockForUpdate()` on recent export audit rows to dedup in-flight exports for the same professional+role+format. It then counts payouts, inserts an audit row, and conditionally dispatches `ExportChunkJob` (when payouts exist) or `ExportFinalizerJob` (when count is zero). The chunk pipeline (`ExportChunkJob` → `ExportFinalizerJob`) is tested elsewhere, but the dispatcher itself — the entry point that gates duplicate requests and resolves the recipient email — has zero direct coverage. A future change that breaks the dedup query or the empty-result branch would silently allow duplicate exports.
    - **Plain English:** When a brand or affiliate clicks "Export my commissions," this service decides whether to start the export or tell them "you already have one running." It also counts how many rows the export will contain and writes that into a tracking record. There are no tests for this decision-making. A bug here could either let someone trigger dozens of duplicate exports (wasting resources) or tell them an export is in progress when it isn't (blocking them from getting their data).
    - **Evidence:**
        ```php
        // app/Services/Stripe/CommissionExportService.php:55-78
        $audit = DB::transaction(function () use (...) {
            $existing = CommissionExportAudit::query()
                ->where('professional_id', $professional->id)
                ->where('role', $role)
                ->where('format', $format)
                ->whereIn('status', [
                    CommissionExportAudit::STATUS_QUEUED,
                    CommissionExportAudit::STATUS_PROCESSING,
                ])
                ->where('created_at', '>=', now()->subMinutes($dedupWindow))
                ->lockForUpdate()
                ->orderByDesc('created_at')
                ->first();

            if ($existing) {
                throw new CommissionExportInProgressException($existing);
            }
            // ... creates audit row, dispatches jobs
        });
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#TEST-3** · P1 — No policy ability unit tests found for any Policy class; controller auth tests only exercise endpoint-level gating
    - **Where:** app/Policies/* (not provided in audit, but referenced by CLAUDE.md doctrine); tests/Feature/Policies/ directory — no policy test files provided
    - **Affects:** Every `authorizeForUser` call in controllers and Form Requests; fine-grained ability assertions (view vs update vs delete per role).
    - **Effort:** XL (~16–32h)
    - **What to do:**
        - Audit `app/Policies/` for every Policy class and add a corresponding `tests/Feature/Policies/{Model}PolicyTest.php`.
        - For each `public function` on each Policy, add an `it('allows ...')` and `it('denies ...')` test.
        - Ensure denied-because-not-yours assertions check for 404 (via `denyAsNotFound()`), not 403.
        - Wire `actingAsBrand($pro)` / `actingAsAffiliate($pro)` from `tests/Pest.php`.
    - **Technical:** The Partna Authorization Doctrine mandates: (a) `authorizeForUser($pro, 'verb', $resource)` in controllers, (b) Policies extending `BasePolicy`, (c) `denyAsNotFound()` for cross-tenant denials producing 404 not 403. Controller tests in `tests/Feature/Stripe/` test auth indirectly (e.g., cross-role 403 assertions in `TransactionsListTest`, `StripeConnectPayoutsControllerTest`) but never exercise the Policy classes directly. Without per-policy unit tests, a Policy method can silently pass (e.g., missing a `return false` branch) while controller tests appear green because the Gate falls through. The existing cross-role 403 tests also assert 403 where the doctrine requires 404 — a mismatch that direct policy tests would catch.
    - **Plain English:** Every door in the system has a lock (a Policy class), but we only test that the doors "feel locked" by trying to open them from the hallway (controller tests). We've never tested the locks themselves. A Policy that accidentally grants access when it shouldn't — for example, letting one brand view another brand's payout details — would go undetected because the controller tests only check obvious cross-role scenarios and can't verify every edge case. Direct lock tests would catch this.
    - **Evidence:**
        ```php
        // CLAUDE.md Authorization Doctrine (from the audit prompt):
        // "Authorization through Policies, never inline."
        // "authorizeForUser, not authorize."
        // "404-on-not-yours assertion: denied-because-not-yours must 404, not 403."
        
        // Example controller auth call (from StripeConnectPayoutsControllerTest.php:235):
        // Tests assert 403 for cross-role — but doctrine requires 404 via denyAsNotFound().
        expect(fn () => makePayoutsController($summary)->payouts(
            makePayoutsRequest($aff, ['role' => 'brand'])
        ))->toThrow(\Illuminate\Auth\Access\AuthorizationException::class);
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#TEST-4** · P2 — StripeRowGenerator (async export row generator) has no direct test; byte-identical normalisers to StripeTransactionFetcher are assumed but unverified
    - **Where:** app/Services/Stripe/StripeRowGenerator.php (entire file); closest test: tests/Feature/Stripe/TransactionsListTest.php (covers StripeTransactionFetcher via controller, not the generator)
    - **Affects:** Async commission export pipeline correctness; row shape drift between the sync fetcher and the async generator.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Stripe/StripeRowGeneratorTest.php` that seeds a `CommissionPayout` with known brand/affiliate, mocks the Stripe client to return a controlled PI/charge/transfer shape, and asserts the yielded row structure matches the `StripeTransactionFetcher` output byte-for-byte.
        - Add a test for the error path: when Stripe throws on retrieve, the generator skips the payout and logs a warning (assert no yield, assert log).
    - **Technical:** `StripeRowGenerator::forPayouts` uses identical normalisation helpers to `StripeTransactionFetcher` (comment in the source: "copied verbatim"). The generator is consumed by the async export chunk jobs; the fetcher is consumed by the sync controller endpoint. Without a pinning test, a future developer could refactor one normaliser and not the other, introducing silent row-shape divergence between the dashboard transaction list and the exported CSV/XLSX. The generator also has error-handling branches (`catch (Throwable $e)`) that return early from the generator — untested.
    - **Plain English:** We have two versions of the same "translate Stripe data into our row format" code — one for the live dashboard, one for the export pipeline. They're supposed to produce identical output. There's a comment in the code saying "copied verbatim — do not modify," but comments don't prevent drift. Without a test that compares the output of both versions, a future change to one but not the other would mean the export file shows different numbers than the dashboard for the exact same data. That would erode trust in the export feature.
    - **Evidence:**
        ```php
        // app/Services/Stripe/StripeRowGenerator.php:17-22
        // Normalisation helpers — copied verbatim from StripeTransactionFetcher.
        // DO NOT modify these; row shape must stay byte-identical to the legacy
        // fetcher so downstream consumers (TransactionResource, export formatters)
        // see the same structure.
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#TEST-5** · P2 — MonitorManualRefundQueueJob has no test covering its handle() or failed() methods
    - **Where:** app/Jobs/Stripe/MonitorManualRefundQueueJob.php; closest test file: tests/Feature/Stripe/ (none found for this job)
    - **Affects:** Ops visibility into payouts needing manual refund attention; Nightwatch alerting gap if the job silently fails.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `tests/Feature/Stripe/MonitorManualRefundQueueJobTest.php` covering: empty queue → logs "empty" info, non-empty queue under 200 → logs warning with exact count + payout list, queue over 200 → count reflects full backlog, fetch capped at 200, failed() handler → calls `report($e)` and logs error.
    - **Technical:** `MonitorManualRefundQueueJob::handle()` queries `CommissionPayout` where `needs_manual_refund=true` and status not in `['cancelled', 'failed']`. It has two code paths: empty (logs info) and non-empty (logs warning with a capped list of 200). The SCALE-2 fix separates the count query from the fetch query so the logged count reflects the true queue depth even when the fetch is capped. Without a test, a future SCALE pass that accidentally re-merges the count + fetch into one query (uncapped) would OOM the job on a large backlog. The `failed()` method calls `report($e)` — untested.
    - **Plain English:** Every morning, a job runs that sends ops a list of payouts that need human attention (usually refunds that couldn't be processed automatically). There are zero tests for this job. If the list gets too long and a future code change tries to load all of them into memory at once, the job crashes — and ops never sees the alert about payouts needing manual review. The "morning digest" goes silent, and payouts sit un-reviewed indefinitely.
    - **Evidence:**
        ```php
        // app/Jobs/Stripe/MonitorManualRefundQueueJob.php:43-48
        // SCALE-2: separate count query for true backlog size, then cap the fetch
        $totalCount = (clone $baseQuery)->count();
        // ...
        $open = (clone $baseQuery)
            ->with([...])
            ->orderBy('updated_at')
            ->limit(200)
            ->get();
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#TEST-6** · P2 — SyncStripeAccountStatusJob has no dedicated test; handle() skip-logic and failed() handler untested in isolation
    - **Where:** app/Jobs/Stripe/SyncStripeAccountStatusJob.php; closest test: tests/Feature/Stripe/StripePlatformWebhookControllerTest.php (tests the webhook that dispatches this job, not the job itself)
    - **Affects:** Reliability of Stripe account status sync when webhook handler decouples from the sync work; the `not_connected` skip guard.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `tests/Feature/Stripe/SyncStripeAccountStatusJobTest.php` covering: professional found → `syncAccountStatus` called on the service, professional not found → silent return (no error), professional with `stripe_connect_status='not_connected'` → logs "skipped_not_connected" and does NOT call `syncAccountStatus`, `failed()` → calls `report($e)` and logs error.
    - **Technical:** `SyncStripeAccountStatusJob` was extracted from the webhook controller to decouple webhook ack (must return 200 quickly) from the Stripe API round-trip in `syncAccountStatus`. The job has a guard that skips professionals whose local status is `not_connected` — this prevents a late webhook event from re-activating an account the brand explicitly disconnected. Without a test, a refactor that removes or weakens this guard would silently re-sync a disconnected account's status, potentially flipping it back to 'active' from a stale Stripe event.
    - **Plain English:** This job keeps a professional's Stripe account status in sync after Stripe sends us a webhook. There's a safety check that says "if the professional disconnected their account on our side, ignore any late-arriving Stripe updates." That safety check has no test. A bug here could mean a brand who disconnects Stripe suddenly sees their account marked "active" again because of a delayed webhook — confusing and potentially letting them attempt payouts that will fail.
    - **Evidence:**
        ```php
        // app/Jobs/Stripe/SyncStripeAccountStatusJob.php:51-59
        if ($professional->stripe_connect_status === 'not_connected') {
            Log::info('stripe.sync_account_status.skipped_not_connected', [
                'professional_id' => $this->professionalId,
            ]);
            return;
        }
        $service->syncAccountStatus($professional);
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#TEST-7** · P2 — VoidPendingCommissionsForLinkJob has no dedicated test; async void loop + auditor/notifier integration untested
    - **Where:** app/Jobs/Stripe/VoidPendingCommissionsForLinkJob.php; closest test: tests/Feature/Stripe/CommissionVoidServiceTest.php (covers the void service methods but not this job's orchestration)
    - **Affects:** Correctness of commission voiding when a brand-affiliate partnership is disconnected; audit trail completeness; affiliate/brand notification delivery.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Stripe/VoidPendingCommissionsForLinkJobTest.php` covering: happy path → `runVoidLoop` called + auditor `recordAsyncVoidCompletion` + notifier methods called, missing professional → logs warning and returns, `failed()` → calls `report($e)` and logs error, and idempotency under retry (same orders voided once, not twice).
    - **Technical:** `VoidPendingCommissionsForLinkJob::handle` orchestrates three services: `CommissionVoidService::runVoidLoop` to void the orders, `BrandPartnerLinkAuditor::recordAsyncVoidCompletion` to write the audit record, and `BrandPartnerLinkNotifier` to notify both parties. The void service itself is tested, but the orchestration — the guarantee that the auditor and notifier are called with the correct parameters AFTER the void loop completes — is not. The `loadProfessionals()` helper returns `[?Professional, ?Professional]` and the job silently skips if either is null — that early-return path is untested.
    - **Plain English:** When a brand removes an affiliate partnership, this job goes through and voids all pending commissions for that pair, writes an audit trail entry, and notifies both sides. The individual pieces are tested, but the conductor that wires them together isn't. If a future change accidentally swaps the order (notifying before voiding) or skips the audit entry, the affiliate would get a "your commissions were voided" notification before the database actually reflects it, or no audit record would exist to explain why.
    - **Evidence:**
        ```php
        // app/Jobs/Stripe/VoidPendingCommissionsForLinkJob.php:43-63
        public function handle(
            CommissionVoidService $voidService,
            BrandPartnerLinkAuditor $auditor,
            BrandPartnerLinkNotifier $notifier,
        ): void {
            [$affiliate, $brand] = $this->loadProfessionals();
            if (! $affiliate || ! $brand) {
                Log::warning('... missing professional, skipping.');
                return;
            }
            $result = $voidService->runVoidLoop(...);
            $auditor->recordAsyncVoidCompletion(...);
            $notifier->notifyAffiliateOfRemoval(...);
            $notifier->notifyBrandOfRemoval(...);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#TEST-8** · P2 — No concurrent webhook re-delivery-during-processing test for payout state transitions
    - **Where:** app/Http/Controllers/Api/Webhooks/Stripe/StripePlatformWebhookController.php (payment_intent.succeeded handler); closest test: tests/Feature/Stripe/StripePlatformWebhookControllerTest.php (tests dedup on same event_id, not in-flight race)
    - **Affects:** Correctness when Stripe delivers a payment_intent.succeeded webhook while a refund-initiated clawback is in progress against the same payout.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a test in `StripePlatformWebhookControllerTest.php` that: (a) seeds a payout in 'processing', (b) dispatches `markPaymentIntentSucceeded` and simultaneously fires `handleOrderRefund` with a completed-payout clawback plan via `DB::afterCommit`, (c) asserts the final payout state is correct — either 'completed' with the clawback row written, or an idempotent no-op on the second path.
    - **Technical:** The `payment_intent.succeeded` webhook calls `CommissionPayoutService::markPaymentIntentSucceeded`, which transitions the payout to 'completed'. Meanwhile, `CommissionPayoutRefundService::handleOrderRefund` for a 'completed' payout issues a Stripe Refund via `DB::afterCommit`. The existing webhook dedup test only covers same-event-id-twice (sequential delivery), not the cross-event race: a `payment_intent.succeeded` arriving concurrent with a refund webhook that's mid-clawback. The `afterCommit` decoupling ensures the Stripe call escapes the DB transaction, but there's no test asserting the idempotency guards in `markPaymentIntentSucceeded` (the `if ($payout->status === 'completed') return;` early return) interact correctly with the clawback path.
    - **Plain English:** Stripe can deliver two different webhook events at nearly the same time — say, "the payout succeeded" and "a refund arrived against that payout." The code has guards to handle both arriving in either order, but we've only tested them arriving separately with a pause in between. The real-world scenario where both fire simultaneously (which happens in production during high-traffic periods) isn't covered. A race condition here could double-count or zero-out a payout.
    - **Evidence:**
        ```php
        // CommissionPayoutService.php — markPaymentIntentSucceeded early return:
        public function markPaymentIntentSucceeded(CommissionPayout $payout, ...): void
        {
            if ($payout->status === 'completed') {
                return;  // idempotent guard — but what if clawback is in-flight?
            }
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **#TEST-9** · P2 — CommissionPayoutService::processPayoutBatch has no test for the `ApiConnectionException` re-throw path where status stays 'processing'
    - **Where:** app/Services/Stripe/CommissionPayoutService.php (processPayoutBatch method); tests/Feature/Stripe/CommissionPayoutServiceTest.php (tests the re-throw but doesn't assert status preservation after the catch)
    - **Affects:** Correctness of Horizon retry loop; ensures the payout is left in 'processing' so the retry picks it up with the same idempotency key.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add an assertion to the existing `re-throws ApiConnectionException so Horizon retries` test that verifies `payment_intent_id` is NOT set after the throw (the PI create didn't persist), confirming the retry will re-create the PI with the same idempotency key rather than finding a stale `payment_intent_id` and no-opping.
    - **Technical:** The existing test in `CommissionPayoutServiceTest.php` at line ~"re-throws ApiConnectionException" asserts `$payout->fresh()->status` is 'processing' but does not assert that `payment_intent_id` remains null. If a future refactor writes `payment_intent_id` before the Stripe call (e.g., an optimistic pre-save), the retry would hit the `if ($payout->status === 'processing' && $payout->payment_intent_id !== null) return null;` guard and permanently park the payout — it would never complete because no PI was actually created at Stripe.
    - **Plain English:** There's a test that makes sure when Stripe is temporarily unreachable, the job retries properly. But the test doesn't check one critical detail: that the payout record doesn't accidentally get marked as "already sent to Stripe" before the network call fails. If that happened, the retry would see the mark and go "yep, already handled, nothing to do" — and the payout would be stuck forever, never actually charged.
    - **Evidence:**
        ```php
        // tests/Feature/Stripe/CommissionPayoutServiceTest.php (around the ApiConnectionException test):
        // Assertion present:
        expect($payout->fresh()->status)->toBe('processing');
        // Assertion NOT present:
        // expect($payout->fresh()->payment_intent_id)->toBeNull();
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#TEST-10** · P3 — StripeWebhookBasilPeriodTest reaches private methods via reflection; no integration test through the actual webhook controller for subscription period resolution
    - **Where:** tests/Feature/Stripe/StripeWebhookBasilPeriodTest.php (entire file); tests/Feature/Stripe/StripeWebhookControllerTest.php (only tests signature gate, not subscription handler dispatch)
    - **Affects:** Confidence that the subscription.created/.updated webhook path works end-to-end with real Basil-shape payloads.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add an integration test in `StripeWebhookControllerTest.php` (or a new `StripeWebhookSubscriptionCreatedTest.php`) that posts a signed Basil-shape `customer.subscription.created` payload through the full HTTP stack and asserts the local `Subscription` row was created with correct `current_period_start`/`current_period_end` from `items.data[0]`.
    - **Technical:** `StripeWebhookBasilPeriodTest` invokes `handleSubscriptionCreated` directly via reflection, bypassing the controller's signature verification, event dedup, and PII sanitisation layers. `StripeWebhookControllerTest` only tests the signature gate (400 responses). There is no test that posts a full Basil-shape payload through the HTTP entry point and asserts the subscription row lands correctly. The `StripeWebhookDeleteOnFailureTest` covers the delete-on-failure path for `.deleted` events, but the `.created` path with Basil period fields has no integration coverage.
    - **Plain English:** Stripe changed how they structure subscription data in their API responses — the billing period dates moved from the top level to inside a nested list. We have tests that call the handler directly and check it reads the dates correctly, but no test that simulates Stripe actually sending us that data through the real webhook endpoint. If the controller's signature check or event dedup accidentally drops Basil-shape events, the tests would never catch it because they skip those layers.
    - **Evidence:**
        ```php
        // tests/Feature/Stripe/StripeWebhookBasilPeriodTest.php:48-52
        // Reaches handler via reflection, bypassing HTTP stack:
        $controller = new StripeWebhookController;
        $method = new ReflectionMethod($controller, 'handleSubscriptionCreated');
        $method->setAccessible(true);
        $method->invoke($controller, $stripeSubscription, $event);
        
        // tests/Feature/Stripe/StripeWebhookControllerTest.php — only signature tests:
        it('returns 400 when Stripe-Signature header is missing', ...);
        it('returns 400 when webhook secret is not configured', ...);
        it('returns 400 when signature does not match', ...);
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#TEST-11** · P3 — StripeBillingService methods beyond ensureStripeCustomer and createCheckoutSession have no test coverage
    - **Where:** app/Services/Stripe/StripeBillingService.php (createBillingPortalSession, updateSubscriptionPlan, cancelSubscriptionAtPeriodEnd, resumeSubscription, cancelSubscriptionImmediately, previewPlanChange); closest test: tests/Feature/Stripe/StripeIdempotencyKeysTest.php (only idempotency key format for customer + checkout)
    - **Affects:** Plan change proration previews, subscription cancellation/resume flows, billing portal session creation.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Stripe/StripeBillingServiceTest.php` covering each method with a mocked Stripe client: `updateSubscriptionPlan` with both `create_prorations` and `none` behaviors, `cancelSubscriptionAtPeriodEnd` sets `cancel_at_period_end=true`, `resumeSubscription` sets it back to false, `cancelSubscriptionImmediately` calls `subscriptions->cancel()`, `previewPlanChange` returns the invoice preview shape with proration lines, and `createBillingPortalSession` returns a URL.
    - **Technical:** Six methods on `StripeBillingService` have zero test coverage. These are invoked from billing controllers and the subscription webhook handler. While the webhook-driven paths are partially exercised by `StripeWebhookSubscriptionUpdatedTest` (which tests `handleSubscriptionUpdated`, not the service directly), the plan-change preview and billing portal session are user-facing features with no safety net. A refactor of the Stripe SDK version or a change to the subscription items shape would break these silently.
    - **Plain English:** The billing service has eight public methods; only two are tested (creating a Stripe customer and starting a checkout). The other six — changing plans, previewing price changes, canceling, resuming, and opening the billing portal — have no tests at all. These are the features users interact with when managing their subscription, and they're entirely unprotected against regressions.
    - **Evidence:**
        ```php
        // app/Services/Stripe/StripeBillingService.php — untested methods:
        public function createBillingPortalSession(...) // line ~96
        public function updateSubscriptionPlan(...)     // line ~109
        public function cancelSubscriptionAtPeriodEnd(...) // line ~127
        public function resumeSubscription(...)         // line ~135
        public function cancelSubscriptionImmediately(...) // line ~143
        public function previewPlanChange(...)           // line ~151
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#TEST-12** · P3 — No Form Request validation test files found for stripe-related Form Requests
    - **Where:** app/Http/Requests/Api/Professional/Stripe/ (ExportsRequest, PayoutsRequest, SyncPaymentMethodSessionRequest, TransactionsRequest — referenced in test files but never tested standalone); closest test directory: tests/Feature/ (no Form Request unit tests in scope)
    - **Affects:** Input validation for export filters, payout listing parameters, transaction list filters; malformed payloads could bypass validation if rules drift.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Requests/` directory with one test file per Form Request class. Each should assert: at least one valid payload passes `authorize()` + `rules()`, at least one invalid payload per rule produces the expected validation error key, and feature-flagged rules are tested with both flag states.
    - **Technical:** The controller tests instantiate Form Requests directly (e.g., `ExportsRequest::create(...)`) which bypasses the Laravel validation pipeline — the request object is manually constructed with attributes set, not validated through the container. True Form Request validation (the `rules()` method, the `authorize()` gate) is never exercised. The `ExportsRequest` likely validates `role`, `format`, `fy`, `date_from`, `date_to` — none of those rules have automated test coverage.
    - **Plain English:** Every API endpoint has a "Form Request" class that acts as a bouncer — it checks that the data coming in is valid before the controller ever sees it. We test the controllers directly by building fake request objects that skip the bouncer entirely. A bug in the validation rules (like accidentally allowing negative date ranges or missing required fields) would only be caught when a real user hits it in production.
    - **Evidence:**
        ```php
        // Example from tests/Feature/Stripe/ExportsTest.php — bypasses validation:
        function exp_makeRequest(Professional $pro, array $query): ExportsRequest
        {
            $request = ExportsRequest::create('/api/stripe/exports/test.csv', 'GET', $query);
            $request->attributes->set('professional', $pro);
            return $request;
        }
        // The rules() and authorize() methods on ExportsRequest are never called.
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#TEST-13** · P3 — StripeConnectService::resolveShopCurrency queries professional_integrations; no test exercises the currency resolution path during createConnectAccount with a real Shopify integration row
    - **Where:** app/Services/Stripe/StripeConnectService.php (resolveShopCurrency method ~line 645); tests/Feature/Stripe/StripeConnectOnboardingPrefillTest.php (mocks the v2 accounts create but the currency field is hardcoded to 'aud' in the fallback)
    - **Affects:** Correct default currency on Stripe v2 Account creation for brands with Shopify integrations; multi-currency shops could get wrong default currency.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a test in `StripeConnectOnboardingPrefillTest.php` that seeds a `professional_integrations` row with `provider_metadata.shop_currency = 'USD'` and asserts the captured v2 account create payload includes `defaults.currency = 'usd'`.
    - **Technical:** `StripeConnectService::createConnectAccount` calls `resolveShopCurrency`, which queries `ProfessionalIntegration` for a Shopify integration and reads `provider_metadata.shop_currency`. The test `StripeConnectOnboardingPrefillTest` tests the identity prefill (shop_name, shop_url) from `resolveShopMetadata`, but never seeds a `shop_currency` value and never asserts on the `defaults.currency` field in the account creation payload. The fallback in the account creation code hardcodes `'aud'` when `resolveShopCurrency` returns null — so a shop running in USD would silently get an AUD-denominated Stripe account unless the currency resolution works.
    - **Plain English:** When a brand connects Stripe, we try to figure out what currency their Shopify store uses and set that as the default on their Stripe account. But the test that checks what we send to Stripe never sets up a Shopify store with a non-AUD currency — it always falls back to the default 'aud'. A US-based merchant would silently get an Australian-dollar Stripe account, and we'd never know from tests.
    - **Evidence:**
        ```php
        // app/Services/Stripe/StripeConnectService.php — createBrandConnectAccount:
        'defaults' => [
            // ...
            'currency' => strtolower($this->resolveShopCurrency($brand) ?? 'aud'),
        ],
        
        // Test file — only seeds shop_name/shop_domain, never shop_currency:
        // tests/Feature/Stripe/StripeConnectOnboardingPrefillTest.php:149-165
        // Asserts shop_name but not currency in the captured payload.
        ```
    - `[DRAFT, confidence: 0.80]`
