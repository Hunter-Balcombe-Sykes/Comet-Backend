`★ Insight ─────────────────────────────────────`
`resolveFromDb` appears exactly once — its own definition. Zero callers anywhere in the codebase. This confirms FFLAG-1 (dead method) but it's firmly P3 — dead private methods don't ship bad behavior, they accumulate confusion. The Grep for square_sync/fresha_sync confirms those services still exist as files, but per project memory those features are dropped, making FFLAG-5 (adding "flag ON" tests for dropped sync paths) wasted effort — that finding is dropped.
`─────────────────────────────────────────────────`

# Test Coverage Gaps & Edge Case Quality Audit — 2026-05-18

**Branch:** development
**Lens:** test coverage gaps and edge case test quality
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/FeatureFlags/FeatureFlagService.php
- app/Services/FeatureFlags/OverrideScope.php
- tests/Feature/FeatureFlags/AllForTest.php
- tests/Feature/FeatureFlags/CacheInvalidationTest.php
- tests/Feature/FeatureFlags/FeatureFlagTestCase.php
- tests/Feature/FeatureFlags/FeatureGateMiddlewareTest.php
- tests/Feature/FeatureFlags/ResolverPrecedenceTest.php
- tests/Feature/FeatureFlags/RolloutDeterminismTest.php
- tests/Feature/FeatureFlags/SectionVisibilityLinkOnlyTest.php
- tests/Feature/FeatureFlags/SectionVisibilityTestCase.php
- tests/Feature/FeatureFlags/ServiceObserverSyncGatedTest.php
- tests/Feature/FeatureFlags/StaffFeatureFlagsControllerTest.php
- tests/Feature/FeatureFlags/SyncJobsGatedTest.php
- tests/Feature/FeatureFlags/WebhooksGatedTest.php
- tests/Feature/FeatureFlags/VideoUploadsFlagTest.php
- tests/Feature/FeatureFlags/BookingModeSmartRejectedTest.php
- tests/Feature/FeatureFlags/BookingRoutesGatedTest.php
- tests/Feature/FeatureFlags/PosSyncRoutesGatedTest.php
- tests/Feature/FeatureFlags/PruneExpiredCommandTest.php
- tests/Unit/FeatureFlags/FeatureHelperTest.php
- tests/Unit/FeatureFlags/OverrideScopeTest.php
- tests/Unit/FeatureFlags/RedisDownFallbackTest.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 5 complete

---

## P2 — Should fix

- [ ] **#FFLAG-1** · P2 — `allFor()` cache-failure degraded path has zero test coverage
    - **Where:** app/Services/FeatureFlags/FeatureFlagService.php (`allFor()` catch block); tests/Feature/FeatureFlags/AllForTest.php
    - **Affects:** Production resilience — if Redis goes down, the `allFor()` degraded path runs 3 raw DB queries. A schema change, query regression, or logic error in `allForFromDb()` would silently break every staff feature-flag listing endpoint and any caller that relies on the batch map.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a test that seeds at least one `FeatureFlag` row and one `FeatureFlagOverride` row, then uses `Cache::shouldReceive('get')->andThrow(new RuntimeException('redis down'))` to force the catch block.
        - Call `$service->allFor($pro, $brand)` and assert the returned map reflects the seeded DB state (override wins over default).
        - Add a second variant with no pro/brand to cover the global-only degraded path.
    - **Technical:** `AllForTest.php` has two tests (`returns global defaults map` and `applies pro and brand overrides correctly`) — both exercise the happy path where `loadAll()` succeeds. `RedisDownFallbackTest` covers only `enabled()`'s catch block. No test ever forces `allFor()`'s catch block, meaning `allForFromDb()`'s three Eloquent queries and its internal `resolveFromArrays()` call are never exercised as a degraded-path unit. A regression here would only surface during a Redis outage in production.
    - **Plain English:** We built a backup staircase for when the elevator (Redis) breaks down, but we've never walked down those stairs to check the railing is actually bolted in. If the stairs collapse during a real outage, the feature-flag dashboard stops working until Redis comes back and we'd have no warning.
    - **Evidence:**
        ```php
        // allFor() — the catch branch is never exercised in any test:
        public function allFor(?Professional $pro = null, ?BrandProfile $brand = null): array
        {
            try {
                [$registry, $proOverrides, $brandOverrides] = $this->loadAll($pro, $brand);
            } catch (Throwable $e) {
                Log::warning('feature_flags.cache_unavailable', [...]);
                return $this->allForFromDb($pro, $brand);  // ← untested
            }
            // ...
        }
        ```

- [ ] **#FFLAG-2** · P2 — `enabled()` cache-failure path for a flag WITH a real DB row is untested
    - **Where:** tests/Unit/FeatureFlags/RedisDownFallbackTest.php; app/Services/FeatureFlags/FeatureFlagService.php (`enabled()` catch block)
    - **Affects:** Per-professional feature checks during Redis outages — the most operationally likely degraded scenario (flag exists in DB, pro override exists) has no test. A regression in `allForFromDb()`'s query builders would pass the test suite and only surface in production during a cache outage.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extend `RedisDownFallbackTest` (or add a companion test in `AllForTest`) that: seeds a `FeatureFlag` row, seeds a `FeatureFlagOverride` for a pro, forces `Cache::shouldReceive('get')->andThrow(...)`, then asserts `enabled('test_db_flag', $pro)` returns the override value.
        - Add a variant that seeds a brand override and passes a brand to `enabled()`, verifying brand-scope resolution also works in the degraded path.
    - **Technical:** The existing test configures `partna.features.fallback_flag = true` but creates no `FeatureFlag` DB row for that key. `allForFromDb()` therefore returns an empty registry, and resolution falls through to step 5 (`config('partna.features.fallback_flag')`). This tests only the config-fallback tail of the resolution chain — steps 1–4 (brand override, pro override, rollout %, registry default) are never exercised via the degraded path. The far more common real scenario — a flag that exists in DB with an override — is untested.
    - **Plain English:** We tested the backup generator by plugging in a lamp that isn't connected to the main circuit. The lamp lit up, so we declared success — but we never tried powering the real appliances (DB-backed flags with overrides) that actually matter during an outage.
    - **Evidence:**
        ```php
        // RedisDownFallbackTest — only exercises the config-fallback tail:
        it('falls back to DB when cache throws and logs a warning', function () {
            Cache::shouldReceive('get')->andThrow(new RuntimeException('redis down'));
            config(['partna.features.fallback_flag' => true]);
            expect($service->enabled('fallback_flag'))->toBeTrue();
            // 'fallback_flag' has NO FeatureFlag row — resolution skips steps 1-4
        });
        ```

- [ ] **#FFLAG-3** · P2 — `setOverride()` and `clearOverride()` brand-scope paths have no test coverage
    - **Where:** tests/Feature/FeatureFlags/CacheInvalidationTest.php; app/Services/FeatureFlags/FeatureFlagService.php (`setOverride` / `clearOverride` brand branch)
    - **Affects:** Brand-scoped feature flag overrides — the DB upsert path (`brand_id` set, `professional_id` null), the `forgetBrand()` cache invalidation, and downstream `enabled()` / `allFor()` resolution for brand scope are all untested. A typo in the `forgetBrand` cache key or a column mismatch in the brand upsert would ship silently.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `setOverride` test that seeds a brand in `brand.brand_profiles`, calls `setOverride('some_flag', OverrideScope::forBrand($brandId), true, ...)`, and asserts a `FeatureFlagOverride` row exists with `brand_id` set and `professional_id` null.
        - Assert the brand cache key (`ff:brand:{id}`) is invalidated so a subsequent `enabled()` with that brand returns the new value.
        - Add a matching `clearOverride` test for brand scope.
        - Add a test that verifies `enabled($flag, $pro, $brand)` returns the brand override after `setOverride` with brand scope.
    - **Technical:** `CacheInvalidationTest` contains three tests that all call `OverrideScope::forProfessional($this->pro->id)`. The brand-scoped branches — which call `FeatureFlagOverride::updateOrCreate` with `brand_id` + `null professional_id`, and `forgetBrand()` instead of `forgetPro()` — are entirely untested. The `OverrideScopeTest` confirms the data class builds correctly, but the service path that consumes a brand scope is never executed in the test suite.
    - **Plain English:** We installed two separate locks on the feature-flag door — one for individual users (professionals) and one for whole stores (brands). We tested the user lock but never the store lock. If the store lock is wired to the wrong key slot, every brand-level override would silently have no effect.
    - **Evidence:**
        ```php
        // CacheInvalidationTest — every test uses pro scope only:
        it('setOverride invalidates the pro cache key', function () {
            $this->service->setOverride('cache_flag',
                OverrideScope::forProfessional($this->pro->id), // ← pro only; brand path never called
                true, null, null);
        });
        // No test calls OverrideScope::forBrand(...) anywhere in the file
        ```

---

## P3 — Nice to have

- [ ] **#FFLAG-4** · P3 — Dead private method `resolveFromDb()` never called after degraded-path refactor
    - **Where:** app/Services/FeatureFlags/FeatureFlagService.php:479
    - **Affects:** Maintainability — future developers reading the `Internal helpers` section may assume `resolveFromDb()` is the active cache-failure fallback, when `allForFromDb()` is the real one. Verified: `grep` across all of `app/` returns exactly one occurrence — the method definition itself. No callers exist.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Delete the `resolveFromDb()` method entirely.
        - Verify no callers appear via `grep -r "resolveFromDb" app/`.
    - **Technical:** The `enabled()` and `allFor()` catch blocks were refactored to call `$this->allForFromDb($pro, $brand)` (batch 3-query load) to avoid N×3 per-flag queries during a Redis outage. `resolveFromDb()` was the original per-key single-flag fallback and was never updated or removed after the refactor. It is a private method with zero call sites — dead code confirmed via Grep.
    - **Plain English:** There's an old backup generator in the basement that was replaced by a newer model. The old one is still plugged in with a label that says "emergency backup," but the wiring was silently rerouted to the new generator. Any maintenance worker who follows the label during an outage will waste time on the wrong machine.
    - **Evidence:**
        ```php
        // Zero callers — only the definition exists at line 479:
        private function resolveFromDb(string $key, ?Professional $pro, ?BrandProfile $brand): bool
        {
            $registry = FeatureFlag::query()
                ->whereNull('deleted_at')
                ->where('key', $key)
                // ... full implementation present but unreachable
        }
        ```

- [ ] **#FFLAG-5** · P3 — `CacheInvalidationTest` "cache hit" test validates request-scope memoization, not Redis persistence
    - **Where:** tests/Feature/FeatureFlags/CacheInvalidationTest.php:32–38
    - **Affects:** Test clarity — the test name implies Redis cache verification but the second `enabled()` call hits `$this->requestCache` (the in-memory array on the service instance), bypassing Redis entirely. A regression in Redis serialization or key naming would not be caught.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Rename the test to `request memoization returns same value within a single request lifecycle`.
        - Optionally add a cross-request cache test: instantiate a fresh `FeatureFlagService` (simulating a new request), assert the override value returns from Redis without re-querying the DB by spying on DB query count.
    - **Technical:** `FeatureFlagService::loadAll()` stores loaded tuples in `$this->requestCache` keyed by `"{proId}:{brandId}"`. After the first `enabled()` call, subsequent calls on the same service instance return from this array, never reaching Redis. The DB mutation between the two `enabled()` calls in the test is intentional as a smoke-test that memoization holds, but it cannot distinguish "Redis hit" from "requestCache hit." The test passes correctly for what it actually tests; the issue is misleading naming.
    - **Plain English:** The test label says "verify the vault door holds." The test actually only checks the guard's desk memo — it never walks back to the vault to confirm anything is stored there. The guard's memo is correct, but the vault might be empty and we'd never know.
    - **Evidence:**
        ```php
        it('cache hit returns same value as DB', function () {
            FeatureFlagOverride::create([...]);
            expect($this->service->enabled('cache_flag', $this->pro))->toBeTrue();
            // Mutate DB without going through service — second call hits $this->requestCache, not Redis:
            FeatureFlagOverride::where('flag_key', 'cache_flag')->update(['enabled' => false]);
            expect($this->service->enabled('cache_flag', $this->pro))->toBeTrue();
        });
        ```

- [ ] **#FFLAG-6** · P3 — `seedProAndSite()` returns an orphaned `$siteId` with no corresponding `site.sites` row
    - **Where:** tests/Feature/FeatureFlags/SectionVisibilityLinkOnlyTest.php:14–24
    - **Affects:** Test accuracy — `checkVisibilityRequirements($proId, $siteId, 'booking')` is called with a `$siteId` UUID that has no backing row. Tests pass today because the service only queries `site.blocks` filtered by `site_id`, not `site.sites`. Any future code that validates the site row exists (e.g., checking `is_published`) would behave differently in tests vs. production, and the failure mode would be opaque.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either add an `INSERT INTO site.sites` inside `seedProAndSite()` with the generated `$siteId`, or remove `$siteId` from the return value and replace it with `null` where `checkVisibilityRequirements` is called (if the service accepts null).
        - Add a `CREATE TABLE IF NOT EXISTS site.sites` to `SectionVisibilityTestCase::boot()` if inserting a site row.
    - **Technical:** `SectionVisibilityTestCase::boot()` creates `core.professionals`, `core.professional_integrations`, `site.services`, and `site.blocks` — but not `site.sites`. `seedProAndSite()` creates a UUID for `$siteId` and returns it, but never inserts it. The mismatch is harmless today because `SectionVisibilityService` only joins against `site.blocks` using `site_id` as a filter column, not a foreign key. As soon as any site-row-dependent logic is added, every test in this file would fail with a confusing "site not found" error rather than a test-setup error.
    - **Plain English:** We hand the doorman an ID badge number but never actually print the badge. The doorman currently only checks a handwritten list (blocks table), so it works. But the day we install a real badge scanner that reads the physical card (sites table), every test in this file starts failing and it's not obvious why.
    - **Evidence:**
        ```php
        function seedProAndSite(): array
        {
            $proId = (string) Str::uuid();
            $siteId = (string) Str::uuid();
            DB::connection('pgsql')->table('core.professionals')->insert([...]);
            // No INSERT INTO site.sites follows — $siteId is a floating UUID
            return [$proId, $siteId];
        }
        ```

- [ ] **#FFLAG-7** · P3 — `StaffFeatureFlagController::index` test passes by catching a `QueryException` instead of asserting controller behavior
    - **Where:** tests/Feature/FeatureFlags/StaffFeatureFlagsControllerTest.php (`index returns flags list` test)
    - **Affects:** Test reliability — the test only proves the controller issued a DB query; it does not assert the response status or JSON shape. If a permission check or early-return is added before the query (e.g., a new `abort(403)` gate), the test fails not because behavior is wrong, but because the exception type changed.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Mock the Eloquent static query chain so `FeatureFlag::query()->withCount(...)` returns a pre-built collection without hitting SQLite.
        - Assert `$response->status() === 200` and that `$payload['data']` is an array — behavioral assertions, not exception assertions.
        - The pattern used in other controller tests in the same file (mocking `FeatureFlagService` and using `mockFormRequest`) is the right model to follow for `index` too.
    - **Technical:** The test comments explicitly acknowledge the SQLite limitation and choose to assert that a `QueryException` mentioning `'feature_flags'` is thrown as a proxy for "controller reached the DB." This is a false signal — it conflates "query was constructed" with "controller is correct." The correct fix is to mock the Eloquent query at the static call site so the controller's full response pipeline (resource transformation, JSON structure, status code) is exercised.
    - **Plain English:** The test for the feature-flag list works by turning the key and listening for an explosion — if we hear a bang, the engine must be connected. But if someone adds a kill switch before the ignition, the test fails because there's no explosion, not because the car is broken. A real test drives the car down the road and checks the speedometer.
    - **Evidence:**
        ```php
        try {
            $this->flagController->index($request);
            $this->fail('Expected QueryException from SQLite withCount limitation');
        } catch (\Illuminate\Database\QueryException $e) {
            expect($e->getMessage())->toContain('feature_flags');
        }
        ```

- [ ] **#FFLAG-8** · P3 — `StaffFeatureFlagController::update` only tests `rollout_percent`; `description` and `default_enabled` updates have no coverage
    - **Where:** tests/Feature/FeatureFlags/StaffFeatureFlagsControllerTest.php (`update changes rollout_percent on an existing flag` test)
    - **Affects:** Staff admin feature flag management — a regression in `description` or `default_enabled` update handling (e.g., a typo in the controller's field assignment) would only surface when a staff member manually edits a description in production.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a test that calls `update` with `['description' => 'Updated description']` and asserts the persisted row has the new description.
        - Add a test that calls `update` with `['default_enabled' => false]` on a flag created with `default_enabled: true` and asserts the DB value changed.
        - The existing `mockFormRequest` helper supports both cases with no additional setup.
    - **Technical:** `UpdateFeatureFlagRequest::rules()` almost certainly includes `description` and `default_enabled` (consistent with the `CreateFeatureFlagRequest` pattern and the `store` test fixture). The `update` controller method likely assigns all three fields, but only `rollout_percent` is validated in the test. A silent assignment bug on the other two fields (e.g., `$flag->descripton = ...`) would pass all tests and only surface in production.
    - **Plain English:** We tested the volume knob on the stereo. The bass and treble knobs might be wired backward or disconnected entirely — we'd only find out when a staff member complains that editing a flag description in the admin panel has no effect.
    - **Evidence:**
        ```php
        it('update changes rollout_percent on an existing flag', function () {
            FeatureFlag::create(['key' => 'update_flag', 'default_enabled' => true, 'rollout_percent' => 10]);
            $formRequest = mockFormRequest(UpdateFeatureFlagRequest::class,
                ['rollout_percent' => 75], $this->staff); // ← only rollout_percent tested
            $response = $this->flagController->update($formRequest, 'update_flag');
            expect($payload['data']['rollout_percent'])->toBe(75);
            // 'description' and 'default_enabled' are never updated in any test
        });
        ```
