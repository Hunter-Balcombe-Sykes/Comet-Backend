`★ Insight ─────────────────────────────────────`
DeepSeek searched only `tests/Feature/Policies/` and found one file (`CommissionPolicyTest.php`), concluding that 10 of 13 policies had zero test coverage. The real picture: `tests/Unit/Policies/` holds a complete policy unit test suite (12 files), and `tests/Feature/Security/PolicyEnforcement/` adds 8 HTTP-layer enforcement tests. DeepSeek missed both directories entirely. This is why adjudication must grep before approving "missing test" findings — the production code and test code are real, but the *directory* the scanner looked in was wrong.
`─────────────────────────────────────────────────`

# Test Coverage Audit — 2026-05-12

**Branch:** development
**Lens:** Test coverage: critical paths, idempotency, race-safety, policy abilities, mock-vs-integration discipline
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- tests/Feature/Policies/CommissionPolicyTest.php
- tests/Unit/Policies/CommissionPolicyTest.php (verified via tool)
- tests/Feature/Security/PolicyEnforcement/ (8 files, verified via tool)
- tests/Unit/Policies/ (12 files, verified via tool)
- tests/Unit/Auth/VerifySupabaseJwtFallbackTest.php (verified via tool)
- tests/Feature/Security/VerifyHydrogenApiKeyTest.php (verified via tool)
- tests/Feature/Staff/EnsurePartnaStaffMiddlewareTest.php (verified via tool)
- tests/Feature/Staff/AccountDeletion/AdminInitiatedDeletionTest.php (verified via tool)
- app/Policies/CommissionPolicy.php
- app/Policies/WalletMovementPolicy.php
- app/Http/Middleware/Auth/VerifySupabaseJwt.php
- app/Http/Middleware/Auth/VerifyEmbeddedApiKey.php
- app/Http/Middleware/Auth/VerifyShopifySessionToken.php
- app/Http/Middleware/Auth/EnsurePartnaAdmin.php
- app/Http/Middleware/Auth/EnsurePartnaStaff.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 5 complete
- P3 Low: 0 of 1 complete

---

## Adjudication notes — mass drop

DeepSeek emitted TEST-2 through TEST-11 claiming that `AffiliateProductPolicy`, `BrandPartnerLinkPolicy`, `BrandResourcePolicy`, `CustomerPolicy`, `GdprPolicy`, `NotificationPolicy`, `ProfessionalSelfPolicy`, `ServicePolicy`, `SitePolicy`, and `SubscriptionPolicy` each had no test file. This is incorrect: `tests/Unit/Policies/` contains a dedicated unit test for each of those 10 policies (confirmed via `Glob app/Policies/*.php` cross-referenced with `Glob tests/Unit/Policies/*.php`). Eight of those policies also have integration-level enforcement tests under `tests/Feature/Security/PolicyEnforcement/`. All 10 findings are dropped.

DeepSeek also emitted TEST-14 (VerifyHydrogenApiKey untested) — `tests/Feature/Security/VerifyHydrogenApiKeyTest.php` exists and covers bypass, fail-closed production behavior, missing header, wrong key, and valid key. Dropped.

DeepSeek emitted TEST-17 (EnsurePartnaStaff untested) — `tests/Feature/Staff/EnsurePartnaStaffMiddlewareTest.php` exists with eight scenarios. Dropped.

The root cause of all mass-drops: DeepSeek searched only `tests/Feature/Policies/` and missed `tests/Unit/Policies/`, `tests/Feature/Security/PolicyEnforcement/`, and `tests/Feature/Staff/`.

---

## P2 — Should fix

- [ ] **#TEST-1** · P2 — CommissionPolicy.startConnect has zero test coverage
    - **Where:** app/Policies/CommissionPolicy.php:startConnect / tests/Unit/Policies/CommissionPolicyTest.php (should host the new tests)
    - **Affects:** Stripe Connect onboarding initiation — the gate that prevents brand professionals from starting an affiliate-mode Express Connect account.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `it('allows a non-brand professional to startConnect for themselves')` asserting `startConnect($aff, $aff) === true`.
        - Add `it('denies a brand professional from startConnect with false')` asserting `startConnect($brand, $brand) === false`.
        - Add `it('denies startConnect when actor is not the requested pro')` asserting cross-actor returns `false`.
        - Follow the pattern already established for `viewProjections` in `tests/Unit/Policies/CommissionPolicyTest.php` (uses `forceFill`, no DB).
    - **Technical:** `CommissionPolicy::startConnect` is the sole gate on `POST /stripe/connect` — it enforces that only non-brand professionals can initiate Stripe Connect Express onboarding. The existing unit test (`tests/Unit/Policies/CommissionPolicyTest.php`) covers `view`, `update`, and `viewProjections` but stops short of `startConnect`, `manageWallet`, and `topUp`/`managePaymentMethod` (those last two are in the older Feature-Policies test). A grep for `startConnect` across the entire `tests/` tree returns zero hits in any test body — meaning a bug that accidentally inverts the `!== 'brand'` check would merge undetected. `manageWallet` is similarly absent from the unit test but is exercised indirectly by controller tests (`BrandPayoutsListTest`, `BrandBillingSummaryTest`) so its gap is lower priority.
    - **Plain English:** This is the lock on the door that says "only delivery drivers (affiliates) can sign up for the payment handoff program — store owners (brands) don't need it." The lock works today, but there's no test dummy that tries to open the door as a store owner and checks it's rejected. If someone swapped one character in the rule during a refactor, no alarm would sound.
    - **Evidence:**
        ```php
        // app/Policies/CommissionPolicy.php — startConnect ability, uncovered by any test
        public function startConnect(Professional $actor, Professional $pro): bool
        {
            return $actor->id === $pro->id
                && ($actor->professional_type ?? null) !== 'brand';
        }
        ```
        ```php
        // tests/Unit/Policies/CommissionPolicyTest.php — file ends at line 175 with viewProjections;
        // startConnect, manageWallet are absent from both this file and CommissionPolicyEnforcementTest
        it('denies a professional from viewing another professional projections', function () {
            $pro = (new Professional)->forceFill(['id' => '11111111-1111-1111-1111-111111111111']);
            $skeleton = (new \App\Models\Commerce\BrandAffiliateRollup)->forceFill(['affiliate_professional_id' => '22222222-2222-2222-2222-222222222222']);

            expect($this->policy->viewProjections($pro, $skeleton))->toBeFalse();
        });
        // EOF — no startConnect or manageWallet tests follow
        ```

- [ ] **#TEST-2** · P2 — WalletMovementPolicy has no unit test and violates the 404-on-not-yours contract
    - **Where:** app/Policies/WalletMovementPolicy.php / tests/Unit/Policies/ (missing WalletMovementPolicyTest.php)
    - **Affects:** Wallet ledger row visibility — a professional requesting another's wallet history receives a 403 (gate returning false) instead of the 404 mandated by CLAUDE.md.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Create `tests/Unit/Policies/WalletMovementPolicyTest.php` following the existing unit policy test pattern (no DB, `forceFill`).
        - Add `it('allows view when actor owns the wallet movement')` → expect `true`.
        - Add `it('denies view with 404 when actor does not own the movement')` → expect `Response::denyAsNotFound()`.
        - Change `WalletMovementPolicy::view` to return `bool|Response` and call `$this->denyAsNotFound()` instead of returning `false` — confirm no other caller depends on the bare bool.
    - **Technical:** Every other policy in `app/Policies/` returns `bool|Response` and calls `$this->denyAsNotFound()` for cross-tenant access. `WalletMovementPolicy::view` returns bare `bool` — when the Gate evaluates `false` it emits a 403, not a 404. This violates the documented invariant ("Not-owned → 404, `denyAsNotFound()`") and leaks wallet-row existence to non-owners. `tests/Unit/Policies/` has a unit test file for every other policy (confirmed via glob); this one is absent. The `WalletMovementsLedgerTest` tests the service (creditWalletFromCheckoutSession), not the gate.
    - **Plain English:** The rule here is: if someone asks "can I see this wallet entry?" and the answer is no, the app should say "that doesn't exist" (404), not "you're not allowed" (403). Saying "not allowed" tells the person the wallet entry does exist, just that they can't see it — which leaks information. This policy returns the wrong answer in a way no test would catch.
    - **Evidence:**
        ```php
        // app/Policies/WalletMovementPolicy.php — returns bool, not Response; denyAsNotFound() never called
        public function view(Professional $actor, WalletMovement $movement): bool
        {
            return (string) $actor->id === (string) $movement->professional_id;
        }
        ```
        ```php
        // Compare: every other policy extends BasePolicy and calls denyAsNotFound():
        // app/Policies/SubscriptionPolicy.php
        public function view(Professional $actor, Subscription $subscription): bool|Response
        {
            if ((string) $subscription->professional_id !== (string) $actor->id) {
                return $this->denyAsNotFound();
            }
            return true;
        }
        ```

- [ ] **#TEST-3** · P2 — VerifySupabaseJwt JWKS primary path has no test; alg-confusion guard is untested
    - **Where:** app/Http/Middleware/Auth/VerifySupabaseJwt.php / tests/Unit/Auth/VerifySupabaseJwtFallbackTest.php (fallback only)
    - **Affects:** Every authenticated request. The alg-confusion rejection (`HS256` → `RuntimeException`) and the kid-lookup failure path are untested; a regression could allow an attacker to forge tokens using a known public key as an HMAC secret.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add `tests/Unit/Auth/VerifySupabaseJwtPrimaryPathTest.php` with scenarios: missing bearer token → 401, malformed JWT (not 3 parts) → 401, `alg=HS256` in header → 401 (the alg-confusion guard), `alg=RS256` missing kid → 401, JWKS empty → 401, valid RS256 token (use a generated RSA test key-pair) → 200 with uid extracted, `iss`/`aud` mismatch → 401.
        - Confirm `SUPABASE_JWKS_FAIL_CLOSED=true` → 503 already tested in the fallback suite (it is: "refuses fallback and returns 503").
    - **Technical:** `tests/Unit/Auth/VerifySupabaseJwtFallbackTest.php` forces the JWKS path to throw (`CacheLockService::rememberLocked` mocked to throw) and then exercises the Auth-Server fallback. This means the `verifyWithJwks` primary path — including the critical `in_array($alg, ['RS256', 'ES256'], true)` guard that blocks algorithm confusion attacks — has never been exercised in the test suite. The comment in the source code explicitly says this guard prevents "HS256 signed with the public key as the HMAC secret" but there is no test that verifies the rejection fires.
    - **Plain English:** The front door ID scanner has two modes: a fast cryptographic mode (JWKS) and a slower phone-home mode (Auth Server). All our tests only check the phone-home mode. The cryptographic mode has a crucial safety rule — it refuses a specific type of forged ID that uses a known trick. That rule has never been tested to confirm it actually fires.
    - **Evidence:**
        ```php
        // app/Http/Middleware/Auth/VerifySupabaseJwt.php — alg-confusion guard, uncovered by any test
        $alg = $header['alg'] ?? null;
        if (! in_array($alg, ['RS256', 'ES256'], true)) {
            throw new \RuntimeException('JWT alg must be RS256 or ES256, got: '.($alg ?? 'none'));
        }
        ```
        ```php
        // tests/Unit/Auth/VerifySupabaseJwtFallbackTest.php — only fallback path tested
        beforeEach(function () {
            // Force JWKS to fail so every test exercises the fallback path.
            $cacheLock = Mockery::mock(CacheLockService::class);
            $cacheLock->shouldReceive('rememberLocked')
                ->andThrow(new RuntimeException('JWKS unavailable'));

            $this->middleware = new VerifySupabaseJwt($cacheLock);
        ```

- [ ] **#TEST-4** · P2 — VerifyEmbeddedApiKey has no test despite identical fail-closed pattern to VerifyHydrogenApiKey
    - **Where:** app/Http/Middleware/Auth/VerifyEmbeddedApiKey.php / (no test file exists)
    - **Affects:** `/internal/embedded/*` routes — including the deployment-token endpoint that rewrites a brand's Shopify storefront. A missing `PARTNA_EMBEDDED_API_KEY` on a production deploy must throw, not bypass.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Create `tests/Feature/Security/VerifyEmbeddedApiKeyTest.php` mirroring the pattern in `tests/Feature/Security/VerifyHydrogenApiKeyTest.php`.
        - Scenarios: dev bypass (env=testing, empty key → 200), production fail-closed (env=production, empty key → RuntimeException / 500), valid key + shop header → resolves `embedded_professional_id` on request, invalid key → 403, missing shop header → 400, shop not connected → 404 with `shop_not_connected` error code.
        - Mock `ShopifyShopResolver::resolveProfessionalId` for the shop-resolution tests.
    - **Technical:** `VerifyHydrogenApiKey` and `VerifyEmbeddedApiKey` share identical environment-gated bypass logic (empty config in non-local/testing → `RuntimeException`). `VerifyHydrogenApiKey` received a comprehensive test in `ea994cf` (6 scenarios). `VerifyEmbeddedApiKey` has none. The routes it guards (`/internal/embedded/*`) include a deployment-token endpoint that can rewrite a brand's Shopify storefront — the same risk class as the Hydrogen routes that prompted the original test.
    - **Plain English:** There are two secret-door locks with identical mechanisms. We tested one exhaustively after a near-miss (the hydrogen door got all its tests after commit `ea994cf`). The other secret door — which controls updates to brands' online stores — got no tests at the same time. If the config key for that door goes missing in production, we have no test to confirm it slams shut rather than opening wide.
    - **Evidence:**
        ```php
        // app/Http/Middleware/Auth/VerifyEmbeddedApiKey.php — same bypass pattern, no test
        if ($expected === '') {
            if (app()->environment(['local', 'testing'])) {
                return $next($request);
            }
            throw new \RuntimeException(
                'services.embedded.api_key is not configured — refusing to fall through to bypass outside local/testing.'
            );
        }
        ```
        ```php
        // tests/Feature/Security/VerifyHydrogenApiKeyTest.php — identical pattern HAS 6 tests
        it('returns 500 (fails closed) when env is production and no key is configured', function () {
            config()->set('services.hydrogen.api_key', '');
            app()['env'] = 'production';

            getJson('/__test/hydrogen-guard')->assertStatus(500);
        });
        ```

- [ ] **#TEST-5** · P2 — VerifyShopifySessionToken has no test; JWT audience check and shop resolution are untested
    - **Where:** app/Http/Middleware/Auth/VerifyShopifySessionToken.php / (no test file exists)
    - **Affects:** Shopify admin UI extension endpoints. The audience check prevents tokens from a different Shopify app being replayed against Partna.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `tests/Feature/Auth/VerifyShopifySessionTokenTest.php` using a real HS256 token signed with a test secret.
        - Scenarios: missing token → 401, invalid signature → 401, audience mismatch (`aud ≠ expected`) → 401, missing/malformed `dest` claim → 401, non-myshopify.com destination → 401, shop not connected → 404 with `shop_not_connected`, valid token + connected shop → 200 with `embedded_professional_id` on request, missing `SHOPIFY_API_SECRET` config → 500.
        - Use `Firebase\JWT\JWT::encode` with a test key in `beforeEach`; mock `ShopifyShopResolver::resolveProfessionalId`.
    - **Technical:** This middleware performs three security-critical operations: HS256 JWT signature verification, audience claim comparison (`hash_equals`), and shop-domain extraction from the `dest` claim. None are exercised in the test suite. The audience check in particular guards against cross-app token replay (a valid session token from a different Shopify app would pass signature verification if only the secret is checked). The adjacent `VerifyEmbeddedApiKey` test — already proposed above — shares the same shop-resolution layer but uses a different auth mechanism, so these tests are complementary, not duplicative.
    - **Plain English:** The Shopify admin entrance uses session ID cards that are issued by Shopify. The guard must check both that the card's signature is valid *and* that the card was issued specifically for *our* app — not any other Shopify app's card. The second check (audience matching) is the important one. Neither check has a test, meaning if someone changes the audience comparison to a weaker string compare or removes it, no alarm would fire.
    - **Evidence:**
        ```php
        // app/Http/Middleware/Auth/VerifyShopifySessionToken.php — audience check untested
        // Audience must match this app's client ID — guards against tokens
        // issued for a different Shopify app being replayed against ours.
        $aud = (string) ($claims['aud'] ?? '');
        if (! hash_equals($expectedAud, $aud)) {
            return response()->json(['message' => 'Session token audience mismatch.'], 401);
        }
        ```

---

## P3 — Nice to have

- [ ] **#TEST-6** · P3 — EnsurePartnaAdmin has no dedicated middleware unit test
    - **Where:** app/Http/Middleware/Auth/EnsurePartnaAdmin.php / tests/Feature/Staff/AccountDeletion/AdminInitiatedDeletionTest.php (partial coverage)
    - **Affects:** Admin-only routes. The non-admin 403 case is tested in AdminInitiatedDeletionTest; admin-passes (200) and unauthenticated-no-uid (401) are not in an isolated test.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Create `tests/Feature/Staff/EnsurePartnaAdminMiddlewareTest.php` following the pattern of the existing `EnsurePartnaStaffMiddlewareTest.php`.
        - Scenarios: admin staff → 200, support-role staff → 403, missing `supabase_uid` → 401, staff record not found → 403.
    - **Technical:** `EnsurePartnaStaff` received a comprehensive 8-scenario dedicated unit test (`EnsurePartnaStaffMiddlewareTest.php`). `EnsurePartnaAdmin` calls into `EnsurePartnaStaff`'s attribute cache and adds an `isAdmin()` check, but has no parallel test. The 403-for-non-admin path is exercised only as a side-effect in `AdminInitiatedDeletionTest`, which means a refactor that changes the middleware signature or the `isAdmin()` logic would not be caught without running the wider test suite. Adding an isolated test here is low cost and keeps the pattern consistent.
    - **Plain English:** We have an independent test for the basic staff badge checker. The senior admin badge checker next to it — which has the same mechanism plus an extra seniority check — has no independent test of its own. It's only tested when the broader "admin deletion" test runs, which is like testing a padlock only as part of testing an entire security system.
    - **Evidence:**
        ```php
        // app/Http/Middleware/Auth/EnsurePartnaAdmin.php — no dedicated test
        if (! $staff || ! $staff->isAdmin()) {
            return response()->json(['message' => 'Admin access required'], 403);
        }
        ```
        ```php
        // tests/Feature/Staff/AccountDeletion/AdminInitiatedDeletionTest.php — only the 403 path tested
        it('non-admin staff get 403 from EnsurePartnaAdmin middleware', function () {
            // ... only asserts 403 for non-admin; admin-pass and 401 paths absent
            $middleware = new EnsurePartnaAdmin;
            $response = $middleware->handle($request, fn () => response()->json(['ok' => true]));
            expect($response->getStatusCode())->toBe(403);
        });
        ```

`★ Insight ─────────────────────────────────────`
Three structural patterns emerge from this audit worth internalizing for future coverage work: (1) **Directory blindness** — scanners that assume a single directory structure miss entire test suites; always glob both `tests/Unit/` and `tests/Feature/` before declaring coverage absent. (2) **Contract drift** — `WalletMovementPolicy` returning `bool` instead of `Response` is the kind of violation that accumulates silently when new policies are added by copy-paste from older code; a shared `BasePolicyTest::assertDenyAsNotFound()` helper that every policy test calls would catch this structurally. (3) **Paired pattern gap** — when you add a comprehensive test for `VerifyHydrogenApiKey` (commit `ea994cf`), the review should automatically check whether the structurally identical `VerifyEmbeddedApiKey` got the same treatment; "implement for all instances of the same pattern" is an easy rule to encode in a PR checklist.
`─────────────────────────────────────────────────`
