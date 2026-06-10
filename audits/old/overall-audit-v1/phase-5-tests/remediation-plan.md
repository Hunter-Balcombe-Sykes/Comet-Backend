# Partna Phase 5 Test Coverage — Consolidated Remediation Plan

> **FROZEN as of 2026-05-12.** This plan is no longer the source of truth for status, regressions, or post-baseline annotations. Live status — including all changes from PR #12 onwards and any new findings introduced after this date — lives in `audits/MASTER-REMEDIATION-PLAN.md`. This file is preserved as provenance for `Original ID: Phase 5 Pattern X` references in the master plan. Do not edit; if a status change is needed, update the master instead.

**Date:** 2026-05-12
**Branch:** development
**Source:** 7 audits across `audits/phase-5-tests/`, adjudicated by `claude-sonnet-4-6` over `deepseek-v4-pro` drafts
**Lens:** Test coverage — critical paths, idempotency, race-safety, policy abilities, mock-vs-integration discipline

## Summary

- **43 reported findings**, **33 unique** after deduplication (10 cross-audit duplicates — see matrix below)
- **Tier breakdown (reported):** 0 P0 · 16 P1 · 24 P2 · 3 P3
- **Tier breakdown (unique):** 0 P0 · 14 P1 · 16 P2 · 3 P3
- **Fourteen foundational patterns close 31 of 33 unique findings** (14 P1 · 14 P2 · 3 P3)
- **2 standalone fixes** for the rest (2 P2)
- **Estimated total:** ~7–9 days of focused work to close all 33 findings (Pattern 6's 11-file DB-mock refactor is the largest single block at ~3 days)

Phase 5 is the test-coverage phase: no P0 findings (everything ships and runs today) but the largest P1 count of any phase to date. The dominant shape is **untested critical paths** — auth gates, webhook idempotency, factory schema, trigger behavior, and policy abilities each have a parallel pattern where one canonical test exists and the structurally-identical sibling has none. Three structural anti-patterns surface repeatedly: (1) **directory blindness** — the unit-policy suite under `tests/Unit/Policies/` exists and is comprehensive, but new policies (`WalletMovementPolicy`) shipped without a parallel test; (2) **DB mocking via `DB::shouldReceive()`** in 11 analytics/staff test files which silently swallows schema/trigger regressions; (3) **factory drift** — five factories emit values that violate v2-baseline CHECK and NOT NULL constraints and survive in tests only because `setupProfessionalsTable()` helpers provision a forgiving subset of the real schema. The first cluster is high-leverage (a single sweep test like `PolicyCoverageTest.php` could detect both axes structurally). The second is the riskiest because production migrations rename `commission_ledger_entries → commission_movements` and the mocks return pre-migration shape with CI green. The third is the most expensive to debug when it hits — orphan `order_id` FK on `CommissionPayoutItemFactory` fails loudly on pgsql but silently on SQLite, so the gap surfaces only at production parity.

## Cross-audit duplicates (collapse on fix)

| Finding | Audits | Same root cause |
|---------|--------|-----------------|
| `WalletMovementPolicy` has no unit test (also: returns bare `bool` not `bool\|Response`, violating 404-on-not-yours contract) | TEST-A#TEST-1 (P1) ≡ TEST-C#TEST-2 (P2) ≡ TEST-D3#TEST-2 (P3) | Pattern 1 — take P1 as canonical (cross-tenant leak risk) |
| `CommissionPolicy::startConnect` untested | TEST-C#TEST-1 (P2) ⊂ TEST-A#TEST-4 (P1 — 6 of 9 methods) | Pattern 2 — A4 broader framing subsumes C1 |
| DB-mock anti-pattern in analytics/staff controller tests | TEST-D2#TEST-1 + #TEST-2 + #TEST-3 (3× P1, three Analytics files) ≡ TEST-D3#TEST-1 (P2, broader 11-file sweep) | Pattern 6 — single refactor closes all 11 files |
| Stripe billing webhook missing dedup test | TEST-A#TEST-9 (P2) ≡ TEST-D1#TEST-2 (P2) | Pattern 14 — bundle with malformed-payload test |
| Migration tests SQL-text-only (no runtime constraint enforcement) | TEST-A#TEST-5 (P2) ≡ TEST-E#TEST-5 (P2) ≡ TEST-D1#TEST-6 (P2 — broader sweep proposal) ≡ TEST-D2#TEST-5 (P2) | Pattern 10 — one `SchemaConstraintCoverageTest` plus behavioral CHECK tests |
| `BrandStoreSettingsResource` snapshot test (specific case) ⊂ Resource snapshot test sweep (broad sweep) | TEST-D1#TEST-3 (P2) ⊂ TEST-D2#TEST-4 (P2) | Pattern 12 — broad sweep absorbs specific case |

**Related (overlapping scope, distinct prescriptions — bundle the PR):**

| Findings | Why bundle |
|----------|------------|
| Pattern 5's four Shopify webhook controller tests (Cancelled, ThemePublished, OrdersEdited, OrdersCreate/generic redelivery) | Identical HMAC + dedup + dispatch structure; one PR reuses scaffolding for all four. |
| Pattern 7's GDPR + install-chain + register/process `failed()` handlers and tests | All are "job retry exhaustion leaves state in indeterminate flag" — one mental model. |
| Pattern 8's five factory fixes (Professional, CommissionPayout, Order, CommissionPayoutItem, WalletMovement) | All are v2-baseline schema-drift; reviewers grep `definition()` once, eyeball five factories in a single diff. |
| Pattern 11's four middleware tests (`VerifySupabaseJwt`-JWKS, `VerifyEmbeddedApiKey`, `VerifyShopifySessionToken`, `EnsurePartnaAdmin`) | Mirror the proven `VerifyHydrogenApiKeyTest` / `EnsurePartnaStaffMiddlewareTest` scaffolds. |

## Cross-phase coordination

| Phase 5 finding | Cross-phase dependency | Sequencing |
|-----------------|------------------------|------------|
| Pattern 6 (DB mock refactor — 11 files use `DB::shouldReceive()`) | **Phase 3** stabilised the read-side caches (`commerce.orders` + `brand_affiliate_rollup` via `CacheLockService::rememberLocked`). Refactoring the mocks to use real Postgres exercises those caches under test, surfacing any trigger or rollup regression Phase 3's SWR layer was designed around. | Land Pattern 6 *after* Phase 3 Pattern 1 (already shipped). Tests then act as a regression guard for Phase 3's correctness assumptions. |
| Pattern 9 (trigger behavioral tests — `rollup_apply_delta`, URL-sync triggers) | **Phase 4 Pattern 2** (`CREATE INDEX CONCURRENTLY` + `NOT VALID` migration conventions) defines the schema-test discipline. Pattern 9's tests are the first concrete artifacts using that convention to validate trigger behavior on a populated `commerce.orders` and `core.professionals`. | Land Phase 4 Pattern 2 first if not yet in `development`; Pattern 9 then becomes the proof artifact. |
| Pattern 10 (schema constraint coverage test) | **Phase 6 Pattern 7** adds CHECK constraints across 10+ enum columns. Pattern 10's `SchemaConstraintCoverageTest` is the place that asserts every CHECK exists and behaves; if Phase 6 lands first, the test must enumerate the expanded constraint set. | Land Pattern 10 either *before* Phase 6 Pattern 7 (start small, expand) or *after* (one comprehensive test for the full set). The latter is cheaper. |
| Pattern 6 (DB mock refactor) also closes **Phase 2** test gaps for the brand analytics dashboard. | Phase 2 lifecycle audits flagged that brand analytics rely on trigger-maintained `brand_affiliate_rollup`; the DB mocks here are why those Phase 2 trigger regressions stayed invisible. | Independent of Phase 2 — but Pattern 6 retroactively hardens Phase 2's deliverables. |

## Source audit files

- `audit-2026-05-12--test-coverage-critical-paths-idempotency-race-saf.md` (**TEST-C**: policies + auth middleware — 0 P1, 5 P2, 1 P3)
- `audit-2026-05-12--test-coverage-critical-paths-idempotency-race-saf-2.md` (**TEST-E**: factories + migrations + triggers — 4 P1, 3 P2, 1 P3)
- `audit-2026-05-12--test-coverage-critical-paths-idempotency-race-saf-3.md` (**TEST-B**: Shopify webhooks + GDPR jobs — 4 P1, 2 P2)
- `audit-2026-05-12--test-coverage-critical-paths-idempotency-race-saf-5.md` (**TEST-A**: Stripe services + commission policies + payout jobs — 4 P1, 5 P2)
- `audit-2026-05-12--test-coverage-critical-paths-idempotency-race-saf-7.md` (**TEST-D1**: Resources + FormRequests + Security + Stripe tests — 1 P1, 5 P2)
- `audit-2026-05-12--test-coverage-critical-paths-idempotency-race-saf-8.md` (**TEST-D3**: analytics + staff tests + small subdirs — 0 P1, 1 P2, 1 P3)
- `audit-2026-05-12--test-coverage-critical-paths-idempotency-race-saf-9.md` (**TEST-D2**: webhooks/brand/shopify/professional/staff/store tests — 3 P1, 2 P2, 1 P3)

Skipped: `*-saf-4.md` and `*-saf-6.md` (1-byte placeholders from failed TEST-D adjudication attempts — Sonnet rejected as "Prompt is too long" due to scope >1.5 MB; resolved by D1/D2/D3 split).

---

# Part 1 — Foundational patterns

Order is severity-then-leverage: P1 patterns first, then high-leverage P2 sweeps that touch the broadest surface, then narrower P2 fixes, then P3. Pattern 6 (DB mock refactor) is the single largest effort and lands mid-list because it depends on Pattern 8 (factories must be fixed first so the refactored tests can use them).

**Order:** Pattern 1 (WalletMovementPolicy) → Pattern 2 (CommissionPolicy methods) → Pattern 3 (Stripe Connect disconnect) → Pattern 4 (payout job orchestration) → Pattern 5 (Shopify webhook controllers) → Pattern 7 (job `failed()` sweep) → Pattern 8 (factory schema sweep) → Pattern 6 (DB mock refactor — depends on 8) → Pattern 9 (trigger behavioral tests) → Pattern 10 (schema constraint coverage) → Pattern 11 (auth middleware sweep) → Pattern 12 (Resource snapshot sweep) → Pattern 14 (Stripe billing webhook tests) → Pattern 13 (residual policy ability coverage).

## Pattern 1 — `WalletMovementPolicy` unit test + 404 contract fix

**Closes 1 unique finding (1 P1):** TEST-A#TEST-1 (absorbs TEST-C#TEST-2, TEST-D3#TEST-2)

**Effort:** ~1h

### Root cause

`app/Policies/WalletMovementPolicy.php` ships with two structural defects against the policy patterns established elsewhere in `app/Policies/`:

1. **No unit test.** Every other policy under `app/Policies/` has a matching `tests/Unit/Policies/<Name>PolicyTest.php` (13 files). `WalletMovementPolicy` is the only one missing. A type refactor (e.g., UUID object instead of string cast, or `===` vs `==` regression) silently breaks the comparison and opens all wallet movement rows to any authenticated professional. `tests/Feature/Stripe/WalletMovementsLedgerTest.php` exercises the service layer (`creditWalletFromCheckoutSession`) but never invokes the gate.
2. **Returns bare `bool` not `bool|Response`.** Every other tenant-scoped policy returns `bool|Response` and calls `$this->denyAsNotFound()` on cross-tenant access. `WalletMovementPolicy::view` returns bare `bool` — when the Gate evaluates `false` it emits a 403, not a 404. This violates the documented invariant ("Not-owned → 404, `denyAsNotFound()`") in `CLAUDE.md` and leaks wallet-row existence to non-owners.

### What to do

- [ ] **Step 1 — Fix the return type.** Change `WalletMovementPolicy::view`:
    ```php
    public function view(Professional $actor, WalletMovement $movement): bool|Response
    {
        if ((string) $actor->id !== (string) $movement->professional_id) {
            return $this->denyAsNotFound();
        }
        return true;
    }
    ```
    Confirm no caller depends on the bare bool — `rg "WalletMovementPolicy" app/` should return only `Gate::policy()` registration and `authorizeForUser($pro, 'view', $movement)` controller calls; the controller path handles `Response` returns natively.
- [ ] **Step 2 — Write the unit test.** Create `tests/Unit/Policies/WalletMovementPolicyTest.php` following the existing pattern (no DB, `forceFill`):
    - `it('allows view when actor owns the wallet movement')` → expect `true`.
    - `it('denies view with 404 when actor does not own the movement')` → expect `Response`, assert `getStatusCode() === 404`.
    - `it('handles UUID string-cast comparison for forceFill IDs')` — coverage for the documented cast.
- [ ] **Step 3 — Run.** `php artisan test --compact tests/Unit/Policies/WalletMovementPolicyTest.php` then `vendor/bin/pint --dirty`.

### Plain English

The lock on the wallet-ledger door currently says "denied" to outsiders, but the building's policy is to say "no such room exists" to outsiders. Saying "denied" tells the outsider the room is real, just locked — which leaks information. The lock also has no test verifying it actually works, so a refactor could replace the lock with a fake one and nobody would notice.

### Why this is a P1

Wallet ledger rows hold the brand's funded balance and every disbursement against it — this is the canonical money-movement surface. A cross-tenant leak surfaces every disbursement amount and timing for every other brand on the platform. Today no real brands exist, so the actual exposure is zero. On pilot day one, the gate's correctness is the only barrier between brand A and brand B's payout history. The fix is two changes in one file plus a 30-line test.

---

## Pattern 2 — `CommissionPolicy` — cover 6 untested ability methods

**Closes 1 unique finding (1 P1):** TEST-A#TEST-4 (absorbs TEST-C#TEST-1)

**Effort:** ~3h

### Root cause

`app/Policies/CommissionPolicy.php` has 9 public ability methods. Existing tests in `tests/Unit/Policies/CommissionPolicyTest.php` cover `topUp` and `managePaymentMethod` plus `viewProjections` (3 abilities); `tests/Feature/Security/PolicyEnforcement/CommissionPolicyEnforcementTest.php` covers HTTP-layer 403s. The remaining 6 methods (`view`, `viewOwnPayouts`, `update`, `delete`, `startConnect`, `manageWallet`) have **no unit test** — controller tests get 403s but can't distinguish "correct policy logic" from "wrong exception type" or "wrong tier (404 vs 403)".

The most acute gap is `startConnect`:

```php
public function startConnect(Professional $actor, Professional $pro): bool
{
    return $actor->id === $pro->id
        && ($actor->professional_type ?? null) !== 'brand';
}
```

This is the sole gate on `POST /stripe/connect`. A regression that drops the `!== 'brand'` check (or inverts it) allows brand-type professionals to initiate Stripe Connect Express onboarding — a flow that creates a payout-receiving account where one should not exist. No test exists across the entire `tests/` tree that exercises this gate.

### What to do

- [ ] **Step 1 — Extend `tests/Unit/Policies/CommissionPolicyTest.php`.** Add allow/deny pairs for each of the 6 untested methods, following the existing `forceFill` no-DB pattern:
    ```php
    // startConnect — three scenarios
    it('allows a non-brand professional to startConnect for themselves');
    it('denies a brand professional from startConnect with false');
    it('denies startConnect when actor is not the requested pro');

    // view / viewOwnPayouts / update / delete / manageWallet — owner vs non-owner pairs
    it('allows view when actor owns the commission record');
    it('denies view with 404 when actor does not own');
    // ... repeat for each
    ```
    For each `denyAsNotFound()` path, assert the returned `Response` has `getStatusCode() === 404`.
- [ ] **Step 2 — Establish helpers.** Add a `beforeEach` that creates two professionals via `forceFill` (one brand, one affiliate) and one `BrandAffiliateRollup` skeleton. Reuse across all new tests.
- [ ] **Step 3 — Verify line coverage.** After adding tests, `php artisan test --compact tests/Unit/Policies/CommissionPolicyTest.php --coverage-text=storage/coverage/CommissionPolicy.txt` should show 100% line coverage on `CommissionPolicy.php`.

### Plain English

The commission policy file has 9 rules. Three of them have tests. The other six — including "only delivery drivers can sign up for the payment program" — have no tests. If someone refactors any of those six rules and breaks the logic, our automated checks pass and the bug ships. The fix is to add a small test for each of the six remaining rules.

### Why this is a P1

Two of the six untested methods (`view`, `viewOwnPayouts`) gate access to payout history rows — same risk class as Pattern 1's wallet leak. `startConnect` gates the creation of a real Stripe Connect account, which mints a payout-receiver identity in Stripe's system. The other three (`update`, `delete`, `manageWallet`) gate write paths. A regression on any of them is silent because controller tests assert HTTP status codes (which can pass for the wrong reason).

---

## Pattern 3 — Stripe Connect disconnect state-machine tests

**Closes 1 unique finding (1 P1):** TEST-A#TEST-2

**Effort:** ~2h

### Root cause

`app/Services/Stripe/StripeConnectService.php` implements a three-state machine for `stripe_connect_status` lifecycle:

| From | To | Triggered by |
|------|----|----|
| `active` / `restricted` | `disconnected` | Dashboard click → `disconnectAccount()` (line 245–250) or Stripe webhook `account.application.deauthorized` |
| `disconnected` | `onboarding` | Reconnect → `createOnboardingLink()` (line 200–206) |
| `onboarding` | `active` | Webhook `account.updated` after KYC complete |

Only the third arc is tested. `tests/Feature/Stripe/StripeConnectStatusCachingTest.php` covers cache invalidation and `account.updated` webhook handling but never invokes `disconnectAccount()` or the `if ($pro->stripe_connect_status === 'disconnected')` guard in `syncAccountStatus()` (line 116–118). The downstream effect is critical: `CommissionPayoutService` guards on `stripe_connect_status === 'active'` before disbursement (`canReceivePayouts()`), so the disconnect arc is load-bearing for payout safety.

This pattern aligns with **Phase 6 Pattern 1** (the P0 `'disconnected'` CHECK fix). Once that migration ships, this test pattern proves the constraint accepts the value end-to-end.

### What to do

- [ ] **Step 1 — Extend `StripeConnectStatusCachingTest.php`.** Add three scenarios:
    - `it('disconnectAccount sets status to disconnected and preserves stripe_account_id')` — assert the row keeps `stripe_account_id` for re-onboarding without re-creating the Stripe account.
    - `it('syncAccountStatus skips Stripe API when status is disconnected')` — fake the Stripe client, call `syncAccountStatus()` on a disconnected pro, assert no Stripe API call.
    - `it('createOnboardingLink transitions disconnected to onboarding')` — start at `disconnected`, call `createOnboardingLink()`, assert status flips to `onboarding`.
- [ ] **Step 2 — Add a webhook-driven scenario.** In `tests/Feature/Webhooks/StripeConnectDeauthorizationTest.php` (per Phase 6 Pattern 1's Step 4), assert end-to-end: seed `status = 'active'`, POST `account.application.deauthorized` payload, assert `status === 'disconnected'`.
- [ ] **Step 3 — Coordinate with Phase 6 Pattern 1.** Until that migration adds `'disconnected'` to the CHECK, this test will fail at the DB level. Land Phase 6 P0 first.

### Plain English

A Stripe-connected affiliate goes through three life stages: signing up (onboarding), active, and disconnected. We test the first transition (signup → active). The other two — disconnecting and reconnecting — have no tests. A regression in the disconnect logic would either leave the user stuck or silently re-bill them. The fix adds three small tests to the existing test file.

### Why this is a P1

Pilot affiliates will disconnect-and-reconnect at non-zero rates (testing the integration, switching accounts, recovering from KYC failures). Today every disconnect is broken (per Phase 6 Pattern 1's P0) — after that lands, this test surface ensures the broader state machine doesn't silently regress in future refactors.

---

## Pattern 4 — Payout job orchestration test sweep

**Closes 3 unique findings (1 P1 · 2 P2):** TEST-A#TEST-3 (P1), TEST-A#TEST-6 (P2), TEST-A#TEST-8 (P2)

**Effort:** ~4h

### Root cause

Three commission payout jobs have coverage gaps in their orchestration layer (rate-limit handling, backoff/retry contracts, failure hooks):

| Job | Coverage today | Gap |
|-----|----------------|-----|
| `ProcessCommissionPayoutsJob` (hourly sweep) | Service layer (`processEligiblePayouts()`) tested; orchestration not. | Rate-limit `release()` w/ exponential backoff and `failed()` Nightwatch hook untested. If `RateLimitException` handling silently regresses to `fail()`, hourly sweeps die with no alert. |
| `ExecuteCommissionPayoutJob` (per-payout disbursement) | 13 tests on `handle()` + `failed()`. | `backoff()` / `tries` / `uniqueFor` invariant untested. `array_sum([60,120,300,600]) = 1080s > uniqueFor=180s` — the unique lock expires mid-chain. Latent double-payout race waiting for a unit test to surface. |
| `VoidPendingCommissionsForLinkJob` (disconnect cleanup) | One happy-path test. | `failed()` hook and missing-professional guard untested. If `failed()` is broken, ops has no Nightwatch alert. |

These are all job-orchestration concerns — `handle()` is well-covered but the surrounding contract (`backoff`, `failed`, retry exhaustion) is not.

### What to do

- [ ] **Step 1 — `ProcessCommissionPayoutsJob`.** Create `tests/Feature/Stripe/ProcessCommissionPayoutsJobTest.php`:
    - `it('releases with correct exponential backoff on rate-limit exception')` — fake the service to throw `RateLimitException`, assert `Queue::assertReleased()` with expected delay.
    - `it('calls void/warning sweep after the eligible-payouts pass succeeds')`.
    - `it('failed() reports via Nightwatch and logs structured error')` — call `->failed(new Exception(...))` directly, assert `Log::shouldReceive('error')` and `report()` invocation.
- [ ] **Step 2 — `ExecuteCommissionPayoutJob`.** Extend `tests/Feature/Stripe/ExecuteCommissionPayoutJobTest.php` with three invariant assertions:
    ```php
    it('backoff sums to less than uniqueFor', function () {
        $job = new ExecuteCommissionPayoutJob(...);
        expect(array_sum($job->backoff()))->toBeLessThan($job->uniqueFor());
    });
    it('tries equals backoff array length + 1');
    it('backoff sequence is monotonically increasing');
    ```
    **Note:** the current `backoff()` returns `[60,120,300,600]` (sum 1080s) and `uniqueFor()` returns 180s — the first invariant will *fail* on landing. That's intentional: the test pins the bug, then the fix is to either lengthen `uniqueFor` or shorten `backoff`. Land the test in the same PR as the contract correction.
- [ ] **Step 3 — `VoidPendingCommissionsForLinkJob`.** Extend `tests/Feature/Jobs/VoidPendingCommissionsForLinkJobTest.php`:
    - `it('returns early when affiliate professional is null')` — spy on void service, assert never called.
    - `it('failed() reports and logs')` — spy on `Log::error` and `report()`, assert both invoked.

### Plain English

Three background jobs that handle paying affiliates have gaps. One doesn't have any test for what happens when Stripe says "too many requests" (it should pause and retry). One has a hidden time-bomb where the retry chain takes longer than the duplicate-prevention lock — a Stripe failure pattern could cause the same payout to fire twice. One only has a single test for the happy case, with no test for "what if the user no longer exists" or "what if the job crashes." All three are job-mechanics tests, no real Stripe calls needed.

### Why these tier together

All three are job-orchestration concerns on the same payout pipeline; one PR adds three short test files (or extends two existing ones). Reviewer mental model is identical: spy on the queue, exception, and logger; assert orchestration contract holds.

---

## Pattern 5 — Shopify webhook controller HMAC + dedup test sweep

**Closes 4 unique findings (4 P1):** TEST-B#TEST-1, TEST-B#TEST-2, TEST-B#TEST-3, TEST-D1#TEST-1

**Effort:** ~6h

### Root cause

Four Shopify webhook controllers share identical three-path logic (pre-dedup early exit → HMAC check → `Cache::add` dedup → job dispatch) but only one is comprehensively tested:

| Controller | Test today | Gap |
|------------|------------|-----|
| `ShopifyOrdersEditedWebhookController` | One happy-path test in `OrderEditedSnapshotTest.php` (valid HMAC, job dispatch). | Invalid HMAC → 401 and duplicate webhook ID → dedup response paths absent. |
| `ShopifyOrdersCancelledWebhookController` | Job-level only (`OrderRace3EditedCancelledBeforePaidTest`). | Controller HTTP entry: 401 and dedup branches absent. |
| `ShopifyThemePublishedWebhookController` | None. `Glob("tests/Feature/Webhooks/Shopify/*Theme*")` returns zero. | HMAC + dedup + dispatch entirely untested. |
| `ShopifyOrderWebhookController` (generic orders/create) | `OrderIdempotencyTest.php` covers job-layer idempotency via `commerce.order_events.shopify_event_id` UNIQUE. | HTTP-layer redelivery dedup — calling controller twice with same `X-Shopify-Webhook-Id` — not asserted. |

Shopify guarantees at-least-once delivery; duplicate `X-Shopify-Webhook-Id` values are documented and expected. Without re-delivery tests, a regression where the controller never writes the event row before enqueueing downstream work would go undetected — the job runs twice and the rollup trigger double-counts the commission.

### What to do

- [ ] **Step 1 — Establish the canonical scaffold.** Use `OrderEditedSnapshotTest.php`'s structure as the template. Each new test file gets four scenarios:
    ```php
    it('rejects with 401 on invalid HMAC');
    it('returns duplicate=true on repeated X-Shopify-Webhook-Id without dispatching the job again');
    it('dispatches the downstream job exactly once on a valid first-delivery webhook');
    it('returns 200 silently for unknown shop_domain (no leak)');
    ```
- [ ] **Step 2 — Create three new test files:**
    - `tests/Feature/Webhooks/Shopify/OrdersCancelledHmacAndDedupTest.php`
    - `tests/Feature/Webhooks/Shopify/ThemePublishedWebhookTest.php`
    - `tests/Feature/Webhooks/Shopify/OrderCreateRedeliveryDedupTest.php`
- [ ] **Step 3 — Extend `OrderEditedSnapshotTest.php`** with the missing 401 and dedup branches (don't duplicate the full scaffold — just append the two scenarios).
- [ ] **Step 4 — Verify the dedup guard lives in the right layer.** For each controller, confirm `Cache::add(...)` precedes job dispatch. The `ProcessShopifyOrderWebhookJob` does its own DB-level dedup via `order_events`, but the controller-layer guard is defense-in-depth and cheaper (no job spawn for replays).

### Plain English

Shopify's normal behavior is to retry every webhook — the same event arrives twice, sometimes three times. Three of our four webhook front doors have no test that exercises this. The signature-check on those doors has no test either, meaning a forged or replayed request would pass without anyone noticing. The fix is four small test files using the same shape.

### Why this is a P1 cluster

Webhook idempotency is critical infrastructure: at pilot scale, double-counted commissions show up as wrong payouts; HMAC bypass is direct order-injection risk. The reference test (`OrderEditedSnapshotTest`) is already there to copy; the gap is purely "implement for all instances of the same pattern."

---

## Pattern 7 — Job `failed()` handler + test sweep

**Closes 3 unique findings (1 P1 · 2 P2):** TEST-B#TEST-4 (P1), TEST-B#TEST-5 (P2), TEST-B#TEST-6 (P2)

**Effort:** ~5h

### Root cause

After retry exhaustion (`$tries` reached), Laravel calls the job's `failed(Throwable $e)` method. Several job classes have inconsistent treatment:

| Group | Has `failed()`? | Has test? | Risk |
|-------|----------------|-----------|------|
| `app/Jobs/Shopify/Gdpr/*` (3 jobs: RedactShop, RedactCustomer, ExportCustomerData) | **No** | No (no handler to test) | Silent retry exhaustion. `GdprRequest->status` stays in flight; no audit trail. Shopify GDPR window is 30 days — silent failure becomes a compliance violation. Pattern exists in `ExportProfessionalDataJob::failed()`. |
| Install-chain jobs (`CreateShopifyAffiliateDiscountJob`, `SyncShopifyBrandDesignJob`, others) | Yes | **No tests for `failed()`** | Refactor to handler logic (wrong key, null-safe operator) goes undetected. Staff rely on these flags for diagnosing stuck onboardings. |
| `RegisterShopifyWebhooksJob`, `ProcessShopifyShopUpdateJob` | **No** | No | `webhooks_state` stays `'partial'` with no alert; stale UI state. Ten other Shopify jobs *do* have `failed()`; these two are outliers. |

### What to do

- [ ] **Step 1 — GDPR jobs (P1).** Add `failed(Throwable $e)` to each of the three GDPR jobs:
    ```php
    public function failed(Throwable $e): void
    {
        report($e);
        Log::error('GDPR job failed after retries', [
            'job' => static::class,
            'shop_domain' => $this->shopDomain ?? null,
            'gdpr_request_id' => $this->gdprRequestId ?? null,
            'error' => $e->getMessage(),
        ]);
        // Transition the GdprRequest to 'failed' so staff dashboards alert.
        GdprRequest::query()->where('id', $this->gdprRequestId)
            ->update(['status' => 'failed', 'failed_at' => now()]);
    }
    ```
    Copy the precise scaffold from `ExportProfessionalDataJob::failed()` (which has the proven shape). Extend `tests/Feature/Webhooks/Shopify/Gdpr/*Test.php` with `it('failed() transitions GdprRequest to failed status')` calling `$job->failed(new RuntimeException())` directly.
- [ ] **Step 2 — Install-chain jobs (P2).** For each job that *has* a `failed()` handler but no test, add one test per job (these are synchronous, no external deps):
    ```php
    it('failed() transitions provider_metadata to design_state=failed', function () {
        $integration = ProfessionalIntegration::factory()->create(['provider_metadata' => ['design_state' => 'pending']]);
        $job = new SyncShopifyBrandDesignJob($integration->id);
        $job->failed(new RuntimeException('Shopify API down'));
        expect($integration->fresh()->provider_metadata['design_state'])->toBe('failed');
    });
    ```
- [ ] **Step 3 — Outlier jobs (P2).** Add `failed()` to `RegisterShopifyWebhooksJob` (transitions `webhooks_state` to `'failed'`) and `ProcessShopifyShopUpdateJob` (logs with `shop_domain` context). Add brief direct-invocation tests.

### Plain English

Every background job in this app gets up to 3 retry attempts. When all attempts fail, Laravel calls a "post-mortem" method on the job to record what went wrong. Several jobs are missing that method — they retry, fail, and disappear silently. The GDPR ones are the most concerning because Shopify gives us 30 days to honour a deletion request and a silently-failed job means we missed the deadline. The fix is a small ~10-line handler per job and a one-test-per-job assertion.

### Why this tiers as P1+P2 together

The GDPR gap is compliance risk (P1). The install-chain and outlier gaps are operational visibility (P2 — recoverable manually, but staff have no automated signal). All three share the same review pattern; bundling them keeps the diff cohesive.

---

## Pattern 8 — Factory schema-correctness sweep

**Closes 5 unique findings (2 P1 · 2 P2 · 1 P3):** TEST-E#TEST-1 (P1), TEST-E#TEST-2 (P1), TEST-E#TEST-6 (P2), TEST-E#TEST-7 (P2), TEST-E#TEST-8 (P3)

**Effort:** ~4h

### Root cause

Five model factories emit values that violate the v2 baseline schema. They survive in tests today only because `tests/TestCase.php`'s `setupProfessionalsTable()` helpers provision a forgiving subset of the real schema. Any test that uses the factory against real pgsql fails; any test that uses SQLite passes silently.

| Factory | Violation | Impact |
|---------|-----------|--------|
| `ProfessionalFactory` | Hardcodes `professional_type => 'affiliate'`. v2 baseline CHECK allows `['professional', 'influencer', 'barber', ...]` — `'affiliate'` is rejected. Also omits `phone` (NOT NULL, no default). | Any pgsql test calling `Professional::factory()->create()` fails with CHECK violation or NOT NULL violation. |
| `CommissionPayoutFactory` | Omits `eligible_after` (NOT NULL since v2 baseline) and `void_at` (NOT NULL since `20260428000000`). | Same — NOT NULL violation. |
| `OrderFactory` | Omits `line_items` (DB default `'[]'::jsonb`) and `shopify_data`. Trigger `trg_order_items_diff` has early-return on empty `line_items`, so factory-built orders never exercise the upsert/delete branch. | Tests asserting trigger population get `0 == 0` false-negatives — the test passes but the trigger never ran. |
| `CommissionPayoutItemFactory` | Sets `order_id => Str::uuid()` — random UUID with no `commerce.orders` row. FK `cpi_order_fk REFERENCES commerce.orders(id) ON DELETE RESTRICT` (added `20260506300000`) rejects on pgsql. | Tests using this factory against pgsql fail with FK violation; SQLite silently allows orphan. |
| `WalletMovementFactory` | No convenience state for same-key duplicate testing. Idempotency tests bypass the factory entirely with inline `['idempotency_key' => 'cs_topup_dup']`. | Workaround is awkward and not reusable. P3 because there's no correctness issue — just ergonomics. |

### What to do

- [ ] **Step 1 — `ProfessionalFactory`.** Change `professional_type` to a valid enum value (use `'influencer'` to match the most common test scenario). Add `'phone' => fake()->e164PhoneNumber()`. Optionally add a `brand()` state for brand-type professional tests.
- [ ] **Step 2 — `CommissionPayoutFactory`.** Add to the base `definition()`:
    ```php
    'eligible_after' => now()->toDateTimeString(),
    'void_at' => now()->addDays(60)->toDateTimeString(),
    ```
    Add states: `pendingFunds()`, `expiredGrace()`, `completed()` — each manipulates the relevant timestamp.
- [ ] **Step 3 — `OrderFactory`.** Add explicit `'line_items' => []` and `'shopify_data' => []` to the base definition (intent clarity over relying on DB defaults). Add a `withLineItem(array $override = [])` state that emits a valid Shopify line-item JSON shape (`shopify_line_item_id`, `title`, `quantity`, `unit_price_cents`, `commission_cents`, etc.) — this is what makes trigger-coverage tests actually exercise the trigger.
- [ ] **Step 4 — `CommissionPayoutItemFactory`.** Replace the random UUID with `Order::factory()` for auto-chained resolution:
    ```php
    'order_id' => Order::factory(),
    ```
    Add `withOrder(?Order $order = null)` for explicit linkage. Add `withPayout()` for payout-flow integration tests.
- [ ] **Step 5 — `WalletMovementFactory` (P3).** Add `withIdempotencyKey(string $key)` and `duplicateOf(WalletMovement $existing)` (copies the key from an existing movement — the most common idempotency-test shape).
- [ ] **Step 6 — Verification.** `php artisan test --compact tests/` against pgsql (set `DB_CONNECTION=pgsql` in `.env.testing`) should pass; against the default SQLite it should also pass. If any test that previously passed now fails, it was relying on the factory bug — fix the test, not the factory.

### Plain English

Five "model factories" — the small classes that generate fake test data — produce data that doesn't match what the real database accepts. Tests pass today because they use a lightweight test database that's more forgiving. Against the production database, the same tests would fail. The fix is updating each factory to match the real schema. This also unlocks more realistic tests (e.g., testing the trigger that runs when an order has line items).

### Why this is the foundation for Pattern 6

Pattern 6 (DB mock refactor) replaces `DB::shouldReceive()` chains with real test-DB inserts. Those inserts go through factories. If the factories are broken, the refactored tests fail. Therefore Pattern 8 must land first.

---

## Pattern 6 — DB mock → real-Postgres refactor

**Closes 1 unique finding combining 4 reports (1 P1):** TEST-D2#TEST-1, TEST-D2#TEST-2, TEST-D2#TEST-3 (3× P1 Analytics) + TEST-D3#TEST-1 (P2 broader 11-file sweep)

**Effort:** ~3 days (LARGE)

### Root cause

Eleven test files use `DB::shouldReceive('table')` to intercept the database facade at the Mockery layer:

| Directory | Files | Sample anti-pattern |
|-----------|-------|---------------------|
| `tests/Feature/Analytics/` | 3 (`AffiliateProjectionsControllerTest`, `AffiliateCommerceAnalyticsControllerTest`, `BrandCommerceAnalyticsControllerTest`) | `DB::shouldReceive('table')->with('commerce.commission_payouts')->andReturn(...)` |
| `tests/Feature/Staff/` | 7 (`StaffAffiliateControllerTest`, `StaffAffiliateStatusControllerTest`, `StaffCommissionControllerTest`, `StaffInviteControllerTest`, `StaffIntegrationControllerTest`, `StaffPayoutListControllerTest`, `StaffStatsControllerTest`) | Same — facade-level intercepts. |
| `tests/Unit/Services/Analytics/` | 1 (`AffiliateProjectionsServiceTest`) | Same. |

`DB::shouldReceive('table')` intercepts at the facade level: any call to `DB::table('commerce.commission_payouts')` returns pre-programmed mock data regardless of chained joins, wheres, columns, or schema state. The brand analytics pipeline reads from `commerce.brand_affiliate_rollup` (trigger-maintained); if the trigger changes its computation, mocks return pre-trigger shape and CI stays green while production is stale. **A migration renaming `commission_ledger_entries → commission_movements` (which happened in `20260506600000`) is invisible** — the mock matches the old string. The correct pattern exists already: `BackfillOrdersPayoutIdTest.php` and `AffiliateCommercePaidGateTest.php` connect to real Postgres, insert via factories, and assert against actual SQL results.

The `BrandCommerceAnalyticsControllerTest` is the worst offender — it also uses stateful `$callCount` tracking that couples test outcomes to internal query execution order, breaking on safe refactors.

### What to do

- [ ] **Step 1 — Sequence.** Land Pattern 8 (factory fixes) first; this work depends on factories producing valid pgsql rows.
- [ ] **Step 2 — Refactor in three bundles.** Three PRs, not one — 11 files is too much for a single review:
    - **PR 1: Analytics controllers (3 files)** — `AffiliateProjectionsControllerTest`, `AffiliateCommerceAnalyticsControllerTest`, `BrandCommerceAnalyticsControllerTest`. Highest leverage (analytics pipeline correctness) and biggest gain from real triggers running.
    - **PR 2: Staff controllers (7 files)** — uniform structure; refactor in one pass.
    - **PR 3: Service unit test (1 file)** — `AffiliateProjectionsServiceTest`. Smallest, ships last.
- [ ] **Step 3 — Per-file refactor pattern.**
    ```php
    // BEFORE
    DB::shouldReceive('table')->with('commerce.commission_payouts')
        ->andReturn($this->affiliateStubDbConnection());

    // AFTER (using Pattern 8 factories)
    $brand = Professional::factory()->brand()->create();
    $affiliate = Professional::factory()->create();
    CommissionPayout::factory()
        ->for($brand, 'brand')
        ->for($affiliate, 'affiliate')
        ->count(3)
        ->create(['gross_cents' => 10000]);
    ```
    Guard pgsql-only paths with `markTestSkipped('Requires pgsql')` so SQLite test runs stay green during local dev. Follow `BackfillOrdersPayoutIdTest.php`'s setup conventions.
- [ ] **Step 4 — Keep `CacheLockService` mocks.** Infrastructure-layer mocks (Stripe SDK, Shopify SDK, `CacheLockService` callback passthrough) are correct and stay. Only DB-layer mocks change.
- [ ] **Step 5 — Eliminate stateful `$callCount` in `BrandCommerceAnalyticsControllerTest`.** Once real queries replace mocks, the call-count coupling disappears naturally.
- [ ] **Step 6 — Add CI lint to prevent regression.** A grep guard in CI:
    ```yaml
    - name: Forbid DB::shouldReceive in tests
      run: |
        if rg "DB::shouldReceive" tests/ ; then
          echo "DB::shouldReceive forbidden — use real test-DB inserts. See audits/phase-5-tests/remediation-plan.md Pattern 6."
          exit 1
        fi
    ```

### Plain English

Eleven test files use a trick where the database layer is replaced with a fake that returns canned answers. If we rename a column or change how a trigger works, those fake answers stay the same, the tests pass, and the change is invisible until production breaks. The fix is to swap the fake database for the real one — slower but accurate. This work also retroactively exercises the cache and trigger logic that earlier phases built, so it's a force-multiplier across the whole audit.

### Why this is a P1 (despite being P2 in the broader sweep audit)

The three Analytics findings were tiered P1 because they directly fronts the brand and affiliate dashboards — the screens that show pilot brands their performance. The broader 11-file sweep was tiered P2 because most of the Staff controllers exercise less-critical paths. Treating the whole cluster as P1 simplifies sequencing: refactor all 11 in one campaign rather than two phases six months apart.

---

## Pattern 9 — Database trigger behavioral test sweep

**Closes 2 unique findings (2 P1):** TEST-E#TEST-3 (rollup), TEST-E#TEST-4 (URL-sync)

**Effort:** ~6h

### Root cause

Two trigger families run silently and have **no behavioral test** in CI:

1. **`rollup_apply_delta()`** (from `20260506000000_create_orders_schema.sql`) maintains `commerce.brand_affiliate_rollup` per-day-per-brand-per-affiliate. Every brand dashboard, every affiliate commission total, every payout eligibility check reads from this table. The trigger has five distinct paths: INSERT approved order, UPDATE refund delta, terminal reversal, stub INSERT, stub-to-approved promotion. The proportional `_reversed_delta` under partial refunds uses `COALESCE(ROUND(...), 0)` and `NULLIF(gross_cents, 0)` guards — these are arithmetic-safety belts whose zero-rounding correctness is unverified. The existing `tests/Feature/Commerce/OrdersSchemaMigrationTest.php` is explicitly structural-only (`->toContain()` on SQL text) — comment in the file states: "actual Postgres behavior validated during Phase 2 backfill, not in CI."

2. **Five URL-sync triggers** across three schemas (`20260508100000_url_columns_and_triggers.sql`): `core.professionals.partna_url` and `brand.brand_partner_links.site_url`. A critical NULL-guard bug was caught and fixed in `20260508200000` (added `IF v_url IS NOT NULL` to `trg_recompute_partna_url`). If that regression returned, every affiliate URL silently wipes on a brand site-settings update. The handle-alias collision check (Trigger 5, fixed in `20260508700000`) is the sole enforcement of the redirect-reservation invariant — zero coverage.

### What to do

- [ ] **Step 1 — Rollup trigger test.** Create `tests/Feature/Commerce/RollupTriggerTest.php` (pgsql-only, `markTestSkipped` on SQLite). Cover the five paths:
    ```php
    it('inserts a rollup row when an approved order is inserted');
    it('updates the rollup with proportional delta when an order is refunded');
    it('applies the terminal reversal when order status becomes void');
    it('inserts a stub rollup row when a stub order is inserted (status=stub)');
    it('promotes the rollup when a stub order is promoted to approved');
    it('no-ops when an UPDATE does not change gross_cents or status');
    it('handles zero-gross divide-by-zero safely via NULLIF guard');
    ```
- [ ] **Step 2 — URL-sync trigger test.** Create `tests/Feature/Migrations/UrlSyncTriggerTest.php` (pgsql-only). Cover:
    ```php
    it('populates partna_url on a fresh site INSERT');
    it('cascades subdomain UPDATE through to professional.partna_url');
    it('records an alias when handle changes');
    it('rejects on handle-alias collision (raises exception)');
    it('populates brand_partner_links.site_url on INSERT');
    it('leaves partna_url NULL when no site row exists (NULL-guard regression test)');
    ```
- [ ] **Step 3 — Use Pattern 8 factories.** These tests are the natural consumers of the fixed `OrderFactory.withLineItem()` state and `ProfessionalFactory`'s schema-valid `professional_type`. Land Pattern 8 first.

### Plain English

The database has triggers — small programs that run automatically when data changes — that maintain rollup totals and URL paths for every affiliate and brand. These triggers are critical (the brand dashboard reads them, every affiliate link is generated by them) and have no automated test. A bug in any of them is invisible until production shows wrong numbers. The fix is two new test files that insert real data and check the trigger did the right thing.

### Why this is a P1

The rollup trigger is the read path for every brand and affiliate dashboard. A regression silently produces wrong numbers. The URL trigger had a near-miss bug (the NULL-guard was added two days after the original migration) — that demonstrates the risk is real, not theoretical.

---

## Pattern 10 — Schema constraint behavioral test sweep

**Closes 1 unique finding from 4 reports (1 P2):** TEST-A#TEST-5 ≡ TEST-E#TEST-5 ≡ TEST-D1#TEST-6 ≡ TEST-D2#TEST-5

**Effort:** ~5h

### Root cause

Three migration test files inspect SQL text only via `file_get_contents()` + `->toContain()`:

- `tests/Feature/Commerce/OrdersSchemaMigrationTest.php`
- `tests/Feature/Commerce/LedgerRenameMigrationTest.php`
- `tests/Feature/Commerce/LegacyAggregatesDroppedMigrationTest.php`

These catch *structural* drift (a constraint keyword removed from the migration file) but not *behavioral* regressions:

- A CHECK constraint with a typo (`CHECK (status IN ('pauot', ...))` vs `'payout'`) passes the text test.
- A UNIQUE that's defined but not actually enforced (e.g., column dropped, constraint name shadowed) passes the text test.
- A trigger that's installed but silently doesn't fire passes the text test.

The reference pattern exists in `BackfillOrdersPayoutIdTest.php`: connect to real pgsql, run schema migrations, attempt INSERTs that violate the constraint, assert `QueryException` is thrown.

### What to do

- [ ] **Step 1 — Add `tests/Feature/Migrations/SchemaConstraintCoverageTest.php`** — modeled on `tests/Feature/Security/PolicyCoverageTest.php` (the "enumerate surface, check against registry, fail on gaps" pattern). Two test bodies:
    - **Existence sweep:** query `information_schema.table_constraints` and `pg_constraint`, assert presence of every constraint listed in a hardcoded registry. The registry is the single source of truth for what *must* exist.
    - **Behavioral assertions:** for each critical constraint, attempt a violating INSERT and assert `QueryException` is thrown. Minimum set:
        ```php
        // UNIQUE
        it('rejects duplicate shopify_event_id on commerce.order_events');
        it('rejects duplicate stripe_event_id on billing.webhook_events');
        it('rejects duplicate idempotency_key on commerce.wallet_movements');

        // CHECK
        it('rejects invalid entry_type on commerce.commission_movements');
        it('rejects invalid rate_source on commerce.orders');
        it('rejects invalid failure_category on commerce.commission_payouts');
        it('rejects funding_failure_count > 50 on commerce.commission_payouts');
        it('rejects invalid status on commerce.commission_payouts');

        // FK
        it('rejects orphan order_id on commerce.commission_payout_items');
        ```
- [ ] **Step 2 — Annotate text-only tests.** Add a top-of-file comment to the three existing migration tests:
    ```php
    // Structural sanity only — text inspection. Behavioral assertions live in
    // tests/Feature/Migrations/SchemaConstraintCoverageTest.php (pgsql-only).
    ```
- [ ] **Step 3 — Add CI lint.** Once the registry exists, a CI check can warn on new constraints in `supabase/migrations/` that aren't registered in `SchemaConstraintCoverageTest`. This is the same shape as `PolicyCoverageTest`'s sweep.
- [ ] **Step 4 — Use raw `DB::statement()`, not Eloquent.** Eloquent's enum casts will reject invalid values *before* they hit Postgres, masking the DB-level CHECK. Per the existing `BackfillOrdersPayoutIdTest` pattern, use raw `DB::statement('INSERT INTO ...')` for violation tests.

### Plain English

Every important table has rules — "this column must be one of these five values," "this combination must be unique," "this row must reference an existing row over there." Today we check those rules exist by looking at the migration files as text. We don't check they actually work. If someone typos a rule, the text check passes and the database is silently less-guarded. The fix is one new test file that tries to insert bad data and confirms the database rejects it.

### Why this is foundational (despite P2 tier)

This test becomes the "load-bearing rules registry" for the schema. Phase 6 Pattern 7 adds 10+ new CHECK constraints; if Pattern 10 lands first, those constraints get behavioral coverage automatically. If Pattern 10 lands after, this is the natural test file where they get added. Either way it's the single artifact that makes schema-correctness assertions auditable.

---

## Pattern 11 — Auth middleware test sweep

**Closes 4 unique findings (3 P2 · 1 P3):** TEST-C#TEST-3, TEST-C#TEST-4, TEST-C#TEST-5, TEST-C#TEST-6

**Effort:** ~5h

### Root cause

Three middlewares have zero or near-zero test coverage, despite each guarding security-critical paths. The proven pattern exists in `VerifyHydrogenApiKeyTest` (6 scenarios) and `EnsurePartnaStaffMiddlewareTest` (8 scenarios).

| Middleware | Coverage today | Gap |
|------------|---------------|-----|
| `VerifySupabaseJwt` | `VerifySupabaseJwtFallbackTest` forces JWKS to fail, exercises the Auth-Server fallback only. | **Primary JWKS path completely untested.** The critical `in_array($alg, ['RS256', 'ES256'], true)` guard that blocks HS256 alg-confusion attacks — explicitly commented as preventing "HS256 signed with the public key as the HMAC secret" — has never been exercised. A regression would silently accept forged tokens. |
| `VerifyEmbeddedApiKey` | None. | `/internal/embedded/*` routes guard the deployment-token endpoint that rewrites a brand's Shopify storefront. Same fail-closed shape as `VerifyHydrogenApiKey` (got 6 tests in `ea994cf`); this got zero. |
| `VerifyShopifySessionToken` | None. | Shopify admin UI extension endpoints. Audience claim check (`hash_equals`) is the sole guard against cross-app token replay. Untested. |
| `EnsurePartnaAdmin` | 403-for-non-admin tested incidentally in `AdminInitiatedDeletionTest`. | Admin-pass (200) and missing-uid (401) paths absent from any dedicated test. |

### What to do

- [ ] **Step 1 — `VerifySupabaseJwt` primary path.** Create `tests/Unit/Auth/VerifySupabaseJwtPrimaryPathTest.php`:
    ```php
    it('rejects missing bearer token with 401');
    it('rejects malformed JWT (not 3 parts) with 401');
    it('rejects alg=HS256 with 401 (alg-confusion guard)');
    it('rejects alg=RS256 with missing kid with 401');
    it('rejects when JWKS is empty with 401');
    it('accepts valid RS256 token and extracts uid', function () {
        // Generate test RSA keypair, sign a token, mock JWKS to return the pub key.
    });
    it('rejects iss/aud mismatch with 401');
    ```
- [ ] **Step 2 — `VerifyEmbeddedApiKey`.** Create `tests/Feature/Security/VerifyEmbeddedApiKeyTest.php` mirroring `VerifyHydrogenApiKeyTest`:
    ```php
    it('bypasses in local/testing when no key configured');
    it('returns 500 in production when no key configured (fails closed)');
    it('accepts valid key + shop header, resolves embedded_professional_id');
    it('rejects with 403 on invalid key');
    it('rejects with 400 on missing shop header');
    it('returns 404 shop_not_connected when ShopifyShopResolver returns null');
    ```
- [ ] **Step 3 — `VerifyShopifySessionToken`.** Create `tests/Feature/Auth/VerifyShopifySessionTokenTest.php`:
    ```php
    it('rejects missing token with 401');
    it('rejects invalid signature with 401');
    it('rejects audience mismatch with 401 (cross-app replay guard)');
    it('rejects missing/malformed dest claim with 401');
    it('rejects non-myshopify.com destination with 401');
    it('returns 404 shop_not_connected when ShopifyShopResolver returns null');
    it('accepts valid token and resolves embedded_professional_id');
    it('returns 500 when SHOPIFY_API_SECRET not configured');
    ```
    Use `Firebase\JWT\JWT::encode` with a test secret in `beforeEach`.
- [ ] **Step 4 — `EnsurePartnaAdmin`.** Create `tests/Feature/Staff/EnsurePartnaAdminMiddlewareTest.php` mirroring `EnsurePartnaStaffMiddlewareTest`:
    ```php
    it('admin staff passes through to 200');
    it('support-role staff rejected with 403');
    it('missing supabase_uid rejected with 401');
    it('staff record not found rejected with 403');
    ```

### Plain English

Four front-door ID checks have no dedicated tests. One of them prevents a specific type of forged token. Another guards the door that lets us update a brand's online store. Another guards Shopify admin extensions against tokens issued by *other* Shopify apps being replayed. The fourth is the senior-admin badge check. We already have proven test scaffolds for the structurally-identical sibling middlewares; the fix is four small test files following those proven patterns.

### Why these bundle

All four are auth middlewares with identical proven scaffolds elsewhere in the suite. Reviewer mental model is uniform: bypass behavior, fail-closed, signature/header validation. One PR for all four; clean to review.

---

## Pattern 12 — Resource snapshot test sweep

**Closes 1 unique finding from 2 reports (1 P2):** TEST-D1#TEST-3 (specific BrandStoreSettingsResource case) ⊂ TEST-D2#TEST-4 (broad sweep)

**Effort:** ~5h

### Root cause

`app/Http/Resources/` contains 20+ Resource classes; only 2 have snapshot tests. Resource classes are the *sole* API contract layer — they transform Eloquent models into the JSON shape returned to clients. Without snapshot tests:

- Column renames have only half CI enforcement (the migration ↔ Resource `toArray()` mismatch goes undetected until a frontend breaks).
- Accidental field exposure (e.g., a private `professional_id` appearing on a public endpoint) has no automated guard.
- Boolean-redaction invariants (`oxygen_token_set` exposed as `bool`, not the raw token; `brand_funding` redacted on `AffiliatePayoutResource`) can silently regress.

The specific TEST-D1 case (`BrandStoreSettingsResource`) is a representative: the resource converts `oxygen_token_set` to boolean with `(bool) ($this->resource['oxygen_token_set'] ?? false)`. A refactor that removes the cast would leak the raw token via the API. No test catches this.

Reference patterns exist:
- `tests/Feature/Resources/ProfessionalPublicResourceTest.php` — snapshot test on a public-facing resource.
- `tests/Feature/Resources/ProfessionalResourceTest.php` — snapshot test on an authenticated resource.
- `tests/Feature/Stripe/AffiliatePayoutsListTest.php:127–146` — asserts `brand_funding` is null (redaction invariant).

### What to do

- [ ] **Step 1 — Enumerate the surface.** `rg "extends JsonResource" app/Http/Resources/` returns the full list. Prioritize for first PR:
    - **Financial / cross-tenant** (highest risk): `WalletMovementResource`, `CommissionPayoutResource`, `BrandStoreSettingsResource`, `AffiliatePayoutResource` (extend existing test), `BrandPartnerLinkResource`, `AffiliateProductResource`, `SiteResource`.
- [ ] **Step 2 — Per-resource test pattern.**
    ```php
    it('serializes BrandStoreSettings with oxygen_token_set as boolean only', function () {
        $resource = new BrandStoreSettingsResource([
            'oxygen_token_set' => 'sho_xxxx_secret_token',
        ]);
        $json = $resource->toArray(request());
        expect($json['oxygen_token_set'])->toBe(true);
        expect($json)->not->toHaveKey('oxygen_token'); // raw token never present
    });

    it('serializes BrandStoreSettings with empty oxygen_token_set as false', function () {
        $resource = new BrandStoreSettingsResource(['oxygen_token_set' => '']);
        expect($resource->toArray(request())['oxygen_token_set'])->toBe(false);
    });
    ```
    For cross-tenant resources, explicitly assert internal fields (`professional_id`, `*_cents` where not intended) are absent.
- [ ] **Step 3 — Use `assertJsonStructure` + `assertExactJson` for HTTP-layer tests.** For Resources that ship through controllers, layer the snapshot at the HTTP level (using `getJson()->assertExactJson(...)`) rather than via direct construction.
- [ ] **Step 4 — Future-proof.** Add a top-of-file comment in each new test referencing this remediation plan, so future Resource additions can grep and find the pattern.

### Plain English

When the app sends data to the frontend or to Hydrogen, it passes through "Resource" classes that decide which fields are public and which are hidden. Most of these classes have no test, so a refactor that accidentally exposes an internal field (like a Shopify access token) or hides a field the frontend depends on would be invisible until something breaks. The fix is to add snapshot tests for the highest-risk Resources — financial data and cross-tenant data first.

---

## Pattern 14 — Stripe billing webhook coverage gaps

**Closes 2 unique findings (2 P2):** TEST-A#TEST-7 (malformed payload), TEST-A#TEST-9 ≡ TEST-D1#TEST-2 (HTTP-layer dedup)

**Effort:** ~2h

### Root cause

`tests/Feature/Stripe/StripeWebhookControllerTest.php` contains exactly three tests, all scoped to signature verification. Two gaps:

1. **Malformed JSON.** No test posts a non-JSON body with a valid signature. The handler's `json_decode` failure throws `UnexpectedValueException`; uncaught, the framework returns 500. Stripe interprets 500 as transient and retries, amplifying Nightwatch error volume.
2. **HTTP-layer dedup.** `billing.webhook_events` has UNIQUE on `stripe_event_id`. The Connect webhook (parallel architecture) has `StripeConnectWebhookDedupeTest`; the billing webhook has nothing. Service-layer dedup via the UNIQUE constraint is real and tested in `WalletMovementsLedgerTest`, but HTTP-layer guard is defense-in-depth and is the cheaper path (no service spawn for replays).

### What to do

- [ ] **Step 1 — Add malformed-payload test.**
    ```php
    it('returns 400 on malformed JSON payload with valid signature', function () {
        $signature = $this->signPayload('not-json-data');
        postJson('/api/webhooks/stripe', /* invalid body */, ['Stripe-Signature' => $signature])
            ->assertStatus(400);
    });
    it('returns 200 on unknown event type without crashing');
    ```
- [ ] **Step 2 — Add HTTP-layer dedup test (mirror `StripeConnectWebhookDedupeTest`).**
    ```php
    it('returns 200 without re-invoking handler on duplicate stripe_event_id', function () {
        $eventId = 'evt_abc123';
        DB::table('billing.webhook_events')->insert([
            'stripe_event_id' => $eventId,
            'created_at' => now(),
        ]);

        $spy = Mockery::spy(StripeWebhookHandler::class);
        app()->instance(StripeWebhookHandler::class, $spy);

        postJson('/api/webhooks/stripe', $this->signedEvent($eventId, 'invoice.paid'))
            ->assertStatus(200);

        $spy->shouldNotHaveReceived('handle');
    });
    ```

### Plain English

The Stripe billing webhook has three tests for its signature check but none for two predictable failure modes — getting a corrupt payload (Stripe's retry will resend) and getting the same event twice (Stripe's at-least-once delivery means this happens routinely). Both are short to add and protect against retry storms.

---

## Pattern 13 — Residual policy ability coverage sweep

**Closes 1 unique finding (1 P3):** TEST-D2#TEST-6

**Effort:** ~6h

### Root cause

After Patterns 1 and 2 close `WalletMovementPolicy` and `CommissionPolicy`, 11 policies remain with ability-method coverage gaps in `tests/Unit/Policies/`. `PolicyCoverageTest` enforces *registration* (every model has a policy registered) — it does not enforce *behavioral* coverage (each ability method has unit tests). A method returning `true` unconditionally, or allowing the wrong `professional_type`, passes all CI.

The reference pattern is `tests/Unit/Policies/CommissionPolicyTest.php`: construct policy directly, inject `forceFill`'d models, assert each ability with owned/unowned/wrong-type actors.

### What to do

- [ ] **Step 1 — Enumerate the surface.** `rg "extends BasePolicy" app/Policies/` returns the list. Exclude `BasePolicy` itself. The list after Patterns 1+2: `AffiliateProductPolicy`, `BrandPartnerLinkPolicy`, `BrandResourcePolicy`, `CustomerPolicy`, `GdprPolicy`, `IntegrationPolicy`, `NotificationPolicy`, `ProfessionalSelfPolicy`, `ServicePolicy`, `SitePolicy`, `SubscriptionPolicy`.
- [ ] **Step 2 — Per-policy test.** Create `tests/Unit/Policies/<Name>PolicyTest.php` for each, following `CommissionPolicyTest`:
    ```php
    foreach (policyMethods() as $method) {
        it("allows {$method} for the owning professional");
        it("denies {$method} with 404 for a different professional");
        it("denies {$method} for a wrong-type professional"); // where applicable
        it("denies {$method} when actor is pending deletion (denyIfPendingDeletion)"); // where applicable
    }
    ```
- [ ] **Step 3 — Add a behavioral sweep test.** Modeled on `PolicyCoverageTest`: enumerate all ability methods across all policies, assert each has at least one unit test calling it. Use reflection. This makes future policy additions self-documenting.

### Plain English

Right now we have a test confirming every model has a policy attached to it. We don't have tests confirming each policy's individual rules work correctly. If someone refactors a policy and accidentally makes one rule say "yes" to everyone, no automated check catches it. The fix is one unit test per remaining policy (11 of them), following the proven pattern that already exists for `CommissionPolicy`.

---

# Part 2 — Standalone fixes

These two findings don't bundle cleanly with any pattern. Each is its own PR.

## Standalone #1 — `UpdateSiteRequest` form-request test

**Closes:** TEST-D1#TEST-4 (P2)
**Effort:** ~3h

`app/Http/Requests/Api/Professional/Site/UpdateSiteRequest.php` carries 15+ `prohibited` rules (retired design key blocklist), three `Rule::in()` enum constraints (3-bucket design system), a `font_family` allowlist, and a multi-table subdomain-uniqueness closure. **No dedicated test exists** — only HTTP integration tests hitting `PATCH /site`, which assert response shape but not the validation contract.

Add `tests/Feature/Requests/UpdateSiteRequestTest.php` mirroring the pattern in `tests/Feature/Validation/LinkBlockSocialValidationTest.php`. Scenarios:
- Passing a `prohibited` legacy key (e.g., `settings.design.border_color`) → 422.
- Each 3-bucket enum rejects out-of-range value → 422.
- `font_family` rejects values not in allowlist → 422.
- Subdomain uniqueness blocks a taken subdomain but allows the brand's current subdomain.
- `is_published: true` with no `display_name` → validation error.

## Standalone #2 — Wallet credit concurrent idempotency test

**Closes:** TEST-D1#TEST-5 (P2)
**Effort:** ~3h

`tests/Feature/Stripe/WalletMovementsLedgerTest.php:100` calls `creditWalletFromCheckoutSession` twice *sequentially* with the same session ID and asserts one row. This proves the UNIQUE constraint on `idempotency_key` works at the DB level — but does not prove the application-level lock prevents a race between balance-read and balance-write under genuine concurrency.

Add a concurrent variant using `pcntl_fork()` or parallel HTTP requests. Assert exactly one `WalletMovement` row exists and the balance reflects exactly one credit (no torn state where balance=10000 with only one row).

Alternative if `pcntl_fork()` is unavailable in the test environment: document the limitation explicitly in a comment on the existing sequential test, noting that the UNIQUE constraint is the sole concurrent guard and any future refactor that removes the constraint must add the missing concurrent test first.

---

# Part 3 — Sequencing summary

```
Week 1 (P1 must-fix before pilot):
  Day 1: Pattern 1 (WalletMovementPolicy)           1h
         Pattern 2 (CommissionPolicy methods)        3h
         Pattern 3 (StripeConnect disconnect)        2h
  Day 2: Pattern 4 (payout job orchestration)        4h
         Pattern 5 (Shopify webhook controllers)     start
  Day 3: Pattern 5 (continue)                        6h total
         Pattern 7 (job failed() sweep)              5h
  Day 4: Pattern 8 (factory schema sweep)            4h
         Pattern 9 (trigger behavioral tests)        start
  Day 5: Pattern 9 (continue)                        6h total

Week 2 (P2 + P3 + foundational sweeps):
  Day 6: Pattern 6 (DB mock refactor PR 1: Analytics — 3 files)
  Day 7: Pattern 6 (DB mock refactor PR 2: Staff — 7 files)
  Day 8: Pattern 6 (DB mock refactor PR 3: Service unit)
         Pattern 10 (schema constraint coverage)     5h
  Day 9: Pattern 11 (auth middleware sweep)          5h
         Pattern 12 (Resource snapshot sweep)        5h
         Pattern 14 (Stripe billing webhook)         2h
  Day 10 (optional): Pattern 13 (residual policy ability coverage)  6h
                     Standalone 1 (UpdateSiteRequest)                3h
                     Standalone 2 (Wallet concurrent)                3h
```

**Critical dependencies:**
- Pattern 6 (DB mock refactor) depends on Pattern 8 (factories must produce valid pgsql rows first).
- Pattern 3 (StripeConnect disconnect tests) is best paired with Phase 6 Pattern 1 (the `'disconnected'` CHECK fix) so the end-to-end webhook test passes.
- Pattern 10 (schema constraint coverage) is the natural home for Phase 6 Pattern 7's new CHECK constraints — coordinate the registry ownership.

**Suggested PR count:** ~16 PRs (12 patterns + 2 standalone + DB-mock refactor split into 3). One pattern = one PR except Pattern 6 (3 PRs by directory) and Pattern 5 (one PR for all 4 webhook controllers).
