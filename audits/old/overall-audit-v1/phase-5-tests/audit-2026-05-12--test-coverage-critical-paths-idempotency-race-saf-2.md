`★ Insight ─────────────────────────────────────`
`VoidExpiredPayoutsJobTest` and `WalletMovementsLedgerTest` are both self-provisioning pgsql tests — they use `CREATE TABLE IF NOT EXISTS` in `beforeEach` rather than relying on the production schema, which means factory bugs don't surface in those tests. This isolation pattern is why TEST-5 and TEST-6 are false positives but factory bugs (TEST-1, TEST-2) can still silently hide: the test schema is a forgiving subset of the real schema.
`─────────────────────────────────────────────────`

# Test Coverage Audit — 2026-05-12

**Branch:** development
**Lens:** Test coverage: critical paths, idempotency, race-safety, policy abilities, mock-vs-integration discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `database/factories/ProfessionalFactory.php`
- `database/factories/CommissionPayoutFactory.php`
- `database/factories/Commerce/OrderFactory.php`
- `database/factories/Commerce/WalletMovementFactory.php`
- `database/factories/Retail/CommissionPayoutItemFactory.php`
- `supabase/migrations/20260403000000_v2_baseline.sql`
- `supabase/migrations/20260428000000_payout_grace_and_app_fee.sql`
- `supabase/migrations/20260506000000_create_orders_schema.sql`
- `supabase/migrations/20260506300000_relax_commission_payout_items_link.sql`
- `supabase/migrations/20260508100000_url_columns_and_triggers.sql`
- `supabase/migrations/20260510000000_add_commission_payouts_lifecycle_columns.sql`
- `supabase/migrations/20260510300000_add_wallet_movements_ledger.sql`
- `supabase/migrations/20260510400000_extend_orders_rate_source_constraint.sql`
- `supabase/migrations/20260511000000_add_commission_payouts_grace_started_at.sql`
- `tests/Feature/Commerce/OrdersSchemaMigrationTest.php`
- `tests/Feature/Stripe/VoidExpiredPayoutsJobTest.php`
- `tests/Feature/Stripe/WalletMovementsLedgerTest.php`
- `tests/Feature/Webhooks/Shopify/Gdpr/RedactCustomerOrdersTest.php`
- `tests/Feature/Migrations/BackfillOrdersPayoutIdTest.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 4 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#TEST-1** · P1 — ProfessionalFactory emits an invalid `professional_type` and omits `phone`, breaking pgsql integration tests
    - **Where:** `database/factories/ProfessionalFactory.php` (definition block)
    - **Affects:** Any future test that calls `Professional::factory()->create()` against a schema-correct PostgreSQL connection. The factory produces a row that violates two constraints simultaneously: a CHECK on `professional_type` (the value `'affiliate'` is not in the allowed enum) and a `NOT NULL` on `phone` (no default exists). After migration `20260508300000`, `partna_url` is also `NOT NULL` but trigger-computed — creating a professional without a site row would violate that constraint too. Existing tests are unaffected only because they use `setupProfessionalsTable()` helpers that build a permissive in-test schema. The bug is invisible until a new contributor writes a conventional integration test.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `'professional_type' => 'affiliate'` to a valid enum value (e.g., `'influencer'`); add a `brand()` state returning `['professional_type' => 'brand']`.
        - Add `'phone' => fake()->e164PhoneNumber()` to the default array.
        - Add `'partna_url' => null` as an explicit nullable default, or add a `withSite()` state that also creates a `site.sites` row so the URL trigger fires. Consider whether `partna_url NOT NULL` should be relaxed in the simplified test schema helpers.
    - **Technical:** The v2 baseline (`20260403000000_v2_baseline.sql`) has `CONSTRAINT professionals_professional_type_check CHECK (professional_type IN ('professional', 'influencer', 'barber', 'hairdresser', 'ambassador', 'promoter', 'brand', 'barbershop', 'salon'))`. The string `'affiliate'` is not in that set. `phone text NOT NULL` has no database default. `partna_url` became `NOT NULL` in `20260508300000_url_columns_not_null.sql`. Current tests sidestep these issues via `setupProfessionalsTable()` (which creates a schema-lite table in the pgsql connection without these constraints) or via raw `DB::table('core.professionals')->insert(...)` that manually supplies all required fields. The factory pattern should be trustworthy — calling `::factory()->create()` should produce a valid row.
    - **Plain English:** The dummy user template used in tests is stamped with a job title ("affiliate") that the database doesn't recognise, and leaves out a required phone number field. This means any developer who writes a new test the normal way — "create a fake user, check it does X" — will get a confusing database rejection error before their test even starts. It's like a test-dummy checklist with a misspelling in the job-title box and a blank field where a phone number is required.
    - **Evidence:**
        ```php
        // database/factories/ProfessionalFactory.php
        'professional_type' => 'affiliate',  // not in CHECK enum
        // 'phone' key is absent — NOT NULL, no DB default
        ```
        ```sql
        -- supabase/migrations/20260403000000_v2_baseline.sql
        CONSTRAINT professionals_professional_type_check CHECK (
            professional_type IN (
                'professional', 'influencer', 'barber', 'hairdresser',
                'ambassador', 'promoter', 'brand', 'barbershop', 'salon'
            )
        ),
        -- separate column:
        phone text NOT NULL,
        ```

- [ ] **#TEST-2** · P1 — `CommissionPayoutFactory` omits NOT NULL columns `eligible_after` and `void_at`
    - **Where:** `database/factories/CommissionPayoutFactory.php` (definition block, lines 18–44)
    - **Affects:** Tests that call `CommissionPayout::factory()->create()` against the real pgsql schema. Three existing test files were confirmed to reference this factory (`CommissionPayoutRefundServiceTest.php`, `BrandPayoutFundingFailedNotificationTest.php`, `AffiliatePayoutGraceWarningNotificationTest.php`). They currently survive only because they also call `CREATE TABLE IF NOT EXISTS commerce.commission_payouts` in `beforeEach` with a permissive column list. Any test that relies on the factory alone — against the production schema — will fail with a NOT NULL violation.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `'eligible_after' => now()->toDateTimeString()` to the definition.
        - Add `'void_at' => now()->addDays(60)->toDateTimeString()` to the definition.
        - Add `'grace_started_at' => null` explicitly (nullable, but makes intent clear).
        - Add convenience states: `pendingFunds()`, `expiredGrace()`, `completed()` — these are the three scenarios most financial-flow tests need.
    - **Technical:** `eligible_after timestamptz NOT NULL` was present in the v2 baseline. `void_at` was added as nullable in `20260428000000_payout_grace_and_app_fee.sql`, then promoted to `NOT NULL` in the same migration (`ALTER COLUMN void_at SET NOT NULL`), with a backfill of `created_at + interval '60 days'`. Neither column has a database DEFAULT. The factory's missing fields mean that any test using `CommissionPayout::factory()->create()` against the real schema will throw `ERROR: null value in column "eligible_after" violates not-null constraint`.
    - **Plain English:** The test template for payout records is missing two required date fields — a "payout becomes eligible" date and a "payout expires if unclaimed" date. Any test that creates a payout using the standard template will be rejected by the database, the same way a form with mandatory date pickers left blank gets rejected. The workaround currently hidden inside existing tests is fragile and will fail once those tests are simplified.
    - **Evidence:**
        ```php
        // database/factories/CommissionPayoutFactory.php
        return [
            'id' => (string) Str::uuid(),
            'brand_professional_id' => (string) Str::uuid(),
            // ... no 'eligible_after' key
            // ... no 'void_at' key
            'grace_notifications_sent' => [],
        ];
        ```
        ```sql
        -- supabase/migrations/20260428000000_payout_grace_and_app_fee.sql
        ALTER TABLE commerce.commission_payouts
            ALTER COLUMN void_at SET NOT NULL;
        ```

- [ ] **#TEST-3** · P1 — No CI test for `rollup_apply_delta()` trigger branches against a live database
    - **Where:** `supabase/migrations/20260506000000_create_orders_schema.sql` (trigger + function definition); closest existing test: `tests/Feature/Commerce/OrdersSchemaMigrationTest.php`
    - **Affects:** `commerce.brand_affiliate_rollup` correctness — every brand dashboard's revenue figures, every affiliate's commission totals, and payout eligibility queries all read from this trigger-maintained table. An undetected regression in the delta computation would silently corrupt analytics and payment amounts for all active brands.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Commerce/RollupTriggerTest.php` (pgsql-only, skip on SQLite — follow the `BackfillOrdersPayoutIdTest.php` pattern).
        - Happy path: INSERT an `approved` order → assert rollup row exists with `orders_count=1`, `gross_cents`, `commission_cents` matching.
        - Refund delta: UPDATE `refund_cents` → assert `reversed_commission_cents` increases proportionally.
        - Terminal reversal: UPDATE status to `cancelled` → assert rollup decrements `orders_count` by 1 and full `commission_cents` moves to `reversed_commission_cents`.
        - Stub skip: INSERT with `status='stub'` → assert NO rollup row created.
        - Stub promotion: UPDATE `stub` → `approved` → assert rollup row appears.
        - No-op guard: UPDATE where all deltas are zero → assert `updated_at` is unchanged.
    - **Technical:** The existing `OrdersSchemaMigrationTest.php` is explicitly a text-only structural check; its comment reads: _"actual Postgres-specific behavior (RLS, triggers, jsonb_strip_pii, BRIN, ON CONFLICT WHERE) is validated during Phase 2 backfill against a real Supabase branch, not in CI."_ That is a deferred validation that was never promoted to a regression test. `rollup_apply_delta()` has five distinct code paths (INSERT, UPDATE-stub-promotion, UPDATE-to-terminal, UPDATE-generic-delta, no-op early-return) and uses arithmetic on `bigint` columns with `COALESCE(ROUND(...), 0)` logic. The proportional `_reversed_delta` calculation is particularly fragile — a divisor-zero guard (`NULLIF(NEW.gross_cents, 0)`) prevents a crash but the rounding behaviour under partial refunds is not verified anywhere.
    - **Plain English:** The table that powers every sales dashboard on the platform updates itself automatically whenever an order changes — through a piece of database code called a trigger. That trigger has five different paths depending on whether an order is new, updated, cancelled, or converted from a draft. None of those paths have a single test that actually runs the trigger and checks the result. The only "test" currently in place checks that the trigger code was written in the migration file — not that it produces the right answer. A typo in the maths would silently show wrong revenue for every brand.
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260506000000_create_orders_schema.sql
        CREATE TRIGGER trg_rollup
            AFTER INSERT OR UPDATE ON commerce.orders
            FOR EACH ROW EXECUTE FUNCTION commerce.rollup_apply_delta();
        ```
        ```php
        // tests/Feature/Commerce/OrdersSchemaMigrationTest.php (line 6-8)
        // Schema-doc test: verifies the orders-schema migration file exists and contains
        // the expected DDL surface. This is a structural check — actual Postgres-specific
        // behavior (RLS, triggers, jsonb_strip_pii, BRIN, ON CONFLICT WHERE) is validated
        // during Phase 2 backfill against a real Supabase branch, not in CI.
        ```

- [ ] **#TEST-4** · P1 — No CI test for any of the five URL-sync triggers across three schemas
    - **Where:** `supabase/migrations/20260508100000_url_columns_and_triggers.sql` (five trigger definitions); no existing test file found
    - **Affects:** `core.professionals.partna_url` and `brand.brand_partner_links.site_url` — every public-facing affiliate link and every brand storefront URL. A regression silently breaks all affiliate partner links on the platform. There is no HTTP 500 or exception; the URL column just holds a stale or NULL value.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Migrations/UrlSyncTriggerTest.php` (pgsql-only).
        - Site INSERT: create a site with `subdomain='testbrand'` → assert `professionals.partna_url = 'https://testbrand.partna.au'`.
        - Subdomain UPDATE: change `subdomain` → assert `partna_url` updates AND cascades to `brand_partner_links.site_url`.
        - Handle CHANGE (Trigger 3): update `handle` on a professional → assert old handle inserted into `professional_handle_aliases`, assert affiliate `site_url` path-segment updates.
        - Handle alias COLLISION (Trigger 5): attempt rename to a handle already reserved in `professional_handle_aliases` → assert `ERRCODE = '23505'` raised.
        - Brand partner link INSERT (Trigger 4): create a `brand_partner_links` row → assert `site_url` computed as `brand.partna_url + '/' + affiliate.handle`.
        - NULL guard (from `20260508200000` patch): brand with no site row → `partna_url` stays NULL, cascade does NOT wipe existing affiliate `site_url` values.
    - **Technical:** Migration `20260508100000` installs five interdependent triggers across `site.sites`, `brand.brand_store_settings`, `core.professionals`, and `brand.brand_partner_links`. A critical NULL-guard bug was caught and patched in `20260508200000_backfill_user_urls.sql` (the `IF v_url IS NOT NULL THEN` guard in `trg_recompute_partna_url`). If that guard ever regresses, every affiliate URL is silently wiped on any brand site-settings update. Trigger 5 (`professional_handle_alias_check_bu`) had its `WHEN` clause fixed in `20260508700000`. Neither bug-fix has a regression test. The handle-alias collision check is also the only enforcement of the redirect-reservation invariant (prevents handle squatting) and has zero coverage.
    - **Plain English:** Every link on the platform — a brand's storefront URL, an affiliate's personalised link — is computed and kept up-to-date by five pieces of database automation. These work together: when a brand changes its subdomain, the system automatically updates every affiliate's link too. A previous bug would have wiped every affiliate link on any routine brand-settings save — it was caught and fixed, but there's no test to make sure it stays fixed. If anyone accidentally undoes that fix in a future migration, every affiliate link on the platform silently becomes broken with no error anywhere.
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260508100000_url_columns_and_triggers.sql
        CREATE TRIGGER sites_url_sync_aiu
            AFTER INSERT OR UPDATE OF subdomain ON site.sites
            FOR EACH ROW EXECUTE FUNCTION site.trg_sites_url_sync();

        CREATE TRIGGER professional_handle_change_au
            AFTER UPDATE OF handle ON core.professionals
            FOR EACH ROW WHEN (OLD.handle IS DISTINCT FROM NEW.handle)
            EXECUTE FUNCTION core.trg_professional_handle_change();
        -- (plus 3 more triggers)
        ```
        ```php
        // Verified: no test file found in tests/ matching url_sync, UrlSync, partna_url,
        // trg_recompute, or professional_handle_aliases via Glob + Grep.
        ```

---

## P2 — Should fix

- [ ] **#TEST-5** · P2 — No CHECK constraint rejection tests for the new `rate_source`, `failure_category`, and status constraints
    - **Where:** `supabase/migrations/20260510400000_extend_orders_rate_source_constraint.sql`; `supabase/migrations/20260510000000_add_commission_payouts_lifecycle_columns.sql`; `supabase/migrations/20260508000000_add_reversed_payout_status.sql`; closest test location: `tests/Feature/Migrations/`
    - **Affects:** Analytics queries that filter on `rate_source`, payout retry logic that branches on `failure_category`, and any code path relying on `status` enum correctness. A future migration that accidentally loosens or misspells one of these constraints would be invisible to CI — bad values would silently accumulate in the table.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Feature/Migrations/CheckConstraintRejectionTest.php` (pgsql-only, follow `BackfillOrdersPayoutIdTest.php` pattern).
        - For `chk_orders_rate_source`: attempt `DB::statement("INSERT INTO commerce.orders (..., rate_source) VALUES (..., 'invalid_source')")` → assert a `check_violation` exception is thrown.
        - For `chk_cp_failure_category`: insert a payout with `failure_category = 'invalid'` → assert rejection.
        - For `chk_cp_funding_failure_count`: insert a payout with `funding_failure_count = 999` (above 50) → assert rejection.
        - For `cp_status_check`: insert a payout with `status = 'suspended'` → assert rejection.
        - Use `DB::statement()` raw SQL, not Eloquent models, so Eloquent enum casts cannot mask a constraint regression.
    - **Technical:** The pattern already exists in `tests/Feature/Audit/` (financial models without SoftDeletes, from commit `29b7eb1`). The same structural-invariant pattern should cover CHECK constraints. `chk_orders_rate_source` was introduced alongside `rate_source = 'pending'` for out-of-bounds metafields (commit `af90b2e`). A constraint regression would cause `pending` orders to silently bypass eligibility checks. `chk_cp_funding_failure_count` caps retries at 50; exceeding it is an invariant the retry-loop logic depends on.
    - **Plain English:** Several recent database changes added strict "allowed values" rules — for example, a payout's failure type must be one of six specific strings, and the retry counter can't exceed 50. There's no test that tries inserting a disallowed value and confirms the database pushes back. If a future migration accidentally drops or misspells one of these rules, wrong data would silently accumulate in the table, and the retry or analytics logic that depends on these values would produce incorrect results with no error.
    - **Evidence:**
        ```sql
        -- supabase/migrations/20260510400000_extend_orders_rate_source_constraint.sql
        ALTER TABLE commerce.orders
            ADD CONSTRAINT chk_orders_rate_source
            CHECK (rate_source IN
                ('product_metafield','metafield_override','brand_default','platform_default','manual','pending'));

        -- supabase/migrations/20260510000000_add_commission_payouts_lifecycle_columns.sql
        ALTER TABLE commerce.commission_payouts
            ADD CONSTRAINT chk_cp_failure_category
            CHECK (failure_category IS NULL OR failure_category IN
                ('brand_funding','affiliate_account','stripe_transient','stripe_terminal','platform','order_refunded'));

        ALTER TABLE commerce.commission_payouts
            ADD CONSTRAINT chk_cp_funding_failure_count
            CHECK (funding_failure_count >= 0 AND funding_failure_count <= 50);
        ```

- [ ] **#TEST-6** · P2 — `OrderFactory` omits `line_items` and `shopify_data`, silently defeating trigger-coverage assertions
    - **Where:** `database/factories/Commerce/OrderFactory.php` (definition block, lines 22–43)
    - **Affects:** Any test that creates an order via the factory and then asserts trigger side-effects. `trg_order_items_diff` fires on `line_items` INSERT/UPDATE but contains an early-return guard: `IF NEW.status = 'stub' OR jsonb_array_length(NEW.line_items) = 0 THEN RETURN NEW`. Factory-created orders produce `line_items = '[]'` (the DB default), so the trigger's entire upsert/delete branch is never reached. A test asserting `commerce.order_items` rows exist will produce a false negative — the table stays empty but the test framework sees a passing assertion of zero against zero.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `withLineItem()` state to `OrderFactory` that populates `line_items` with one valid JSONB structure matching the trigger's expected shape:
            ```php
            public function withLineItem(array $override = []): static
            {
                return $this->state(fn ($attrs) => [
                    'line_items' => [array_merge([
                        'shopify_line_item_id' => (string) Str::uuid(),
                        'shopify_product_id' => 'gid://shopify/Product/1',
                        'shopify_variant_id' => null,
                        'sku' => null,
                        'title' => 'Test Product',
                        'quantity' => 1,
                        'unit_price_cents' => $attrs['gross_cents'] ?? 5000,
                        'discount_cents' => 0,
                        'line_total_cents' => $attrs['gross_cents'] ?? 5000,
                        'commission_cents' => $attrs['commission_cents'] ?? 750,
                        'commission_rate' => $attrs['commission_rate'] ?? 15.0,
                    ], $override)],
                ]);
            }
            ```
        - Explicitly add `'line_items' => []` and `'shopify_data' => []` to the default definition so intent is clear and Eloquent casts behave predictably.
    - **Technical:** The `order_items_diff()` trigger function (defined in `20260506000000_create_orders_schema.sql`) checks `jsonb_array_length(NEW.line_items) = 0` as an early return condition. Since the DB default for `line_items` is `'[]'::jsonb` and the factory omits the field, every factory-created order takes the early-return path and leaves `commerce.order_items` empty. Tests in `tests/Feature/Commerce/` that assert `order_items` population after factory inserts will never exercise the actual trigger upsert logic.
    - **Plain English:** The test template for orders creates orders with an empty shopping cart. That's valid in the database but means every test that's supposed to verify "when an order is placed, individual line-item records appear in the breakdown table" will produce a misleading result. The test passes because it's comparing zero to zero, not because the trigger actually worked. It's like testing a parcel scanner by running it over an empty conveyor and concluding "no errors found."
    - **Evidence:**
        ```php
        // database/factories/Commerce/OrderFactory.php
        return [
            'id' => (string) Str::uuid(),
            'shopify_order_id' => (string) $this->faker->unique()->numerify('################'),
            // ... no 'line_items' key (DB default '[]' hides the gap)
            // ... no 'shopify_data' key
            'occurred_at' => now()->toDateTimeString(),
        ];
        ```
        ```sql
        -- supabase/migrations/20260506000000_create_orders_schema.sql
        -- Inside order_items_diff():
        IF NEW.status = 'stub' OR jsonb_array_length(NEW.line_items) = 0 THEN
            RETURN NEW;
        END IF;
        ```

- [ ] **#TEST-7** · P2 — `CommissionPayoutItemFactory` generates a random orphan `order_id` with no matching `commerce.orders` row
    - **Where:** `database/factories/Retail/CommissionPayoutItemFactory.php` (definition block)
    - **Affects:** Any test that uses `CommissionPayoutItem::factory()->create()` against a pgsql connection that has the FK constraint from `20260506300000_relax_commission_payout_items_link.sql`. The FK (`cpi_order_fk`) enforces `order_id REFERENCES commerce.orders(id) ON DELETE RESTRICT`. A random UUID that has no matching orders row violates the FK and the INSERT is rejected.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `withOrder()` state that accepts or creates an `Order`:
            ```php
            public function withOrder(?Order $order = null): static
            {
                return $this->state(fn () => [
                    'order_id' => ($order ?? Order::factory()->create())->id,
                ]);
            }
            ```
        - Change the default `'order_id'` to `Order::factory()` so factory resolution chains automatically, rather than `Str::uuid()` which is always an orphan.
        - Separately, optionally add a `withPayout()` state on `CommissionPayoutFactory` that creates N payout items with valid orders, for payout-flow integration tests.
    - **Technical:** `CommissionPayoutItemFactory` sets `'order_id' => (string) Str::uuid()` — a freshly generated UUID with no referent in `commerce.orders`. Migration `20260506300000_relax_commission_payout_items_link.sql` added `FOREIGN KEY (order_id) REFERENCES commerce.orders(id) ON DELETE RESTRICT`. On PostgreSQL with FK enforcement active, any factory-created payout item will fail. On SQLite test schemas that omit the FK (which is likely the current test setup), the orphan row is created but is useless for any test that joins `commission_payout_items → orders`. This is the same root-cause pattern as TEST-1 and TEST-2.
    - **Plain English:** The test template for payout line-items generates a random transaction ID that points to an order that doesn't exist in the database. It's like writing a delivery receipt that references a tracking number no one ever created. In a database with proper cross-table checks, this gets rejected outright; in a test environment that skips those checks, the record is created but useless for any test that needs to see the actual order details.
    - **Evidence:**
        ```php
        // database/factories/Retail/CommissionPayoutItemFactory.php
        return [
            'id' => (string) Str::uuid(),
            'payout_id' => (string) Str::uuid(),
            'order_id' => (string) Str::uuid(),  // random UUID — no FK target
            'amount_cents' => $this->faker->numberBetween(1000, 10000),
        ];
        ```
        ```sql
        -- supabase/migrations/20260506300000_relax_commission_payout_items_link.sql
        ALTER TABLE commerce.commission_payout_items
            ADD CONSTRAINT cpi_order_fk
                FOREIGN KEY (order_id) REFERENCES commerce.orders(id) ON DELETE RESTRICT;
        ```

---

## P3 — Nice to have

- [ ] **#TEST-8** · P3 — `WalletMovementFactory` has no convenience state for same-key duplicate testing
    - **Where:** `database/factories/Commerce/WalletMovementFactory.php:23`
    - **Affects:** Tests asserting idempotency of wallet credit operations. The `WalletMovementsLedgerTest.php` currently workarounds this by overriding `idempotency_key` inline (`walletLedger_makeSession($brand->id, ['id' => 'cs_topup_dup'])`), but that pattern doesn't generalise to factory-based test setups and requires test authors to understand the internal keying scheme.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `withIdempotencyKey(string $key): static` state method.
        - Optionally add `duplicateOf(WalletMovement $existing): static` that copies the `idempotency_key` from an existing movement — the most common idempotency test pattern.
    - **Technical:** The factory uses `'idempotency_key' => 'test:' . Str::uuid()->toString()`, which guarantees uniqueness per factory call. That is correct for general test data but means no factory-based path exists to exercise the `UNIQUE (idempotency_key)` constraint at the DB level. The idempotency tests that do exist in `WalletMovementsLedgerTest.php` bypass the factory entirely. Adding a state method lowers the barrier for future test authors to write idempotency assertions correctly.
    - **Plain English:** The wallet-movement test template always generates a brand-new unique transaction ID, which is correct for most cases. But when you want to test "what happens if the same transaction comes in twice," there's no quick shortcut — you have to know the internal naming scheme and override it manually. This is a missing convenience shortcut, not a broken feature.
    - **Evidence:**
        ```php
        // database/factories/Commerce/WalletMovementFactory.php
        'idempotency_key' => 'test:' . Str::uuid()->toString(),
        // Always unique — no factory state for "same key, second call"
        ```
