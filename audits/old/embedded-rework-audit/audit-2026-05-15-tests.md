`★ Insight ─────────────────────────────────────`
**Three key patterns I found during verification:**

1. **`DB::shouldReceive` is systemic** — it appears in 50+ test files across Analytics, Staff, and Unit test directories. The EmbeddedSetupOverviewCacheTest is consistent with the codebase-wide pattern (rather than being a unique violation), but the consequence is still the same: the SQL aggregation logic has no real-data validation.

2. **TEST-2 was a false negative** — `ShopifyAppUninstalledWebhookControllerTest.php` already exists with 5 tests covering HMAC, token clearance, job dispatch, and unknown-shop. DeepSeek hallucinated the "no test" claim. The real gap is narrower: the `BrandProfile.brand_status → Disconnected` state transition is never asserted.

3. **Scope boundary matters** — The embedded wizard test gap (TEST-1, TEST-5/TEST-8) is the most consequential because `provisionShopifyIntegration` fires on every Shopify admin page load and controls 6-job dispatch decisions. A silent regression there breaks every new brand install.
`─────────────────────────────────────────────────`

# Test Coverage Audit — 2026-05-15

**Branch:** development
**Lens:** Test coverage: critical paths, idempotency, race-safety, policy abilities, mock-vs-integration discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/Internal/EmbeddedSetupController.php
- app/Http/Controllers/Api/Internal/EmbeddedOrderAnalyticsController.php
- app/Http/Controllers/Api/Internal/EmbeddedProductAnalyticsController.php
- app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php
- app/Http/Controllers/Api/Internal/EmbeddedConnectController.php
- app/Http/Controllers/Api/Webhooks/ShopifyAppUninstalledWebhookController.php
- app/Http/Middleware/Auth/VerifyShopifySessionToken.php
- tests/Feature/Embedded/EmbeddedSetupOverviewCacheTest.php
- tests/Feature/Shopify/EmbeddedConnectControllerTest.php
- tests/Feature/Validation/EmbeddedSetupRequestValidationTest.php
- tests/Feature/Security/VerifyShopifySessionTokenTest.php
- tests/Feature/Webhooks/Shopify/ShopifyAppUninstalledWebhookControllerTest.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 7 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

- [ ] **#TEST-1** · P1 — No feature test for `provisionShopifyIntegration` — multi-branch job dispatch and no-op detection fire on every embedded page load
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedSetupController.php (`provisionShopifyIntegration`)
    - **Affects:** Every brand going through the embedded Shopify wizard. The endpoint runs on every admin page load; the `isNoOpRefresh` guard, the `needsJobDispatch` calculation (checking four collection handles individually), the `disconnected_at` clearance, and the job dispatch loop for six jobs are all completely untested. A silent regression in any branch ships broken onboarding to all future brands.
    - **Effort:** L (~1–2d) — requires mocking `Http::fake()` for the Shopify Admin API validation call, seeding integration rows at varying states, and asserting job dispatch counts and metadata updates.
    - **What to do:**
        - Add `it('dispatches all six setup jobs on first provision, clears disconnected_at')` — seed no existing integration row, assert `Bus::assertDispatched` for each of the six jobs and that `disconnected_at` is absent from saved metadata.
        - Add `it('skips job dispatch on no-op token refresh when integration is complete')` — seed an integration with all four collection handles and `webhook_registration_state != queued`, submit the same `access_token`, assert `Bus::assertNotDispatched`.
        - Add `it('re-dispatches jobs when webhook_registration_state is queued')` — seed an integration with `webhook_registration_state: queued`, assert jobs are dispatched.
        - Add `it('re-dispatches when any collection handle is missing (partial setup)')` — seed an integration missing `high_commission_collection_handle`, assert jobs are dispatched.
        - Add `it('returns 422 shopify_token_rejected and does not overwrite existing token on Shopify 401')` — `Http::fake(['*/shop.json' => Http::response([], 401)])`, assert integration `access_token` is unchanged.
        - Add `it('returns 422 shopify_token_rejected on shop domain mismatch')` — fake a 200 response with a different `myshopify_domain`, assert rejection.
        - Add `it('skips cache invalidation and status sync on no-op refresh')` — assert `ProfessionalCacheService::invalidateProfessional` is not called.
    - **Technical:** `provisionShopifyIntegration` is the most branch-heavy endpoint in the codebase — it runs token validation via a live Shopify HTTP call, evaluates five independent boolean flags (`needsJobDispatch`, `isNoOpRefresh`, `collectionsIncomplete`, per-collection-handle presence, `existingWebhookState`), dispatches up to six jobs, and conditionally skips cache + status sync. The `EmbeddedSetupRequestValidationTest` only exercises Form Request rules; `EmbeddedConnectControllerTest` covers the connect step, not this provisioning step. Zero tests cover the controller method logic. The existing test helper `Http::fake()` (used in `ShopifyOAuthCallbackPathBTest`) establishes the pattern for mocking Shopify Admin API responses — reuse it.
    - **Plain English:** Every time a brand opens their Shopify admin panel, the Partna app calls this endpoint to make sure everything is still connected. It's also the engine that runs when a brand first installs the app — it kicks off background tasks to create product collections, register update notifications, and set up the storefront connection. With no automated tests, any code change to this endpoint ships unverified. If the logic that decides "should I skip re-running the setup jobs?" breaks, brands get redundant jobs queued on every page load. If the logic that clears the "disconnected" flag breaks, reinstalled brands stay stuck in a redirect loop. There's no safety net to catch these.
    - **Evidence:**
        ```php
        $isNoOpRefresh = ! $needsJobDispatch
            && $existing !== null
            && $existing->access_token === $data['access_token'];
        ```
        ```php
        $collectionsIncomplete = empty(Arr::get($existingMetadata, 'active_collection_handle'))
            || empty(Arr::get($existingMetadata, 'default_collection_handle'))
            || empty(Arr::get($existingMetadata, 'favourites_collection_handle'))
            || empty(Arr::get($existingMetadata, 'high_commission_collection_handle'));
        ```
        ```php
        unset($metadata['disconnected_at']);
        unset($metadata['disconnected_reason']);
        ```

---

## P2 — Should fix

- [ ] **#TEST-2** · P2 — `DB::shouldReceive('table')->never()` in EmbeddedSetupOverviewCacheTest mocks the DB facade to assert cache bypass — the SQL aggregation logic has no real-data test
    - **Where:** tests/Feature/Embedded/EmbeddedSetupOverviewCacheTest.php:31
    - **Affects:** Developer confidence in the overview endpoint's SQL — a broken WHERE clause, wrong status exclusion, or off-by-one in the `reversed_commission_cents` subtraction would all pass this test. This is the companion gap to TEST-7 (no cache-miss integration test).
    - **Effort:** S (~0.5–1h) — the `DB::shouldReceive('table')->never()` assertion can stay (it correctly proves cache bypass); add a second test that seeds real rows and hits the cold-miss path.
    - **What to do:**
        - Keep the existing cache-hit test as-is; it correctly proves the DB is not called when the cache is warm.
        - Remove `DB::shouldReceive('table')->never()` from the existing test and replace it with `DB::spy()` so accidental cold-miss calls are surfaced rather than silently blocked.
        - See TEST-7 — add a complementary cache-miss integration test that seeds `commerce.orders` and `brand_affiliate_rollup` rows with real data and validates the computed aggregates.
    - **Technical:** The test seeds the cache key manually and then asserts the controller returns the cached payload verbatim. `DB::shouldReceive('table')->never()` is a strict Mockery expectation that prevents any `DB::table(...)` call from reaching the actual driver — which means if the cache evicts between the seed and the request (e.g. a flush in a parallel test), the test would fail with a Mockery violation rather than a meaningful assertion error, making CI diagnosis harder. The mock also prevents any future test in the same describe block from making real DB calls, coupling test-ordering. Per `feedback_shopify_integration_lessons.md`, the DB layer must be real in integration tests.
    - **Plain English:** This test confirms that if the dashboard data is already saved in the cache, the server uses it without re-querying the database. That's a useful check. But the way it's written, it actually blocks any real database queries from happening at all — so if another test in the same group accidentally needs the database, it would get a confusing error instead of a real answer. A small cleanup makes the test more robust without losing what it proves.
    - **Evidence:**
        ```php
        DB::shouldReceive('table')->never();
        ```

- [ ] **#TEST-3** · P2 — No feature test for `EmbeddedOrderAnalyticsController` — `deriveLineStatus` logic and affiliate block data shape are fully untested
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedOrderAnalyticsController.php (`show`, `deriveLineStatus`)
    - **Affects:** The affiliate-order-block Shopify admin UI extension that shows which affiliate drove a sale and the per-line commission. A broken `deriveLineStatus` would show "pending" for already-paid or reversed orders, misleading brands in the admin.
    - **Effort:** M (~2–4h) — seed `commerce.orders` + `commerce.order_items`, assert the response shape and status derivation for each status path.
    - **What to do:**
        - Add `it('returns has_affiliate:false and empty line_items when order has no affiliate')`.
        - Add `it('returns correct affiliate, line_items, and commission totals when order has an affiliate')`.
        - Add `it('derives status as reversed for cancelled/voided/refunded orders')`.
        - Add `it('derives status as reversed when refund_cents >= net_cents')`.
        - Add `it('derives status as paid when payout_id is set')`.
        - Add `it('strips GID prefix from shopifyOrderId before querying')`.
    - **Technical:** The controller applies a multi-branch `deriveLineStatus` that maps order aggregate state to four display statuses. The priority order (cancelled/voided/refunded → refund threshold → payout_id → pending) means the wrong branch order would silently misclassify orders. The only existing embedded tests cover the connect and validation paths; no test exercises this read path against real order + order_item rows.
    - **Plain English:** When a brand opens an order in their Shopify admin, a panel shows which affiliate brought the customer and how much commission was earned. The code that decides whether to show "paid", "pending", or "reversed" has several possible outcomes. There are no tests to confirm those decisions are correct — a code change could flip "paid" to "pending" for settled orders and nobody would notice until a brand reports it as a bug.
    - **Evidence:**
        ```php
        private function deriveLineStatus(Order $order): string
        {
            if (in_array($order->status, ['cancelled', 'voided', 'refunded'], true)) {
                return 'reversed';
            }
            if ((int) $order->refund_cents >= (int) $order->net_cents && (int) $order->net_cents > 0) {
                return 'reversed';
            }
            if (! empty($order->payout_id)) {
                return 'paid';
            }

            return 'pending';
        }
        ```

- [ ] **#TEST-4** · P2 — No feature test for `EmbeddedProductAnalyticsController` — variant rollup aggregation and weighted average commission rate are untested
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedProductAnalyticsController.php (`build`, `resolveActive`)
    - **Affects:** Product-level affiliate sales view in the Shopify admin extension. Wrong totals or variant grouping would misrepresent which products are driving affiliate revenue — brands make catalog decisions on this data.
    - **Effort:** M (~2–4h) — seed `commerce.order_items` + `commerce.orders`, mock `BrandCatalogService::fetchProductActiveMetafield`, assert aggregates and variant list.
    - **What to do:**
        - Add `it('returns 30-day totals, variant breakdown sorted by revenue desc, and recent sales')`.
        - Add `it('excludes order_items linked to orders with excluded statuses')`.
        - Add `it('computes weighted average commission rate correctly across variants')`.
        - Add `it('resolves active via cached metafield fetch, returning null when integration is absent')`.
        - Add `it('returns zero totals when no order_items exist for the product in the window')`.
    - **Technical:** `build()` performs a join across `commerce.order_items → commerce.orders → core.professionals`, groups by variant key, and computes a weighted average commission rate (`$weightedRateSum / $rateWeight`). A division-by-zero guard exists (`$rateWeight > 0`) but is untested. The variant sort (`usort` by `revenue_cents` desc) and the synthetic `__no_variant__` key for products without a variant ID are similarly untested. `resolveActive` has a nested two-layer cache (5m outer, 10m inner) with a nullable return — the null path when the integration is absent has no test.
    - **Plain English:** The product analytics panel shows how much revenue and commission each product and variant is generating through affiliates. The calculations behind it — summing sales, grouping by variant, averaging commission percentages — have no tests. If someone accidentally changes the query to include cancelled orders, or the variant grouping breaks, the numbers would silently be wrong and brands would use bad data to decide which products to promote.
    - **Evidence:**
        ```php
        foreach ($rows as $row) {
            $qty = (int) $row->quantity;
            $revenueCents = (int) $row->line_total_cents;
            $commissionCents = (int) $row->commission_cents;

            $totalUnits += $qty;
            $totalRevenueCents += $revenueCents;
            $totalCommissionCents += $commissionCents;
            $weightedRateSum += ((float) $row->commission_rate) * $commissionCents;
            $rateWeight += $commissionCents;
            $currency = (string) ($row->currency_code ?? $currency);

            $variantKey = $variantId !== '' ? $variantId : '__no_variant__';
            // ...
            $variants[$variantKey]['revenue_cents'] += $revenueCents;
        }
        ```

- [ ] **#TEST-5** · P2 — No feature test for `EmbeddedProductSettingsController` — Shopify metafield mutations and collection toggles are untested
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php (`show`, `update`, `saveMetafield`, `toggleCollection`, `saveVariantEnabledStates`)
    - **Affects:** The product settings panel in the Shopify admin extension that lets brands toggle products active/inactive for affiliates, override commission rates, and manage collection membership. A broken mutation would silently fail to write the metafield — brands see "Saved" but affiliates see the old state.
    - **Effort:** M (~2–4h) — `Http::fake()` for Shopify Admin API GraphQL calls, mock `ProfessionalIntegration`, assert correct GraphQL variables are sent per field.
    - **What to do:**
        - Add `it('returns 422 when product_gid or field is missing from PATCH body')`.
        - Add `it('dispatches correct productUpdate mutation with correct namespace/key for each supported field')` — use `Http::fake()` to capture the request body and assert variables.
        - Add `it('returns 422 with Shopify userErrors message when metafield write fails')`.
        - Add `it('toggles collection membership by resolving collection ID then calling collectionAddProducts/collectionRemoveProducts')`.
        - Add `it('saves per-variant enabled state, only updating variants whose state changes')`.
        - Add `it('returns 404 when no Shopify integration exists')`.
    - **Technical:** `saveMetafield` performs two sequential Shopify Admin API calls — a metafield lookup then an update or create — and the two code paths use different mutation names (`metafieldUpdate` vs `productUpdate`). The GraphQL variable shape is different in each branch. `toggleCollection` resolves a collection ID before the add/remove mutation — a missing collection returns a runtime exception that surfaces as a 422. `saveVariantEnabledStates` calls `fetchVariants` (a separate API round-trip) then iterates to call `saveVariantMetafield` per changed variant. None of these multi-step flows have any test coverage. `Http::fake()` with recorded request inspection is the correct pattern (used in `BrandDesignImporterTest` for Admin API calls).
    - **Plain English:** When a brand flips a switch to disable a product for affiliates, or changes the commission override for a product, this controller sends the change to Shopify. The code does several steps: look up the existing setting, decide whether to create or update it, then make the change. If any of those steps break, the brand sees "Saved" but nothing actually changed in Shopify — affiliates would still see the old setting. There are no tests to catch this.
    - **Evidence:**
        ```php
        $mutation = <<<'GRAPHQL'
        mutation setProductMetafield($input: ProductInput!) {
          productUpdate(input: $input) {
            product { id }
            userErrors { field message }
          }
        }
        GRAPHQL;
        ```

- [ ] **#TEST-6** · P2 — Uninstall webhook test doesn't assert the `BrandProfile.brand_status → Disconnected` state transition — the controller's primary business-state write is invisible to the test suite
    - **Where:** tests/Feature/Webhooks/Shopify/ShopifyAppUninstalledWebhookControllerTest.php:53 / app/Http/Controllers/Api/Webhooks/ShopifyAppUninstalledWebhookController.php
    - **Affects:** Brand status machine integrity. If the `BrandProfile::where(...)->update(['brand_status' => BrandStatus::Disconnected->value])` call silently stops working (wrong column name, missing model, schema drift), brands would be left with tokens cleared but status still showing "Active" — `BrandStatusService::determine()` reads `disconnected_at` first so the wizard redirect loop fires, but any code path that reads `brand_status` directly would see stale Active state.
    - **Effort:** S (~0.5–1h) — seed a BrandProfile row in the existing `'valid HMAC clears access_token'` test and add an assertion on `brand_status` + `setup_complete`.
    - **What to do:**
        - In the `'valid HMAC clears access_token and marks disconnected_reason'` test, insert a `brand.brand_profiles` row for the test professional before sending the webhook.
        - After the assertion on `professional_integrations`, add: `$profile = DB::table('brand.brand_profiles')->where('professional_id', $proId)->first(); expect($profile->brand_status)->toBe('disconnected'); expect((bool)$profile->setup_complete)->toBeFalse();`
        - Add a separate `it('app/uninstalled — second delivery is idempotent (already-disconnected state unchanged)')` test that calls the webhook twice with the same valid HMAC and asserts the second call returns 200 with the integration row in identical final state.
    - **Technical:** The controller's `BrandProfile::where(...)->update(...)` at the bottom of `__invoke` is the authoritative state machine write that moves a brand to Disconnected. The existing test at line 53 checks the `professional_integrations` row (`access_token`, `refresh_token`, `disconnected_reason`, `webhooks_state`) but does not set up a `brand_profiles` row — so the `update(0 rows)` silent no-op is indistinguishable from a correct write. The `setupBrandProfilesTable()` helper in `beforeEach` creates the table but inserts no row, so the update affects zero rows and the assertion is missing entirely.
    - **Plain English:** When a brand uninstalls the Partna Shopify app, the system should immediately mark their account as "Disconnected" so no new affiliate activity is attributed to them. The existing test proves that their API key is cleared and an "uninstalled" flag is written — but it never checks whether the brand's status actually changes to Disconnected. If that part of the code broke, the system would clear the token but leave the brand showing as Active, which could confuse anything that reads that status field directly.
    - **Evidence:**
        ```php
        // ShopifyAppUninstalledWebhookController::__invoke — state transition not asserted in tests
        BrandProfile::where('professional_id', $integration->professional_id)
            ->update([
                'brand_status' => BrandStatus::Disconnected->value,
                'setup_complete' => false,
            ]);
        ```

- [ ] **#TEST-7** · P2 — No integration test for the `overview` SQL aggregation logic — the brand dashboard numbers are never validated against real data
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedSetupController.php (`overview`) / tests/Feature/Embedded/EmbeddedSetupOverviewCacheTest.php
    - **Affects:** Brand dashboard — total commission, 30-day commission, 30-day revenue, dominant currency, and recent-sales list. Wrong numbers mislead brands about affiliate program performance. The `max(0, ...)` floor for reversed commissions, the `EXCLUDED_FROM_AGGREGATES` status filter, and the dominant-currency tie-breaking all have zero real-data validation.
    - **Effort:** M (~2–4h) — seed `commerce.orders` + `commerce.brand_affiliate_rollup` rows with known values, call the endpoint on a cache-miss path, and assert computed fields match expected values.
    - **What to do:**
        - Add `it('sums all-time commission_cents excluding excluded order statuses')` — seed orders with mixed statuses, assert only eligible rows contribute to `total_commission_cents`.
        - Add `it('deducts reversed_commission_cents from rollup and floors at zero when reversed > earned')` — seed a rollup row where `reversed_commission_cents > commission_cents`, assert `total_commission_cents === 0`.
        - Add `it('picks the dominant currency by order count, defaulting to AUD when no orders exist')`.
        - Add `it('computes 30-day commission and revenue only for orders within the window')` — seed orders before and after `now()->subDays(30)`, assert only in-window orders contribute.
        - Add `it('returns the 5 most recent sales with affiliate display_name resolved via join')`.
    - **Technical:** `overview()` runs five separate `DB::table()` queries with `COALESCE(SUM(...))` aggregates, status exclusion via `whereNotIn`, a 30-day window filter, a dominant-currency `COUNT + ORDER BY cnt DESC`, and a joined `recent_sales` query. The only existing test (`EmbeddedSetupOverviewCacheTest`) seeds the cache manually and never exercises any of these queries. A WHERE clause bug, wrong column name, or status constant drift (e.g. `Order::EXCLUDED_FROM_AGGREGATES` adding a new status) is invisible until a brand notices wrong totals. Unlike financial service tests, this is a read-only dashboard path so there's no on-write side-effect to catch the regression — only a direct integration test can.
    - **Plain English:** The brand dashboard shows how much total commission has been earned, what happened in the last 30 days, and recent sales. All those numbers come from database calculations. The only existing test pretends the database doesn't exist and just checks that cached data is returned unchanged. If someone accidentally changes the query — say, forgetting to exclude cancelled orders — the totals would be wrong and brands would see inflated commission numbers. There's no test to catch that.
    - **Evidence:**
        ```php
        $allTimeRow = DB::table('commerce.orders')
            ->where('brand_professional_id', $professionalId)
            ->whereNotIn('status', Order::EXCLUDED_FROM_AGGREGATES)
            ->selectRaw('COALESCE(SUM(commission_cents), 0) AS commission_cents')
            ->first();
        $totalCommissionCents = max(0, $totalCommissionCents - (int) ($reversedAllTimeRow->reversed_cents ?? 0));
        ```

- [ ] **#TEST-8** · P2 — Wizard endpoint controller logic is entirely untested — `saveIdentity`, `saveBusinessDetails`, `updateSetting`, `setupDomain`, `brandProfile`, and `confirmHydrogenInstall` have no feature test covering persistence, cache invalidation, or status sync
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedSetupController.php (methods: `saveIdentity`, `saveBusinessDetails`, `updateSetting`, `saveDeploymentToken`, `confirmHydrogenInstall`, `setupDomain`, `provisionDomainTxt`, `brandProfile`, `embeddedProducts`)
    - **Affects:** The embedded Shopify setup wizard — every step a brand completes during onboarding. A regression that accepts input but doesn't persist it (e.g. a wrong column name, a failed `updateOrCreate` due to FK absence, or a cache invalidation that never fires) would leave brands stuck on an apparently-complete step that hasn't actually saved. `brandProfile`'s auto-heal logic (backfilling `hydrogen_install_confirmed` when storefront is live) and `setupDomain`'s Cache debounce are particularly branch-rich.
    - **Effort:** L (~1–2d) — each endpoint has distinct side-effects requiring separate integration tests; `brandProfile` and `embeddedProducts` require `Http::fake()` for the storefront probe and catalog fetch respectively.
    - **What to do:**
        - Add `it('saveIdentity persists display_name and primary_email to the Professional record and invalidates cache')`.
        - Add `it('saveBusinessDetails creates or updates BrandProfile with legal details and triggers status sync')`.
        - Add `it('updateSetting with key=default_commission_rate writes to BrandStoreSettings')`.
        - Add `it('updateSetting with key=setup_complete writes to BrandProfile, not BrandStoreSettings')`.
        - Add `it('confirmHydrogenInstall sets hydrogen_install_confirmed=true and triggers status sync')`.
        - Add `it('setupDomain persists oxygen_storefront_id and dispatches ProvisionBrandDnsJob (once per 30s debounce)')`.
        - Add `it('setupDomain does not dispatch a second DNS job within the 30s debounce window')`.
        - Add `it('brandProfile auto-heals hydrogen_install_confirmed=true when storefront status is live')` — `Http::fake()` returning 200 for the storefront probe.
        - Add `it('embeddedProducts returns only active=true products from the catalog')`.
        - Add `it('embeddedProducts returns empty array when BrandCatalogService throws')`.
    - **Technical:** `EmbeddedSetupRequestValidationTest` tests Form Request rules via `Validator::make` only — it never exercises HTTP routing, middleware, controller logic, or DB writes. The `saveIdentity` through `confirmHydrogenInstall` methods each call `invalidateProfessional` + `BrandStatusService::sync` — both of which must run to keep the brand status machine consistent. `setupDomain` has a 30-second `Cache::add` debounce that prevents duplicate DNS job dispatch; this race-guard has no test. `brandProfile` performs an auto-heal write to `BrandStoreSettings` when the storefront probe returns 'live' — the heal path changes wizard state without the brand explicitly submitting anything, and is completely untested.
    - **Plain English:** The setup wizard has about ten steps — saving the brand name, filling in business details, setting commission rates, confirming Hydrogen is installed, connecting a domain. Each step makes a call to the backend, which is supposed to save the data and update the brand's setup progress. The existing tests only check that the input validation rules are correct (like "email must be a valid email"). They never actually run the full step end-to-end — so a bug that accepts the input and returns "saved" but doesn't actually write to the database would go completely undetected. Brands would think setup is done when it isn't.
    - **Evidence:**
        ```php
        public function saveIdentity(SaveIdentityRequest $request): JsonResponse { ... }
        public function saveBusinessDetails(SaveBusinessDetailsRequest $request): JsonResponse { ... }
        public function setupDomain(SetupDomainRequest $request): JsonResponse { ... }
        public function brandProfile(Request $request): JsonResponse { ... }
        ```
        ```php
        // setupDomain debounce — no test asserts single-dispatch within window
        if (Cache::add("dns:provision:cname:{$professionalId}", true, 30)) {
            ProvisionBrandDnsJob::dispatch($professionalId);
        }
        ```

`★ Insight ─────────────────────────────────────`
**On the DB::shouldReceive prevalence:** This pattern exists in 50+ test files across Analytics, Staff, and Unit directories — it's an accepted codebase convention, not an anomaly in the overview cache test. The most impactful fix isn't removing the mock but adding the missing **cold-miss integration tests** (TEST-7, TEST-8) that give real-data confidence. The mock-discipline finding (TEST-2) is secondary to closing those SQL coverage gaps.
`─────────────────────────────────────────────────`
