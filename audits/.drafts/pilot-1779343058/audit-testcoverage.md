# Test Coverage Audit — 2026-05-21

**Branch:** development
**Lens:** Whole-backend PILOT audit — 'testcoverage' lens
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/`
- `supabase/migrations/`
- `tests/`

> **Adjudication notes:** Four draft findings dropped as false positives after `Glob`/`Read` verification: `StripePlatformWebhookControllerTest.php` has 18 tests (draft claimed zero); `StripeConnectWebhookDedupeTest.php` covers dedup (draft claimed missing); `LoadCurrentProfessionalTest.php`, `VerifyShopifySessionTokenTest.php`, `VerifyHydrogenApiKeyTest.php`, `ReadOnlyEnforcementTest.php` all exist (draft claimed zero); `CommissionExportServiceTest.php` fully covers the dispatcher (draft claimed zero); `SupabaseEmailHookTest.php` tests signature validation end-to-end through HTTP stack.

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 3 complete
- P2 Medium: 0 of 16 complete
- P3 Low: 0 of 6 complete

---

## P1 — Fix before pilot launch

- [ ] **#TEST-1** · P1 — Secondary Shopify webhook controllers missing dedup tests
    - **Where:** `tests/Feature/Webhooks/Shopify/SecondaryWebhookHmacTest.php` (entire file — dataset covers `orders/edited`, `orders/cancelled`, `refunds/create`, `themes/publish`, all three GDPR webhooks)
    - **Affects:** Webhook idempotency for three order-lifecycle topics — a re-delivered webhook that passes HMAC but should be caught by the Redis `Cache::add` dedup gate silently re-processes.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a dedup test for each of `orders/edited`, `orders/cancelled`, and `refunds/create`: post the same payload with the same `X-Shopify-Webhook-Id` twice; assert second response is `{ duplicate: true }` and exactly one job dispatch.
        - Mirror the pattern from `ShopifyOrderWebhookControllerTest.php` `it('orders/paid — second delivery with same X-Shopify-Webhook-Id returns duplicate=true')`.
    - **Technical:** All three controllers extend `HandlesShopifyWebhook` which calls `claimShopifyWebhookEvent` — a `Cache::add` atomic lock — before dispatching. The primary controllers (`orders/paid`, `orders/updated`) have this dedup path tested. The secondary controllers were added later and received HMAC coverage via the dataset sweep but never got corresponding dedup tests. Shopify documents at-least-once delivery explicitly, making duplicate webhook delivery a documented, expected scenario. A refactor to the `HandlesShopifyWebhook` trait that breaks `Cache::add` would be caught by the primary controller tests but not by these three.
    - **Plain English:** Shopify deliberately delivers the same webhook more than once to handle network hiccups. The system keeps a "seen it" note in Redis — first delivery processes, second delivery sees the note and skips. We've tested this for the main "order paid" webhook but not for the three supporting webhooks (order edited, cancelled, refunded). If someone changes the code that writes the note, these three would silently re-process duplicate deliveries — double-voiding commissions or applying the same refund twice.
    - **Evidence:**
        ```php
        // tests/Feature/Webhooks/Shopify/SecondaryWebhookHmacTest.php
        // Only two tests exist, both HMAC-only:
        it('secondary webhook returns 401 on bad HMAC and dispatches nothing', function (string $method, string $path) { ... });
        it('secondary webhook returns 401 on missing HMAC header', function (string $method, string $path) { ... });

        // Versus the tested primary — ShopifyOrderWebhookControllerTest.php:
        it('orders/paid — second delivery with same X-Shopify-Webhook-Id returns duplicate=true', function () { ... });
        ```

- [ ] **#TEST-2** · P1 — `DB::shouldReceive` mock in app-uninstall test makes the transaction body dead code
    - **Where:** `tests/Feature/Webhooks/Shopify/ShopifyAppUninstalledWebhookControllerTest.php:283`
    - **Affects:** The `finally`-block `Cache::forget` path is tested, but the real transaction closure (token nulling, metadata update, brand-profile transition) is never executed in this test — a regression inside the closure would pass.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `DB::shouldReceive('transaction')->once()->andThrow(...)` with a real DB failure path: e.g. seed a constraint violation on an inner `update()`, or trigger a model observer that throws during the real transaction.
        - Keep the existing `Cache::has()` assertion — it correctly tests the `finally` release.
    - **Technical:** `DB::shouldReceive('transaction')` intercepts the call before any SQL reaches Postgres. The test verifies that `Cache::forget` runs in the `finally` block, but it never runs the transaction closure that nulls the access token, updates provider metadata, or transitions brand status. A silent NPE or type error inside the closure would not be caught. Integration tests must exercise the real DB layer per the architecture rules.
    - **Plain English:** This test simulates the database crashing by putting a fake wall in front of the database door. The test confirms the code cleans up after a crash, but never opens the door itself. If the "clear access token and disconnect the brand" code has a bug, this test won't find it — that code never runs.
    - **Evidence:**
        ```php
        // tests/Feature/Webhooks/Shopify/ShopifyAppUninstalledWebhookControllerTest.php:283
        DB::shouldReceive('transaction')->once()->andThrow(new \RuntimeException('simulated db failure'));
        ```

- [ ] **#TEST-3** · P1 — `CommissionPolicy` `view`, `update`, `delete`, `startConnect`, `viewProjections`, `viewOwnTransactions` abilities have zero test coverage
    - **Where:** `app/Policies/CommissionPolicy.php`; `tests/Feature/Policies/CommissionPolicyAbilityTest.php` (covers only `viewOwnPayouts`, `managePaymentMethod`, `manageWallet`)
    - **Affects:** Every brand/affiliate accessing commission records; team members with delegated financial-analytics capability; Stripe Connect onboarding flow.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `it('view: brand owner can view', ...)`, `it('view: affiliate can view own', ...)`, `it('view: team member with financial read can view', ...)`, `it('view: unrelated actor gets 404', ...)` covering the `BrandAccessService` delegation path.
        - Add `it('update: brand owner can update', ...)` plus deny cases for `pending_deletion`, non-owner, affiliate.
        - Add `it('startConnect: self only', ...)` + `it('startConnect: different professional denied', ...)`.
        - Add `it('viewProjections: only matching affiliate', ...)`.
        - Add `it('viewOwnTransactions: brand sees brand-side, affiliate sees affiliate-side', ...)`.
    - **Technical:** The `view` method delegates to `BrandAccessService::canReadBrandFinancialAnalytics()`. A Mockery mock of `BrandAccessService` is wired in the test file's `beforeEach` but never exercised for the `view` ability. If the capability check changes or the team-member auth model shifts — both plausible pre-launch — there is zero test to catch the regression. The `startConnect` ability guards the Stripe Connect onboarding flow; the `viewProjections` ability gates affiliate earnings projections. Both have business-critical implications.
    - **Plain English:** The authorization rules for who can see commission financial records involve a chain: brand owner → yes, affiliate → yes for their own, team member → yes if they have the "read financials" permission. None of this chain has automated tests. If someone refactors the team-permission system, commission visibility could break silently — either leaking data across accounts or hiding records that should be visible. Given these are financial records, a cross-tenant read is a data breach.
    - **Evidence:**
        ```php
        // app/Policies/CommissionPolicy.php — untested methods:
        public function view(Professional $actor, Model $record): bool|Response
        {
            // Brand team member with financial read capability — UNTESTED PATH
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

        // tests/Feature/Policies/CommissionPolicyAbilityTest.php — only covers:
        //   viewOwnPayouts (6 tests), managePaymentMethod (4 tests), manageWallet (4 tests)
        ```

---

## P2 — Should fix

- [ ] **#TEST-4** · P2 — `CommissionAdjustmentService` PostgreSQL `23505` catch path exercised only via mock, not seeded row
    - **Where:** `app/Services/Stripe/CommissionAdjustmentService.php:64-65`; `tests/Feature/Staff/StaffCommissionAdjustmentControllerTest.php:123`
    - **Affects:** Staff-initiated commission adjustments; duplicate-prevention via `idempotency_key` unique index.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a test that seeds a `CommissionMovement` row with a known `idempotency_key` and then calls `CommissionAdjustmentService::post` with the same reference string, asserting `DuplicateAdjustmentException` is thrown from the real Postgres constraint — not a mock.
        - The controller test at line 123 already validates the exception is surfaced as the right HTTP response; this test validates the DB layer actually raises it.
    - **Technical:** The controller test mocks `DuplicateAdjustmentException` being thrown (it imports the exception and sets it up via `andThrow`) rather than seeding a real colliding row. If a future migration renames the `idempotency_key` column or drops the unique index, the duplicate-prevention mechanism silently stops working but the controller test still passes because it mocks the exception at the service level. The real integration test must trigger the `23505` code path through actual Postgres.
    - **Plain English:** The safety rail that prevents applying the same commission correction twice is the database refusing to accept a duplicate entry (using a "cheque number" as a unique key). The controller test proves the error is reported correctly — but it fakes the error. A real test would actually try to insert the same cheque twice and confirm the database itself blocks it. If someone accidentally removes the unique constraint, the fake test still passes.
    - **Evidence:**
        ```php
        // app/Services/Stripe/CommissionAdjustmentService.php:64-65
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw new DuplicateAdjustmentException($reference, previous: $e);
            }
            throw $e;
        }

        // tests/Feature/Staff/StaffCommissionAdjustmentControllerTest.php:123 — mocked, not real:
        ->andThrow(new DuplicateAdjustmentException('support-ticket-dup'));
        ```

- [ ] **#TEST-5** · P2 — `StripeRowGenerator` normalisation helpers assumed byte-identical to `StripeTransactionFetcher` but unverified by test
    - **Where:** `app/Services/Stripe/StripeRowGenerator.php:17-22`
    - **Affects:** Async commission export pipeline correctness; row-shape drift between the sync dashboard fetcher and the async export generator.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Stripe/StripeRowGeneratorTest.php` that seeds a `CommissionPayout` with known brand/affiliate, mocks the Stripe client to return a controlled PI/charge/transfer shape, and asserts the yielded row structure matches `StripeTransactionFetcher` output field-for-field.
        - Add a test for the error path: when Stripe throws on retrieve, the generator skips the payout and logs a warning (assert no yield, assert log).
    - **Technical:** `StripeRowGenerator::forPayouts` uses normalisation helpers copied verbatim from `StripeTransactionFetcher` (annotated in the source). The generator feeds async export chunk jobs; the fetcher feeds the sync dashboard endpoint. Without a pinning test, a future developer could refactor one normaliser and not the other, introducing silent row-shape divergence between the transaction list and the exported CSV/XLSX.
    - **Plain English:** Two different code paths are supposed to produce identical row shapes — one for the live dashboard and one for exported files. There's a comment saying "do not change these" but comments don't prevent drift. Without a test that compares both outputs for the same input, a change to one path would mean the export shows different numbers than the dashboard for the exact same data, eroding user trust in the export feature.
    - **Evidence:**
        ```php
        // app/Services/Stripe/StripeRowGenerator.php:17-22
        // Normalisation helpers — copied verbatim from StripeTransactionFetcher.
        // DO NOT modify these; row shape must stay byte-identical to the legacy
        // fetcher so downstream consumers (TransactionResource, export formatters)
        // see the same structure.
        ```

- [ ] **#TEST-6** · P2 — `MonitorManualRefundQueueJob` has no test covering `handle()` or `failed()`
    - **Where:** `app/Jobs/Stripe/MonitorManualRefundQueueJob.php`
    - **Affects:** Ops visibility into payouts needing manual refund attention; the SCALE-2 count/fetch split is untested.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `tests/Feature/Stripe/MonitorManualRefundQueueJobTest.php` covering: empty queue → logs info, non-empty queue under 200 → logs warning with count + list, queue over 200 → logged count reflects true backlog (not capped), `failed()` → calls `report($e)` and logs error.
    - **Technical:** The SCALE-2 fix separates the count query from the fetch query so the logged count reflects the true queue depth even when the fetch is capped at 200. Without a test, a future change that accidentally re-merges count and fetch into one uncapped query would OOM the job on a large backlog. The `failed()` handler calls `report($e)` — also untested.
    - **Plain English:** Every morning a job sends ops a list of payouts that need human attention. There are zero tests for this job. If a future change loads all of them into memory at once instead of capping the fetch, the job crashes on a large backlog — and ops never sees the alert. Payouts sit un-reviewed indefinitely.
    - **Evidence:**
        ```php
        // app/Jobs/Stripe/MonitorManualRefundQueueJob.php:43-48
        // SCALE-2: separate count query for true backlog size, then cap the fetch
        $totalCount = (clone $baseQuery)->count();
        $open = (clone $baseQuery)
            ->with([...])
            ->orderBy('updated_at')
            ->limit(200)
            ->get();
        ```

- [ ] **#TEST-7** · P2 — `SyncStripeAccountStatusJob` `not_connected` skip guard and `failed()` handler untested in isolation
    - **Where:** `app/Jobs/Stripe/SyncStripeAccountStatusJob.php:51-59`
    - **Affects:** Reliability of Stripe account status sync when webhook handler decouples from the sync work; a broken skip guard could re-activate an explicitly disconnected account.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `tests/Feature/Stripe/SyncStripeAccountStatusJobTest.php` covering: professional found → `syncAccountStatus` called, professional not found → silent return, `stripe_connect_status='not_connected'` → logs "skipped_not_connected" and does NOT call `syncAccountStatus`, `failed()` → calls `report($e)` and logs error.
    - **Technical:** The skip guard prevents a late webhook event from re-activating an account the brand explicitly disconnected. A refactor removing or weakening this guard could flip a disconnected account back to 'active' from a stale Stripe event. The webhook controller test file covers dispatch (the test that dispatches this job) but not the job's internal logic.
    - **Plain English:** This job keeps Stripe account status in sync after Stripe sends a webhook. There's a safety check that says "if the professional already disconnected their Stripe account on our side, ignore any late-arriving updates." That safety check has no test. A bug here could mean a brand who disconnects Stripe suddenly sees their account marked "active" again from a delayed webhook.
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

- [ ] **#TEST-8** · P2 — `VoidPendingCommissionsForLinkJob` orchestration (auditor + notifier calls after void loop) untested
    - **Where:** `app/Jobs/Stripe/VoidPendingCommissionsForLinkJob.php:43-63`
    - **Affects:** Commission voiding when a brand-affiliate partnership is disconnected; audit trail completeness; notification delivery to both parties.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Stripe/VoidPendingCommissionsForLinkJobTest.php` covering: happy path → `runVoidLoop` called + `auditor::recordAsyncVoidCompletion` called + notifier called on both sides; missing professional → logs warning and returns; `failed()` → calls `report($e)`.
    - **Technical:** `CommissionVoidService::runVoidLoop` is tested in `CommissionVoidServiceTest.php`, but the job's orchestration — the guarantee that the auditor and notifier are called with correct parameters AFTER the void loop completes — is not. The `loadProfessionals()` early-return path (either professional is null → skip) is also untested.
    - **Plain English:** When a brand removes an affiliate, this job voids pending commissions, writes an audit record, and notifies both parties. The individual pieces are tested but the conductor that wires them together isn't. A change that swaps the order (notifying before voiding) or drops the audit entry would go undetected.
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

- [ ] **#TEST-9** · P2 — `StripeBillingService` methods beyond `ensureStripeCustomer` and `createCheckoutSession` have no coverage
    - **Where:** `app/Services/Stripe/StripeBillingService.php` (`createBillingPortalSession`, `updateSubscriptionPlan`, `cancelSubscriptionAtPeriodEnd`, `resumeSubscription`, `cancelSubscriptionImmediately`, `previewPlanChange`)
    - **Affects:** Plan change proration previews, subscription cancellation/resume flows, billing portal session creation — all user-facing subscription management features.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Stripe/StripeBillingServiceTest.php` with mocked Stripe client covering each method: `updateSubscriptionPlan` with both proration modes, `cancelSubscriptionAtPeriodEnd` sets `cancel_at_period_end=true`, `resumeSubscription` sets it false, `cancelSubscriptionImmediately` calls `subscriptions->cancel()`, `previewPlanChange` returns correct invoice preview shape, `createBillingPortalSession` returns a URL.
    - **Technical:** Six methods on `StripeBillingService` have zero test coverage. `StripeWebhookSubscriptionUpdatedTest` tests `handleSubscriptionUpdated` but not the service methods directly. A Stripe SDK version bump or change to the subscription items shape would break these silently.
    - **Plain English:** The billing service has eight public methods; only two are tested. The other six — changing plans, previewing price changes, canceling, resuming, and opening the billing portal — are entirely unprotected against regressions. These are the features users interact with when managing their subscription.
    - **Evidence:**
        ```php
        // app/Services/Stripe/StripeBillingService.php — untested methods:
        public function createBillingPortalSession(...)       // line ~96
        public function updateSubscriptionPlan(...)           // line ~109
        public function cancelSubscriptionAtPeriodEnd(...)    // line ~127
        public function resumeSubscription(...)               // line ~135
        public function cancelSubscriptionImmediately(...)    // line ~143
        public function previewPlanChange(...)                // line ~151
        ```

- [ ] **#TEST-10** · P2 — `BrandFundingGate` middleware has no test for the 402 response contract the frontend depends on
    - **Where:** `app/Http/Middleware/BrandFundingGate.php`
    - **Affects:** Brand-side affiliate-invite write endpoints; the 402 response payload drives the frontend funding-gate dialog.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('passes through when brand has payment method', ...)`.
        - Add `it('returns 402 with brand_funding_required code when no payment method', ...)`.
        - Add `it('returns 402 payload contains connect_path', ...)` — the frontend reads `data.connect_path` to render the dialog button.
        - Add `it('passes through for non-brand professionals', ...)`.
    - **Technical:** The middleware gates invite creation on `stripe_payment_method_id` being non-null via `StripeConnectService::brandHasPaymentMethod()`. The 402 response carries a `code: 'brand_funding_required'` payload and `data.connect_path` that the dashboard reads to render a funding-gate dialog. If either the code key or `connect_path` are renamed, the frontend dialog silently breaks. No test file exists for this middleware.
    - **Plain English:** Before a brand can send affiliate invites, the system checks they have a card on file. It returns a specific error code and a link to the payment settings page — the frontend reads both to show a helpful dialog. There are zero tests for this middleware. If someone renames the error code, the frontend button stops working with no error message shown to the user.
    - **Evidence:**
        ```php
        // app/Http/Middleware/BrandFundingGate.php
        return response()->json([
            'message' => 'A payment method is required before sending affiliate invites.',
            'code' => 'brand_funding_required',
            'data' => [
                'reason' => 'no_payment_method',
                'connect_path' => '/account/settings?section=payments',
            ],
        ], 402);
        ```

- [ ] **#TEST-11** · P2 — `ShopifyThemePublishedWebhookController` inline `Cache::add` dedup path untested
    - **Where:** `app/Http/Controllers/Api/Webhooks/Shopify/ShopifyThemePublishedWebhookController.php`; `tests/Feature/Webhooks/Shopify/SecondaryWebhookHmacTest.php`
    - **Affects:** Duplicate `themes/publish` deliveries — the controller uses inline `Cache::add` rather than the shared trait, making it structurally isolated from the trait's test coverage.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a test posting the same payload with the same `X-Shopify-Webhook-Id` twice, asserting second response is `{ duplicate: true }`.
    - **Technical:** Unlike the order webhook controllers that use `HandlesShopifyWebhook`, `ShopifyThemePublishedWebhookController` inlines its own `Cache::add` dedup gate. A refactor to the inline implementation (e.g., changing the cache key prefix) would not be caught by any existing test.
    - **Plain English:** This webhook handler has its own custom "seen it" check instead of using the shared helper. The test file only verifies bad signatures are rejected — it never verifies the "seen it" check works. Low functional risk since this controller is a deliberate no-op, but inconsistencies in test coverage create false confidence.
    - **Evidence:**
        ```php
        // app/Http/Controllers/Api/Webhooks/Shopify/ShopifyThemePublishedWebhookController.php
        if ($webhookId !== '') {
            $dedupeKey = "shopify:webhook:themes-publish:{$webhookId}";
            if (! Cache::add($dedupeKey, true, (int) config('partna.cache.ttls.webhook_idempotency'))) {
                return $this->success(['received' => true, 'duplicate' => true]);
            }
        }
        ```

- [ ] **#TEST-12** · P2 — Five Shopify install-chain jobs missing `failed()` handler tests
    - **Where:** `app/Jobs/Shopify/CreateShopifyCollectionsJob.php`, `CreateShopifyMetafieldsJob.php`, `CreateShopifySalesChannelJob.php`, `CreateStorefrontAccessTokenJob.php`, `SetShopifySetupCompleteJob.php`
    - **Affects:** Setup wizard state display — when any of these jobs exhaust retries, `failed()` writes a `*_state = 'failed'` key to `provider_metadata` that the setup-status API reads. A broken write means the wizard shows "still installing" forever.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a test file for each of the five jobs (or bundle them in one file).
        - Each should assert that calling `$job->failed(new RuntimeException('test'))` sets the appropriate `*_state` key to `'failed'` on the integration row.
        - Model on the existing `CreateShopifyAffiliateDiscountJobTest.php` and `BackfillBrandHasEnabledVariantsJobTest.php` which already test `failed()` handlers.
    - **Technical:** `CreateShopifyAffiliateDiscountJob` and `BackfillBrandHasEnabledVariantsJob` both have dedicated test files asserting their `failed()` handlers set the expected metadata key. The five sibling install-chain jobs were written in the same pattern but never received equivalent coverage. Three of the eight jobs in the install chain have tests; five don't.
    - **Plain English:** The Shopify install runs eight jobs in sequence. Each job has a "if I fail after all retries, mark my step as failed so the brand can see what broke" handler. Three of the eight have tests proving that handler works. Five don't. If a future change accidentally removes the "mark as failed" line from any of those five, no test catches it — the brand's dashboard shows "still installing" forever.
    - **Evidence:**
        ```php
        // app/Jobs/Shopify/CreateShopifySalesChannelJob.php — representative example
        public function failed(\Throwable $e): void
        {
            $integration = ProfessionalIntegration::find($this->integrationId);
            $integration?->mergeProviderMetadata(['sales_channel_state' => 'failed']);
        }
        // No tests/Feature/Shopify/CreateShopifySalesChannelJobTest.php exists.
        ```

- [ ] **#TEST-13** · P2 — `ReconcileStuckShopifyIntegrationsJob` auto-heal logic has zero test coverage
    - **Where:** `app/Jobs/Shopify/ReconcileStuckShopifyIntegrationsJob.php`
    - **Affects:** Stuck-integration auto-healing — this daily sweep is the safety net for lost `app/uninstalled` webhooks. A regression that breaks 401-detection stops healing without any alarm.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Shopify/ReconcileStuckShopifyIntegrationsJobTest.php`.
        - Test: 401 response → token cleared + `disconnected_at` set + brand status → Disconnected; 200 + matching domain → row untouched; 500 → row untouched (transient); domain mismatch → disconnected; network timeout → row untouched.
        - Fake Shopify HTTP calls with `Http::fake()`; seed real integration rows in the test DB.
    - **Technical:** The `validateAccessToken` private method classifies responses into three buckets: healthy (leave alone), revoked (heal), transient (leave alone). None of these classification branches are tested. Without coverage, a regression in the 401-detection branch means broken stores pile up silently — integrations that should be disconnected stay connected with dead tokens.
    - **Plain English:** This daily background job is the janitor that finds Shopify stores whose connection silently broke (Shopify's "app uninstalled" notification sometimes gets lost). It calls Shopify, checks if the token still works, and disconnects broken ones. None of this "check and clean" logic has tests. A bug here means broken connections pile up silently — no alarms, no fix.
    - **Evidence:**
        ```php
        // app/Jobs/Shopify/ReconcileStuckShopifyIntegrationsJob.php
        // Entire class — ~180 lines of resolution logic — zero tests
        private function validateAccessToken(ProfessionalIntegration $integration): array
        {
            // 401 → revoked (heal), 5xx/network → transient (leave),
            // 2xx + matching domain → healthy, 2xx + mismatched → heal
        }
        ```

- [ ] **#TEST-14** · P2 — `ProcessShopifyShopUpdateJob` dead code still carries mutation risk with no test
    - **Where:** `app/Jobs/Shopify/ProcessShopifyShopUpdateJob.php`
    - **Affects:** Brand profile auto-resync — if the job is ever re-dispatched (manual ops, future flag), it writes `display_name` and dispatches `SyncShopifyBrandDesignJob` with no test asserting correct behavior.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either add `tests/Feature/Shopify/ProcessShopifyShopUpdateJobTest.php` asserting the `resyncFromShopData` + brand-design-dispatch path, **or** delete the job class since its only dispatcher (`ShopifyShopUpdateWebhookController`) explicitly no-ops with a log message.
    - **Technical:** The controller was changed to a deliberate no-op (log only) to prevent overwriting brand edits. The job class remains fully functional and could be revived by connecting a new dispatcher. If reactivated untested, it would silently overwrite brand data.
    - **Plain English:** This job used to run every time a brand changed their Shopify settings, automatically pulling changes into Partna. The trigger was disabled because it was overwriting Partna-side edits. But the job itself is still sitting there, fully functional, waiting to cause trouble if someone reconnects it. Either test it or delete it.
    - **Evidence:**
        ```php
        // app/Jobs/Shopify/ProcessShopifyShopUpdateJob.php — no test file exists
        public function handle(): void
        {
            app(ShopProfileAutoFillService::class)->resyncFromShopData($integration, $this->payload);
            SyncShopifyBrandDesignJob::dispatch((string) $integration->id);
        }

        // The only dispatcher now no-ops:
        // app/Http/Controllers/Api/Webhooks/Shopify/ShopifyShopUpdateWebhookController.php
        protected function dispatchWebhookJob(...): void
        {
            Log::info('Shopify shop/update webhook ignored — auto-resync disabled.', [...]);
        }
        ```

- [ ] **#TEST-15** · P2 — `SitePolicy` spoofing-prevention cross-check (`site_id` vs preloaded site) has zero test coverage
    - **Where:** `app/Policies/SitePolicy.php`
    - **Affects:** `SiteMedia` and `SiteSubdomainAlias` ownership resolution — a broken cross-check could let one professional access another's media/subdomain records via a preloaded-site injection.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `it('SiteMedia: resolves ownership via preloaded site relation', ...)`.
        - Add `it('SiteMedia: blocks spoofed site relation where site_id does not match preloaded site id', ...)` — the `site_id` comparison defense.
        - Add `it('view: non-owner gets 404', ...)` for Site, Block, SiteMedia, SiteSubdomainAlias.
        - Add `it('update: pending_deletion owner gets 423', ...)`.
    - **Technical:** `SitePolicy::resolveOwnerId` requires callers to `setRelation('site', $site)` AND cross-checks that the resource's raw `site_id` attribute matches the preloaded site's `id`. This cross-check prevents an attacker from injecting a site they own to spoof access to another owner's resource. A refactor that drops the `site_id` comparison would silently degrade the ownership check to trusting whatever site is preloaded — with no test catching it.
    - **Plain English:** The Site policy handles access to a brand's website content — pages, blocks, images, subdomains. For images and subdomains, there's a double-check: the code verifies that the image's stored "parent site" ID matches the site that was pre-loaded, preventing a clever trick where someone could inject a fake parent. None of this is tested. A future code change could accidentally remove the double-check.
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

- [ ] **#TEST-16** · P2 — `BrandPartnerLinkPolicy` has zero test coverage
    - **Where:** `app/Policies/BrandPartnerLinkPolicy.php`
    - **Affects:** `BrandPartnerLink`, `BrandPartnerLinkEvent` (immutable audit log), `BrandAffiliateInvite` — the link/invite system connecting brands and affiliates.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `it('view: brand owner can view their link', ...)`, `it('view: linked affiliate can view', ...)`, `it('view: unrelated actor gets 404', ...)`.
        - Add `it('update: BrandPartnerLinkEvent is immutable (denyAsNotFound)', ...)`.
        - Add `it('view: BrandAffiliateInvite uses claimed_professional_id for affiliate side', ...)`.
    - **Technical:** The `resolveAffiliateId` private method handles the field-name difference between `affiliate_professional_id` and `claimed_professional_id`. A regression that breaks the invite read path for claimed professionals would have no test to catch it. The audit-log immutability check (`$record instanceof BrandPartnerLinkEvent → denyAsNotFound`) is also untested.
    - **Plain English:** When a brand sends an affiliate invite or establishes a partnership, this policy controls who can see and modify those records. The rules are: brand can write, both sides can read, the audit trail is read-only forever. None of these rules are tested. A bug where an invited affiliate can't see their own invite would break onboarding with no test to catch it.
    - **Evidence:**
        ```php
        // app/Policies/BrandPartnerLinkPolicy.php — all untested
        private function resolveAffiliateId(Model $record): string
        {
            return (string) ($record->affiliate_professional_id
                ?? $record->claimed_professional_id
                ?? '');
        }
        ```

- [ ] **#TEST-17** · P2 — `NotificationPolicy` global-notification broadcast semantics untested
    - **Where:** `app/Policies/NotificationPolicy.php`
    - **Affects:** All notification CRUD — the global-notification path (`professional_id = null`) has special visibility and immutability semantics untested anywhere.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `it('view: global notification (null professional_id) is visible to all authenticated users', ...)`.
        - Add `it('update: global notification is immutable (denyAsNotFound)', ...)`.
        - Add `it('view: personal notification is private — non-owner gets 404', ...)`.
    - **Technical:** The `view` method has a special early-return for global notifications (`professional_id === null → true`). The `update` method explicitly denies mutations on global notifications with `denyAsNotFound`. If the `instanceof Notification` check ever stops matching (e.g., a notification subtype is introduced), global notifications become invisible to everyone or writable by anyone with no test catching it.
    - **Plain English:** Notifications can be personal (one user) or global (everyone sees them, like platform announcements). The rules say everyone can see global ones, nobody can edit them. If a code change accidentally treats global notifications like personal ones, they disappear from everyone's inbox — with no test to flag it.
    - **Evidence:**
        ```php
        // app/Policies/NotificationPolicy.php — untested
        if ($resource instanceof Notification && $resource->professional_id === null) {
            return true;  // global — visible to all
        }
        // update method:
        if ($resource instanceof Notification && $resource->professional_id === null) {
            return $this->denyAsNotFound();  // global — immutable
        }
        ```

- [ ] **#TEST-18** · P2 — `CustomerPolicy`, `IntegrationPolicy`, `ServicePolicy` have zero test coverage
    - **Where:** `app/Policies/CustomerPolicy.php`, `app/Policies/IntegrationPolicy.php`, `app/Policies/ServicePolicy.php`
    - **Affects:** Customer record CRUD; Shopify/Fresha/Square OAuth credential management (including team-member delegation via `canManageShopify`); Service and ServiceCategory catalog CRUD.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - For each: add `it('view: owner can view', ...)`, `it('view: non-owner gets 404', ...)`, `it('create/update/delete: pending_deletion gets 423', ...)`.
        - For `IntegrationPolicy`: add `it('manage: team member with Shopify manage can manage', ...)` and `it('manage: non-owner, non-team gets denyAsNotFound', ...)`.
    - **Technical:** All three are structurally simple direct-ownership policies but must enforce `denyAsNotFound()` for 404-not-403 semantics per the Partna Authorization Doctrine. Without tests asserting the 404 status code, a `BasePolicy` refactor or `Response` helper change could flip the status and create an enumeration vector. `IntegrationPolicy` additionally has a `BrandAccessService` delegation path for `canManageShopify` — untested.
    - **Plain English:** Three separate "who can access what" policies — one for customer lists, one for Shopify connections, one for service catalogs — have zero tests. The key rule these are supposed to enforce is: if you don't own the record, the API should say "not found" rather than "forbidden," so you can't probe whether other accounts' data exists. A code change could silently change this behavior with no test failing.
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

        // app/Policies/IntegrationPolicy.php
        private function actorCanReachOwner(Professional $actor, ProfessionalIntegration $integration): bool|Response
        {
            return $this->brandAccess->canManageShopify($actor, $ownerId);  // UNTESTED
        }
        ```

---

## P3 — Nice to have

- [ ] **#TEST-19** · P3 — Concurrent payout state transition race test missing
    - **Where:** `tests/Feature/Stripe/StripePlatformWebhookControllerTest.php` (missing test scenario)
    - **Affects:** Correctness when `payment_intent.succeeded` arrives concurrent with a refund-initiated clawback; the `afterCommit` decoupling and idempotency guard interaction.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a test that asserts the `if ($payout->status === 'completed') return;` early-return in `markPaymentIntentSucceeded` interacts correctly with the clawback path — post a payout in `'processing'`, call `markPaymentIntentSucceeded`, then verify a concurrent `handleOrderRefund` call on the now-completed payout produces the expected clawback state.
    - **Technical:** Existing dedup tests cover same-event-id sequential delivery. The cross-event race (`payment_intent.succeeded` concurrent with `charge.refunded`) is not covered. The existing platform webhook test file is thorough on happy paths; this would fill the concurrent-delivery gap.
    - **Plain English:** We've tested that duplicate webhooks of the same type are handled gracefully. But we haven't tested two different webhook types arriving at the same time for the same payout. Low priority — the individual handlers have been tested; this is belt-and-suspenders.
    - **Evidence:**
        ```php
        // CommissionPayoutService.php — markPaymentIntentSucceeded early return:
        if ($payout->status === 'completed') {
            return;  // idempotent guard — interaction with concurrent clawback untested
        }
        ```

- [ ] **#TEST-20** · P3 — `ApiConnectionException` retry path missing `payment_intent_id` null assertion
    - **Where:** `tests/Feature/Stripe/CommissionPayoutServiceTest.php` (the "re-throws ApiConnectionException" test)
    - **Affects:** Horizon retry correctness — ensures the payout is left in `'processing'` with no `payment_intent_id` so the retry re-creates the PI with the same idempotency key.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `expect($payout->fresh()->payment_intent_id)->toBeNull()` to the existing `ApiConnectionException` re-throw test.
    - **Technical:** The existing test asserts `status` stays `'processing'` but not that `payment_intent_id` remains null. If a future refactor pre-saves `payment_intent_id` before the Stripe call, the retry would hit the "PI already exists" guard and permanently park the payout.
    - **Plain English:** A test checks that when Stripe is unreachable, the job retries correctly. But it doesn't check one detail: that the payout isn't accidentally marked as "already sent to Stripe." If that mark was accidentally written before the network call failed, the retry would skip it permanently.
    - **Evidence:**
        ```php
        // tests/Feature/Stripe/CommissionPayoutServiceTest.php — assertion present:
        expect($payout->fresh()->status)->toBe('processing');
        // Assertion NOT present:
        // expect($payout->fresh()->payment_intent_id)->toBeNull();
        ```

- [ ] **#TEST-21** · P3 — `StripeWebhookBasilPeriodTest` uses reflection to bypass HTTP stack
    - **Where:** `tests/Feature/Stripe/StripeWebhookBasilPeriodTest.php:48-52`
    - **Affects:** Confidence that `customer.subscription.created` with Basil-shape period fields works end-to-end through the real webhook entry point.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add an integration test in `StripeWebhookControllerEndToEndTest.php` that posts a signed Basil-shape `customer.subscription.created` payload through the full HTTP stack and asserts the local `Subscription` row was created with correct `current_period_start`/`current_period_end` from `items.data[0]`.
    - **Technical:** The reflection test invokes `handleSubscriptionCreated` directly, bypassing signature verification, event dedup, and PII sanitisation. There is no test posting a full Basil-shape payload through the HTTP entry point.
    - **Plain English:** The test for the new Stripe subscription data format calls the handler directly, skipping the signature check and dedup layer. It's like testing a lock by directly moving the bolt rather than inserting a key.
    - **Evidence:**
        ```php
        // tests/Feature/Stripe/StripeWebhookBasilPeriodTest.php:48-52
        $controller = new StripeWebhookController;
        $method = new ReflectionMethod($controller, 'handleSubscriptionCreated');
        $method->setAccessible(true);
        $method->invoke($controller, $stripeSubscription, $event);
        ```

- [ ] **#TEST-22** · P3 — Stripe Form Request validation rules never exercised through Laravel's validation pipeline
    - **Where:** `tests/Feature/Stripe/ExportsTest.php` (representative example); all Stripe Form Request test helpers
    - **Affects:** Input validation for export filters, payout listing parameters, transaction list filters.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Requests/` directory with one test file per Form Request class asserting valid payloads pass `rules()` and invalid payloads produce expected validation error keys.
    - **Technical:** Controller tests instantiate Form Requests directly with attributes pre-set, bypassing Laravel's validation pipeline. `ExportsRequest`'s rules for `role`, `format`, `fy`, `date_from`, `date_to` are never triggered via a real HTTP request through the container.
    - **Plain English:** Every API endpoint has a "bouncer" class that checks incoming data is valid. Tests build fake request objects that walk past the bouncer directly. A bug in the bouncer's rules would only be caught in production.
    - **Evidence:**
        ```php
        // tests/Feature/Stripe/ExportsTest.php — bypasses validation:
        function exp_makeRequest(Professional $pro, array $query): ExportsRequest
        {
            $request = ExportsRequest::create('/api/stripe/exports/test.csv', 'GET', $query);
            $request->attributes->set('professional', $pro);
            return $request;
        }
        // The rules() and authorize() methods on ExportsRequest are never called.
        ```

- [ ] **#TEST-23** · P3 — `StripeConnectService::resolveShopCurrency` untested for non-AUD shops
    - **Where:** `app/Services/Stripe/StripeConnectService.php` (`resolveShopCurrency` ~line 645); `tests/Feature/Stripe/StripeConnectOnboardingPrefillTest.php`
    - **Affects:** Default currency on Stripe Account creation for brands with non-AUD Shopify stores.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a test in `StripeConnectOnboardingPrefillTest.php` that seeds a `professional_integrations` row with `provider_metadata.shop_currency = 'USD'` and asserts the captured v2 account create payload includes `defaults.currency = 'usd'`.
    - **Technical:** `resolveShopCurrency` queries `ProfessionalIntegration` for a Shopify integration and reads `provider_metadata.shop_currency`. The existing prefill tests seed `shop_name` and `shop_domain` but never `shop_currency`, so the `'aud'` fallback always fires. A US-based merchant would silently get an AUD-denominated Stripe account.
    - **Plain English:** When setting up a Stripe account, Partna tries to detect the merchant's local currency from their Shopify store. The test always falls back to the default Australian dollar — it never sets up a USD store to verify the detection works. A non-Australian merchant could silently get the wrong currency on their Stripe account.
    - **Evidence:**
        ```php
        // app/Services/Stripe/StripeConnectService.php — createBrandConnectAccount:
        'defaults' => [
            'currency' => strtolower($this->resolveShopCurrency($brand) ?? 'aud'),
        ],
        // Test — only seeds shop_name/shop_domain, never shop_currency:
        // tests/Feature/Stripe/StripeConnectOnboardingPrefillTest.php:149-165
        ```

- [ ] **#TEST-24** · P3 — JWKS-path claims promotion in `VerifySupabaseJwt` covered only by self-documented workaround
    - **Where:** `tests/Feature/Auth/VerifySupabaseJwtClaimsExposureTest.php`
    - **Affects:** AAL step-up, fresh-MFA checks, session-ID tracking on the primary JWKS path; the auth-server fallback (currently tested) defaults `aal` to `aal1` and `amr` to `[]`.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Generate a test RSA/EC key pair and sign a JWT to exercise the JWKS path end-to-end, or mock `resolveSigningKey` to return a known key so claims flow through `setSupabaseContext`.
        - Add `it('exposes aal, amr, and session_id from JWKS-decoded claims', ...)`.
    - **Technical:** The test file explicitly acknowledges the gap: the test JWT uses HS256 so the JWKS path throws and falls through to the auth-server fallback. The production JWKS path is the primary path — all AAL2/MFA enforcement depends on claims being correctly promoted by `setSupabaseContext`.
    - **Plain English:** The JWT verification has two code paths: one for production (cryptographic keys) and one for fallback. Only the fallback is tested. The production path is the one that reads MFA status from the token. If a change stops reading MFA claims on the production path, the "require MFA" checks would silently treat everyone as unverified.
    - **Evidence:**
        ```php
        // tests/Feature/Auth/VerifySupabaseJwtClaimsExposureTest.php — self-documented gap
        it('exposes aal, amr, and session_id on the request attributes when the JWKS path sets claims', function () {
            // Because our test JWT uses HS256, the JWKS path will throw and we fall
            // through to the auth-server path. To test the JWKS-path attribute-setting
            // we call handle() with a real asymmetric JWT instead — but that requires
            // a key pair.
            //
            // [falls through to test the auth-server fallback instead]
        });
        ```
