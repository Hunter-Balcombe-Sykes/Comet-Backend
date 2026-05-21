# Test Coverage & Edge Case Quality Audit — 2026-05-20

**Branch:** development
**Lens:** test coverage gaps and edge case test quality
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Accounts/AccountCapabilities.php
- app/Services/Accounts/AccountTypeTransitionService.php
- app/Http/Controllers/Api/PublicSite/IndividualProfileController.php
- app/Http/Controllers/Api/PublicSite/BootstrapController.php
- app/Http/Controllers/Api/PublicSite/PublicShopifyStorefrontController.php
- tests/Feature/Security/IntegrationPolicy/FreshaPolicyEnforcementTest.php
- tests/Feature/Security/IntegrationPolicy/SquarePolicyEnforcementTest.php
- tests/Feature/Security/PolicyEnforcement/DocumentPolicyEnforcementTest.php
- tests/Feature/Security/AuditTableHardeningTest.php

**Dropped findings:**
- TEST-4 (PublicBookingController): Booking integration dropped 2026-05-11 per `project_booking_dropped.md` — Square/booking paths are not being built out. No finding warranted.
- TEST-7 (Fresha/Square team-member capability pattern): Fresha and Square integrations dropped 2026-05-11 per `project_booking_dropped.md`. Adding capability tests for discontinued integrations is out of scope.

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 3 complete
- P3 Low: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **#TEST-1** · P1 — AccountCapabilities runtime registry has zero test coverage
    - **Where:** app/Services/Accounts/AccountCapabilities.php (entire file)
    - **Affects:** Every API request, job, and route guard that calls `AccountCapabilities::for($pro)`. A single wrong boolean in the 16-flag registry silently grants or denies features to the wrong account types across the entire application surface — affiliates dashboard exposed to partners, design editor blocked for brands, notification gates misconfigured.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `tests/Unit/Services/Accounts/AccountCapabilitiesTest.php`.
        - For each `AccountType` case (Brand, Partner, Individual), assert every boolean flag and both string fields in the returned `AccountCapabilitySet` against the expected matrix from docs §9.
        - Add a test for `null` account_type input — verify it resolves to individual capabilities (the `AccountType::Individual, null` arm).
        - Add a test for `flushCache()`: call `for($pro)`, mutate `$pro->account_type`, call `flushCache()`, call `for($pro)` again, assert the new return reflects the mutation — not the stale WeakMap entry.
        - Add a negative test verifying a Partner does NOT have `shows_affiliates_dashboard` and does NOT have `can_edit_design`.
    - **Technical:** `AccountCapabilities::for()` is a pure `match` dispatch to three private factory methods returning `AccountCapabilitySet`. There are 16 boolean flags + 2 string configuration values. The WeakMap memoization cache (`self::$cache`) is exercised on every authenticated request — if a type-transition happens and `flushCache()` is not called, the WeakMap silently serves stale capabilities. None of this is covered. The `default =>` fallback arm and the `null` arm exist specifically to survive the §28.1 transition window — also untested. Since `AccountCapabilities` is the authoritative gate used by notification dispatchers (§28.11 commit `5bceb128`) and route guards, a flag regression can be entirely invisible in CI.
    - **Plain English:** This file is the master access list for your entire application — it decides what each type of account (brand, partner, individual) is allowed to do. Right now there is no automated check that the list is correct. If someone accidentally swapped two lines — say, giving partners access to the brand analytics dashboard — no alarm would go off. You would only discover it when a real user reported seeing something they shouldn't.
    - **Evidence:**
        ```php
        $set = match ($pro->account_type) {
            AccountType::Brand => self::brandCapabilities(),
            AccountType::Partner => self::partnerCapabilities(),
            AccountType::Individual, null => self::individualCapabilities($pro),
            default => self::individualCapabilities($pro),
        };

        self::$cache[$pro] = $set;
        ```

- [ ] **#TEST-2** · P1 — AccountTypeTransitionService canonical mutation gateway has zero test coverage
    - **Where:** app/Services/Accounts/AccountTypeTransitionService.php (entire file)
    - **Affects:** All post-signup `account_type` mutations. This is the only code path that flips `individual → partner` and `partner → individual`. A regression can corrupt account types, skip Cloudflare KV sync, or leave a professional with the wrong routing tag in the worker.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `tests/Unit/Services/Accounts/AccountTypeTransitionServiceTest.php`.
        - Test: Brand→anything throws `InvalidAccountTypeTransition`. Anything→Brand throws.
        - Test: Same-state no-op returns without a DB write or job dispatch (assert `SyncSubdomainToKvJob` and `CloudflareCachePurgeJob` were not queued).
        - Test: Individual→Partner flips `account_type`, dispatches `SyncSubdomainToKvJob`, dispatches `CloudflareCachePurgeJob` (when handle is non-empty), fires `AccountTypeTransitionEvent`.
        - Test: Partner→Individual, same assertions.
        - Test: Concurrent race — simulate the `lockForUpdate` re-read seeing the target state already set; assert `return` is hit without a second save.
        - Test: Missing `account_type` on the professional throws `InvalidAccountTypeTransition` with a clear message.
    - **Technical:** The service wraps the mutation in `DB::transaction()` with `lockForUpdate()`. Post-commit dispatches (`SyncSubdomainToKvJob`, `CloudflareCachePurgeJob`, `AccountTypeTransitionEvent`) run outside the transaction. The concurrent-race re-check (`if ($currentType === $to) { return; }`) inside the lock is the most nuanced branch — it prevents double-transition when two requests race — and it is completely untested. `AccountTypeTransitionService` is the canonical writer cited in the architecture rules; correctness here is load-bearing for worker routing and cache coherence across the §28.4–28.7 feature set delivered in commits `64a0223c` and `37f29149`.
    - **Plain English:** One specific piece of code handles all account type changes — turning an "individual" into a "partner" when they join a brand, and back again. If this code has a bug, people get the wrong account type, their website stops routing correctly through Cloudflare, and the cache serving their public profile goes stale. Nobody has verified any of the detailed steps work correctly — it is the most critical mutation pathway in the system with no test watchdog.
    - **Evidence:**
        ```php
        DB::transaction(function () use ($pro, $to): void {
            $locked = Professional::query()
                ->whereKey($pro->id)
                ->lockForUpdate()
                ->firstOrFail();

            $currentType = $locked->account_type;
            if ($currentType === $to) {
                return;  // concurrent-race bail-out: completely untested
            }

            $locked->account_type = $to;
            $locked->save();
            // ...
        });

        SyncSubdomainToKvJob::dispatch((string) $pro->id);
        CloudflareCachePurgeJob::dispatch($handle);
        AccountTypeTransitionEvent::dispatch($pro, $from, $to);
        ```

---

## P2 — Should fix

- [ ] **#TEST-3** · P2 — IndividualProfileController block-settings allow-list filtering has zero test coverage
    - **Where:** app/Http/Controllers/Api/PublicSite/IndividualProfileController.php:153–167 (`PUBLIC_BLOCK_SETTINGS` constant and `filterBlockSettings()` method)
    - **Affects:** Every public individual profile request (`GET /api/public/profiles/{handle}`). The `filterBlockSettings` method guards against raw JSONB settings leaking to unauthenticated visitors. Its `?? []` fallback for unknown block types is the only protection for future block types added without an explicit allow-list entry — it is untested.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add unit tests for `filterBlockSettings` (accessible via reflection or by extracting to a testable public static helper).
        - Test: a known block type (`link`) returns only the keys listed in `PUBLIC_BLOCK_SETTINGS['link']`, stripping any injected extra keys.
        - Test: an unknown block type (`future_admin_panel`) returns an empty array — the strict default.
        - Test: `bio` block type returns only `['title', 'body']` even when the input contains additional keys.
    - **Technical:** Commit `72050326` introduced `PROF-1` (the allow-list) and `PROF-2` (design key filtering) as explicit audit fixes. The allow-list itself is correct, but the guard — `array_intersect_key($settings, array_flip($allowed))` with `?? []` fallback — has no test to catch a future regression (e.g., someone accidentally changes `return []` to `return $settings` for the unknown-type path). The method is private and sits inside a `rememberLocked` closure, making it a reasonable candidate for extraction to a `public static` or standalone class for testability.
    - **Plain English:** Every block type on a public profile (links, bios, galleries, etc.) has a whitelist of which settings are safe to show the public. A clerk filters each block before handing it to visitors. The test suite verifies the blocks appear, but nobody has checked that the filtering step actually removes the right data. If a future change accidentally breaks the filter, internal settings stored in those blocks — staging flags, admin keys, customer-form output — would go straight to anonymous visitors.
    - **Evidence:**
        ```php
        private const PUBLIC_BLOCK_SETTINGS = [
            'link' => ['title', 'url', 'icon_key', 'icon_url', 'description'],
            'social' => ['title', 'url', 'platform', 'handle', 'icon_key'],
            // ... 13 more block types
        ];

        private function filterBlockSettings(string $blockType, array $settings): array
        {
            $allowed = self::PUBLIC_BLOCK_SETTINGS[$blockType] ?? [];
            if ($allowed === []) {
                return [];  // strict default for unknown types — untested
            }

            return array_intersect_key($settings, array_flip($allowed));
        }
        ```

- [ ] **#TEST-4** · P2 — BootstrapController brand-attach branches have zero test coverage
    - **Where:** app/Http/Controllers/Api/PublicSite/BootstrapController.php (~lines 150–310, the four brand-attach branches inside `DB::transaction`)
    - **Affects:** New user signup — the most critical onboarding funnel. Existing tests (`BootstrapIndividualWaitlistTest`, `BootstrapWaitlistGateTest`) cover the waitlist divert and waitlist gate only; the actual brand-attach logic — invite token, partner professional ID, join handle, signup code — is entirely uncovered.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Create `tests/Feature/PublicSite/BootstrapBrandAttachTest.php` covering the four brand-attach branches with mocks for `BrandAffiliateInviteService`, `BrandPartnerLinkService`, `BrandSignupCodeService`.
        - Test: `invite_token` successfully attaches affiliate, flips `account_type` to `Partner`, `SyncSubdomainToKvJob` dispatched.
        - Test: `invite_token` not found → `RuntimeException('Invite not found.')` propagates and rolls back the transaction.
        - Test: each branch's inner `try/catch` — when `connectBrandToAffiliate` throws `RuntimeException`, the professional row is preserved (transaction committed), the error is logged, and `$attachedAsPartner` remains false.
        - Test: `brand_signup_code` cap exceeded → `$brandSignupCodeError` is set, transaction commits, response returns 422 with `BRAND_SIGNUP_CODE_CAP_EXCEEDED` and `partial_success` payload.
        - Test: `EMAIL_ALREADY_REGISTERED` collision path returns 409.
    - **Technical:** The four brand-attach branches use a `try/catch (RuntimeException)` pattern that deliberately allows the outer `DB::transaction` to commit even when the brand-attach fails — the `$brandSignupCodeError` by-reference variable is the mechanism for surfacing the error post-commit. This is a nuanced pattern: rethrowing would roll back the fresh Professional row, losing the user's account. The `by-reference` closure variable (`&$brandSignupCodeError`) is a subtle PHP pattern that is easy to break silently. None of this is exercised. The happy paths (successful attach + `account_type` flip to Partner) are also untested.
    - **Plain English:** When someone signs up using a brand's invite link or signup code, the system creates their account AND tries to connect them to the brand in a single operation. If the brand-connection step fails (e.g., the brand is at capacity), the system is designed to still create the person's account and just show a connection error. Nobody has tested whether the account actually survives the failed connection — or whether the successful connection path correctly upgrades the account type to "partner." The entire onboarding funnel for partner signups is flying blind.
    - **Evidence:**
        ```php
        try {
            $brandAffiliateInviteService->claimInvite($invite, $professional);
            $this->syncSiteBrandPartnerSettings($site, $brandPartnerLinks, (string) $professional->id);
            $accountTypeDefaultsService->applyAffiliateDefaults($professional, $site);
            $attachedAsPartner = true;
        } catch (RuntimeException $e) {
            Log::warning('Bootstrap brand-attach via invite_token skipped', [
                'professional_id' => (string) $professional->id,
                'reason' => $e->getMessage(),
            ]);
        }
        ```

- [ ] **#TEST-5** · P2 — PublicShopifyStorefrontController has zero test coverage for resolution paths and pending-token 202 response
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicShopifyStorefrontController.php (entire file)
    - **Affects:** Hydrogen storefronts — every brand's Shopify-powered storefront calls this endpoint at boot to get its Storefront API token and shop domain. The 202 pending path and both resolution strategies (`shop_domain` vs `brand_slug`) are untested.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Create `tests/Feature/PublicSite/PublicShopifyStorefrontControllerTest.php`.
        - Test: `shop_domain` param resolves integration by `shopify_shop_domain` column; returns token when present.
        - Test: `brand_slug` param resolves integration via site subdomain → professional_id.
        - Test: missing `storefront_token` returns 202 with `status: pending` and dispatches `CreateStorefrontAccessTokenJob` (assert queued once); second call within 600s does NOT re-dispatch (dedup key in Cache).
        - Test: no integration found returns 404.
        - Test: neither param provided returns 422 with a validation error message.
    - **Technical:** The controller has two resolution paths and a third response path for missing tokens that dispatches a job with a 10-minute Cache dedup key. The dedup uses `Cache::has()` then `Cache::put()` — a TOCTOU race where two concurrent requests can both pass the `has()` check before either writes — but the larger issue is that none of the behavior is tested at all. The `resolveByBrandSlug` path in particular does a `whereRaw('lower(subdomain) = ?')` lookup that could silently return null for a misspelled column name or a schema change — untested.
    - **Plain English:** Every brand's Shopify storefront asks this endpoint for its credentials when it starts up. If the credentials are still being created, it's supposed to say "come back in a few seconds" and kick off a background job to create them. The "come back later" response, the "find the brand by store domain" lookup, and the "find the brand by slug" lookup have never been tested. If any of these break, brand storefronts fail to load for all visitors — silently, with no test alarm.
    - **Evidence:**
        ```php
        if ($storefrontToken === '') {
            $jobKey = 'storefront-token-job:'.$integration->id;
            if (! Cache::has($jobKey)) {
                Log::info('Storefront token missing, dispatching creation job.', [...]);
                CreateStorefrontAccessTokenJob::dispatch((string) $integration->id);
                Cache::put($jobKey, true, 600);
            }

            return response()->json([
                'status' => 'pending',
                'message' => 'Storefront token is being created. Try again in a few seconds.',
            ], 202);
        }
        ```

---

## P3 — Nice to have

- [ ] **#TEST-6** · P3 — DocumentPolicyEnforcementTest missing owner-delete happy-path test
    - **Where:** tests/Feature/Security/PolicyEnforcement/DocumentPolicyEnforcementTest.php
    - **Affects:** Document deletion by the owner — the most common delete operation. Absence is a consistency gap, not a security gap (the non-owner deny path is tested), but it breaks the suite-wide pattern.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('allows the owner to delete their own document')` following the same pattern as the existing `it('allows the owner to update their own document')` test. Create a document for the owner, call `ProfessionalDocumentController::destroy()`, assert 200.
    - **Technical:** Every other policy enforcement test file in the suite (`SitePolicyEnforcementTest`, `LinkBlockPolicyEnforcementTest`, `ServicePolicyEnforcementTest`, `CustomerPolicyEnforcementTest`) includes a symmetrical "owner can delete" test alongside the "non-owner cannot delete" test. `DocumentPolicyEnforcementTest` has the non-owner block test but not the owner allow test. `DocumentPolicy` uses `ResourcePolicy` which shares the `update`/`delete` permission logic — the delete path is almost certainly correct, but the gap means a refactor that accidentally breaks owner-delete for documents would not be caught while the equivalent break in every other resource would be.
    - **Plain English:** Every type of content on the platform (links, services, gallery images, customers) has two tests for deletion: "the owner can do it" and "a stranger cannot." Documents only have the second test. The lock keeps strangers out, but nobody checked that the key holder can open the door. This is a consistency gap — the door is almost certainly working correctly, but the test that would catch a regression is missing.
    - **Evidence:**
        ```php
        // Present in the test file:
        it('blocks a non-owner from deleting a document with 404', function () { ... });

        // Missing:
        // it('allows the owner to delete their own document', function () { ... });
        ```

- [ ] **#TEST-7** · P3 — AuditTableHardeningTest is a fragile text-match test that breaks on SQL reformatting
    - **Where:** tests/Feature/Security/AuditTableHardeningTest.php (entire file)
    - **Affects:** CI pipeline — a DBA reformatting the migration file with consistent indentation or reordering `ALTER TABLE` statements breaks the assertions with zero behavioral change to the database.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a comment block at the top of the file documenting that exact SQL text matching is intentional and that reformatting the migration requires updating the test. This makes the coupling visible rather than surprising.
        - Optionally: replace exact `->toContain("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY")` assertions with a regex variant (e.g., `preg_match`) that tolerates whitespace variation while still catching semantic removal of the clause.
    - **Technical:** The test reads the raw SQL file and uses `->toContain(...)` with full multi-word SQL fragments like `ALTER TABLE core.professional_deletion_audit ENABLE ROW LEVEL SECURITY`. The approach is sound — RLS and FK-on-delete behavior cannot be asserted against the SQLite test harness, so guarding the migration text is a reasonable proxy. The fragility comes from exact whitespace/ordering sensitivity. The five invariants tested (RLS on 3 tables, FK type, handle snapshot column, RLS policies, tenant SELECT policies) are all meaningful — the test just needs to be resilient to formatting.
    - **Plain English:** The test verifies the safety properties of the database migration by checking for exact phrases in the raw SQL file. If someone adds a comment or reformats the file, the test fails even though the database behavior is unchanged. It's like verifying a contract by checking the exact font rather than reading what it says. The test is doing the right job — it just needs to be less brittle about how it reads.
    - **Evidence:**
        ```php
        it('enables RLS on all three audit tables', function () {
            foreach ([
                'core.professional_deletion_audit',
                'core.wallet_currency_switch_audit',
                'core.brand_status_history',
            ] as $table) {
                expect($this->migration)->toContain("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            }
        });
        ```

`★ Insight ─────────────────────────────────────`
The test suite has strong integration/isolation coverage (tenant isolation, policy enforcement, middleware) but a systematic gap at the **unit boundary of pure service classes**. `AccountCapabilities` and `AccountTypeTransitionService` are both pure functions over in-memory state — they don't need database fixtures, Mockery, or HTTP — making them the cheapest possible tests to write yet the most likely to catch capability-matrix regressions silently introduced during feature work. The pattern to adopt: every `final class` with no DB dependencies in `app/Services/Accounts/` gets a corresponding unit test file.
`─────────────────────────────────────────────────`
