# Caching Audit — Consolidated Fix Plan

**Source audit:** `audits/audit-2026-05-21-caching-full-backend.md`
**Date:** 2026-05-21 · **Branch:** development
**Scope:** 25 findings (18 gold-standard adherence + 7 coverage gaps) consolidated into **17 fixes**.

## How to read this

- Fixes are in **priority order** (P1 → P2 → P3). Do them top-to-bottom.
- Where two or more findings share a root cause or touch the same file, they are merged into **one fix** — the "Covers" line lists every finding ID it resolves.
- **Implement** = model to write the fix. **Review** = model to verify it before merge.
  Rationale: Sonnet implements all fixes (mechanical cache-pattern work); Opus reviews
  anything on a money path, a public endpoint, or with multi-file invalidation wiring
  where a missed bust is silent and dangerous. Sonnet reviews the self-contained
  mechanical fixes.
- **Total estimated effort: ~34–40h (~4.5–5 working days).**

---

## P1 — Fix before pilot launch

### Fix 1 — Cache `storefrontConfig`
- [ ] **Done**
- **Covers:** CCH-1
- **Priority:** P1 · **Effort:** S (~1h)
- **What:** Wrap the integration resolution + payload assembly in `PublicShopifyStorefrontController::storefrontConfig` in `CacheLockService::rememberLocked` (60s TTL), mirroring `HydrogenBrandConfigController::show`. Add `CacheKeyGenerator::shopifyStorefrontConfig()`. Bust the key (+ `:stale` twin) on storefront-token creation and brand-status transitions via `DB::afterCommit`.
- **Why P1:** Public, unauthenticated, hit on every Hydrogen storefront render across all brands — no TTL amortisation, every cold load runs 2+ DB queries.
- **Implement:** Sonnet · **Review:** Opus (public endpoint, no auth)

### Fix 2 — Notification listing cache: bust on publish + fix incomplete invalidation
- [ ] **Done**
- **Covers:** CCH-2 (P1), CCH-14 (P3)
- **Priority:** P1 · **Effort:** S (~1.5h)
- **What:**
  - CCH-2: Make `NotificationListingService::bustIndexCache` callable (extract a public `bustForProfessional(string $id)`), then call it from `NotificationPublisher::publish`/`publishMany` after a successful insert (or dispatch a `BustNotificationCacheJob`).
  - CCH-14: While in the same file — replace the hardcoded `[50, 100, 200]` limit loop in `bustIndexCache` with an `ALLOWED_LIMITS` constant that `index()` also validates against, so the bust is provably complete.
- **Why grouped:** Both edit `NotificationListingService`; doing CCH-14 here avoids a second visit to the file.
- **Why P1:** Every notification publish (payout settled, invite, warning) shows a guaranteed stale bell for up to 15s.
- **Implement:** Sonnet · **Review:** Opus (P1, correctness of invalidation surface)

---

## P2 — Should fix

### Fix 3 — Convert raw `Cache::get`/`put` to single-flight `rememberLocked`
- [ ] **Done**
- **Covers:** CCH-3, CCH-5
- **Priority:** P2 · **Effort:** M (~2h)
- **What:**
  - CCH-3: `BrandStatusService::isStorefrontReachable` — replace `Cache::get`+HTTP+`Cache::put` with `rememberLocked` (integer TTLs 60/15). Extract the HTTP probe into a private `doHttpCheck()`.
  - CCH-5: `BrandCatalogService::fetchProductCustomPhotosMetafield` — replace raw get/put with `rememberLockedNullable`; drop the `'true'`/`'false'`/`'unset'` string sentinels; integer TTL.
- **Why grouped:** Identical anti-pattern (no single-flight lock, no jitter on an outbound vendor/HTTP call); same fix shape.
- **Implement:** Sonnet · **Review:** Sonnet

### Fix 4 — Payout terminal-state: invalidate analytics + cache the payout summary
- [ ] **Done**
- **Covers:** CCH-4, CCG-1
- **Priority:** P2 · **Effort:** M (~3h)
- **What:**
  - CCH-4: In `CommissionPayoutService::failPayout` and `CommissionVoidService::cancelExpiredPayout`, call `analyticsCache->bumpAnalyticsVersion()` for both brand and affiliate IDs after the state transition — matching `markCompleted`.
  - CCG-1: Wrap `CommissionPayoutService::getPayoutSummary` in `rememberLocked` (60–120s TTL, `CacheKeyGenerator` key scoped to `professional_id`). Bust that key from `markCompleted`, `failPayout`, and `cancelExpiredPayout`.
- **Why grouped:** Both edit the exact same three payout-lifecycle methods; splitting means touching `CommissionPayoutService` twice.
- **Implement:** Sonnet · **Review:** Opus (money path; missed bust = wrong dashboard numbers)

### Fix 5 — Invalidate brand catalog caches on Shopify disconnect
- [ ] **Done**
- **Covers:** CCH-6
- **Priority:** P2 · **Effort:** S (~1h)
- **What:** In `ShopifyDisconnectService::disconnect`, after the integration delete, `Cache::forget` `brandAdminCatalog` + `brandActiveCatalog` (and both `:stale` twins) inside `DB::afterCommit`.
- **Implement:** Sonnet · **Review:** Sonnet

### Fix 6 — Move token-refresh locks to the `cache_locks` store
- [ ] **Done**
- **Covers:** CCH-7
- **Priority:** P2 · **Effort:** S (~1h)
- **What:** In `SquareTokenService` and `FreshaTokenService`, change `Cache::lock(...)` to `Cache::store('cache_locks')->lock(...)` and add a vendor prefix (`square:`/`fresha:`) to the key. Isolates locks from data-cache flushes.
- **Implement:** Sonnet · **Review:** Sonnet

### Fix 7 — Centralise Stripe cache keys + add webhook push-invalidation
- [ ] **Done**
- **Covers:** CCH-15, CCH-8
- **Priority:** P2 · **Effort:** L (~4h)
- **What:**
  - CCH-15 first: add `CacheKeyGenerator::stripeBalance/stripeUpcomingPayouts/stripeTransactions` helpers; replace the ad-hoc `sprintf`/concat keys in `StripeConnectController`.
  - CCH-8: in the Stripe webhook handlers (`payment_intent.succeeded`, `payment_intent.payment_failed`, `account.updated`), `Cache::forget` the balance + upcoming-payouts keys (+ `:stale`) using the new helpers. Add a `?fresh=1` escape hatch on `balance`/`upcomingPayouts`.
- **Why grouped:** CCH-15 is a hard prerequisite — the webhook handler must reference the same key strings the controller writes; centralise once, use both sides.
- **Implement:** Sonnet · **Review:** Opus (webhook wiring, money path)

### Fix 8 — Stop caching failures inside `rememberLocked` closures
- [ ] **Done**
- **Covers:** CCH-9, CCH-10
- **Priority:** P2 · **Effort:** S (~1.5h)
- **What:**
  - CCH-9: Remove the `try/catch → zeroed object` wrappers around click-analytics queries in `StaffAnalyticsController::summary` and `ProfessionalAnalyticsController::summary`. Let `QueryException` propagate so the failure isn't cached.
  - CCH-10: In `EmbeddedProductSettingsController::fetchProductMetafields`, re-throw after logging instead of returning `$empty` — matching `fetchVariants` in the same class.
- **Why grouped:** Same anti-pattern — a swallowed error inside a cache-fill closure turns a transient fault into a stale-wrong value for the full TTL.
- **Implement:** Sonnet · **Review:** Sonnet

### Fix 9 — Invalidate public site payload on publish toggle
- [ ] **Done**
- **Covers:** CCH-11
- **Priority:** P2 · **Effort:** S (~1h)
- **What:** In `SiteVisibilityController::update`, after `$site->save()`, call `SiteCacheService::forgetPublicSitePayload($site->subdomain)` (primary + `:stale`) inside `DB::afterCommit`.
- **Note:** Unpublish-without-bust is a confidentiality gap (hidden site still served) — flag to reviewer.
- **Implement:** Sonnet · **Review:** Sonnet

### Fix 10 — Cache `HydrogenAffiliateController::services`
- [ ] **Done**
- **Covers:** CCH-12
- **Priority:** P2 · **Effort:** S (~1h)
- **What:** Wrap the services resolution in `rememberLocked` (`self::CACHE_TTL_SECONDS`), mirroring `show()`. Ensure `SiteCacheService::forgetHydrogenAffiliate` also forgets the new key.
- **Implement:** Sonnet · **Review:** Sonnet

### Fix 11 — FeatureFlag registry observer
- [ ] **Done**
- **Covers:** CCH-13
- **Priority:** P2 · **Effort:** M (~2.5h)
- **What:** Add a `FeatureFlagObserver` calling `FeatureFlagService::flushRegistry()` on `created`/`updated`/`deleted`; register it in `AppServiceProvider::boot()`. Removes the manual-flush footgun on flag-row changes.
- **Implement:** Sonnet · **Review:** Sonnet

### Fix 12 — Cache the card-less brand billing summary
- [ ] **Done**
- **Covers:** CCG-2
- **Priority:** P2 · **Effort:** S (~1h)
- **What:** Wrap the `$blockedData` aggregate in `BrandBillingSummaryController` in `rememberLocked` (60s TTL, key scoped to `brand_professional_id`). Bust on Shopify order webhook ingest.
- **Implement:** Sonnet · **Review:** Sonnet

### Fix 13 — Cache the brand "My Affiliates" list
- [ ] **Done**
- **Covers:** CCG-3
- **Priority:** P2 · **Effort:** M (~3h)
- **What:** Wrap the links + sites resolution in `BrandAffiliateController::index` in `rememberLocked` (60s TTL, key scoped to `brand_professional_id`). `CacheLockService` is already injected. Bust on `BrandPartnerLink` create/delete (via `BrandPartnerLinkLifecycleService::disconnect`) and on affiliate `Professional` status changes.
- **Implement:** Sonnet · **Review:** Opus (invalidation must cover the full link lifecycle)

### Fix 14 — Cache favourites-collection membership
- [ ] **Done**
- **Covers:** CCG-4
- **Priority:** P2 · **Effort:** S (~1.5h)
- **What:** Wrap `AffiliateProductCatalogService::fetchCollectionGids` in `rememberLocked` (60–300s TTL, key scoped to `brand_professional_id` + handle). Bust on `BrandCatalogService::addProductsToCollection`/`removeProductsFromCollection`.
- **Implement:** Sonnet · **Review:** Sonnet

### Fix 15 — Cache the brand discovery page
- [ ] **Done**
- **Covers:** CCG-5
- **Priority:** P2 · **Effort:** M (~3h)
- **What:** Cache the paginated brand list + resolved logo URLs in `BrandPartnerController::index` (`rememberLocked`, key scoped to `page`+`per_page` — same for every affiliate). Bust on brand-profile changes (`affiliate_visibility`, `brand_status`) and logo uploads. Leave the affiliate-specific "connected brands" query uncached or on a separate short-TTL per-affiliate key.
- **Implement:** Sonnet · **Review:** Opus (multi-query, broad invalidation surface)

### Fix 16 — Orders list cache layer (brand + affiliate)
- [ ] **Done**
- **Covers:** CCG-6, CCG-7
- **Priority:** P2 · **Effort:** L (~5h)
- **What:** Add a shared `CacheKeyGenerator::ordersListPage(scopeType, scopeId, status, page)`. Wrap the first-page / common-status path in both `BrandOrdersController::index` and `AffiliateOrdersController::index` in `rememberLocked` (30s TTL, page-1 only to bound key cardinality). Bust on Shopify order webhook ingest via `AnalyticsCacheService::bumpAnalyticsVersion`.
- **Why grouped:** Audit Bundle A — structurally identical 4-table LEFT JOIN; one shared key helper keeps both controllers in sync.
- **Implement:** Sonnet · **Review:** Opus (shared key design, two controllers, cardinality bounding)

---

## P3 — Nice to have

### Fix 17 — Replace `DateTimeInterface` TTLs with integer seconds
- [ ] **Done**
- **Covers:** CCH-16, CCH-17, CCH-18
- **Priority:** P3 · **Effort:** S (~2h)
- **What:** Convert `now()->addMinutes()`/`addHour()` TTLs to integer seconds so `JitteredTtl` can apply ±20% spread:
  - CCH-16: `BookingAnalyticsController` + `StaffBookingController` (`120`/`600`). Note: booking feature is dropped (2026-05-11) — prefer deleting these controllers as feature teardown.
  - CCH-17: `ShopifySetupTokenService` (`3600`, with a comment that this key intentionally needs precise expiry); move key into `CacheKeyGenerator::shopifySetupToken`.
  - CCH-18: `ProcessShopifyShopUpdateJob` throttle — `random_int(3240, 3960)` for staggered expiry; move key into `CacheKeyGenerator`.
- **Why grouped:** Identical anti-pattern across three files.
- **Implement:** Sonnet · **Review:** Sonnet

---

## Effort summary

| Priority | Fixes | Findings | Effort |
|----------|-------|----------|--------|
| P1       | 1–2   | 3        | ~2.5h  |
| P2       | 3–16  | 19       | ~30h   |
| P3       | 17    | 3        | ~2h    |
| **Total** | **17** | **25** | **~34–40h** |

## Suggested session batching

1. **Session A (P1):** Fix 1 + Fix 2 — ship before pilot.
2. **Session B (money/Stripe):** Fix 4 + Fix 7 — both money-path, both Opus-reviewed.
3. **Session C (mechanical sweep):** Fix 3 + Fix 5 + Fix 6 + Fix 8 + Fix 9 + Fix 10 + Fix 17 — all S/M, all Sonnet-reviewed, all the same `rememberLocked`/invalidation muscle memory.
4. **Session D (coverage gaps):** Fix 11 + Fix 12 + Fix 13 + Fix 14 + Fix 15 + Fix 16.
