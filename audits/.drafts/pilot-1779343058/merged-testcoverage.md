
<!-- ═══ CHUNK: tc-financial ═══ -->

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

<!-- ═══ CHUNK: tc-webhook ═══ -->

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

<!-- ═══ CHUNK: tc-policy ═══ -->

- [ ] **#TEST-1** · P1 — CommissionPolicy view/update/delete/startConnect/viewProjections abilities have zero test coverage
    - **Where:** app/Policies/CommissionPolicy.php (view, update, delete, startConnect, viewProjections, viewOwnTransactions)
    - **Affects:** Every brand/affiliate accessing commission records, team members with delegated financial-analytics capability, Stripe Connect onboarding flow.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `it('view: brand owner can view', ...)`, `it('view: affiliate can view own', ...)`, `it('view: team member with financial read can view', ...)`, `it('view: unrelated actor gets 404', ...)` covering the BrandAccessService delegation path.
        - Add `it('update: brand owner can update', ...)` + deny cases for pending_deletion, non-owner, affiliate.
        - Add `it('startConnect: self only', ...)` + `it('startConnect: different professional denied', ...)`.
        - Add `it('viewProjections: only matching affiliate', ...)`.
        - Add `it('viewOwnTransactions: brand sees brand-side, affiliate sees affiliate-side', ...)`.
    - **Technical:** CommissionPolicyAbilityTest.php tests viewOwnPayouts, managePaymentMethod, and manageWallet but none of the core CRUD abilities. The `view` method in particular delegates to `BrandAccessService::canReadBrandFinancialAnalytics()` — a Mockery mock of BrandAccessService is set up in the test file's `beforeEach` but never exercised for the `view` ability. If the capability check changes or the team-member auth model shifts, there is zero test to catch the regression.
    - **Plain English:** The authorization rules for who can see commission financial records have a complex chain: brand owner → yes, affiliate → yes for their own, team member → yes if they have the "read financials" key. None of this chain has automated tests. If someone refactors the team-permission system, commission visibility could break silently — either leaking data or hiding records that should be visible.
    - **Evidence:**
        ```php
        // app/Policies/CommissionPolicy.php - untested methods
        public function view(Professional $actor, Model $record): bool|Response
        {
            // ...
            // Brand team member with financial read capability (UNTESTED PATH)
            if ($this->brandAccess->canReadBrandFinancialAnalytics($actor, $brandId)) {
                return true;
            }
            // ...
        }

        public function viewProjections(Professional $pro, BrandAffiliateRollup $skeleton): bool
        public function viewOwnTransactions(Professional $pro, CommissionPayout $skeleton): bool
        public function update(Professional $actor, Model $record): bool|Response
        public function delete(Professional $actor, Model $record): bool|Response
        public function startConnect(Professional $actor, Professional $pro): bool
        ```
        ```php
        // tests/Feature/Policies/CommissionPolicyAbilityTest.php — only covers these methods:
        //   viewOwnPayouts (6 tests)
        //   managePaymentMethod (4 tests)
        //   manageWallet (4 tests)
        // tests/Feature/Policies/CommissionPolicyTest.php — only:
        //   managePaymentMethod allow + deny (2 tests)
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#TEST-2** · P1 — LoadCurrentProfessional middleware has zero test coverage
    - **Where:** app/Http/Middleware/Context/LoadCurrentProfessional.php
    - **Affects:** Every authenticated request. This middleware gates the bootstrap flow (new sign-ups), email sync, collision handling, and suspended-account blocking.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `it('returns 401 when supabase_uid is missing', ...)` and `it('returns 401 when uid is not a UUID', ...)`.
        - Add `it('returns 403 with bootstrap_required when no professional exists', ...)` — this is the post-signup resume path.
        - Add `it('returns 403 when account is suspended', ...)`.
        - Add `it('syncs primary_email from verified JWT claims', ...)`.
        - Add `it('handles email sync UniqueConstraintViolation gracefully', ...)`.
    - **Technical:** This middleware runs on every single authenticated request after supabase.jwt. It resolves the professional via cache, enforces account status, and reconciles primary_email on drift. A regression here — e.g., the bootstrap_required error shape changing — would silently break the frontend sign-up flow because the SPA relies on that exact JSON structure to route users back into the "about" step. The email-sync collision path (`UniqueConstraintViolationException`) is completely untested; a production collision would log a warning but no test proves it doesn't 500.
    - **Plain English:** Every time someone logs into Partna, this middleware looks up their account, checks it's not suspended, and silently fixes their email if it changed in Supabase. There are zero automated tests for any of these steps. If a future change accidentally returns the wrong error code for a half-finished sign-up, the frontend will dead-end users at a blank screen instead of sending them back into the sign-up flow.
    - **Evidence:**
        ```php
        // app/Http/Middleware/Context/LoadCurrentProfessional.php
        // Critical untested paths:
        if (! $professional) {
            // Verified auth user with no Partna profile — they bailed mid-signup
            return response()->json([
                'error' => 'bootstrap_required',
                'message' => 'Finish setting up your Partna account.',
            ], 403);
        }

        // Email sync with collision handling:
        try {
            $professional->primary_email = $claimedEmail;
            $professional->save();
        } catch (UniqueConstraintViolationException $e) {
            Log::warning('LoadCurrentProfessional email sync collision', [...]);
        }
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#TEST-3** · P1 — VerifyShopifySessionToken middleware has zero test coverage
    - **Where:** app/Http/Middleware/Auth/VerifyShopifySessionToken.php
    - **Affects:** Every Shopify embedded-app route. JTI replay protection, JWT validation (9 distinct rejection reasons), tenant resolution, lenient mode for connect-account flow.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Add `it('rejects with token_missing when no Authorization header', ...)`.
        - Add `it('rejects with sig_invalid on bad signature', ...)`.
        - Add `it('rejects with aud_mismatch when aud != api_key', ...)`.
        - Add `it('rejects with dest_invalid for non-myshopify dest', ...)`.
        - Add `it('rejects with iss_mismatch when iss != dest', ...)`.
        - Add `it('rejects with jti_missing when no jti claim', ...)`.
        - Add `it('rejects with cache_unavailable (503) when Redis is down', ...)`.
        - Add `it('rejects with jti_replay on repeated use', ...)`.
        - Add `it('rejects with shop_unlinked (404) when no professional matches', ...)`.
        - Add `it('lenient mode skips shop resolution and sets domain only', ...)`.
        - Add `it('allows up to jti_max_uses within the 120s window', ...)`.
        - Add `it('returns 500 when api_key/api_secret config is missing', ...)`.
    - **Technical:** This middleware has 9 distinct rejection codes, JTI atomic-counter replay protection via Redis Lua, lenient vs default mode, and a JWT::$leeway save/restore pattern to prevent clock-skew bleed. The JTI Lua script (`INCR + conditional EXPIRE`) is non-trivial — a test suite that overrides `jti_max_uses` to 1 and fires two requests with the same JWT is the only way to prove replay protection works across both Redis and array-cache fallback paths. Without tests, a refactor of the Cache facade or the Lua script could silently weaken replay protection to none.
    - **Plain English:** When Shopify merchants use the Partna embedded app, every request carries a Shopify-signed token. This middleware checks the token is real, hasn't expired, hasn't been replayed, and maps to the right Partna account. It has nine different ways to reject a bad request. None of these nine are tested. It's like having a security checkpoint with nine inspection stations, and zero security cameras to confirm the guards are actually checking IDs.
    - **Evidence:**
        ```php
        // app/Http/Middleware/Auth/VerifyShopifySessionToken.php — 9 rejection codes, all untested
        //   1. token_missing       — no Authorization header
        //   2. sig_invalid         — JWT::decode threw
        //   3. aud_mismatch        — aud != SHOPIFY_API_KEY
        //   4. dest_invalid        — dest host does not end .myshopify.com
        //   5. iss_mismatch        — iss host != dest host
        //   6. jti_missing         — no jti claim
        //   7. cache_unavailable   — JTI counter increment threw (503)
        //   8. jti_replay          — jti use-count exceeded max
        //   9. shop_unlinked       — no professional linked
        //
        // JTI Lua script — atomic counter on Redis:
        $script = <<<'LUA'
        local current = redis.call('INCR', KEYS[1])
        if current == 1 then
            redis.call('EXPIRE', KEYS[1], ARGV[1])
        end
        return current
        LUA;
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#TEST-4** · P1 — VerifySupabaseEmailHookSignature middleware has zero test coverage
    - **Where:** app/Http/Middleware/Auth/VerifySupabaseEmailHookSignature.php
    - **Affects:** POST /internal/email-hooks/supabase — the endpoint Supabase calls to deliver send-email-hook events. Signature bypass means forged email events.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('returns 503 when email_hook_secret config is empty', ...)`.
        - Add `it('returns 401 when signature header is missing or invalid', ...)`.
        - Add `it('passes through with valid webhook-id/timestamp/signature', ...)`.
    - **Technical:** This middleware uses the Standard Webhooks HMAC verification pattern (webhook-id + webhook-timestamp + webhook-signature headers). It delegates verification to `SupabaseEmailHookSignatureVerifier`. A misconfigured deploy (missing secret) returns 503 — but no test asserts this 503 response shape, so a frontend or monitoring system relying on it has no contract guarantee. The signature-pass and signature-fail paths are both untested; a regression in the verifier service would go undetected until production webhook deliveries start failing.
    - **Plain English:** Supabase sends Partna an email-delivery webhook with a cryptographic signature to prove it's really from Supabase. This middleware checks that signature. There are zero tests for it. If someone accidentally changes the signature library or the secret config key, forged email events could be accepted — or real ones could be rejected — with no test to catch it.
    - **Evidence:**
        ```php
        // app/Http/Middleware/Auth/VerifySupabaseEmailHookSignature.php
        $valid = $this->verifier->verify(
            configuredSecret: $secret,
            webhookId: $webhookId,
            webhookTimestamp: $webhookTimestamp,
            webhookSignatureHeader: $webhookSignature,
            rawBody: $rawBody,
        );

        if (! $valid) {
            return response()->json([
                'error' => true,
                'message' => 'Invalid webhook signature.',
            ], 401);
        }
        // No corresponding test file for this middleware exists in the provided test suite.
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#TEST-5** · P1 — BrandFundingGate middleware has zero test coverage
    - **Where:** app/Http/Middleware/BrandFundingGate.php
    - **Affects:** Brand-side affiliate-invite write endpoints. A broken gate means brands without payment methods could send invites, leaving the platform holding the float for commission payouts.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('passes through when brand has payment method', ...)`.
        - Add `it('returns 402 with brand_funding_required code when no payment method', ...)`.
        - Add `it('passes through for non-brand professionals', ...)`.
        - Add `it('returns 402 with structured payload including connect_path', ...)`.
    - **Technical:** This middleware gates invite creation on `stripe_payment_method_id` being non-null via `StripeConnectService::brandHasPaymentMethod()`. The 402 response carries a structured `code: 'brand_funding_required'` payload that the dashboard reads to render a funding-gate dialog. If the response shape changes (e.g., code key renamed or connect_path dropped), the frontend breaks silently — no toast, no redirect, just a dead invite button. The non-brand pass-through path is also untested; if a staff JWT accidentally hits an invite route and the middleware started rejecting it, a regression test would catch it.
    - **Plain English:** Before a brand can send affiliate invites, Partna checks they have a card on file — because every sale that affiliate makes becomes a commission the brand has to pay. This check is the bouncer at the door. There are zero tests for the bouncer. If the "payment required" response format changes, the dashboard button just stops working with no error message.
    - **Evidence:**
        ```php
        // app/Http/Middleware/BrandFundingGate.php
        if ($this->connectService->brandHasPaymentMethod($professional)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'A payment method is required before sending affiliate invites.',
            'code' => 'brand_funding_required',
            'data' => [
                'reason' => 'no_payment_method',
                'connect_path' => '/account/settings?section=payments',
            ],
        ], 402);
        // No test file provided for this middleware.
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#TEST-6** · P1 — VerifyHydrogenApiKey production fail-closed behavior untested
    - **Where:** app/Http/Middleware/Auth/VerifyHydrogenApiKey.php:14-19
    - **Affects:** All `/internal/hydrogen/*` routes — deployment tokens, brand storefront config rewrite endpoint.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('bypasses in local/testing when api_key config is empty', ...)`.
        - Add `it('throws RuntimeException in production when api_key config is empty', ...)`.
        - Add `it('returns 403 when header is missing', ...)`.
        - Add `it('returns 403 when header does not match', ...)`.
        - Add `it('passes through when header matches configured key', ...)`.
    - **Technical:** Commit `4416acf4` (F6) fixed the P0 silent-bypass bug where an empty config env var would open every `/internal/hydrogen/*` route. The fix gates the bypass on `app()->environment(['local', 'testing'])` and throws in production. But the test that verifies this fix (this is the behavior that must never regress) does not appear in the provided test files. A single env-var misconfig on a production deploy would still open these routes — the fix is code, not a test. The test is the safety net.
    - **Plain English:** This was already flagged as a top-priority security issue (the Hydrogen API key bypass). The code fix is in place, but there's no test that proves it stays fixed. It's like installing a deadbolt but never checking that it actually latches after you close the door. One configuration mistake on a deploy and those internal endpoints go wide open again — and no test fails to warn you.
    - **Evidence:**
        ```php
        // app/Http/Middleware/Auth/VerifyHydrogenApiKey.php — the P0 fix, untested
        if ($expected === '') {
            if (app()->environment(['local', 'testing'])) {
                return $next($request);
            }

            throw new \RuntimeException(
                'services.hydrogen.api_key is not configured — refusing to fall through to bypass outside local/testing.'
            );
        }
        // No test file in the provided tests asserts the production-throw path
        // or exercises the happy-path hash_equals comparison.
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#TEST-7** · P2 — SitePolicy has zero test coverage including complex SiteMedia ownership resolution
    - **Where:** app/Policies/SitePolicy.php
    - **Affects:** All CRUD on Site, Block, SiteMedia, Enquiry, SiteSubdomainAlias, LeadSubmission. The SiteMedia/SubdomainAlias ownership resolution path has a subtle spoofing-prevention check.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `it('view: site owner can view', ...)` + `it('view: non-owner gets 404', ...)` for Site, Block, SiteMedia, SiteSubdomainAlias each.
        - Add `it('update: pending_deletion owner gets 423', ...)`.
        - Add `it('SiteMedia: resolves ownership via preloaded site relation', ...)`.
        - Add `it('SiteMedia: blocks spoofed site relation where site_id does not match', ...)` — the setRelation injection defense.
        - Add `it('SiteSubdomainAlias: same ownership resolution as SiteMedia', ...)`.
    - **Technical:** SitePolicy's `resolveOwnerId` has a two-layer defense for SiteMedia and SiteSubdomainAlias: it requires the caller to `setRelation('site', $site)` to avoid N+1, AND it cross-checks that the resource's `site_id` matches the preloaded site's `id`. This prevents an attacker from injecting a site they own to spoof access to another owner's resource. This cross-check is non-obvious and has zero test coverage — if a refactor drops the `site_id` comparison, the ownership check silently degrades to trusting whatever site is preloaded.
    - **Plain English:** The Site policy handles access to a brand's website content — pages, blocks, images, subdomains. For some of these (images and subdomains), ownership is determined indirectly through the parent site record, and there's a double-check to prevent a clever attack where someone pretends to own a resource by injecting a fake parent. None of this is tested. If someone refactors the image-upload code and accidentally removes the double-check, one brand could potentially see another brand's uploaded images.
    - **Evidence:**
        ```php
        // app/Policies/SitePolicy.php — untested spoofing defense
        if ($resource instanceof SiteMedia || $resource instanceof SiteSubdomainAlias) {
            $site = $resource->getRelation('site');
            if (! $site) {
                return null;
            }

            // Confirm the resource's site_id matches the preloaded site's id
            $resourceSiteId = $resource->getAttributes()['site_id'] ?? null;
            if ($resourceSiteId === null || (string) $resourceSiteId !== (string) $site->id) {
                return null;
            }

            return (string) $site->professional_id;
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#TEST-8** · P2 — BrandPartnerLinkPolicy has zero test coverage
    - **Where:** app/Policies/BrandPartnerLinkPolicy.php
    - **Affects:** BrandPartnerLink, BrandPartnerLinkEvent (immutable audit log), BrandAffiliateInvite — the link/invite system connecting brands and affiliates.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `it('view: brand owner can view their link', ...)` + `it('view: linked affiliate can view', ...)` + `it('view: unrelated actor gets 404', ...)`.
        - Add `it('create: only brand owner can create a link skeleton', ...)` + `it('create: pending_deletion brand gets 423', ...)`.
        - Add `it('update: brand owner can update', ...)` + `it('update: BrandPartnerLinkEvent is immutable (denyAsNotFound)', ...)`.
        - Add `it('view: BrandAffiliateInvite uses claimed_professional_id for affiliate side', ...)`.
    - **Technical:** This policy covers three models: BrandPartnerLink (brand writes, both sides read), BrandPartnerLinkEvent (append-only, no writes), and BrandAffiliateInvite (uses `claimed_professional_id` instead of `affiliate_professional_id`). The `resolveAffiliateId` private method handles the field-name difference — a regression that breaks the invite read path for claimed professionals would have no test to catch it. The audit-log immutability check (`$record instanceof BrandPartnerLinkEvent → denyAsNotFound`) is also untested.
    - **Plain English:** When a brand sends an affiliate invite or establishes a partnership link, this policy controls who can see and modify those records. The rules are: brand can write, both sides can read, and the audit trail is read-only forever. None of these rules have automated tests. If an invite recipient can't see their own invite after claiming it, that's a broken onboarding flow — and no test would flag it.
    - **Evidence:**
        ```php
        // app/Policies/BrandPartnerLinkPolicy.php — all untested
        public function view(Professional $actor, Model $record): bool|Response { ... }
        public function create(Professional $actor, BrandPartnerLink $skeleton): bool|Response { ... }
        public function update(Professional $actor, Model $record): bool|Response { ... }
        public function delete(Professional $actor, Model $record): bool|Response { ... }

        private function resolveAffiliateId(Model $record): string
        {
            return (string) ($record->affiliate_professional_id
                ?? $record->claimed_professional_id
                ?? '');
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#TEST-9** · P2 — NotificationPolicy has zero test coverage including global-notification broadcast logic
    - **Where:** app/Policies/NotificationPolicy.php
    - **Affects:** All notification CRUD — Notification, NotificationEmailPreference, NotificationEmailPolicy, NotificationReceipt, EmailSubscription. The global-notification path (professional_id = null) has special semantics.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `it('view: owner can view their targeted notification', ...)` + `it('view: non-owner gets 404', ...)`.
        - Add `it('view: global notification (null professional_id) is visible to all', ...)`.
        - Add `it('update: global notification is immutable (denyAsNotFound)', ...)` — no owner to authorize writes.
        - Add `it('update: pending_deletion actor gets 423', ...)`.
        - Add `it('view: NotificationEmailPreference follows standard ownership', ...)`.
    - **Technical:** The `view` method has a special early-return for global notifications: `if ($resource instanceof Notification && $resource->professional_id === null) return true;`. The `update` method explicitly denies mutations on global notifications with `denyAsNotFound()`. If someone adds a new notification subtype in the future and the `instanceof Notification` check stops matching, global notifications would become invisible to everyone — or writable by anyone. Neither regression would be caught.
    - **Plain English:** Notifications can be personal (targeted to one user) or global (broadcast to everyone, like a platform announcement). The rules say: everyone can see global notifications, nobody can edit them, and personal notifications are private. None of this is tested. If a code change accidentally makes global notifications behave like personal ones, they'd disappear from everyone's inbox.
    - **Evidence:**
        ```php
        // app/Policies/NotificationPolicy.php
        public function view(Professional $actor, Model $resource): bool|Response
        {
            // Global notifications (null professional_id) are visible to all.
            if ($resource instanceof Notification && $resource->professional_id === null) {
                return true;  // UNTESTED
            }
            // ...
        }

        public function update(Professional $actor, Model $resource): bool|Response
        {
            // Global notifications have no single owner — deny all mutations.
            if ($resource instanceof Notification && $resource->professional_id === null) {
                return $this->denyAsNotFound();  // UNTESTED
            }
            // ...
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#TEST-10** · P2 — CustomerPolicy has zero test coverage
    - **Where:** app/Policies/CustomerPolicy.php
    - **Affects:** All CRUD on Customer records — the professional's client/customer list.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('view: owner can view their customer', ...)` + `it('view: non-owner gets 404', ...)`.
        - Add `it('create: owner can create for self', ...)` + `it('create: cannot create for another professional', ...)`.
        - Add `it('update: pending_deletion owner gets 423', ...)`.
        - Add `it('delete: pending_deletion owner gets 423', ...)`.
    - **Technical:** Standard direct-ownership policy pattern (Shape A in the codebase doctrine). While simple, it still needs tests to enforce the denyAsNotFound contract — the CLAUDE.md spec is that denied-because-not-yours must 404, not 403. Without a test asserting the 404 status code, a refactor to BasePolicy or the Response helper could accidentally change the status and leak resource existence across tenants.
    - **Plain English:** Customer records belong to one professional. The policy says: if you don't own the customer, the API should say "not found" (404) rather than "forbidden" (403) — that way someone can't probe whether a customer ID exists in another account. There are no tests for this policy, so a code change could accidentally switch 404 to 403 and create an information leak.
    - **Evidence:**
        ```php
        // app/Policies/CustomerPolicy.php
        public function view(Professional $actor, Customer $customer): bool|Response
        {
            if ((string) $customer->professional_id !== (string) $actor->id) {
                return $this->denyAsNotFound();  // UNTESTED — 404 contract
            }
            return true;
        }
        // create, update, delete — all untested
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#TEST-11** · P2 — IntegrationPolicy has zero test coverage
    - **Where:** app/Policies/IntegrationPolicy.php
    - **Affects:** Shopify/Fresha/Square OAuth credential management. Team members with `canManageShopify` capability, and the pending_deletion guard.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('view: integration owner can view', ...)` + `it('view: team member with Shopify manage can view', ...)` + `it('view: unrelated actor gets 404', ...)`.
        - Add `it('manage: pending_deletion owner gets 423', ...)`.
        - Add `it('manage: team member can manage (disconnect/sync)', ...)`.
        - Add `it('manage: non-owner, non-team gets denyAsNotFound', ...)`.
    - **Technical:** Uses BrandAccessService delegation similar to CommissionPolicy, but for the `canManageShopify` capability. The `actorCanReachOwner` private method handles both direct ownership and team delegation. Without tests, a capability-name refactor (e.g., renaming `CAPABILITY_SHOPIFY_MANAGE`) or a change to BrandAccessService would silently break integration access for team members — and because integrations are infrequently managed (connect once, rarely touch), the break would go unnoticed for weeks.
    - **Plain English:** When a brand connects their Shopify store, both the brand owner and any team members with Shopify management permissions should be able to view and manage that connection. These permissions are enforced by this policy, and there are zero tests. If someone renames the "manage Shopify" permission key, team members would silently lose access to the integration settings — and since integrations are set up once and rarely touched, nobody would notice for a long time.
    - **Evidence:**
        ```php
        // app/Policies/IntegrationPolicy.php
        private function actorCanReachOwner(Professional $actor, ProfessionalIntegration $integration): bool|Response
        {
            // ...
            return $this->brandAccess->canManageShopify($actor, $ownerId);  // UNTESTED
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#TEST-12** · P2 — ServicePolicy has zero test coverage
    - **Where:** app/Policies/ServicePolicy.php
    - **Affects:** All CRUD on Service and ServiceCategory records (the booking/product catalog).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('view: owner can view', ...)` + `it('view: non-owner gets 404', ...)`.
        - Add `it('create: owner can create for self', ...)` + `it('create: pending_deletion gets 423', ...)`.
        - Add `it('update: pending_deletion owner gets 423', ...)`.
        - Add `it('delete: pending_deletion owner gets 423', ...)`.
    - **Technical:** Standard direct-ownership Shape A policy. Uses `Model` type-hint to cover both Service and ServiceCategory with one policy class. The comment in the file notes "Narrowing to concrete types would require separate policies." If someone splits this into two policies without migrating tests, the gap wouldn't be visible. A test that exercises both model types through the same policy would catch this.
    - **Plain English:** Services and service categories (the booking catalog) are owned by a professional. The ownership rules are straightforward — you can see and edit your own, nobody else's. But there are no tests confirming this. A future refactor that splits services and categories into separate policies could accidentally drop authorization on one of them.
    - **Evidence:**
        ```php
        // app/Policies/ServicePolicy.php — covers Service + ServiceCategory with Model type-hint
        public function view(Professional $actor, Model $resource): bool|Response { ... }
        public function create(Professional $actor, Model $skeleton): bool|Response { ... }
        public function update(Professional $actor, Model $resource): bool|Response { ... }
        public function delete(Professional $actor, Model $resource): bool|Response { ... }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#TEST-13** · P2 — EnforcePendingDeletionReadOnly middleware has zero test coverage
    - **Where:** app/Http/Middleware/Context/EnforcePendingDeletionReadOnly.php
    - **Affects:** Every write request from a user whose account is pending deletion. The 423 response payload drives the frontend cancellation prompt.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('allows GET/HEAD/OPTIONS through for pending_deletion account', ...)`.
        - Add `it('blocks POST/PATCH/PUT/DELETE with 423 for pending_deletion', ...)`.
        - Add `it('returns deletes_at timestamp in the 423 response', ...)`.
        - Add `it('passes through all methods for active account', ...)`.
    - **Technical:** The middleware reads `deletion_confirmed_at` from the professional model, adds `soft_delete_retention_days` (default 30), and returns the ISO 8601 `deletes_at` timestamp. The frontend uses this to render a "your account will be deleted on [date]" prompt with a cancel button. If the `deletes_at` field format changes (e.g., from ISO 8601 to Unix timestamp), the frontend date parser breaks silently. The `confirmedAt instanceof \DateTimeInterface` and `is_string` branches both need coverage.
    - **Plain English:** When someone requests account deletion, there's a 30-day grace period where they can still read their data but can't make changes. The frontend shows them exactly when deletion will happen. There are no tests for the middleware that enforces this. If the date format changes, the frontend would show a broken date — or worse, block the cancel button.
    - **Evidence:**
        ```php
        // app/Http/Middleware/Context/EnforcePendingDeletionReadOnly.php
        return response()->json([
            'message' => 'Account is pending deletion.',
            'pending_deletion' => true,
            'deletes_at' => $deletesAt,  // UNTESTED — frontend depends on this format
        ], 423);
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#TEST-14** · P3 — JWKS-success path in VerifySupabaseJwt not tested for claims exposure
    - **Where:** app/Http/Middleware/Auth/VerifySupabaseJwt.php, tests/Feature/Auth/VerifySupabaseJwtClaimsExposureTest.php
    - **Affects:** AAL step-up, fresh-MFA checks, session-ID tracking — all of which depend on claims being set on the request attributes by the JWKS path.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Generate a test RSA/EC key pair and sign a JWT to exercise the JWKS path end-to-end, or mock `resolveSigningKey` to return a known Key so claims flow through `setSupabaseContext`.
        - Add `it('exposes aal, amr, and session_id from JWKS-decoded claims', ...)` to replace the current self-documented gap.
    - **Technical:** The existing test acknowledges this gap explicitly: "Because our test JWT uses HS256, the JWKS path will throw and we fall through to the auth-server path. To test the JWKS-path attribute-setting we call handle() with a real asymmetric JWT instead — but that requires a key pair." The test then only covers the auth-server fallback (which defaults aal to aal1 and amr to []). The production JWKS path is the primary path — all AAL2/MFA enforcement depends on claims being correctly promoted to request attributes by `setSupabaseContext`. A regression in the claims-to-attributes mapping would go undetected.
    - **Plain English:** The JWT verification has two code paths: one for production (using cryptographic keys) and one for legacy fallback. Only the legacy fallback is tested. The production path is the one that actually reads multi-factor-authentication status from the token. If a code change accidentally stops reading the MFA claims, all the "require MFA" checks would silently treat everyone as unverified — and no test would catch it because the test only exercises the fallback path.
    - **Evidence:**
        ```php
        // tests/Feature/Auth/VerifySupabaseJwtClaimsExposureTest.php — self-documented gap
        it('exposes aal, amr, and session_id on the request attributes when the JWKS path sets claims', function () {
            // On the JWKS-success path the claims array is passed to setSupabaseContext.
            // We simulate this by calling the middleware and inspecting what it sets on
            // the request after a successful JWKS decode. Because our test JWT uses HS256,
            // the JWKS path will throw and we fall through to the auth-server path. To
            // test the JWKS-path attribute-setting we call handle() with a real
            // asymmetric JWT instead — but that requires a key pair.
            //
            // [falls through to test the auth-server fallback instead]
        });
        ```
    - `[DRAFT, confidence: 0.85]`

<!-- ═══ CHUNK: tc-migration ═══ -->

No test or application source files were provided — only migration SQL is available. Without `tests/`, `app/`, or `database/factories/` directories, it is impossible to verify coverage, identify missing tests, or quote evidence from the codebase. No findings can be produced.
