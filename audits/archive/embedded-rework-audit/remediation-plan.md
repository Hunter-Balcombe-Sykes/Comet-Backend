# Embedded Rework — Consolidated Remediation Plan

**Date:** 2026-05-15
**Branch:** development
**Source:** 6 lens-focused audits across `audits/embedded-rework-2026-05-15/`, adjudicated by `claude-sonnet-4-6` over `deepseek-v4-pro` drafts
**Scope:** the embedded Shopify admin app surface — 5 `Embedded*` controllers, `VerifyShopifySessionToken` middleware, 7 form requests, `ShopifyShopResolver`, `ShopifyAppUninstalledWebhookController`, and the 4 embedded test files (~2,541 lines of source + tests)

## Summary

- **31 reported findings**, **28 unique** after cross-phase deduplication (3 cross-phase duplicates collapse)
- **Tier breakdown (reported):** 0 P0 · 7 P1 · 18 P2 · 6 P3
- **Tier breakdown (unique):** 0 P0 · 6 P1 · 16 P2 · 6 P3
- **Four foundational patterns close 16 of 28 unique findings** (5 P1 · 10 P2 · 1 P3)
- **12 standalone fixes** for the rest (1 P1 · 6 P2 · 5 P3)
- **Estimated total:** ~10–14 days of focused work to close all 28 unique findings

**Headline takeaway:** the embedded auth middleware (`VerifyShopifySessionToken`) and `EmbeddedConnectController` (post-rework) are clean — no P0/P1 against either. The remaining P1 cluster concentrates on three files: `ShopifyAppUninstalledWebhookController` (dedup + reconcile), `EmbeddedSetupController::provisionShopifyIntegration` (sync validation + race), and `EmbeddedProductSettingsController` (missing Form Request).

## Cross-phase duplicates (collapse on fix)

| Finding | Phases · IDs | Same root cause | Canonical tier |
|---------|--------------|-----------------|----------------|
| `ShopifyAppUninstalledWebhookController` missing `X-Shopify-Webhook-Id` dedup | Security SEC-2 ≡ Lifecycle LIFE-2 | One fix: add `Cache::add(...)` after HMAC | **P1** |
| Synchronous `validateShopifyAccessToken` on every embedded page load | Lifecycle LIFE-4 ≡ Database SCALE-1 | One fix: gate by `$isNoOpRefresh` and/or cache by token hash | **P1** (take the higher tier of the pair) |
| `EmbeddedProductSettingsController::show()` 3 uncached Shopify API calls per mount | Scaling CACHE-1 ≡ Database SCALE-2 | One fix: wrap in `rememberLocked` | **P2** |

When the canonical fix lands, all duplicate checkboxes flip together.

## Source audit files

- `audit-2026-05-15-security.md` (5 findings: 2 P1, 2 P2, 1 P3)
- `audit-2026-05-15-lifecycle.md` (7 findings: 2 P1, 3 P2, 2 P3)
- `audit-2026-05-15-scaling.md` (3 findings: 2 P2, 1 P3)
- `audit-2026-05-15-database.md` (6 findings: 1 P1, 3 P2, 2 P3)
- `audit-2026-05-15-tests.md` (8 findings: 1 P1, 7 P2)
- `audit-2026-05-15-data.md` (2 findings: 1 P1, 1 P2)

---

# Part 1 — Foundational fixes

These four patterns are sequenced by tier-impact-per-day (findings closed per day of work). Recommended landing order: Pattern A first (highest tier impact per hour), then B (unblocks Pattern D's test-author work), then C (controller-local cleanup), then D (the long tail).

## Pattern A — `app/uninstalled` webhook hardening (dedup + reconcile)

**Closes 3 unique findings (2 P1 · 1 P2):** SEC-2 (≡ LIFE-2), LIFE-1, TEST-6

**Effort:** ~4–6h (one half-day session)

### Root cause

`ShopifyAppUninstalledWebhookController` pre-dates the `HandlesShopifyWebhook` trait that all other Shopify webhook controllers adopted. It performs inline mutations (`integration->update()` + `BrandProfile::update()`) rather than dispatching a job, so the trait can't be dropped in directly — but the **canonical dedup pattern** (`Cache::add("shopify:webhook:app-uninstalled:{$webhookId}", true, ttl)` after HMAC) absolutely can be. Compounding this, no reconcile job exists for missed `app/uninstalled` deliveries — Shopify's documented at-least-once-occasionally-zero guarantee means a brand can be left in a Connected-but-invalid state with a 401-ing access token, and the only recovery path is an Ops ticket. The existing uninstall test never asserts the `BrandProfile.brand_status → Disconnected` transition either, so a silent regression on the authoritative state-machine write would not be caught.

### What to do

- [ ] **Step 1 — Add inline dedup gate after HMAC.** In `ShopifyAppUninstalledWebhookController::__invoke()`, after the `isValidShopifyHmac` check, read `X-Shopify-Webhook-Id`. Atomically claim via `Cache::add("shopify:webhook:app-uninstalled:{$webhookId}", true, config('partna.cache.ttls.webhook_idempotency'))` — on `false` (duplicate), return `$this->success(['received' => true])`. If `X-Shopify-Webhook-Id` is absent (manual test delivery only), fall through. **Closes SEC-2/LIFE-2.**
- [ ] **Step 2 — Secondary idempotency guard.** Before executing the `integration->update()` mutations, check `$metadata['disconnected_at']` — if already set, return 200 immediately. This makes the handler idempotent even when the cache TTL has expired between Shopify's 48-hour retry window and the first delivery.
- [ ] **Step 3 — Create `ReconcileStuckShopifyIntegrationsJob`.** Mirror the shape of `ReconcileStuckPayoutsJob` (canonical model in `app/Jobs/Stripe/`). Query `professional_integrations` where `provider = 'shopify'` AND `access_token IS NOT NULL` AND `provider_metadata->>'disconnected_at' IS NULL`. For each, `HEAD https://{shop}/admin/api/{version}/shop.json` with the stored token. On 401 or `myshopify_domain` mismatch: null the token, write `disconnected_at` + `disconnected_reason = 'reconcile_detected_revocation'`, then sync brand status. Emit `shopify.reconcile.healed` log with `professional_id` + `shop_domain` on every auto-heal. **Closes LIFE-1.**
- [ ] **Step 4 — Schedule daily.** Add `Schedule::job(ReconcileStuckShopifyIntegrationsJob::class)->dailyAt('02:03')` to `routes/console.php`. Pick an off-peak slot that doesn't collide with `ReconcileStuckPayoutsJob`.
- [ ] **Step 5 — Add Nightwatch alert.** Alert on integrations where `access_token IS NULL` AND `brand_status != 'disconnected'` for > 7 days — the silent-drift case the reconcile job catches but doesn't immediately resolve if status sync fails.
- [ ] **Step 6 — Extend the existing test.** In `ShopifyAppUninstalledWebhookControllerTest`, the `'valid HMAC clears access_token'` test currently asserts `professional_integrations` columns but never seeds a `brand_profiles` row, so the `update(0 rows)` silent no-op is indistinguishable from a correct write. Add a `brand.brand_profiles` row in `beforeEach` and assert `brand_status === 'disconnected'` and `setup_complete === false` after the webhook. Add a separate `it('second delivery is idempotent — already-disconnected state unchanged')` that calls the webhook twice with the same valid HMAC. **Closes TEST-6.**

### Plain English

When a brand removes the Partna app from their Shopify store, Shopify sends a "goodbye" signal. Three things can go wrong today:

1. Shopify retries the signal (at-least-once delivery), but our uninstall door has no memory of who has knocked before — so a second delivery after a reinstall wipes the freshly-issued access key and locks the brand out.
2. The signal can be silently lost. We have no daily check to ask Shopify "are these brands still installed?", so brands stay "Connected" with an invalid key, and every background job for them fails with no recovery.
3. The test that proves the uninstall flow works never actually checks that the brand's status changes to Disconnected — so a future bug that breaks that part is invisible.

This pattern fixes all three: write down each knock's ID and ignore repeats, run a nightly health check that auto-heals stuck brands, and add the missing test assertion.

### Why this is the highest-leverage fix

Two independent audit lenses flagged the dedup gap, and a third flagged the missing reconcile loop. The trio is the entire correctness story for `app/uninstalled`. The fix is small in code volume (~80 lines) and closes two P1s in one half-day session — best tier-impact-per-hour in the plan.

---

## Pattern B — `provisionShopifyIntegration` correctness (vendor I/O + race-safety + tests)

**Closes 3 unique findings (2 P1 · 1 P2):** SCALE-1 (≡ LIFE-4), LIFE-3, TEST-1

**Effort:** ~1–2 days

### Root cause

`EmbeddedSetupController::provisionShopifyIntegration` is the most branch-heavy endpoint in the backend — fired on every embedded admin page load, it validates a Shopify token via a synchronous HTTP call, evaluates five independent boolean flags, dispatches up to six jobs, and conditionally skips cache + status sync. Three structural issues compound:

1. **`$isNoOpRefresh` is computed at line ~723 but `validateShopifyAccessToken()` already ran at line ~734** — the no-op path optimisation (skip cache invalidate + status sync) lands at line ~808, *after* the expensive vendor call. So a brand that reloads the admin tab 10 times in an hour incurs 10 sequential Shopify REST round-trips against an unchanged token.
2. **The JSONB metadata merge has no row lock.** Two concurrent page loads (multiple admin tabs, Remix SSR fan-out) both read `$existingMetadata`, merge their respective changes, and write — second writer wins. A lost `webhook_registration_state = 'registered'` reset to `'queued'` re-dispatches all six setup jobs unnecessarily. `EmbeddedConnectController::connect()` already has the correct pattern (`DB::transaction` + `->lockForUpdate()`); this method drifted.
3. **Zero feature tests** cover the controller method. `EmbeddedSetupRequestValidationTest` exercises Form Request rules only. The five-flag branch table, the no-op detection, the 422 paths (token rejected, domain mismatch), the `disconnected_at` clearance, and the six-job dispatch are all completely untested.

### What to do

- [ ] **Step 1 — Short-circuit `validateShopifyAccessToken` on no-op refresh.** Compute `$isNoOpRefresh` *before* the validation call. If true (same token, complete setup, no jobs needed), return `['provisioned' => true]` immediately — the token was already validated on first store. **Closes SCALE-1/LIFE-4.**
    - **Safety note:** keep the validation on the `$tokenChanged` path (a brand submitting a *different* token always re-validates) so the cross-shop-token-substitution defence (PR #23) is preserved.
    - **Alternative:** wrap the validation in `Cache::remember("shopify:token-valid:{$shopDomain}:".sha1($accessToken), 60, fn() => ...)` for the rare case where a token check is needed but stable across page loads. Token-rotation produces a different key, so revocation window is at most 60s.
- [ ] **Step 2 — Wrap the read-merge-write in `DB::transaction` + `lockForUpdate`.** Mirror `EmbeddedConnectController::connect()` line-for-line. The existing `UniqueConstraintViolationException` catch on the outer `updateOrCreate` stays valid. **Closes LIFE-3.**
- [ ] **Step 3 — Add the 7 feature tests from TEST-1's spec.** All require `Http::fake()` for the Shopify Admin API + `Bus::fake()` for job dispatch assertions. **Closes TEST-1.**
    - `it('dispatches all six setup jobs on first provision, clears disconnected_at')`
    - `it('skips job dispatch on no-op token refresh when integration is complete')`
    - `it('re-dispatches jobs when webhook_registration_state is queued')`
    - `it('re-dispatches when any collection handle is missing (partial setup)')`
    - `it('returns 422 shopify_token_rejected and does not overwrite existing token on Shopify 401')`
    - `it('returns 422 shopify_token_rejected on shop domain mismatch')`
    - `it('skips cache invalidation and status sync on no-op refresh')`
- [ ] **Step 4 — Verify `BrandSignupBypassTrait`** (or the test-suite equivalent) bypasses `shopify.session` middleware so tests can hit the endpoint without forging JWTs. The `EmbeddedConnectControllerTest` is the canonical example; reuse its setup.

### Plain English

Every time a brand opens any page inside their Shopify admin, our server: (a) phones Shopify to re-verify their access key, (b) re-reads their stored settings, (c) decides whether to dispatch setup jobs. Three things are subtly broken: (a) happens even when nothing changed in the last 5 seconds, so the page load is slow whenever Shopify is slow; (b) two browser tabs opening simultaneously race each other and the loser's settings vanish; and (c) the entire decision tree has no automated tests, so any change to this code ships unverified. The fix skips the unnecessary phone call, holds a row lock during the read-merge-write, and adds the seven tests that should have been there from the start.

### Why this pattern bundles three findings

All three live in one method. Fixing the no-op skip without adding tests means the fix itself ships unverified. Adding tests without fixing the race means flaky tests on parallel CI runs. Adding the lock without the no-op skip means we still pay the Shopify round-trip cost N times per minute. The three findings only make sense as one PR.

---

## Pattern C — `EmbeddedProductSettingsController` hardening (Form Request + cache + log + service injection)

**Closes 4 unique findings (1 P1 · 2 P2 · 1 P3):** SEC-1, CACHE-1 (≡ SCALE-2), LIFE-5, SCALE-6

**Effort:** ~1–2 days

### Root cause

`EmbeddedProductSettingsController` (675 lines) is the only embedded mutation endpoint that **didn't** receive a Form Request during the `fb322322` extract-Form-Requests refactor, and it's the only embedded read endpoint that **didn't** receive cache wrapping during the Master Pattern 11/14/15 cache sweep. Its sibling `EmbeddedProductAnalyticsController` got both treatments — the absence here is a clear refactor miss rather than a design choice. On top of that: two methods (`fetchVariants`, `isInCollection`) swallow `\Throwable` and return `[]` / `false` with no log, hiding write-path failures behind "Saved" UI; and `toggleCollection` re-implements `BrandCatalogService::resolveCollectionGid()` inline (uncached) instead of injecting the service that already exists.

### What to do

- [ ] **Step 1 — Add `UpdateProductSettingsRequest`.** Mirror the other 7 embedded form requests. Rules:
    - `product_gid`: `required|string|regex:/^gid:\/\/shopify\/Product\/\d+$/`
    - `field`: `required|string|in:active,commission_override,affiliate_discount_pct,custom_photos_enabled,add_to_favourites,add_to_default,disabled_variant_gids` — moves the allowlist from the `match` block into the validation layer so it surfaces as 422.
    - `value`: conditional on `field` — `numeric|min:0|max:100` for `commission_override` / `affiliate_discount_pct`; `boolean` for `active` / `custom_photos_enabled` / `add_to_favourites` / `add_to_default`; `array` for `disabled_variant_gids`.
    - Change `update(Request $request)` signature to `update(UpdateProductSettingsRequest $request)`. **Closes SEC-1.**
- [ ] **Step 2 — Wrap `show()` in `rememberLocked`.** Add `CacheKeyGenerator::embeddedProductSettings(string $professionalId, string $productId): string` (mirror `embeddedProductAnalytics`). Inject `CacheLockService` via constructor. Wrap the entire `show()` response body (including `fetchProductMetafields` + both `isInCollection` calls) in `$this->cacheLock->rememberLocked($key, 300, fn() => [...])` with ±20% jitter (int TTL, not `DateTimeInterface`). **Closes CACHE-1/SCALE-2.**
- [ ] **Step 3 — Bust cache on every successful write.** In `update()`, after a successful Shopify mutation: `Cache::forget($key)` and `Cache::forget($key.':stale')`. Also bust `CacheKeyGenerator::embeddedProductActive(...)` — the deferred Step 3 follow-up flagged in `EmbeddedProductAnalyticsController::resolveActive()`'s inline comment.
- [ ] **Step 4 — Log-with-context on swallowed exceptions.**
    - `fetchVariants` (write path): replace `catch (\Throwable $e) { return []; }` with `Log::warning('Shopify Admin API exception fetching variants', ['shop_domain' => ..., 'product_id' => ..., 'operation' => 'fetchVariants', 'error' => $e->getMessage()])` and **re-throw** (or throw `\RuntimeException` wrapping it) so `saveVariantEnabledStates` → `update()` returns 422 instead of a false-success "Saved."
    - `isInCollection` (read path): add `Log::warning` with `shop_domain`, `collection_handle`, `product_gid`, `error_class: class_basename($e)` before returning `false`. Returning `false` on read is fine; silent is not. **Closes LIFE-5.**
- [ ] **Step 5 — Inject `BrandCatalogService` and use `resolveCollectionGid`.** Replace the inline `collection(handle:) { id }` GraphQL call in `toggleCollection` with `$this->catalog->resolveCollectionGid($integration, $collectionHandle)` — already cached via `rememberLocked` keyed by `(shop_domain, handle)` with TTL `config('partna.cache.ttls.collection_gid')`. `BrandCatalogService::bustCatalogCaches()` already handles invalidation. **Closes SCALE-6.**

### Plain English

One controller (`EmbeddedProductSettingsController`) missed three recent refactors that every other embedded controller went through:

- The bouncer-validation pass (Form Request classes) that catches malformed input at the door — its absence here means a non-numeric commission rate gets silently stored in Shopify with the wrong type and the override invisibly clears.
- The cache-wrapping pass that means re-opening a product's settings panel doesn't phone Shopify three times — its absence here means every mount costs 3 Shopify calls and ~1s of latency.
- The log-on-failure pass that means a Shopify hiccup surfaces in the monitoring dashboard — its absence here means failed saves show as success in the UI.

Plus one duplicated code path: `toggleCollection` re-implements a cached service lookup that already exists, missing the cache. The fix is co-located in one file, runs ~1.5 days, and closes one P1 + two P2s + one P3.

---

## Pattern D — Embedded test coverage sweep

**Closes 6 unique findings (6 P2):** TEST-2, TEST-3, TEST-4, TEST-5, TEST-7, TEST-8

**Effort:** ~3–5 days

### Root cause

The embedded surface has 4 test files (~640 lines) for ~2.5K lines of controller + middleware + form-request code. The middleware (`VerifyShopifySessionToken`) is well-tested. `EmbeddedConnectController` is well-tested. **Everything else is not.** Specifically:

- `EmbeddedSetupController` has 12 mutation/read methods; only `overview` has a test (and that test mocks the DB facade so its SQL is never validated).
- `EmbeddedOrderAnalyticsController::show()` and its `deriveLineStatus` 4-way branch table are untested.
- `EmbeddedProductAnalyticsController::build()` (variant rollup, weighted-average commission rate, division-by-zero guard) is untested.
- `EmbeddedProductSettingsController::show()` / `update()` / `saveMetafield` / `toggleCollection` / `saveVariantEnabledStates` (the entire mutation surface) are untested.
- The existing `EmbeddedSetupOverviewCacheTest` uses `DB::shouldReceive('table')->never()`, a strict Mockery expectation that prevents cold-miss code from executing and silently couples test ordering.

### What to do

- [ ] **Step 1 — `EmbeddedSetupOverviewCacheTest` cleanup (TEST-2).** Replace `DB::shouldReceive('table')->never()` with `DB::spy()` so accidental cold-miss calls surface as meaningful failures rather than Mockery violations. Keep the cache-hit assertion intact.
- [ ] **Step 2 — `overview` SQL integration test (TEST-7).** Seed `commerce.orders` + `commerce.brand_affiliate_rollup` rows with known values; call the endpoint on a cache-miss path; assert computed fields. Five tests:
    - All-time commission sum excludes `EXCLUDED_FROM_AGGREGATES` statuses
    - `reversed_commission_cents > commission_cents` floors at zero (the `max(0, ...)` guard)
    - Dominant currency by order count, AUD default when no orders
    - 30-day window filter is correct
    - `recent_sales` returns 5 most recent with `affiliate.display_name` joined
- [ ] **Step 3 — `EmbeddedOrderAnalyticsController` tests (TEST-3).** Six tests covering the `deriveLineStatus` 4-way branch table, GID-prefix stripping, and `has_affiliate: false` shape.
- [ ] **Step 4 — `EmbeddedProductAnalyticsController` tests (TEST-4).** Five tests covering the variant rollup, weighted-average commission, excluded statuses, and the nullable `resolveActive` cache path.
- [ ] **Step 5 — `EmbeddedProductSettingsController` tests (TEST-5).** Six tests covering metafield mutations (capture GraphQL variables via `Http::fake()`), collection toggles, variant state saves, and 422/404 paths. *Will land naturally as the Pattern C Form Request work proceeds.*
- [ ] **Step 6 — Wizard endpoint sweep (TEST-8).** Ten tests covering `saveIdentity`, `saveBusinessDetails`, `updateSetting` (two key paths), `confirmHydrogenInstall`, `setupDomain` (debounce window), `brandProfile` (auto-heal), `embeddedProducts`. The largest sub-pattern; can be split into 2–3 PRs by endpoint group.

### Plain English

Every part of the embedded admin app that isn't `EmbeddedConnectController` or the JWT middleware has no automated tests. The setup wizard, the orders panel, the product analytics, the product settings — all ten-plus endpoints that brands hit every day during onboarding and ongoing use — would let any regression ship unverified. This pattern adds the missing tests over 3–5 days, split into per-controller PRs that can land independently.

### Coordination with Patterns B and C

Pattern B writes the `provisionShopifyIntegration` tests (TEST-1) as part of its work; Pattern C writes the `EmbeddedProductSettingsController` mutation tests (most of TEST-5) as part of its work. Pattern D picks up what's left: the read controllers (TEST-3, TEST-4), the `overview` SQL (TEST-7), the wizard sweep (TEST-8), and the mock cleanup (TEST-2). Order: B → C → D so each pattern lands its own tests, and Pattern D isn't writing tests for code that's still mid-refactor.

---

# Part 2 — Standalone fixes

These 12 findings don't cluster into architectural patterns. Each is a discrete, localized fix.

## Cluster: PII / data integrity (1 P1, 1 P2)

- [ ] **#DATA-1 · P1** — `brand_profiles.abn` and `brand_profiles.legal_business_name` not pseudonymized during 30-day deletion grace period
    - **Effort:** S (~0.5–1h)
    - **Where:** `app/Services/Professional/AccountDeletionService.php:214–227` (`pseudonymiseAccountPii`)
    - **What to do:** Extend `pseudonymiseAccountPii()` to scrub `brand_profiles.legal_business_name` and `brand_profiles.abn` in the same DB transaction as the `professionals` table scrub. Also audit `professionals.public_contact_email`, `public_contact_number`, and `about` — not in the current `forceFill` list but may carry PII depending on brand input. Add a comment referencing the `brand_profiles` scrub so future PII columns get caught by the same checklist.
    - **Plain English:** When a brand clicks "delete my account," email + phone are scrambled immediately but ABN (a tax number that uniquely identifies a sole trader) and legal business name sit raw for 30 days. Privacy law generally requires *all* PII to be erased at once.

- [ ] **#DATA-2 · P2** — `provider_metadata` JSONB key proliferation with no canonical definition; live `webhook_registration_state` vs `webhooks_state` dual-write
    - **Effort:** M (~2–4h)
    - **Where:** `EmbeddedSetupController::provisionShopifyIntegration`, `ShopifyAppUninstalledWebhookController::__invoke`, `EmbeddedProductSettingsController::show`
    - **What to do:** Define `ProfessionalIntegration::PROVIDER_METADATA_KEYS` constant (or a `ShopifyIntegrationMetadata` value object) enumerating all 16 keys currently in use. Remove the redundant `webhooks_state` dual-write from the uninstall controller (the `// note: different key` comment is the smoking gun). Promote `webhook_registration_state` and `disconnected_at` to real nullable columns + index — both gate control-flow decisions and would benefit from a typed schema rather than a JSONB lookup.
    - **Plain English:** Four controllers write notes into the same unlabelled drawer with different vocabularies. The dual `webhook_registration_state`/`webhooks_state` write is a defensive duplicate someone added to paper over a past mismatch; both keys must now be maintained forever or one becomes a time bomb. Define the vocabulary, drop the duplicate, promote the two control-flow keys to real columns.

## Cluster: Security defense-in-depth (3 P2 / P3)

- [ ] **#SEC-3 · P2** — Embedded controllers resolve tenant from request attributes and scope queries inline; no Policy gate on any embedded mutation endpoint
    - **Effort:** L (~1–2d)
    - **Where:** All 12 `EmbeddedSetupController` methods, plus `show`/`update` on the analytics + product-settings controllers
    - **What to do:** Introduce a `currentEmbeddedProfessional(Request $request): Professional` concern (mirrors the existing `LoadCurrentProfessional`) that reads `embedded_professional_id` from request attributes only and throws if absent — encapsulating the source-of-truth in one place. For write operations on known resources (`ProfessionalIntegration`, `BrandStoreSettings`, `BrandProfile`), add `$this->authorizeForUser($pro, 'update', $resource)` after loading. Create the two missing policies (`ProfessionalIntegrationPolicy`, `BrandStoreSettingsPolicy`) extending `BasePolicy`. Register via `Gate::policy()` so the `PolicyCoverageTest` sweep picks them up.
    - **Plain English:** The current auth is cryptographically correct — `embedded_professional_id` is bound to the JWT signature. The risk is architectural: if someone later adds an embedded endpoint that reads `professional_id` from a body parameter instead of request attributes, no Policy gate catches the tenant-isolation failure before it ships. Centralising tenant resolution + adding Policy gates closes that future hazard.

- [ ] **#SEC-4 · P2** — Exception renderer sets `Access-Control-Allow-Origin: *` on all API error responses
    - **Effort:** S (~0.5–1h)
    - **Where:** `bootstrap/app.php:108–113`
    - **What to do:** Replace the `'*'` fallback with reflection of the request's `Origin` header gated against `config('cors.allowed_origins')`. If origin is absent or not in the allow-list, omit the header entirely. Or call `app(HandleCors::class)->handle($request, fn() => $response)` on the built response to re-run the configured CORS middleware on the error path.
    - **Plain English:** Today's auth is keycard-based (Supabase JWT, Shopify session token) — no cookies — so `*` on error responses is low-risk. If a cookie-based path is added later (staff SSO, OAuth callback), the `*` would let any origin read error bodies including exception messages in debug mode. The fix narrows the allow to the same list as the success path.

- [ ] **#SEC-5 · P3** — `checkStorefrontStatus()` outbound HTTP request uses a DB-sourced subdomain without IP-range validation
    - **Effort:** S (~0.5–1h)
    - **Where:** `EmbeddedSetupController.php:361–393`
    - **What to do:** Add a Guzzle `on_stats` callback (or HTTP middleware) that checks the resolved IP against RFC1918 + link-local ranges before the connection is established, or assert that the URL host ends with `.partna.au` (or `config('partna.public_domain')`) before issuing the request.
    - **Plain English:** The health check always dials `{subdomain}.partna.au` from a controlled phone book, so SSRF requires DB compromise. The IP-range check costs nothing and closes the theoretical gadget permanently.

## Cluster: Performance / scaling (3 P2)

- [ ] **#CACHE-2 · P2** — Analytics version-token bumped on every pageview/click/cart-event defeats SWR on active sites
    - **Effort:** S (~0.5–1h)
    - **Where:** `AnalyticsController.php:73-77, 185-189, 227-232`; `AnalyticsCacheService.php:18-21`
    - **What to do:** Remove the `invalidateAnalytics()` call from `pageview()`, `click()`, `cartEvent()` — site-analytics counts tolerate 60–300s TTL staleness. If per-event freshness is needed, debounce via `Cache::add("analytics:ingest-debounce:{$professionalId}", 1, 30)` and only bump when absent. Retain `invalidateAnalytics()` on commerce-write paths (orders, refunds, webhooks) — those are the genuinely high-value signals.
    - **Plain English:** Commerce writes correctly invalidate analytics caches — those happen ~100×/affiliate/year. The same mechanism was over-applied to pageview ingest (10× the volume per day). A storefront with 200 daily pageviews invalidates the cache every 7 minutes on average against a 300s TTL, meaning the analytics rebuild from raw tables runs roughly 3× more often than the TTL alone implies.

- [ ] **#SCALE-3 · P2** — Variant enabled-state save issues N sequential synchronous Shopify mutations on the request thread
    - **Effort:** M (~2–4h)
    - **Where:** `EmbeddedProductSettingsController::saveVariantEnabledStates`
    - **What to do:** Replace the per-variant `saveVariantMetafield` loop with a single `productUpdate` mutation that sets metafields on all changed variants in one payload. Shopify's `productUpdate` accepts `variants` with per-variant `metafields`, collapsing N round-trips into 1. Alternative: dispatch a queued `SaveVariantEnabledStatesJob` and let the UI poll the (now cached) `show()` endpoint.
    - **Plain English:** A product with 30 variants where half are toggled in one save = 16 sequential Shopify calls (one read, 15 writes), worst case 16 × 15s = 240s of PHP-FPM worker time. Bundling into one mutation per save is the canonical Shopify-recommended pattern for bulk metafield writes.

- [ ] **#SCALE-4 · P2** — Product analytics builder hydrates all matching `order_items` into PHP memory instead of pushing aggregation into PostgreSQL
    - **Effort:** M (~2–4h)
    - **Where:** `EmbeddedProductAnalyticsController::build`
    - **What to do:** Replace the unbounded `->get()` + PHP `foreach` aggregation with three targeted queries: a `SUM`-aggregate query for totals, a `GROUP BY shopify_variant_id` query for per-variant sums, and a `->limit(5)` query for `recent_sales`. Only the third needs to hydrate rows.
    - **Plain English:** To answer "how many units of this product sold in 30 days," the server pulls every individual sales line and adds them up in PHP. At 1M orders/year × 2 line-items, a product receiving 5% of a brand's volume = ~8,250 rows hydrated per cache miss, ~2–4 MB per Collection. Concurrent cold misses across products can exhaust the analytics worker's 512 MB heap. PostgreSQL does the same aggregation in microseconds with zero PHP-heap impact.

## Cluster: Observability + config hygiene (2 P3)

- [ ] **#LIFE-6 · P3** — Stale `'2025-01'` API version fallback in `EmbeddedProductSettingsController` (6 occurrences)
    - **Effort:** S (~0.5–1h)
    - **Where:** `EmbeddedProductSettingsController.php` — `fetchProductMetafields`, `fetchVariants`, `isInCollection`, `saveMetafield`, `saveVariantMetafield`, `toggleCollection`
    - **What to do:** Replace all six `config('services.shopify.api_version', '2025-01')` with `config('services.shopify.api_version')` (no fallback). Add a boot-time assertion in `AppServiceProvider::boot()` that the key is non-empty in non-test environments so a missing env var fails the deploy rather than running deprecated API calls. The codebase is inconsistent — `EmbeddedSetupController::validateShopifyAccessToken` falls back to `'2026-04'`.

- [ ] **#LIFE-7 · P3** — Swallowed exception in `EmbeddedProductAnalyticsController::resolveActive()` caches `null` for the full 10-minute TTL
    - **Effort:** S (~0.5–1h)
    - **Where:** `EmbeddedProductAnalyticsController.php:205`
    - **What to do:** Add `Log::warning` before `return null` with `professional_id`, `product_id`, `operation: 'resolveActive'`, `error_class: class_basename($e)` (class only — exception messages may carry GIDs). Consider a sentinel return that distinguishes "Shopify unreachable" from "metafield not set yet" so the UI can show a transient error state rather than treating the product as newly-onboarded.
    - **Plain English:** The exception is caught inside `rememberLockedNullable`'s closure, so `null` is stored in cache for the full 10-minute TTL — every subsequent read for the same (brand, product) pair serves the cached `null` with no Nightwatch trace of the underlying Shopify error.

## Cluster: Independent (1 P3)

- [ ] **#SCALE-5 · P3** — Single-field metafield saves make two sequential Shopify API calls (find-then-mutate) when one would suffice
    - **Effort:** S (~0.5–1h)
    - **Where:** `EmbeddedProductSettingsController::saveMetafield`
    - **What to do:** Remove the `findMetafield` lookup query entirely. The `productUpdate` mutation with a `metafields` array already handles the upsert case (it's the existing "create" branch). Use it unconditionally — Shopify upserts by `(namespace, key)` without needing the metafield ID.
    - **Plain English:** Every per-field save currently does "what's the ID of this setting?" → "now change it." The second call (`productUpdate` with `metafields`) already handles create-or-update — using it for the update branch too collapses every save from 2 API calls to 1.

- [ ] **#CACHE-3 · P3** — `StaffAnalyticsController::summary()` scans raw event tables on every staff request with no cache
    - **Effort:** S (~0.5–1h)
    - **Where:** `StaffAnalyticsController::summary`
    - **What to do:** Inject `CacheLockService`, wrap `summary()` body in `rememberLocked` keyed by `(professional_id, from, to, days)` with 60s TTL. Or re-use the professional's `analyticsSummaryVersion` token so staff + self views share a cache.
    - **Plain English:** Brand owners see a cached version of their dashboard (5-min TTL). Staff users see the same dashboard rebuilt from raw tables every request. Low urgency (2–5 staff), but the fix is one wrapper call.

---

# Suggested merge order

| Day | Work | Findings closed |
|-----|------|-----------------|
| 0.5 | **DATA-1** — pseudonymise `brand_profiles.abn` + `legal_business_name` | 1 P1 |
| 1   | **Pattern A** — `app/uninstalled` dedup + reconcile job + test assertion | 2 P1, 1 P2 |
| 2–3 | **Pattern B** — `provisionShopifyIntegration` no-op skip + lock + 7 feature tests | 2 P1, 1 P2 |
| 4–5 | **Pattern C** — `EmbeddedProductSettingsController` Form Request + cache + log + service injection | 1 P1, 2 P2, 1 P3 |
| 6–10 | **Pattern D** — Embedded test coverage sweep (TEST-2/3/4/5/7/8 in 2–3 PRs by controller) | 6 P2 |
| 11  | Performance cluster (CACHE-2, SCALE-3, SCALE-4) | 3 P2 |
| 12  | Security defense-in-depth cluster (SEC-3, SEC-4, SEC-5) | 2 P2, 1 P3 |
| 13  | Cleanup: DATA-2 schema definition, LIFE-6 config, LIFE-7 log, SCALE-5/CACHE-3 perf-P3 | 1 P2, 4 P3 |

**Why this order:**

- **DATA-1 first** — a half-hour single-file change that closes a P1. Highest tier-impact-per-hour in the entire plan. Get it out of the way before the larger patterns.
- **Pattern A next** — half-day session, 2 P1s closed. Hardens the most user-visible failure mode (uninstall → reinstall lockout).
- **Pattern B before Pattern D** — Pattern B writes the `provisionShopifyIntegration` tests as part of its scope; Pattern D doesn't have to re-write them. Same logic for Pattern C and the `EmbeddedProductSettingsController` mutation tests.
- **Pattern D last among foundationals** — pure test-coverage work, parallelisable across 2–3 contributors, doesn't block any of the other patterns.
- **Performance cluster mid-week 2** — CACHE-2 and SCALE-3/4 are independent of each other and of the controllers Patterns B/C touched. Can ship in parallel as separate PRs.
- **DATA-2 last** — schema work (column promotion for `webhook_registration_state` + `disconnected_at`) requires a migration and broader cross-controller awareness; let the controller refactors settle first.

# What this plan does NOT cover

- **Hydrogen storefront controllers** (`HydrogenAffiliate*`, `HydrogenBrandConfig*`, etc.) — out of scope for the embedded-app rework. Covered by the original `audits/phase-{1..6}` sweep + `MASTER-REMEDIATION-PLAN.md`.
- **Shopify webhook controllers other than `app/uninstalled`** — covered by Pattern C of `audits/phase-1-security/remediation-plan.md` (canonical webhook controller base class).
- **`ShopifyShopResolver` service** — audited clean; no findings.
- **`VerifyShopifySessionToken` middleware** — audited clean post-rework (the JWT/JTI/dest-claim flow held up under the security lens; the atomic Lua INCR+EXPIRE in `a5eaf779` and cross-tenant rebind hardening in `eb2fdc5a` were verified working).
- **Cross-cutting concerns already covered by the existing master plan** — see `audits/MASTER-REMEDIATION-PLAN.md` for the broader status; this plan extends rather than replaces it.
