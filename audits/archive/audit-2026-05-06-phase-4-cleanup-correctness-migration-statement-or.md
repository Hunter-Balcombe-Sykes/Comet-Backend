`★ Insight ─────────────────────────────────────`
The most dangerous class of Phase-N cleanup bug is the "schema leads, code lags" pattern: the migration drops a column, but an Eloquent `$fillable` still lists it and the service still passes it to `create()`. Eloquent's mass-assignment guard won't protect you here — the `null` value IS in `$fillable`, so it lands in the INSERT SQL and PostgreSQL rejects the query. The test suite masks the breakage because SQLite test tables are created with the old column still present.
`─────────────────────────────────────────────────`

# Phase 4 Cleanup Correctness Audit — 2026-05-06

**Branch:** development-v2
**Lens:** Phase 4 cleanup correctness: migration statement ordering, FK drop safety, no remaining callers of dropped aggregate tables, doc accuracy, rewritten-caller equivalence
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `supabase/migrations/20260506500000_drop_legacy_aggregates.sql`
- `supabase/migrations/20260506300000_relax_commission_payout_items_link.sql`
- `app/Models/Retail/CommissionPayoutItem.php`
- `app/Services/Stripe/CommissionPayoutService.php`
- `app/Http/Controllers/Api/Professional/BrandAffiliateController.php`
- `app/Http/Controllers/Api/Professional/Analytics/BrandCommerceAnalyticsController.php`
- `app/Http/Controllers/Api/Professional/Analytics/AffiliateCommerceAnalyticsController.php`
- `app/Jobs/Notifications/SendWeeklyAnalyticsNotificationJob.php`
- `routes/api/professional.php`
- `tests/Feature/Commerce/LegacyAggregatesDroppedMigrationTest.php`
- `tests/Feature/Brand/BrandAffiliateSnapshotTest.php`
- `tests/Feature/Stripe/CommissionPayoutServiceTest.php`
- `tests/Feature/Stripe/VoidExpiredPayoutsJobTest.php`
- `AI_CONTEXT.md` / `CLAUDE.md`

## Progress

- P0 Blockers: 0 of 1 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 2 complete

---

## P0 — Must fix before any real user touches the system

- [ ] **#PH4-1** · P0 — `CommissionPayoutItem` + `CommissionPayoutService` still write to the column Phase 4 is dropping
    - **Where:** `app/Models/Retail/CommissionPayoutItem.php:26` · `app/Services/Stripe/CommissionPayoutService.php:223`
    - **Affects:** Every new payout batch created after the Phase 4 migration runs. `ProcessCommissionPayoutsJob` (daily) calls `CommissionPayoutService` which calls `CommissionPayoutItem::create([ 'commission_ledger_entry_id' => null, ... ])`. Once the column is gone, PostgreSQL rejects the INSERT with "column commission_ledger_entry_id of relation commission_payout_items does not exist". The daily payout sweep fails 100% of the time — affiliates receive no payouts.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `'commission_ledger_entry_id'` from `CommissionPayoutItem::$fillable`.
        - Remove the `ledgerEntry()` `BelongsTo` relationship from `CommissionPayoutItem` (the FK column will not exist post-Phase-4).
        - Remove the `'commission_ledger_entry_id' => null` key from the `CommissionPayoutItem::create([...])` call in `CommissionPayoutService::processNewBatch()`.
        - Update the `CommissionPayoutItem` class-level V2 comment (currently says "Exactly one of `commission_ledger_entry_id` / `order_id` must be set" — post-Phase-4 only `order_id` exists).
        - Ship the PHP changes in the same deploy as the migration; they are safe to deploy before the migration runs (passing an unknown key to a guarded `create()` is a no-op once it's out of `$fillable`).
    - **Technical:** Phase 3.5 (commit `1d46bb2`) introduced `CommissionPayoutItem::create(['commission_ledger_entry_id' => null, 'order_id' => $order->id, ...])` as a transitional form — `null` kept the old column satisfied while new rows were linked by `order_id`. Phase 4 drops the column via `ALTER TABLE commerce.commission_payout_items DROP COLUMN commission_ledger_entry_id`, but neither `CommissionPayoutItem::$fillable` nor the `create()` call site in `CommissionPayoutService` was updated. Because `commission_ledger_entry_id` is in `$fillable`, Eloquent includes it in the generated INSERT SQL (`INSERT INTO commerce.commission_payout_items (payout_id, commission_ledger_entry_id, order_id, amount_cents) VALUES (?, ?, ?, ?)`). After the column is dropped, PostgreSQL rejects this statement. The Phase 3.5 `relax_commission_payout_items_link` migration also added a `cpi_link_target_check` CHECK constraint and a `cpi_unique_ledger_entry` partial index on the column; both cascade-drop automatically when `DROP COLUMN` executes in Phase 4, so those require no explicit cleanup.
    - **Plain English:** The cleanup migration is going to demolish a hallway in the building — but the daily payroll robot still has "walk down that hallway" in its instructions. The moment the hallway disappears, the robot crashes and every affiliate stops getting paid. All three pieces need updating before this migration can safely run: the robot's instruction sheet (the service), the floor-plan reference it reads (the model's `$fillable` list), and the floor-plan legend (the class comment).
    - **Evidence:**
        ```php
        // CommissionPayoutItem.php:24-29
        protected $fillable = [
            'payout_id',
            'commission_ledger_entry_id',  // ← column dropped by Phase 4 migration
            'order_id',
            'amount_cents',
        ];

        // CommissionPayoutService.php:221-227
        foreach ($orders as $order) {
            CommissionPayoutItem::create([
                'payout_id' => $payout->id,
                'commission_ledger_entry_id' => null,  // ← INSERT fails post-Phase-4
                'order_id' => $order->id,
                'amount_cents' => $order->commission_cents,
            ]);
        }
        ```

---

## P1 — Fix before pilot launch

- [ ] **#PH4-2** · P1 — Test scaffolding creates `commission_payout_items` with the dropped column, masking PH4-1 in CI
    - **Where:** `tests/Feature/Stripe/CommissionPayoutServiceTest.php:76–83` · `tests/Feature/Stripe/VoidExpiredPayoutsJobTest.php:83–90`
    - **Affects:** CI test suite. After the P0 fix removes `commission_ledger_entry_id` from `$fillable`, `composer test` will still pass because both test files create their own in-memory SQLite `commission_payout_items` table that still includes the column. `CommissionPayoutServiceTest` also directly asserts `expect($item->commission_ledger_entry_id)->toBeNull()` — an assertion on a column that no longer exists in production. The test suite gives a false green that the payout path is healthy when it has diverged from the production schema.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `CommissionPayoutServiceTest.php` and `VoidExpiredPayoutsJobTest.php`, remove `commission_ledger_entry_id TEXT` from the `CREATE TABLE` DDL for `commerce.commission_payout_items`.
        - Remove the assertion `expect($item->commission_ledger_entry_id)->toBeNull()` from `CommissionPayoutServiceTest.php`.
        - Remove any `'commission_ledger_entry_id' => null` values from manual `commerce.commission_payout_items` insert fixtures in both test files.
        - Run `composer test` to confirm the suite stays green against the Phase-4-correct schema.
    - **Technical:** SQLite in-memory test databases are declared inline in `beforeEach` setup blocks, meaning they exactly mirror whatever DDL the test author wrote — not the Supabase production schema. This is a well-known divergence risk: the test schema was last updated for Phase 3.5 (which kept the column but made it nullable) and was never forward-updated for Phase 4's `DROP COLUMN`. Because Eloquent reads from `$fillable` (not the live schema) and SQLite accepts the column, `create()` succeeds in tests even once `$fillable` is cleaned up — but only because the test table still has the column. The assertion `expect($item->commission_ledger_entry_id)->toBeNull()` compounds the issue by pinning the expectation against a field the Phase-4 production schema won't have.
    - **Plain English:** The test workshop has a detailed mock-up of the production building, but that mock-up still includes the hallway that's being demolished. Every test performed in the workshop passes because the hallway is still there in the mock-up. This makes the test results meaningless for catching the P0 — it's like smoke-testing a fire alarm by using fake smoke in a room where the real alarm is disconnected.
    - **Evidence:**
        ```php
        // CommissionPayoutServiceTest.php:73–83
        // commission_payout_items supports both legacy ledger-entry items and new order-based items.
        // commission_ledger_entry_id and order_id are both nullable — SQLite does not enforce the
        // CHECK constraint (at least one must be set) from the PG migration, which is acceptable for tests.
        $conn->statement('CREATE TABLE IF NOT EXISTS commerce.commission_payout_items (
            id TEXT PRIMARY KEY,
            payout_id TEXT,
            commission_ledger_entry_id TEXT,   // ← Phase-4 drops this; test table still has it
            order_id TEXT,
            amount_cents INTEGER,
            created_at TEXT,
            updated_at TEXT
        )');

        // CommissionPayoutServiceTest.php:851 — assertion on a dropped column
        expect($item->commission_ledger_entry_id)->toBeNull();
        ```

---

## P2 — Should fix

- [ ] **#PH4-3** · P2 — `BrandAffiliateController` routes are not wrapped in `brand.only` middleware; inline `professional_type` guards used instead
    - **Where:** `routes/api/professional.php:82–92` · `app/Http/Controllers/Api/Professional/BrandAffiliateController.php:37,67,130,279`
    - **Affects:** All four `BrandAffiliateController` actions (`index`, `disconnect`, `snapshot`, `updateCustomPhotos`). Every action manually checks `mb_strtolower(trim(...)) !== 'brand'` and returns a 403. If the string comparison ever diverges from how the middleware evaluates brand status (e.g., casing rules, trimming, type column aliasing), a non-brand user can reach brand-only data.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the four `BrandAffiliateController` route registrations in `Route::middleware(['brand.only'])->group(...)` — the `EnsureBrandAccount` middleware already exists and is already applied to adjacent brand-only routes in the same file.
        - Remove the four identical `if (mb_strtolower(trim(...)) !== 'brand')` early-return guards from the controller methods.
        - The 403 response semantics are unchanged; the middleware issues it before the controller runs.
    - **Technical:** The Partna doctrine requires brand-only endpoints to gate via the `brand.only` middleware (`EnsureBrandAccount`), not inline `professional_type` string comparisons. `routes/api/professional.php:216` and `:301` already demonstrate the correct pattern — brand upload endpoints and Shopify-embedded-connection endpoints are correctly wrapped. The four `BrandAffiliateController` registrations at lines 82–92 were not added to an equivalent group. The inline guards work correctly today, but they duplicate logic that the middleware owns and are invisible to route-level audit tooling that scans for `brand.only` groups.
    - **Plain English:** The building has a key-card reader at the front door labelled "brand accounts only" — and it works perfectly for the logo-upload corridor and the Shopify corridor. But the affiliate-management corridor skipped the key-card reader and instead has a bouncer inside each room who checks IDs manually. The bouncer is doing the right thing today, but having two separate systems for the same rule means they can drift, and anyone auditing "which rooms require a brand key card" will miss these four rooms entirely.
    - **Evidence:**
        ```php
        // routes/api/professional.php:82–92 — no brand.only middleware on these routes
        Route::get('/brand-affiliates', [BrandAffiliateController::class, 'index']);
        Route::middleware('throttle:30,1')->group(function (): void {
            Route::delete('/brand-affiliates/{affiliate}', [BrandAffiliateController::class, 'disconnect'])
                ->whereUuid('affiliate');
        });
        Route::patch('/brand-affiliates/{affiliate}/custom-photos', [BrandAffiliateController::class, 'updateCustomPhotos'])
            ->whereUuid('affiliate');
        Route::get('/brand-affiliates/{affiliate}/snapshot', [BrandAffiliateController::class, 'snapshot'])
            ->whereUuid('affiliate');

        // routes/api/professional.php:216 — correct pattern used elsewhere
        Route::middleware(['brand.only'])->group(function () {
            Route::post('/uploads/brand-logo', [ProfessionalUploadController::class, 'uploadBrandLogo']);

        // BrandAffiliateController.php:37–40 — inline guard (repeated in all 4 methods)
        if (mb_strtolower(trim((string) $professional->professional_type)) !== 'brand') {
            return $this->error('Only brand accounts can view affiliates.', 403);
        }
        ```

- [ ] **#PH4-4** · P2 — `EXCLUDED_STATUSES` defined independently in four separate files; silent divergence corrupts revenue figures
    - **Where:** `app/Http/Controllers/Api/Professional/BrandAffiliateController.php:26` · `app/Http/Controllers/Api/Professional/Analytics/BrandCommerceAnalyticsController.php:22` · `app/Http/Controllers/Api/Professional/Analytics/AffiliateCommerceAnalyticsController.php:23` · `app/Jobs/Notifications/SendWeeklyAnalyticsNotificationJob.php` (inline array)
    - **Affects:** Commission totals, order counts, and customer counts shown to affiliates and brands. If a future status value is added (e.g., `'disputed'`) and one of the four sites is updated while the others are not, that status leaks into reported totals selectively — the snapshot modal shows it, the analytics chart doesn't, and the weekly email doesn't. Silent inconsistency in money reporting.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the canonical list to a shared constant, e.g., `App\Enums\OrderStatus::EXCLUDED_FROM_AGGREGATES` or a `CommerceAggregateScope` trait with a `const EXCLUDED_STATUSES`.
        - Replace all four independent definitions with a reference to the shared constant.
        - Add a comment at the definition site noting that adding a status here affects all commerce aggregate reads — payout eligibility, analytics charts, snapshot modal, and weekly notification.
    - **Technical:** All four sites currently carry the identical list `['stub', 'cancelled', 'voided', 'refunded']` and are in sync. The risk is forward-looking: the `BrandAffiliateController` comment already acknowledges the dependency ("Mirrors the analytics controllers' canonical list — keep in sync") but relies entirely on human discipline with no mechanical enforcement. The `SendWeeklyAnalyticsNotificationJob` doesn't even use a named constant — it inlines the array directly. A status addition during Phase 5 or later that updates only two of the four sites would produce different totals in the snapshot modal vs. the analytics chart vs. the weekly email, which is a trust-eroding inconsistency in financial reporting.
    - **Plain English:** Four separate notice-boards in four different offices each list the order types that shouldn't count toward earnings. Right now all four boards say the same thing, but they were written separately and nobody is checking that they stay in sync. The next time someone adds a new order type to exclude, they need to remember to update all four boards — and the job's board isn't even labelled, it's just a sticky note. One missed board and the weekly earnings email contradicts the analytics dashboard, and the founder gets angry support tickets asking why the numbers don't match.
    - **Evidence:**
        ```php
        // BrandAffiliateController.php:24–26
        // Status values excluded from live commerce aggregations. Mirrors the
        // analytics controllers' canonical list — keep in sync.
        private const EXCLUDED_STATUSES = ['stub', 'cancelled', 'voided', 'refunded'];

        // BrandCommerceAnalyticsController.php:20–22
        // Status values excluded from all live-query commerce aggregations.
        // 'approved' is the canonical "paid" status — do NOT exclude it.
        private const EXCLUDED_STATUSES = ['stub', 'cancelled', 'voided', 'refunded'];

        // AffiliateCommerceAnalyticsController.php:23
        private const EXCLUDED_STATUSES = ['stub', 'cancelled', 'voided', 'refunded'];

        // SendWeeklyAnalyticsNotificationJob.php — inline, no named constant
        ->whereNotIn('status', ['stub', 'cancelled', 'voided', 'refunded'])
        ```

---

## P3 — Nice to have

- [ ] **#PH4-5** · P3 — `CommissionPayoutItem` V2 class comment contradicts Phase 4 schema
    - **Where:** `app/Models/Retail/CommissionPayoutItem.php:10–11`
    - **Affects:** Developer orientation. Any engineer (or AI agent) reading the V2 comment will believe `commission_ledger_entry_id` is still a valid link target and may write code against it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Rewrite the class comment to reflect the Phase-4-correct contract: `CommissionPayoutItem` links a payout batch exclusively to `commerce.orders` rows via `order_id`. Remove all reference to `commission_ledger_entry_id`.
        - Update the comment to note that `order_id` is NOT NULL post-Phase-4 migration `20260506500000`.
    - **Technical:** The comment `// Exactly one of commission_ledger_entry_id / order_id must be set` describes the Phase 3.5 transitional invariant (`cpi_link_target_check` CHECK constraint in migration `20260506300000`). Phase 4 drops the column entirely, making `order_id` the sole link target and the old CHECK constraint meaningless. The CHECK constraint cascades-drops with the column, but the class comment does not self-update.
    - **Plain English:** The room-plan on the door still says "this room has two exits" after one of them was bricked over during the renovation. Nobody will get hurt immediately, but the next contractor will spend time looking for a door that isn't there.
    - **Evidence:**
        ```php
        // CommissionPayoutItem.php:10–11
        // V2: Line item linking a payout batch to either a (legacy) ledger entry or a (Phase 3.5+)
        // commerce.orders row. Exactly one of commission_ledger_entry_id / order_id must be set.
        ```

- [ ] **#PH4-6** · P3 — `AI_CONTEXT.md` analytics schema entry doesn't name the eight tables dropped by Phase 4
    - **Where:** `AI_CONTEXT.md` — Database Schemas table, `analytics` row
    - **Affects:** Developer onboarding and AI agent orientation. The schema entry mentions "hourly/daily aggregate tables" exist for booking without naming them, which a reader could conflate with the eight commerce/site aggregate tables that Phase 4 drops. Post-Phase-4, the analytics schema is significantly smaller — only raw event tables plus booking aggregates survive.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Update the `analytics` schema row to name the eight tables being dropped (`brand_metrics_daily`, `brand_metrics_hourly`, `brand_affiliate_daily`, `brand_commission_daily`, `professional_metrics_daily`, `professional_metrics_hourly`, `site_metrics_daily`, `site_metrics_hourly`) and note they are removed post-Phase-4.
        - Clarify that the surviving booking aggregate tables are distinct from the dropped commerce/site aggregate tables.
        - Optionally, add a note to the "What Was Removed in V2" section that Phase 4 drops eight additional analytics aggregate tables beyond the V1 removal.
    - **Technical:** The current entry reads "Booking analytics still keep their hourly/daily aggregate tables; commerce/site analytics use live queries (Phase 3+)." This is accurate but ambiguous — it implies aggregate tables still exist for some domain without specifying which. After Phase 4, the only aggregate tables in the `analytics` schema are the booking-specific ones (served by `BookingAnalyticsAggregateService`), whose names are also not listed. A developer or AI agent reading the schema map has no way to enumerate the surviving tables. Since `AI_CONTEXT.md` is the canonical shared orientation document for all AI tools working on the codebase, an outdated schema map is actively misleading.
    - **Plain English:** The building directory in the lobby hasn't been updated since the renovation. It still says "floors 4–5 have conference rooms" without mentioning that half of them were converted to open-plan. Someone trying to book a meeting will wander around looking for rooms that no longer exist. Updating the directory takes ten minutes and saves every new visitor from the same confusion.
    - **Evidence:**
        ```
        // AI_CONTEXT.md — Database Schemas table
        | `analytics` | Raw event tables: `site_visits`, `link_clicks`, `cart_events`,
        `lead_submissions`, `booking_events`. Booking analytics still keep their
        hourly/daily aggregate tables; commerce/site analytics use live queries (Phase 3+) |
        ```
