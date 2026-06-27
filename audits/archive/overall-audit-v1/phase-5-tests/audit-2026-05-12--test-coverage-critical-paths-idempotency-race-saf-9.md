Now I have all the evidence I need. Let me produce the final audit document.

# Test Coverage Audit — 2026-05-12

**Branch:** development
**Lens:** Test coverage: critical paths, idempotency, race-safety, policy abilities, mock-vs-integration discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- tests/Feature/Analytics/AffiliateCommerceAnalyticsControllerTest.php
- tests/Feature/Analytics/BrandCommerceAnalyticsControllerTest.php
- tests/Feature/Analytics/AffiliateProjectionsControllerTest.php
- tests/Feature/Analytics/AffiliateCommercePaidGateTest.php
- tests/Feature/Commerce/OrdersSchemaMigrationTest.php
- tests/Feature/Migrations/BackfillOrdersPayoutIdTest.php
- tests/Feature/Policies/CommissionPolicyTest.php
- tests/Feature/Resources/ProfessionalPublicResourceTest.php
- tests/Feature/Resources/ProfessionalResourceTest.php
- tests/Feature/Stripe/ (16 files — Glob verified)
- tests/Feature/Webhooks/ (20 files — Glob verified)
- app/Policies/ (14 files — Glob verified)

> **Dropped from DeepSeek draft (hallucinated or unverifiable):**
> - *Original TEST-1* — claimed `tests/Feature/Stripe/` is absent; Glob found 16 files including `CommissionPayoutServiceTest.php`, `CommissionVoidServiceTest.php`, `RetryPendingFundsPayoutsJobTest.php`, `VoidExpiredPayoutsJobTest.php`, `ReconcileStuckTransferringPayoutsJobTest.php`.
> - *Original TEST-2* — claimed no Stripe webhook tests exist; Glob found `StripeWebhookControllerEndToEndTest.php`, `StripeConnectWebhookControllerEndToEndTest.php`, `StripeReplayAttackTest.php`, and `StripeConnectWebhookDedupeTest.php`.
> - *Original TEST-6* — claimed no concurrency tests exist for financial payout flows; Glob found `OrderRace1OutOfOrderUpdateTest.php`, `OrderRace2RefundBeforePaidTest.php`, `OrderRace3EditedCancelledBeforePaidTest.php`; full coverage of `CommissionPayoutServiceTest.php` unread within budget — dropped per always-drop rule 11 (precision > recall).
> - *Original TEST-8* — claimed no idempotency test exists for `ProcessShopifyOrderWebhookJob`; Glob found `tests/Feature/Webhooks/Shopify/OrderIdempotencyTest.php`.

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 3 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **TEST-1** · P1 — DB-layer Mockery in `AffiliateProjectionsControllerTest` masks rollup trigger regressions
    - **Where:** tests/Feature/Analytics/AffiliateProjectionsControllerTest.php:34
    - **Affects:** Projection analytics correctness; any change to `commerce.brand_affiliate_rollup` schema, column names, or trigger logic passes CI silently while real users receive incorrect projection data
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `DB::shouldReceive('table')->with('commerce.brand_affiliate_rollup')` with a real fixture row inserted directly into the test database
        - The `CacheLockService` passthrough mock (`fn ($key, $ttl, $cb) => $cb()`) is acceptable — it bypasses Redis infrastructure but still executes the real callback; keep it
        - Guard pgsql-specific paths with `$this->markTestSkipped('Requires pgsql')` where trigger behavior matters, following the established `BackfillOrdersPayoutIdTest.php` pattern
        - Reference the real-DB approach already demonstrated in `AffiliateCommercePaidGateTest.php`
    - **Technical:** `commerce.brand_affiliate_rollup` is trigger-maintained — application code never writes to it directly. The mock returns whatever the test declares, which means a trigger failure, column rename, or schema drift is invisible to this test. Vendor/infrastructure mocks (Redis, Stripe, Shopify) are acceptable per project discipline; the DB layer is not. The `CacheLockService` mock is a correct infrastructure-layer mock and should be preserved.
    - **Plain English:** The projections feature shows affiliates what they're likely to earn. Underneath, it reads from a summary table that the database itself keeps up-to-date automatically. The test bypasses this table with a fake, so if the automatic updates ever break or the table structure changes, the test still passes — but real affiliates would see wrong numbers.
    - **Evidence:**
        ```php
        it('returns insufficient_data when the affiliate has no rollup history', function () {
            $rollupMock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
            foreach (['where', 'whereBetween', 'whereRaw', 'select', 'selectRaw', 'orderBy', 'groupBy', 'fromRaw'] as $m) {
                $rollupMock->shouldReceive($m)->andReturnSelf();
            }
            $rollupMock->shouldReceive('value')->with('day')->andReturn(null); // no history at all
            $rollupMock->shouldReceive('get')->andReturn(collect());

            DB::shouldReceive('table')->with('commerce.brand_affiliate_rollup')->andReturn($rollupMock);

            // Bypass Redis lock with array-driver closure passthrough
            $this->mock(\App\Services\Cache\CacheLockService::class, function ($m) {
                $m->shouldReceive('rememberLocked')
                    ->andReturnUsing(fn ($key, $ttl, $cb) => $cb());
            });
        ```

- [ ] **TEST-2** · P1 — DB-layer Mockery in `AffiliateCommerceAnalyticsControllerTest` masks migration and trigger regressions
    - **Where:** tests/Feature/Analytics/AffiliateCommerceAnalyticsControllerTest.php:18
    - **Affects:** Affiliate analytics dashboard correctness; migration renames, trigger changes, or FK constraint additions on `commerce.orders`, `commerce.commission_payouts`, or `commerce.brand_affiliate_rollup` pass CI silently
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace `affiliateStubDbConnection()`, `affiliatePaidQueryMock()`, and `affiliateStubExtras()` with real data setup using the `setupCommerceOrdersTables()` helpers already established in `AffiliateCommercePaidGateTest.php`
        - Seed a brand, an affiliate, and a set of completed/refunded orders directly into the test database; assert the controller returns correctly shaped response data from real queries
        - Guard pgsql-specific assertion paths with `$this->markTestSkipped('Requires pgsql')` following `BackfillOrdersPayoutIdTest.php`
        - Remove the three helper functions once replaced
    - **Technical:** `DB::shouldReceive()` replaces the real database facade entirely. When a migration renames a column or a trigger begins computing a derived field differently, these mocks return the pre-migration shape — CI stays green while production returns stale or wrong analytics. The correct discipline is established in `AffiliateCommercePaidGateTest.php` (real inserts, real query assertions). Vendor SDKs may be mocked; the DB layer never should be.
    - **Plain English:** The tests for the affiliate analytics dashboard use a "fake database" that returns pre-programmed answers instead of querying real data. This means if you rename a column or change a business rule, these tests will still pass even though the dashboard would be broken in production. There's already a correct pattern in the codebase — these tests just need to follow it.
    - **Evidence:**
        ```php
        function affiliateStubDbConnection(string $driver = 'pgsql'): void
        {
            $connMock = Mockery::mock(\Illuminate\Database\Connection::class);
            $connMock->shouldReceive('getDriverName')->andReturn($driver);
            DB::shouldReceive('connection')->andReturn($connMock);
        }

        function affiliatePaidQueryMock(): \Illuminate\Database\Query\Builder
        {
            $mock = Mockery::mock(\Illuminate\Database\Query\Builder::class);
            foreach (['where', 'whereIn', 'whereNotIn', 'join', 'selectRaw'] as $m) {
                $mock->shouldReceive($m)->andReturnSelf();
            }
            $mock->shouldReceive('first')->andReturn((object) ['paid_cents' => 0]);

            return $mock;
        }
        ```

- [ ] **TEST-3** · P1 — DB-layer Mockery in `BrandCommerceAnalyticsControllerTest` plus stateful call-order coupling
    - **Where:** tests/Feature/Analytics/BrandCommerceAnalyticsControllerTest.php:21
    - **Affects:** Brand analytics dashboard correctness; same migration/trigger exposure as TEST-2, with the additional risk that stateful `$callCount` tracking makes tests fail on safe internal refactors and pass silently when queries are removed
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace `brandEmptyQueryMock()` and `stubDbConnection()` with real fixture data following the `AffiliateCommercePaidGateTest.php` pattern
        - The stateful `$callCount` counter on `first()` (which couples test outcomes to internal query execution order) will be naturally eliminated when switched to real queries
        - Seed representative brand + affiliate + order fixtures and assert shaped response data from real query results
        - Guard pgsql paths with `$this->markTestSkipped('Requires pgsql')` following `BackfillOrdersPayoutIdTest.php`
    - **Technical:** Same root cause as TEST-2. The `brandEmptyQueryMock()` function mocks 16 query-builder methods. This creates a dual failure mode: adding a new query method the mock doesn't anticipate causes a Mockery unexpected-call error (false negative), while removing a query that should still exist goes undetected (false positive). The stateful `$callCount` tracking on `first()` — coupling assertions to internal query order — is especially brittle and is an anti-pattern that would not survive a legitimate performance refactor.
    - **Plain English:** Same core problem as the affiliate analytics tests, but worse in one respect: these tests track the exact order in which the database is asked questions. That means if a developer legitimately reorders two queries to improve performance (keeping the dashboard output identical), the tests break for the wrong reason. Conversely, if a query that should exist gets accidentally deleted, the fake database doesn't complain — so the tests still pass while real brand dashboards show wrong numbers.
    - **Evidence:**
        ```php
        function brandEmptyQueryMock(): \Illuminate\Database\Query\Builder
        {
            $mock = Mockery::mock(\Illuminate\Database\Query\Builder::class);

            foreach (['where', 'whereNotIn', 'whereIn', 'whereNull', 'whereNotNull',
                'whereBetween', 'leftJoin', 'join', 'select', 'selectRaw', 'groupBy',
                'groupByRaw', 'orderBy', 'orderByDesc', 'orderByRaw', 'limit'] as $m) {
                $mock->shouldReceive($m)->andReturnSelf();
            }
            $mock->shouldReceive('get')->andReturn(collect());
            $mock->shouldReceive('first')->andReturn(null);
            $mock->shouldReceive('pluck')->andReturn(collect());
            $mock->shouldReceive('count')->andReturn(0);

            return $mock;
        }
        ```

---

## P2 — Should fix

- [ ] **TEST-4** · P2 — Resource snapshot tests cover 2 of N Resource classes; shape regressions and accidental field exposure go undetected
    - **Where:** tests/Feature/Resources/ (2 files for all Resource classes in `app/Http/Resources/`)
    - **Affects:** All API consumers (frontend, Hydrogen storefront, future partners); a field removal, accidental rename, or private field surfacing on a public endpoint is invisible to the test suite until the frontend breaks or a security review catches it
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Enumerate `app/Http/Resources/**/*.php` and prioritise Resources that transform financial or cross-tenant data: `WalletMovementResource`, `CommissionPayoutResource`, `BrandPartnerLinkResource`, `AffiliateProductResource`, `SiteResource`
        - For each, write a snapshot test that constructs the resource with a factory-built model and asserts `->assertExactJson([...])` or `->assertJsonStructure([...])`
        - For Resources returned on public or cross-tenant endpoints (PublicSite, Hydrogen), additionally assert that internal fields (`professional_id`, `*_cents` monetary internals) are absent from the response
        - Follow the pattern established in `ProfessionalPublicResourceTest.php` and `ProfessionalResourceTest.php`
    - **Technical:** Resource classes are the sole API contract layer — controllers always return Resources, never raw Eloquent models. Without snapshot tests, any column rename (which requires updating both the migration and the Resource `toArray()`) has only one half of the update enforced by CI. The PII risk is highest on Resources used on public or cross-tenant endpoints: an internal money field or a `professional_id` appearing in a response the wrong user can read is a data leak that no other test layer catches. The 2 existing Resource tests establish the correct shape and demonstrate the test pattern is understood.
    - **Plain English:** Every piece of data the app sends to the frontend passes through a "translator" class. Right now, almost none of these translators have tests that check exactly what they send. If a developer accidentally makes a private field (like a cost amount in cents or a user's internal ID) visible in the wrong place, nobody would notice until someone spotted it in production. It's like printing a form letter without checking the template — small additions or removals slip through unnoticed.
    - **Evidence:**
        ```
        // Glob: tests/Feature/Resources/*.php
        tests/Feature/Resources/ProfessionalPublicResourceTest.php
        tests/Feature/Resources/ProfessionalResourceTest.php
        // 2 files total — no snapshot coverage for WalletMovementResource,
        // CommissionPayoutResource, BrandPartnerLinkResource, AffiliateProductResource,
        // SiteResource, or any other Resource class in app/Http/Resources/
        ```

- [ ] **TEST-5** · P2 — Migration tests inspect SQL text only; no runtime constraint enforcement
    - **Where:** tests/Feature/Commerce/OrdersSchemaMigrationTest.php:11
    - **Affects:** CI confidence in DB constraint correctness; a CHECK constraint with a typo in its expression, or a trigger that silently doesn't fire, passes all current migration tests
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `CommerceConstraintsTest.php` guarded with `$this->markTestSkipped('Requires pgsql')` when not on pgsql — follow `tests/Feature/Migrations/BackfillOrdersPayoutIdTest.php` exactly
        - Insert rows that violate each critical CHECK constraint (`entry_type = 'invalid_value'` against `commission_ledger_entries`) and assert a `QueryException` / `PDOException` is thrown
        - Cover at minimum: `entry_type` CHECK on `commission_ledger_entries`, NOT NULL guards on `_cents` money columns, and FK references between `commerce.orders` and `commerce.order_events`
        - Existing text-inspection tests in `OrdersSchemaMigrationTest.php`, `LegacyAggregatesDroppedMigrationTest.php`, and `LedgerRenameMigrationTest.php` serve as useful structural documentation and should be kept alongside the new runtime tests — annotate them as structural-only
    - **Technical:** The current tests call `file_get_contents($migrationPath)` and assert `->toContain(...)` on the raw SQL string. This catches structural drift (e.g., the CHECK clause was accidentally deleted from the file) but does not verify that PostgreSQL actually enforces the constraint at runtime. A typo in a constraint expression (`'pauot'` instead of `'payout'`) passes the text test trivially. The correct approach is demonstrated in `BackfillOrdersPayoutIdTest.php`: connect to real pgsql, run the schema, and assert constraint violations raise exceptions.
    - **Plain English:** The current migration tests work like proofreading a rulebook — they verify the rules are written down, but don't check that the referee actually enforces them during a game. A constraint with a small typo would still pass these tests. Adding tests that deliberately try to break the rules (insert invalid data and confirm the database rejects it) gives real assurance that the guardrails work in production, not just on paper.
    - **Evidence:**
        ```php
        beforeEach(function () {
            $this->migrationPath = base_path('supabase/migrations/20260506000000_create_orders_schema.sql');
            expect(file_exists($this->migrationPath))->toBeTrue('Phase 1 migration file is missing');
            $this->sql = file_get_contents($this->migrationPath);
        });

        it('extends commission_ledger_entries entry_type CHECK with clawback and adjustment', function () {
            expect($this->sql)
                ->toContain('commission_ledger_entries_entry_type_check')
                ->toContain("CHECK (entry_type IN ('accrual','reversal','payout','clawback','adjustment'))");
        });
        ```

---

## P3 — Nice to have

- [ ] **TEST-6** · P3 — 12 of 13 policies have no ability-level unit tests
    - **Where:** tests/Feature/Policies/ (1 file: `CommissionPolicyTest.php`; 13 concrete policies in `app/Policies/`)
    - **Affects:** Policy ability logic for `AffiliateProductPolicy`, `BrandPartnerLinkPolicy`, `SitePolicy`, `IntegrationPolicy`, `WalletMovementPolicy`, `SubscriptionPolicy`, and 6 others — allow/deny boundary regressions go undetected at the unit level
    - **Effort:** S (~0.5–1h per policy)
    - **What to do:**
        - For each policy in `app/Policies/*.php` (excluding `BasePolicy.php`), create a corresponding `tests/Feature/Policies/<Name>PolicyTest.php` following `CommissionPolicyTest.php`
        - For each ability method, test: own-resource allow, other-professional deny, wrong professional type deny, and (where applicable) pending-deletion `denyIfPendingDeletion` branching
        - CI already asserts model→policy registration via `PolicyCoverageTest.php` — these tests add behavioral coverage of individual ability logic, not structural coverage
    - **Technical:** `PolicyCoverageTest.php` enforces that every tenant-owned model has a `Gate::policy()` registration, but it does not exercise individual ability methods. A policy method that returns `true` unconditionally due to a refactor, or one that accidentally allows the wrong `professional_type`, passes all current CI checks. `CommissionPolicyTest.php` demonstrates the correct isolation pattern: construct the policy directly (bypassing Gate), inject mocked dependencies, and assert each ability method with owned/unowned/wrong-type actors. Note that `authorize()` calls `Gate::forUser(null)` in this codebase — silently passing. Unit tests that call policy methods directly are the only layer that catches ability logic bugs without a full integration test.
    - **Plain English:** The system has a "security guard" class for each type of data — one for affiliate products, one for wallet transactions, one for site settings, and so on. Right now only the financial commission guard has been tested individually to confirm it makes the right call in every situation. The other guards do get exercised in broader end-to-end tests, but if someone accidentally changes an "allow" to an "allow-all" during a refactor, only a dedicated unit test would catch it immediately. This is low urgency because the guards are working correctly today — it's purely defense-in-depth for future changes.
    - **Evidence:**
        ```php
        // tests/Feature/Policies/CommissionPolicyTest.php — the only existing policy unit test

        it('allows a brand to topUp on themselves', function () {
            $brand = (new Professional)->forceFill(['id' => 'brand-1', 'status' => 'active', 'professional_type' => 'brand']);
            expect($this->policy->topUp($brand, $brand))->toBeTrue();
        });

        it('forbids an affiliate from topping up another professional', function () {
            $affiliate = (new Professional)->forceFill(['id' => 'aff-1', 'status' => 'active', 'professional_type' => 'affiliate']);
            $brand = (new Professional)->forceFill(['id' => 'brand-1', 'status' => 'active', 'professional_type' => 'brand']);
            expect($this->policy->topUp($affiliate, $brand))->toBeFalse();
        });

        // 12 policies with no corresponding test file:
        // AffiliateProductPolicy, BrandPartnerLinkPolicy, BrandResourcePolicy,
        // CustomerPolicy, GdprPolicy, IntegrationPolicy, NotificationPolicy,
        // ProfessionalSelfPolicy, ServicePolicy, SitePolicy,
        // SubscriptionPolicy, WalletMovementPolicy
        ```

`★ Insight ─────────────────────────────────────`
**Why DB mocks are uniquely dangerous in this architecture:** Partna's analytics queries span multiple Postgres schemas (`commerce.*`) with trigger-maintained rollup tables. When you mock `DB::shouldReceive('table')`, you're not just skipping the query — you're also bypassing the schema prefix routing, the trigger chain, and any FK constraints that would expose data integrity issues. This is why `AffiliateCommercePaidGateTest.php` was written with real DB insertions: it found real trigger bugs during development that the mock-based tests would have silently passed.

**The `$callCount` antipattern in TEST-3:** The stateful query-order tracking in `BrandCommerceAnalyticsControllerTest.php` is a code smell that signals the test was written to match the current implementation rather than to specify observable behavior. Tests should assert outputs, not implementation sequence. When you switch to real DB tests, this antipattern disappears naturally because you're asserting on the final response body rather than internal query order.

**Policy ability tests vs. policy registration tests are different layers:** `PolicyCoverageTest.php` (existing, CI-enforced) answers "does a guard exist for this data type?" Policy unit tests answer "does the guard make the right call?" Both are needed. The CLAUDE.md auth doctrine's emphasis on `authorizeForUser` (not `authorize`) makes ability-level testing especially important: a policy that accidentally returns `true` for all callers would never be caught by integration tests that only check whether authorization was *attempted*, not whether it was *evaluated correctly*.
`─────────────────────────────────────────────────`
