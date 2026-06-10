Now I have the complete verified picture. Let me write the final audit:

- **TEST-1 (dropped)**: Financial coverage claim was false — all service/job test files exist.
- **TEST-2 (dropped)**: Policy coverage claim was false — 13/14 policies have unit tests in `tests/Unit/Policies/`.
- **TEST-3 (kept, expanded to P2)**: DB mock anti-pattern confirmed in 11 files (7 Staff + 3 Analytics + 1 unit service).
- **TEST-4, TEST-5 (dropped)**: Claims overlap with disproven TEST-1.
- **TEST-6 (dropped)**: Resource tests exist in `tests/Feature/Resources/`.
- **New finding (P3)**: `WalletMovementPolicy` is the only policy class without a unit test.

---

# Test Coverage Audit — 2026-05-12

**Branch:** development
**Lens:** Test coverage: critical paths, idempotency, race-safety, policy abilities, mock-vs-integration discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- tests/Feature/Staff/StaffAffiliateControllerTest.php
- tests/Feature/Staff/StaffAffiliateStatusControllerTest.php
- tests/Feature/Staff/StaffCommissionControllerTest.php
- tests/Feature/Staff/StaffInviteControllerTest.php
- tests/Feature/Staff/StaffIntegrationControllerTest.php
- tests/Feature/Staff/StaffPayoutListControllerTest.php
- tests/Feature/Staff/StaffStatsControllerTest.php
- tests/Feature/Analytics/BrandCommerceAnalyticsControllerTest.php
- tests/Feature/Analytics/AffiliateCommerceAnalyticsControllerTest.php
- tests/Feature/Analytics/AffiliateProjectionsControllerTest.php
- tests/Unit/Services/Analytics/AffiliateProjectionsServiceTest.php
- app/Policies/WalletMovementPolicy.php
- tests/Unit/Policies/ (13 files)
- tests/Feature/Policies/CommissionPolicyTest.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 1 complete

---

## P2 — Should fix

- [ ] **TEST-1** · P2 — DB mock anti-pattern in 11 staff and analytics test files
    - **Where:** tests/Feature/Staff/StaffAffiliateControllerTest.php, StaffAffiliateStatusControllerTest.php, StaffCommissionControllerTest.php, StaffInviteControllerTest.php, StaffIntegrationControllerTest.php, StaffPayoutListControllerTest.php, StaffStatsControllerTest.php; tests/Feature/Analytics/BrandCommerceAnalyticsControllerTest.php, AffiliateCommerceAnalyticsControllerTest.php, AffiliateProjectionsControllerTest.php; tests/Unit/Services/Analytics/AffiliateProjectionsServiceTest.php
    - **Affects:** All staff controller tests and analytics controller tests — a multi-schema SQL bug (wrong join column, renamed table, changed `search_path`) would pass silently because the mock chain absorbs any query, not the real schema.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Replace `DB::shouldReceive('table')->...->andReturn(...)` chains with real SQLite-in-memory DDL (same approach as `tests/Feature/Webhooks/Stripe/StripeConnectWebhookControllerEndToEndTest.php` which uses `insertWebhookPayout()` with real CREATE TABLE statements).
        - For each test file: create the minimum in-memory schema via `Schema::create()` or a shared `beforeEach` helper, insert fixture rows using `DB::table(...)` inserts, then let the real query builder run.
        - Group the 7 Staff files and 4 Analytics files into two separate bundled refactor sessions — they share the same fix pattern but differ in schema (brand.*, commerce.*, analytics.* vs core.*, billing.*).
        - Keep Mockery for the HTTP layer (e.g. Shopify SDK calls) — only restore the DB layer to real execution.
    - **Technical:** `DB::shouldReceive('table')` intercepts Mockery at the facade level. Any call to `DB::table('commerce.commission_payouts')` — regardless of what joins, wheres, or columns are chained after it — returns the mock's pre-programmed return value. This means the tests exercise controller logic and response shape but never execute a single byte of real SQL. A migration that renames `commission_ledger_entries` to `commission_movements` (which happened in `20260506600000_rename_ledger_to_movements.sql`) would be completely invisible to a test using `DB::shouldReceive('table')->with('commerce.commission_ledger_entries')` — the mock would still match the old string and return fake data, green-lighting code that would 500 in production. The Stripe end-to-end tests demonstrate the correct pattern: DDL-first helpers that stand up a real (SQLite) schema before each test.
    - **Plain English:** These tests are like a building inspector who brings a cardboard prop instead of checking the real pipes. The tests tell you "the controller returned the right JSON shape" but can't tell you "the database query actually works." If someone renames a table or changes a column name in a SQL migration, these 11 tests would still pass — and the first real sign of trouble would be a 500 error in production. The fix is to replace the prop with a real (lightweight, in-memory) database so the tests actually run the SQL.
    - **Evidence:**
        ```php
        // tests/Feature/Staff/StaffAffiliateControllerTest.php
        $mockQuery = Mockery::mock();
        $mockQuery->shouldReceive('join')->andReturnSelf();
        $mockQuery->shouldReceive('where')->andReturnSelf();
        $mockQuery->shouldReceive('whereNull')->andReturnSelf();
        $mockQuery->shouldReceive('orderByDesc')->andReturnSelf();
        $mockQuery->shouldReceive('get')->andReturn(collect([$row]));
        DB::shouldReceive('table')->with('brand.brand_partner_links as bpl')->andReturn($mockQuery);
        ```
        ```php
        // tests/Feature/Analytics/BrandCommerceAnalyticsControllerTest.php
        DB::shouldReceive('table')->with('commerce.orders')->andReturn($defaultMock);
        DB::shouldReceive('table')->with('commerce.orders as o')->andReturn($defaultMock);
        DB::shouldReceive('table')->with('commerce.brand_affiliate_rollup')->andReturn($defaultMock);
        DB::shouldReceive('table')->with('analytics.site_visits')->andReturn($defaultMock);
        DB::shouldReceive('table')->with('brand.brand_partner_links')->andReturn($defaultMock);
        ```

## P3 — Nice to have

- [ ] **TEST-2** · P3 — WalletMovementPolicy is the only policy class without a unit test
    - **Where:** app/Policies/WalletMovementPolicy.php:16; tests/Unit/Policies/ (no WalletMovementPolicyTest.php)
    - **Affects:** Tenant isolation on financial ledger rows — the `view()` method enforces that a professional can only read their own wallet movements. An accidental regression (e.g. early-return `true`, relaxed cast comparison) would go undetected.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `tests/Unit/Policies/WalletMovementPolicyTest.php` following the pattern of the other 13 policy unit tests in that directory.
        - Cover: owner can view, non-owner is denied, type-coercion edge (UUID string vs UUID string comparison via `(string)` cast).
    - **Technical:** All other 13 policy classes — including `CommissionPolicy`, `BrandPartnerLinkPolicy`, `SubscriptionPolicy` — have a matching file in `tests/Unit/Policies/`. `WalletMovementPolicy` is the lone exception. Its `view()` method performs `(string) $actor->id === (string) $movement->professional_id`, a cast-guarded UUID comparison. The cast is deliberate (Eloquent can return both `UuidInterface` objects and plain strings depending on cast config), but there is no test asserting the non-owner path returns `false`. The `PolicyCoverageTest` in `tests/Feature/Security/` checks that the policy is *registered*, not that its logic is correct — so this gap isn't caught by CI.
    - **Plain English:** Every other "who can see what" rule in the system has a small automated test confirming "the right person can see it and the wrong person can't." The wallet movements policy — which controls who can read financial ledger rows — is the only one missing that check. It's a simple fix: one test file, two assertions.
    - **Evidence:**
        ```php
        // app/Policies/WalletMovementPolicy.php
        class WalletMovementPolicy extends BasePolicy
        {
            public function view(Professional $actor, WalletMovement $movement): bool
            {
                return (string) $actor->id === (string) $movement->professional_id;
            }
        }
        ```

`★ Insight ─────────────────────────────────────`
The core antipattern across all 11 affected test files is that `DB::shouldReceive()` (Mockery facade interception) decouples the test from the actual SQL entirely — the mock swallows any query string, any join, any where clause. This is fundamentally different from mocking an HTTP client (Stripe SDK, Shopify API) where you *want* to decouple from the network. For database code, the schema *is* the contract, and SQLite in-memory DDL is cheap enough that there's no reason to avoid it. The pattern to follow already exists in the codebase: `StripeConnectWebhookControllerEndToEndTest.php` stands up real DDL before each test using `insertWebhookPayout()` helpers.
`─────────────────────────────────────────────────`

The audit surfaces one meaningful test-hygiene gap (11 files where SQL bugs would be invisible to CI) and one minor gap (one financial policy without a logic test) — all other areas DeepSeek flagged were disproved by verification.
