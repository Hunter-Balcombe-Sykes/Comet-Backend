I now have all the verification I need. Here is the final adjudicated audit:

---

# Test Coverage Audit — 2026-05-12

**Branch:** development
**Lens:** Test coverage: critical paths, idempotency, race-safety, policy abilities, mock-vs-integration discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/Webhooks/ShopifyOrdersCancelledWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyThemePublishedWebhookController.php
- app/Http/Controllers/Api/Webhooks/ShopifyOrdersEditedWebhookController.php
- app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php
- app/Jobs/Shopify/Gdpr/RedactShopJob.php
- app/Jobs/Shopify/Gdpr/RedactCustomerJob.php
- app/Jobs/Shopify/Gdpr/ExportCustomerDataJob.php
- app/Jobs/Shopify/CreateShopifyAffiliateDiscountJob.php
- app/Jobs/Shopify/SyncShopifyBrandDesignJob.php
- app/Jobs/Shopify/RegisterShopifyWebhooksJob.php
- app/Jobs/Shopify/ProcessShopifyShopUpdateJob.php
- tests/Feature/Webhooks/Shopify/OrderEditedSnapshotTest.php
- tests/Feature/Webhooks/Shopify/OrderRace3EditedCancelledBeforePaidTest.php
- tests/Feature/Webhooks/Stripe/StripeConnectWebhookControllerEndToEndTest.php
- tests/Feature/Shopify/Gdpr/RedactShopJobTest.php
- tests/Feature/Shopify/Gdpr/RedactCustomerJobTest.php
- tests/Feature/Shopify/CreateShopifyAffiliateDiscountJobTest.php
- tests/Feature/Stripe/CommissionPayoutServiceTest.php
- tests/Feature/Stripe/VoidExpiredPayoutsJobTest.php
- tests/Unit/Policies/ (13 files)

> **Adjudication notes:** DeepSeek's TEST-4 (CommissionPayoutService zero tests) was hallucinated — `CommissionPayoutServiceTest.php`, `VoidExpiredPayoutsJobTest.php`, `ReconcileStuckTransferringPayoutsJobTest.php`, and `RetryPendingFundsPayoutsJobTest.php` all exist. TEST-8 (policy ability tests absent) was false — 13 policy unit test files exist under `tests/Unit/Policies/`. TEST-9 (FormRequest tests absent) was false — base FormRequest behavior tests exist under `tests/Unit/Http/Requests/`. TEST-5 (Stripe checkout stub) was re-tiered P1→dropped: the stub is an explicit, guarded deferral (`method_exists()` guard + A3.1 comment) and the test correctly covers the deferred path. TEST-11 (concurrent payout race tests) was dropped: `lockForUpdate()` is present throughout `CommissionPayoutService` and meaningful concurrent-execution tests require a real PG connection, not the SQLite test environment.

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 4 complete
- P2 Medium: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **#TEST-1** · P1 — ShopifyOrdersCancelled webhook controller has no HMAC or dedup controller test
    - **Where:** app/Http/Controllers/Api/Webhooks/ShopifyOrdersCancelledWebhookController.php:28–43
    - **Affects:** All brands. A regression to HMAC validation or the Redis dedup logic would silently allow replayed or spoofed `orders/cancelled` webhooks to alter order state in production, with no test catching it.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Webhooks/Shopify/ShopifyOrdersCancelledWebhookControllerTest.php` mirroring the pattern in `OrderEditedSnapshotTest.php`.
        - Assert that a request with an invalid HMAC signature returns HTTP 401.
        - Assert that a duplicate `X-Shopify-Webhook-Id` (second request with same ID) returns `['received' => true, 'duplicate' => true]` without dispatching the job.
        - Assert that a valid request with a known shop domain dispatches `ProcessShopifyOrderUpdatedWebhookJob` with the correct topic and event ID.
    - **Technical:** `OrderRace3EditedCancelledBeforePaidTest.php` only instantiates `ProcessShopifyOrderUpdatedWebhookJob` directly — it is a job-level test, not a controller test. The controller's three-path logic (pre-dedup early return → HMAC validation → post-dedup atomic `Cache::add`) is completely untested at the HTTP layer. Because `Cache::has()` fires before `isValidShopifyHmac()`, a regression that short-circuits to `has()` on every request would silently suppress HMAC enforcement, which no test would catch.
    - **Plain English:** Think of the cancelled-order webhook like a letter with a wax seal — the seal (HMAC) proves it's really from Shopify, and the stamp inside (webhook ID) prevents the same letter being opened twice. We have tests that check what happens after the letter is opened, but no tests that verify the seal is actually checked before opening, or that a duplicate letter is correctly discarded. If something breaks the seal-checking step, orders could be cancelled by anyone who can craft an HTTP request.
    - **Evidence:**
        ```php
        $dedupeKey = $webhookId !== '' ? "shopify:webhook:order-cancelled:{$webhookId}" : null;
        if ($dedupeKey && Cache::has($dedupeKey)) {
            return $this->success(['received' => true, 'duplicate' => true]);
        }

        if (! $this->isValidShopifyHmac($rawBody, $signature)) {
            Log::warning('Shopify orders/cancelled webhook: invalid HMAC signature', [
                'shop_domain' => $shopDomain,
            ]);

            return $this->error('invalid signature', 401);
        }

        if ($dedupeKey && ! Cache::add($dedupeKey, true, (int) config('partna.cache.ttls.webhook_idempotency'))) {
            return $this->success(['received' => true, 'duplicate' => true]);
        }
        ```

- [ ] **#TEST-2** · P1 — ShopifyThemePublished webhook controller has zero tests
    - **Where:** app/Http/Controllers/Api/Webhooks/ShopifyThemePublishedWebhookController.php:1
    - **Affects:** All brands using Shopify. A silent regression here would cause brand design tokens (colours, logos) to stop syncing when a brand publishes a new theme — with no alert and no test coverage.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `tests/Feature/Webhooks/Shopify/ShopifyThemePublishedWebhookControllerTest.php`.
        - Assert that an invalid HMAC returns HTTP 401.
        - Assert that a duplicate `X-Shopify-Webhook-Id` returns `['received' => true, 'duplicate' => true]` without dispatching `SyncShopifyBrandDesignJob`.
        - Assert that a valid request dispatches `SyncShopifyBrandDesignJob` with the correct integration ID.
        - Assert that an unknown shop domain returns HTTP 200 (silent accept) and does not dispatch the job.
    - **Technical:** `Glob("tests/Feature/Webhooks/Shopify/*Theme*")` returns zero results. The controller uses `ValidatesShopifyWebhookHmac`, performs HMAC validation, and then uses `Cache::add()` for dedup (correctly, after HMAC — unlike the cancelled/edited controllers). None of this is covered. `SyncShopifyBrandDesignJob` is the same job exercised by some install-chain tests, but the controller entry point is entirely untested.
    - **Plain English:** When a brand publishes a new Shopify theme, we re-sync their colours and logo into the platform. There is not a single automated test for this webhook path — not for the security check, not for the dedup logic, not for the job dispatch. Any change to this controller could silently break brand design syncing, and we'd only find out when a brand manually reports that their branding looks wrong.
    - **Evidence:**
        ```php
        if (! $this->isValidShopifyHmac($rawBody, $signature)) {
            Log::warning('Shopify themes/publish webhook: invalid HMAC signature', [
                'shop_domain' => $shopDomain,
            ]);

            return $this->error('invalid signature', 401);
        }

        // Deduplicate: Shopify may deliver the same webhook ID more than once.
        if ($webhookId !== '') {
            $dedupeKey = "shopify:webhook:themes-publish:{$webhookId}";
            if (! Cache::add($dedupeKey, true, (int) config('partna.cache.ttls.webhook_idempotency'))) {
                return $this->success(['received' => true, 'duplicate' => true]);
            }
        }
        ```

- [ ] **#TEST-3** · P1 — ShopifyOrdersEdited controller test covers happy path only; HMAC rejection and dedup branches are untested
    - **Where:** app/Http/Controllers/Api/Webhooks/ShopifyOrdersEditedWebhookController.php:28–43 / tests/Feature/Webhooks/Shopify/OrderEditedSnapshotTest.php
    - **Affects:** All brands. The `orders/edited` path is on the critical order-lifecycle arc (ADR 0001 Decision #3: commission frozen at paid time, edits only snapshot). A regression breaking HMAC or dedup on this controller could either allow spoofed edits or allow the same edit to be applied twice.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extend `OrderEditedSnapshotTest.php` (or add a companion file) with:
            - A test for invalid HMAC → HTTP 401.
            - A test for a repeated `X-Shopify-Webhook-Id` → `['received' => true, 'duplicate' => true]` without dispatching the job a second time.
    - **Technical:** `OrderEditedSnapshotTest.php` has one HTTP controller test that calls `signShopifyBody()` to produce a valid HMAC, asserts a 200, and confirms job dispatch. That covers exactly one branch. The controller has a three-path structure (pre-dedup early exit → HMAC check → atomic `Cache::add` dedup) identical to the cancelled controller; neither the 401 path nor the dedup path have assertions. Adding two more `it()` cases to the existing test file covers this fully.
    - **Plain English:** We have one test that checks the "everything goes right" path for order-edit webhooks. But there are two other important paths: one where the security signature is wrong (should be rejected), and one where Shopify sends the same event twice (should be ignored after the first time). Neither is checked. It's like verifying a lock works but only by unlocking it with the correct key — nobody tests what happens when you use the wrong key or knock twice.
    - **Evidence:**
        ```php
        $dedupeKey = $webhookId !== '' ? "shopify:webhook:order-edited:{$webhookId}" : null;
        if ($dedupeKey && Cache::has($dedupeKey)) {
            return $this->success(['received' => true, 'duplicate' => true]);
        }

        if (! $this->isValidShopifyHmac($rawBody, $signature)) {
            Log::warning('Shopify orders/edited webhook: invalid HMAC signature', [
                'shop_domain' => $shopDomain,
            ]);

            return $this->error('invalid signature', 401);
        }

        if ($dedupeKey && ! Cache::add($dedupeKey, true, (int) config('partna.cache.ttls.webhook_idempotency'))) {
            return $this->success(['received' => true, 'duplicate' => true]);
        }
        ```

- [ ] **#TEST-4** · P1 — GDPR erasure jobs have no `failed()` handler and no failure-path test; retry exhaustion silently stalls
    - **Where:** app/Jobs/Shopify/Gdpr/RedactShopJob.php:26–28 / app/Jobs/Shopify/Gdpr/RedactCustomerJob.php / app/Jobs/Shopify/Gdpr/ExportCustomerDataJob.php
    - **Affects:** Any brand or customer triggering a Shopify GDPR erasure/export request. After all retries exhaust, the `GdprRequest` record is left in an indeterminate state with no audit trail and no notification to staff or the requesting shop.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `public function failed(\Throwable $e): void` to `RedactShopJob`, `RedactCustomerJob`, and `ExportCustomerDataJob`. Transition the associated `GdprRequest` to a `failed` status and log the exception with context (`gdpr_request_id`, exception message). Use the same pattern as `ExportProfessionalDataJob::failed()`.
        - Extend `RedactShopJobTest.php` and `RedactCustomerJobTest.php` to assert that when the job throws and exhausts retries, the `GdprRequest` is marked failed (i.e., call `->failed(new \Exception('...'))` directly on the job instance and assert the model state).
    - **Technical:** Grep for `public function failed` in `app/Jobs/Shopify/Gdpr/*.php` returns zero matches. `$tries = 3` with `backoff(): [60, 300, 900]` means Horizon will attempt the job 3 times over ~25 minutes and then silently move it to the failed-jobs table. Neither `GdprRequest->status` nor any alert is updated. The Shopify GDPR compliance window is 30 days, but without staff visibility into failures the window can expire unnoticed. `ExportProfessionalDataJob` (internal GDPR export) already implements the correct pattern with `$audit->markFailed(...)`. The fix is a direct copy of that pattern plus the matching test assertion.
    - **Plain English:** If someone asks us to delete their data (as required by law) and our deletion job crashes — say, because of a database timeout or Shopify API error — the system silently gives up after a few retries without telling anyone. The deletion request just sits there with no status update, no alert to our team, and no way to know it needs to be retried manually. Our internal data-export jobs already handle this correctly by recording a failure; the Shopify GDPR jobs are missing the same safety net.
    - **Evidence:**
        ```php
        // RedactShopJob.php — no failed() handler
        class RedactShopJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;

            public int $timeout = 600;

            public function backoff(): array
            {
                return [60, 300, 900];
            }
            // handle() follows — no failed() method anywhere in the class
        ```
        ```php
        // ExportProfessionalDataJob.php — the correct pattern to copy
        public function failed(Throwable $e): void
        {
            $audit = DataExportAudit::find($this->auditId);
            if ($audit && $audit->status !== DataExportAudit::STATUS_COMPLETED) {
                $audit->markFailed('Job failed after retries: '.$e->getMessage());
            }
        }
        ```

---

## P2 — Should fix

- [ ] **#TEST-5** · P2 — Install-chain jobs have `failed()` handlers that transition `provider_metadata` but no test exercises those handlers
    - **Where:** app/Jobs/Shopify/CreateShopifyAffiliateDiscountJob.php:193–197 / app/Jobs/Shopify/SyncShopifyBrandDesignJob.php:186
    - **Affects:** Brand onboarding reliability. If any install-chain job silently marks state as 'failed' without a test, a refactor to the `failed()` handler (e.g. wrong metadata key, null-safe operator removed) goes undetected until a real brand install fails.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - For each install-chain job that has a `failed()` handler (`CreateShopifyAffiliateDiscountJob`, `SyncShopifyBrandDesignJob`, `CreateShopifyCollectionsJob`, `CreateShopifyMetafieldsJob`, `CreateShopifySalesChannelJob`, `CreateStorefrontAccessTokenJob`, `SetShopifySetupCompleteJob`): add a test case that instantiates the job, calls `->failed(new \RuntimeException('test'))` directly, and asserts the `ProfessionalIntegration::provider_metadata` transitions to the expected `*_state = 'failed'` value.
        - These can be concise — no HTTP layer needed, no queue faking needed. Direct method call + model assertion.
    - **Technical:** Grep for `->failed(` in `tests/Feature/Shopify/*.php` returns zero matches. The `failed()` handlers are all structurally similar: they resolve the integration by ID and call `mergeProviderMetadata([...])`. The test gap is small — these are all synchronous method calls with no external dependencies — but the handlers are on the critical path for diagnosing stuck onboardings. The Nightwatch alert model shows these state flags are what staff use to diagnose install failures.
    - **Plain English:** Each Shopify onboarding job knows how to record its own failure — when it gives up after three tries, it stamps "failed" onto the brand's integration record so the dashboard can surface the problem. We have tests for what happens when the jobs succeed, but none for the failure stamp. If someone accidentally removes or misspells the failure stamp during a refactor, we'd only notice when a real brand's onboarding gets stuck with no explanation shown in the dashboard.
    - **Evidence:**
        ```php
        // CreateShopifyAffiliateDiscountJob.php:193–197
        public function failed(\Throwable $e): void
        {
            $integration = ProfessionalIntegration::find($this->integrationId);
            $integration?->mergeProviderMetadata(['sidest_discount_state' => 'failed']);
        }
        ```

- [ ] **#TEST-6** · P2 — `RegisterShopifyWebhooksJob` and `ProcessShopifyShopUpdateJob` have no `failed()` handler and no failure test
    - **Where:** app/Jobs/Shopify/RegisterShopifyWebhooksJob.php:21–23 / app/Jobs/Shopify/ProcessShopifyShopUpdateJob.php
    - **Affects:** Brand onboarding. If `RegisterShopifyWebhooksJob` exhausts all retries, `webhooks_state` remains at `'partial'` indefinitely. The brand's webhooks are not fully registered, meaning future order events (paid, cancelled, edited) will not trigger — but no alert fires and no state flag transitions to `'failed'`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `public function failed(\Throwable $e): void` to `RegisterShopifyWebhooksJob`. Transition `webhooks_state` to `'failed'` on the integration's `provider_metadata` (same pattern as other install-chain jobs).
        - Add `public function failed(\Throwable $e): void` to `ProcessShopifyShopUpdateJob`. Log the failure with `shop_domain` context (or update a metadata field if one is appropriate).
        - Add brief tests: call `->failed(new \RuntimeException(...))` directly and assert the expected metadata state.
    - **Technical:** Grep for `public function failed` across `app/Jobs/Shopify/*.php` lists 10 jobs with handlers — `RegisterShopifyWebhooksJob` and `ProcessShopifyShopUpdateJob` are the only install-chain or webhook-processing jobs without one. `RegisterShopifyWebhooksJob` is `ShouldBeUnique` with `$tries = 3` and `uniqueFor = 300`, so a three-failure scenario is possible. After exhaustion, the integration's `webhooks_state` is never updated — the onboarding UI shows a stale state with no indication of failure.
    - **Plain English:** When a brand installs Partna, one job's only job is to register all the order-event hooks with Shopify. If that job fails three times in a row, Shopify never gets told to send us order events — but the brand's account looks like it's still "setting up" rather than "broken." There's no test that proves the system records this failure correctly, because there's no failure-recording code to test yet. The fix is a three-line method plus a short test.
    - **Evidence:**
        ```php
        // RegisterShopifyWebhooksJob.php — no failed() handler
        class RegisterShopifyWebhooksJob implements ShouldBeUnique, ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;

            public int $timeout = 300;

            public int $uniqueFor = 300;
            // ... handle() follows — no failed() method anywhere in the class
        ```

`★ Insight ─────────────────────────────────────`
**Structural finding pattern:** Four of the six findings share the same root cause — the install/webhook job layer has inconsistent `failed()` coverage. DeepSeek fragmented these into six separate findings across different tiers; adjudication collapsed and re-tiered them by root cause. When building the fixes, bundle the `failed()` handler additions as a single PR (TEST-4, TEST-6) and the "add tests for existing handlers" as another (TEST-5) to keep diffs reviewable.

**Pre-dedup-then-HMAC ordering:** The cancelled and edited controllers check `Cache::has()` *before* HMAC validation as an early exit. This is intentional for CPU efficiency — a replayed genuine webhook ID skips HMAC work. The theme controller correctly places HMAC first, then `Cache::add()`. Neither ordering is a security vulnerability (the webhook ID alone gives an attacker nothing), but the inconsistency is worth noting if you ever document the dedup contract.

**Why TEST-4 (GDPR) lands at P1 despite being a test finding:** GDPR jobs are unusual in that silent failure has a regulatory consequence — the Shopify GDPR compliance window is fixed at 30 days. Unlike other background jobs where a failed task is a reliability concern, a silently-stuck GDPR erasure job can become a compliance violation. The test fix and the `failed()` handler fix are tightly coupled here; one without the other isn't sufficient.
`─────────────────────────────────────────────────`
