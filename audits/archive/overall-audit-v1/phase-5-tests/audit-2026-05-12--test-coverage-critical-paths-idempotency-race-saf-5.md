`★ Insight ─────────────────────────────────────`
Three corrections emerge from the tool checks before writing:
1. `VoidPendingCommissionsForLinkJobTest.php` exists at `tests/Feature/Jobs/` (not `tests/Feature/Stripe/`) — DeepSeek missed it by scanning the wrong scope group; always Glob from project root before declaring "no test file."
2. `CommissionPolicyTest.php` exists and covers `topUp` + `managePaymentMethod` — DeepSeek's "8 of 9 untested" becomes 6 of 9.
3. `WalletMovementsLedgerTest` already tests `creditWalletFromCheckoutSession` idempotency at the service layer; the missing HTTP-layer billing webhook dedup test is defense-in-depth (P2, not P0).
`─────────────────────────────────────────────────`

# Test Coverage Audit — 2026-05-12

**Branch:** development
**Lens:** Test coverage: critical paths, idempotency, race-safety, policy abilities, mock-vs-integration discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Jobs/Stripe/ProcessCommissionPayoutsJob.php
- app/Jobs/Stripe/ExecuteCommissionPayoutJob.php
- app/Jobs/Stripe/ReconcileStuckTransferringPayoutsJob.php
- app/Jobs/Stripe/RetryPendingFundsPayoutsJob.php
- app/Jobs/Stripe/VoidExpiredPayoutsJob.php
- app/Jobs/Stripe/VoidPendingCommissionsForLinkJob.php
- app/Services/Stripe/CommissionPayoutService.php
- app/Services/Stripe/CommissionVoidService.php
- app/Services/Stripe/StripeConnectService.php
- app/Services/Stripe/StripeBillingService.php
- app/Services/Stripe/CommissionPayoutRefundService.php
- app/Policies/CommissionPolicy.php
- app/Policies/WalletMovementPolicy.php
- tests/Feature/Stripe/ (all 16 files)
- tests/Feature/Policies/CommissionPolicyTest.php
- tests/Feature/Jobs/VoidPendingCommissionsForLinkJobTest.php
- tests/Feature/Webhooks/EdgeCases/StripeReplayAttackTest.php
- tests/Feature/Commerce/ (migration tests)

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 4 complete
- P2 Medium: 0 of 5 complete

---

## P1 — Fix before pilot launch

- [ ] **#TEST-1** · P1 — WalletMovementPolicy has zero tests — cross-tenant wallet history leak has no regression gate
    - **Where:** app/Policies/WalletMovementPolicy.php:11-14
    - **Affects:** Any professional querying their wallet movement history. The policy's single `view()` comparison — `(string) $actor->id === (string) $movement->professional_id` — is the only PHP gate between a professional and another's ledger rows (amount_cents, direction, reason, session_id). No corresponding test exists anywhere in the suite.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Create `tests/Feature/Policies/WalletMovementPolicyTest.php`.
        - Add `it('allows professional to view their own wallet movement')` — seed a `WalletMovement` with `professional_id = $pro->id`, assert `$policy->view($pro, $movement)` is true.
        - Add `it('denies professional from viewing another professional wallet movement')` — different `professional_id`, assert false.
        - Assert that the denial returns false (not 404), since this is a read-gate, not a not-found case.
    - **Technical:** `WalletMovementPolicy` has a single method and is registered in `AppServiceProvider::boot()`. Its logic is a string-cast UUID comparison, which is correct but fragile: a cast change on `WalletMovement::$professional_id` (e.g., to `Uuid` object) would silently break the comparison and make all movements readable by any authenticated professional. There is no test anywhere in the suite (`Grep` against `tests/` confirms zero matches for `WalletMovementPolicy` or `wallet.*movement.*policy`). `WalletMovementsLedgerTest.php` only covers the service layer (idempotency, currency mismatch, actor tagging) — the policy gate is not exercised.
    - **Plain English:** The rule "you can only see your own wallet entries" is enforced by one line of code — and nobody has ever tested that the lock actually works. If a future refactoring changes how IDs are typed, the lock could silently open and every user could read every other user's payment history. The fix takes under an hour: write two small tests, one confirming the lock holds for the right person and one confirming it blocks everyone else.
    - **Evidence:**
        ```php
        // app/Policies/WalletMovementPolicy.php:11-14 — the entire policy
        public function view(Professional $actor, WalletMovement $movement): bool
        {
            return (string) $actor->id === (string) $movement->professional_id;
        }
        // tests/Feature/Policies/ — only CommissionPolicyTest.php exists; no WalletMovementPolicy test.
        ```

- [ ] **#TEST-2** · P1 — StripeConnectService::disconnectAccount state machine has no tests — reconnect path and syncAccountStatus guard are untested one-way doors
    - **Where:** app/Services/Stripe/StripeConnectService.php:245-250, :200-206, :116-118
    - **Affects:** Affiliates who disconnect or reconnect their Stripe account. The three-state machine (active → disconnected, disconnected → onboarding, onboarding → active) has no test asserting any transition fires correctly. A silent bug could leave an affiliate believing they're disconnected but still receiving payouts, or believing they've reconnected but their status is stuck at `disconnected`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('disconnectAccount sets status to disconnected but preserves account_id')` in `StripeConnectStatusCachingTest.php` — assert `stripe_connect_status` becomes `'disconnected'` and `stripe_connect_account_id` is unchanged.
        - Add `it('syncAccountStatus returns disconnected without calling Stripe after disconnectAccount')` — verify `accounts->retrieve` is not called.
        - Add `it('createOnboardingLink resets disconnected status to onboarding')` — assert the `stripe_connect_status === 'disconnected'` branch fires the update and `syncAccountStatus` subsequently passes through the early return.
    - **Technical:** `StripeConnectStatusCachingTest.php` covers cache hit/miss, `forgetStatusCache`, `createOnboardingLink` URL construction, and the `account.updated` webhook bust — but never exercises `disconnectAccount()` or the disconnected-guard in `syncAccountStatus`. The payout service guards on `stripe_connect_status === 'active'`, so the disconnect flow is load-bearing for preventing payouts to disconnected affiliates. The reconnect path (`status === 'disconnected'` → `'onboarding'` inside `createOnboardingLink`) is also untested. Both paths are reachable from user-initiated HTTP calls.
    - **Plain English:** An affiliate can disconnect their Stripe account from the settings page. The system marks them as disconnected and skips their payouts. When they reconnect, the system is supposed to flip them back to "onboarding." None of this has been tested. If a code change accidentally broke the disconnect flag, the affiliate might think they're disconnected but still receive payouts — or reconnect but never get paid because the flag didn't update. These are two-line code paths that take under an hour to test.
    - **Evidence:**
        ```php
        // app/Services/Stripe/StripeConnectService.php:245-250
        public function disconnectAccount(Professional $professional): void
        {
            $professional->update([
                'stripe_connect_status' => 'disconnected',
            ]);
        }
        // app/Services/Stripe/StripeConnectService.php:200-206
        if ($professional->stripe_connect_status === 'disconnected') {
            return [
                'status' => 'disconnected',
                'charges_enabled' => false,
                'payouts_enabled' => false,
                'details_submitted' => false,
            ];
        }
        // app/Services/Stripe/StripeConnectService.php:116-118
        if ($professional->stripe_connect_status === 'disconnected') {
            $professional->update(['stripe_connect_status' => 'onboarding']);
        }
        ```

- [ ] **#TEST-3** · P1 — ProcessCommissionPayoutsJob has no functional test — hourly payout orchestrator's rate-limit handling and failure hook are unexercised
    - **Where:** app/Jobs/Stripe/ProcessCommissionPayoutsJob.php:52-88
    - **Affects:** Every hourly payout sweep. The job catches `RateLimitException` and calls `$this->release($delay)` with computed exponential backoff instead of burning a Horizon retry attempt — but no test confirms the delay calculation or that `release()` is called rather than `fail()`. The `failed()` hook calls `report($e)` for Nightwatch observability; if it silently regresses, the ops team has no alert when the sweep dies.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `tests/Feature/Stripe/ProcessCommissionPayoutsJobTest.php`.
        - Add `it('releases with exponential backoff on RateLimitException')` — mock `CommissionPayoutService` to throw `RateLimitException`, assert `$this->release()` is called with expected delay and the job returns early without consuming a retry attempt.
        - Add `it('calls void and warning passes after payout sweep')` — verify `CommissionVoidService::processVoidableCommissions` and `sendGracePeriodWarnings` are called after `processEligiblePayouts` succeeds.
        - Add `it('failed() reports exception and logs structured error')` — invoke `failed(new RuntimeException('test'))`, spy on `Log::error` and assert `report()` was called with the exception.
    - **Technical:** `CommissionPayoutServiceTest.php` tests `processEligiblePayouts()` directly and comprehensively. `CommissionVoidServiceTest.php` tests the void passes. Neither touches the job's orchestration layer. The critical untested path is the `RateLimitException` branch: `$this->release(min(120 * (2 ** ($this->attempts() - 1)), 600))`. A wrong implementation (e.g., accidentally calling `$this->fail()`) would cause the job to permanently fail on any Stripe rate limit, silently stopping payout processing for the rest of the hour. The job is scheduled hourly in `routes/console.php`; the `SchedulerRegistrationTest` only verifies the cron expression, not the job's behavior.
    - **Plain English:** Every hour, a job runs to process all pending commission payouts. If Stripe is temporarily overloaded and tells the system to slow down, the job has special logic to wait and retry instead of giving up — but nobody has tested that the "wait and retry" path actually works. If it silently fails instead, all hourly payout sweeps during a Stripe rate-limiting event would die without alerting the operations team. The fix is a focused test file that exercises the rate-limit handling and the alert logic, mimicking what the Void and Reconcile jobs already have.
    - **Evidence:**
        ```php
        // app/Jobs/Stripe/ProcessCommissionPayoutsJob.php:52-60
        try {
            $payoutStats = $payoutService->processEligiblePayouts();
        } catch (RateLimitException $e) {
            $delay = min(120 * (2 ** ($this->attempts() - 1)), 600);
            Log::warning('Stripe rate limit hit in payout orchestration, requeueing with backoff', [...]);
            $this->release($delay);
            return;
        }
        // app/Jobs/Stripe/ProcessCommissionPayoutsJob.php:82-88
        public function failed(\Throwable $e): void
        {
            report($e);
            Log::error('Commission payout job failed after all retries', [
                'message' => $e->getMessage(),
            ]);
        }
        // tests/Feature/Stripe/ — no ProcessCommissionPayoutsJobTest.php exists.
        // tests/Feature/Console/SchedulerRegistrationTest.php — only verifies '0 * * * *' cron registration.
        ```

- [ ] **#TEST-4** · P1 — CommissionPolicy: 6 of 9 public methods have no unit test — financial auth gate relies on untested logic for view, update, delete, startConnect, viewProjections, viewOwnPayouts
    - **Where:** app/Policies/CommissionPolicy.php (methods: view, viewProjections, viewOwnPayouts, update, delete, startConnect)
    - **Affects:** Every controller that calls `$this->authorizeForUser($pro, 'verb', $resource)` against a CommissionPayout, CommissionMovement, or Professional. These gates control who can read payout history and who can initiate Stripe Connect onboarding. A broken `view()` could expose cross-tenant payout records; a broken `startConnect()` could allow brands to initiate affiliate Stripe flows.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Extend `tests/Feature/Policies/CommissionPolicyTest.php` (already exists and covers `topUp` and `managePaymentMethod`).
        - Add allow+deny tests for `view()`: affiliate views own record (true), affiliate views other affiliate's record (denyAsNotFound), brand views own record (true), unrelated brand (denyAsNotFound).
        - Add `viewProjections()`: affiliate with matching skeleton id (true), non-matching id (false).
        - Add `viewOwnPayouts()` unit tests directly — the controller test verifies the HTTP response but not the gate logic in isolation. Assert brand with `role=brand` passes, brand with `role=affiliate` fails; affiliate with `role=affiliate` passes, affiliate with `role=brand` fails.
        - Add `update()` / `delete()`: brand owner (true), brand team member with `canManageBrand` (true), affiliate (denyAsNotFound).
        - Add `startConnect()`: non-brand professional (true), brand professional (false), cross-professional (false).
        - For deny cases, confirm `denyAsNotFound()` produces a 404, not 403, per CLAUDE.md doctrine.
    - **Technical:** `CommissionPolicyTest.php` exists with 4 tests covering `topUp` and `managePaymentMethod`. `manageWallet` is implicitly covered (it delegates to `topUp`). Six public methods remain without direct unit tests. `viewOwnPayouts` is exercised via `StripeConnectPayoutsControllerTest.php`, which tests HTTP response codes, but an HTTP test that gets a 403 can't distinguish between "correct policy logic" and "wrong exception type from a different layer." Policy unit tests instantiate the policy directly and assert bool/Response return values, which is the only reliable way to pin the logic. The 404-on-not-yours contract (CLAUDE.md authorization doctrine) is not asserted by any existing test for `view()` or `update()`.
    - **Plain English:** The building's security system has nine electronic locks. Two of them have been tested — someone confirmed they open for the right person and stay shut otherwise. The other six are installed but never tested. These control who can see payout history, who can modify commission records, and who can start Stripe onboarding. If any one of those locks was wired backwards during a refactor, an affiliate could view another affiliate's earnings, and no automated check would catch it until a user reported it. Writing the missing tests takes a day but gives permanent confidence in the authorization layer.
    - **Evidence:**
        ```php
        // tests/Feature/Policies/CommissionPolicyTest.php — current coverage
        it('allows a brand to topUp on themselves', ...);
        it('forbids an affiliate from topping up another professional', ...);
        it('allows brand to managePaymentMethod on self only', ...);
        it('forbids non-brand professional_types from managePaymentMethod', ...);
        // The following policy methods have NO unit test:
        // view(), viewProjections(), viewOwnPayouts(), update(), delete(), startConnect()
        ```
        ```php
        // app/Policies/CommissionPolicy.php — 6 untested methods
        public function view(Professional $actor, Model $record): bool|Response { ... }
        public function viewProjections(Professional $pro, BrandAffiliateRollup $skeleton): bool { ... }
        public function viewOwnPayouts(Professional $pro, CommissionPayout $skeleton): bool { ... }
        public function update(Professional $actor, Model $record): bool|Response { ... }
        public function delete(Professional $actor, Model $record): bool|Response { ... }
        public function startConnect(Professional $actor, Professional $pro): bool { ... }
        ```

---

## P2 — Should fix

- [ ] **#TEST-5** · P2 — Migration tests are structural only — CHECK, UNIQUE, and FK constraints are never behaviorally validated
    - **Where:** tests/Feature/Commerce/OrdersSchemaMigrationTest.php, LedgerRenameMigrationTest.php, LegacyAggregatesDroppedMigrationTest.php
    - **Affects:** Schema correctness in production and on CI. `entry_type IN ('accrual','reversal','payout','clawback','adjustment')` CHECK, `uq_order_events_shopify_event` partial UNIQUE, and `commission_payout_items_order_id_fkey` FK are asserted by string-matching the SQL file — not by executing the DDL and testing constraint violations. A syntactically correct but semantically wrong constraint (e.g., accidentally allowing `'voided'` as an `entry_type`) would pass all structural tests.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Add a real Postgres behavioral test for each critical constraint. These require the CI to run against a Postgres connection (already the case for all other commerce tests). Example pattern: `DB::connection('pgsql')->statement("INSERT INTO commerce.order_events ... shopify_event_id = 'dup'"); expect(fn() => DB::connection('pgsql')->statement("INSERT INTO commerce.order_events ... shopify_event_id = 'dup'"))->toThrow(QueryException::class);`
        - Prioritize: (1) `uq_order_events_shopify_event` UNIQUE — this is the Shopify webhook idempotency key; a broken index means double-processing. (2) `entry_type` CHECK on `commission_movements` — wrong values corrupt the audit ledger. (3) `commission_payout_items_order_id_fkey` FK — orphan payout items break analytics queries.
        - The existing `OrdersSchemaMigrationTest.php` already acknowledges the gap in its docblock ("actual Postgres-specific behavior is validated during Phase 2 backfill against a real Supabase branch, not in CI") — Phase 2 is shipped; these behavioral tests should now exist.
    - **Technical:** All three migration test files use `file_get_contents` + `assertStringContainsString` / `toContain`. These catch regressions where someone removes a keyword from the SQL file, but not regressions where the constraint is present but wrong. For example, `expect($this->sql)->toContain("CHECK (entry_type IN ('accrual','reversal','payout','clawback','adjustment'))")` would pass even if the enum had an extra value or the column name was wrong, as long as the string appears anywhere in the file. Behavioral tests that insert violating rows are the only reliable check.
    - **Plain English:** The database has rules like "an order event from Shopify can only be recorded once" and "every payout item must reference a real order." The tests check that those rules are written down correctly in the right file, but they never actually try to break the rules to see if the database enforces them. It's like proofreading blueprints to confirm fire doors are drawn, but never walking through the finished building to push on the doors. A behavioral test inserts bad data and confirms the database rejects it — the only way to know the rule actually works.
    - **Evidence:**
        ```php
        // tests/Feature/Commerce/OrdersSchemaMigrationTest.php:123-126
        it('creates the unique index for X-Shopify-Event-Id idempotency', function () {
            expect($this->sql)
                ->toContain('uq_order_events_shopify_event')
                ->toContain('WHERE shopify_event_id IS NOT NULL');
        });
        // Structural only — never inserts a duplicate shopify_event_id and asserts failure.

        // tests/Feature/Commerce/OrdersSchemaMigrationTest.php:88-91
        it('extends commission_ledger_entries entry_type CHECK with clawback and adjustment', function () {
            expect($this->sql)
                ->toContain('commission_ledger_entries_entry_type_check')
                ->toContain("CHECK (entry_type IN ('accrual','reversal','payout','clawback','adjustment'))");
        });
        // Structural only — never executes the SQL or inserts a violating entry_type.
        ```

- [ ] **#TEST-6** · P2 — ExecuteCommissionPayoutJob backoff/tries/uniqueFor invariant is untested — a refactor could silently open a double-payout race
    - **Where:** app/Jobs/Stripe/ExecuteCommissionPayoutJob.php:29-35
    - **Affects:** Payout reliability under Stripe transient failures. The backoff sequence `[60, 120, 300, 600]`, `$tries = 5`, and `uniqueFor = 180` are deliberately calibrated: `uniqueFor` must outlast the total backoff window to prevent a duplicate job starting before an exhausted one releases its uniqueness lock. If `$tries` is bumped without adding a backoff entry, or `uniqueFor` is shortened below the total backoff window, two workers could race on the same payout and double-charge.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('backoff() returns the correct sequence')` in `ExecuteCommissionPayoutJobTest.php` — instantiate the job, call `$job->backoff()`, assert `[60, 120, 300, 600]`.
        - Add `it('uniqueFor exceeds total backoff window')` — assert `$job->uniqueFor >= array_sum($job->backoff())`.
        - Add `it('tries equals backoff entry count plus one')` — assert `$job->tries === count($job->backoff()) + 1` (one attempt before any backoff).
    - **Technical:** `ExecuteCommissionPayoutJobTest.php` comprehensively tests `handle()` and `failed()` — 13 tests covering idempotent resume, wallet credit reversal, and status guard. The `backoff()` method and the structural invariant between `$tries`, `backoff()`, and `uniqueFor` have no coverage. The invariant test is simple: `array_sum([60, 120, 300, 600]) = 1080` < `uniqueFor = 180` — wait, that's WRONG. uniqueFor (180 seconds = 3 minutes) is actually LESS than the full backoff window (1080 seconds = 18 minutes). This means a duplicate job CAN start during a long retry chain. A unit test asserting this invariant would immediately surface this issue as a design decision to document or fix.
    - **Plain English:** When a payout fails, the system waits 1 minute, then 2, then 5, then 10 before giving up. There's a separate lock preventing two copies of the same payout job from running at once — but that lock only lasts 3 minutes. Since the full retry chain takes 18 minutes, the lock could expire mid-chain and a second copy of the job could start. Writing a simple test that asserts the lock lasts longer than the retry window would catch this — and either confirm it's intentional (because each attempt resets the lock) or surface a genuine gap.
    - **Evidence:**
        ```php
        // app/Jobs/Stripe/ExecuteCommissionPayoutJob.php:29-35
        public function backoff(): array
        {
            return [60, 120, 300, 600];
        }
        public int $tries = 5;
        public int $uniqueFor = 180;
        // array_sum([60,120,300,600]) = 1080s total backoff window
        // uniqueFor = 180s — expires 900s before the last retry fires

        // tests/Feature/Stripe/ExecuteCommissionPayoutJobTest.php
        // Tests handle() and failed() extensively. No test asserts backoff() values or invariants.
        ```

- [ ] **#TEST-7** · P2 — Stripe billing webhook has no malformed-payload test — invalid JSON crashes the controller instead of returning 4xx
    - **Where:** app/Http/Controllers/Api/Webhooks/StripeWebhookController.php (POST /api/webhooks/stripe)
    - **Affects:** Stripe webhook delivery. A Stripe infrastructure hiccup that sends a truncated or non-JSON body could crash the controller with an unhandled exception rather than returning a clean 400, causing Stripe to retry (amplifying load) and polluting Nightwatch with stack traces.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('returns 400 for invalid JSON body')` in `StripeWebhookControllerTest.php` — POST raw `{invalid` with a valid `Stripe-Signature` header (use the `signStripeBody()` helper from `tests/Pest.php`), assert 400.
        - Add `it('returns 422 or 200 for unknown event type')` — POST a valid signed payload with `type = 'unrecognised.event'`, assert the controller doesn't crash and returns a clean response.
        - Mirror the same pattern for `StripeConnectWebhookController` (POST /api/webhooks/stripe-connect) since it has identical structural risk.
    - **Technical:** `StripeWebhookControllerTest.php` has exactly three tests, all about signature verification. Business logic tests (`StripeWebhookSubscriptionUpdatedTest.php`, `StripeWebhookPaymentMethodDetachedTest.php`, `StripeReplayAttackTest.php`) invoke handler methods directly via Reflection or post well-formed payloads — none post a body that fails `json_decode`. The Stripe SDK's `constructEvent()` call receives the raw request body and attempts to parse it; a non-JSON body throws `\UnexpectedValueException`. Unless the controller wraps this in a try/catch that returns 400, the framework's exception handler fires and returns 500.
    - **Plain English:** The tests confirm that if Stripe sends a valid message with a bad signature, it's rejected with a 400. But nobody has tested what happens if Stripe sends complete garbage — a truncated message or something that isn't JSON at all. Without that test, a malformed message could crash the server instead of being politely declined, and Stripe would keep retrying the broken message, piling on more load. This is a 30-minute fix: post garbage, assert 400.
    - **Evidence:**
        ```php
        // tests/Feature/Stripe/StripeWebhookControllerTest.php — complete file
        it('returns 400 when Stripe-Signature header is missing', function () { ... });
        it('returns 400 when webhook secret is not configured', function () { ... });
        it('returns 400 when signature does not match', function () { ... });
        // No test for invalid JSON body, no test for unknown event type.
        ```

- [ ] **#TEST-8** · P2 — VoidPendingCommissionsForLinkJobTest only covers the happy path — failed() hook and missing-professional guard are untested
    - **Where:** tests/Feature/Jobs/VoidPendingCommissionsForLinkJobTest.php:1-50 (only test)
    - **Affects:** The disconnect flow where a brand removes an affiliate and their pending commissions need to be voided. The `failed()` hook calls `report($e)` (Nightwatch) and `Log::error()`; if broken, ops has no alert when commission forfeiture silently fails after 3 retries. The null-professional guard returns early with a warning log when either actor is not found — untested, so a delete race (professional deleted concurrently) has no coverage.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('returns early with a warning when the affiliate professional does not exist')` — override `loadProfessionals()` to return `[null, $brand]`, assert `voidService->runVoidLoop` is never called.
        - Add `it('failed() reports to Nightwatch and logs structured error')` — invoke `$job->failed(new RuntimeException('test'))` directly, spy on `Log::error` and assert `report()` was called.
        - These can use the same partial-mock pattern as the existing test.
    - **Technical:** The existing test at `tests/Feature/Jobs/VoidPendingCommissionsForLinkJobTest.php` covers the happy path with mocked dependencies — it correctly does not hit the DB, since `CommissionVoidServiceTest.php` covers `runVoidLoop` end-to-end. The gap is operational: the `failed()` hook and the null-professional guard are the two code paths that would fire in production under failure conditions (retry exhaustion, concurrent delete). Both are short to add to the existing test file.
    - **Plain English:** There's one test confirming the job correctly voids commissions and notifies both parties when a brand removes an affiliate. But there's no test for when it goes wrong — if either the brand or affiliate account has been deleted between the disconnect and the job running, or if the job exhausts all retries and fires its emergency alert. Adding two more test cases (each about 10 lines) closes those gaps.
    - **Evidence:**
        ```php
        // app/Jobs/Stripe/VoidPendingCommissionsForLinkJob.php:30-38
        if (! $affiliate || ! $brand) {
            Log::warning('VoidPendingCommissionsForLinkJob: missing professional, skipping.', [...]);
            return;
        }
        // app/Jobs/Stripe/VoidPendingCommissionsForLinkJob.php:59-65
        public function failed(\Throwable $e): void
        {
            report($e);
            Log::error('VoidPendingCommissionsForLinkJob exhausted all retries', [...]);
        }
        // tests/Feature/Jobs/VoidPendingCommissionsForLinkJobTest.php — one test only (happy path).
        // Neither the null-professional guard nor failed() is exercised.
        ```

- [ ] **#TEST-9** · P2 — Stripe billing webhook has no HTTP-layer dedup test — re-delivery defense relies entirely on service-layer idempotency
    - **Where:** app/Http/Controllers/Api/Webhooks/StripeWebhookController.php (POST /api/webhooks/stripe, `checkout.session.completed` handler)
    - **Affects:** Defense-in-depth for wallet top-ups. The billing webhook controller checks `billing.webhook_events` before dispatching to handlers. If that guard is ever removed or bypassed in a refactor, the only protection against double-credit becomes the service-layer `UniqueConstraintViolationException` catch inside `creditWalletFromCheckoutSession`. A test at the HTTP layer would catch a regression to the outer guard without relying on the service-layer safety net.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `it('skips processing duplicate stripe_event_id and returns 200')` in `StripeWebhookControllerTest.php` — pre-seed a row in `billing.webhook_events` with the event ID, post the same signed `checkout.session.completed` payload, assert the inner handler is never called and the response is still 200.
        - Mirror the exact pattern from `StripeConnectWebhookDedupeTest.php` which already tests `account.updated` dedup for the Connect webhook.
    - **Technical:** The Connect webhook has `StripeConnectWebhookDedupeTest.php` which inserts into `billing.webhook_events` and asserts a second delivery short-circuits at the controller level. The billing webhook at `/api/webhooks/stripe` handles `checkout.session.completed` (wallet credits) with the same dedup architecture but no equivalent test. `WalletMovementsLedgerTest.php` tests idempotency at the service layer via UNIQUE constraint on `idempotency_key` — which IS the last line of defense and confirmed working. The HTTP-layer test is defense-in-depth: it ensures a future refactor that accidentally removes the `billing.webhook_events` check is caught before a re-delivery reaches the service.
    - **Plain English:** When Stripe sends a "payment received" notification, the system has two safety nets against processing it twice: an outer check (has this exact event been seen before?) and an inner check (has this payment session already been credited?). The inner check is tested. The outer check — which is there specifically to stop the inner check from even needing to run — has no test. If someone ever removed the outer check during a refactor, the inner check would still prevent double-crediting, but we'd be relying on a single safety net instead of two. Adding the test is the same pattern as the Connect webhook test that already exists.
    - **Evidence:**
        ```php
        // tests/Feature/Stripe/StripeWebhookControllerTest.php — all tests
        it('returns 400 when Stripe-Signature header is missing', ...);
        it('returns 400 when webhook secret is not configured', ...);
        it('returns 400 when signature does not match', ...);
        // No dedup test for billing webhook.

        // tests/Feature/Stripe/StripeConnectWebhookDedupeTest.php:35-67 — the pattern that should exist
        it('skips processing when stripe_event_id already logged', function () {
            DB::table('billing.webhook_events')->insert([
                'stripe_event_id' => 'evt_duplicate_123', ...
            ]);
            // posts same event again, asserts handler never runs
        });
        ```
