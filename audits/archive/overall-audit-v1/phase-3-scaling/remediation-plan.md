# Partna Phase 3 Scaling Antipatterns — Consolidated Remediation Plan

> **FROZEN as of 2026-05-12.** This plan is no longer the source of truth for status, regressions, or post-baseline annotations. Live status — including all changes from PR #12 onwards and any new findings introduced after this date — lives in `audits/MASTER-REMEDIATION-PLAN.md`. This file is preserved as provenance for `Original ID: Phase 3 Pattern X` references in the master plan. Do not edit; if a status change is needed, update the master instead.

**Date:** 2026-05-11
**Branch:** development
**Source:** 4 audits across `audits/phase-3-scaling/`, adjudicated by `claude-sonnet-4-6` over `deepseek-v4-pro` drafts
**Lens:** Scaling antipatterns — write amplification, rebuild-on-write, weak caching

## Summary

- **19 reported findings**, **17 unique** after deduplication (2 cross-audit duplicates — see matrix below)
- **Tier breakdown (reported):** 0 P0 · 4 P1 · 9 P2 · 6 P3
- **Tier breakdown (unique):** 0 P0 · 4 P1 · 8 P2 · 5 P3
- **Five foundational patterns close 13 of 17 unique findings** (4 P1 · 6 P2 · 3 P3)
- **4 standalone fixes** for the rest (2 P2 · 2 P3)
- **Estimated total:** ~5–6 days (1–1.5 weeks) of focused work to close all 17 findings

The Phase 3 surface is smaller and more pattern-dense than Phase 1 or Phase 2: 13 of 17 findings collapse into 5 root-cause sweeps. The work is light on architectural rewrites and heavy on mechanical cache-layer hardening.

## Cross-audit duplicates (collapse on fix)

| Finding | Audits | Same root cause |
|---------|--------|-----------------|
| `Cache::memo()->remember` in `AffiliateProductCatalogService::fetchActiveCatalog` | SCALE-A#CACHE-1 (P2) ≡ SCALE-D#CACHE-4 (P2, partial) | Pattern 1 |
| `Cache::memo()->remember` in `BrandCatalogService::fetchBrandCatalog` | SCALE-A#CACHE-2 (P2) ≡ SCALE-D#CACHE-4 (P2, partial) | Pattern 1 |

SCALE-D#CACHE-4 bundles both catalog services into a single finding; SCALE-A keeps them split per-file. Take SCALE-A's split as canonical (different upstream APIs — Storefront vs Admin — so reviewers want to see them named separately) and treat SCALE-D#CACHE-4 as the bundle reference.

**Related (overlapping scope, distinct prescriptions — bundle the PR):**

| Findings | Why bundle |
|----------|------------|
| SCALE-C#CACHE-2 (delete dead 90-day deletion loop) + SCALE-D#CACHE-6 (partial version-token migration audit) | Both target `AnalyticsCacheService::invalidateAnalytics`. SCALE-C's grep confirmed `getVisitStats`/`getClickStats` have zero callers — the 90-day loop deletes keys that are never written. SCALE-D's broader concern is the two-strategy coexistence. Land C's deletion first; D becomes simpler afterward. |
| SCALE-B#CACHE-1 (HydrogenAffiliateController add server cache) + SCALE-B#CACHE-9 (`Cache-Control: no-store` → CDN strategy) | Same controller, same return path. CACHE-9 explicitly depends on CACHE-1 landing first. |
| SCALE-B#CACHE-3 (EmbeddedSetupController::overview) + SCALE-B#CACHE-4 (EmbeddedOrderAnalyticsController) | Same root cause (reads `CommissionMovement` for accrual rows that were dropped in Phase 4), same migration pattern (live query against `commerce.orders` / `commerce.order_items`). |
| SCALE-D#CACHE-2 (`CacheLockService::writeWithJitter`) + SCALE-D#CACHE-3 (`SiteCacheService::writePayloadWithStale`) | Same fix (jitter the stale TTL) in two parallel cache writers. Extract a shared helper. |

## Source audit files

- `audit-2026-05-11--scaling-antipatterns-write-amplification-rebuild.md` (**SCALE-A**: 2 P2)
- `audit-2026-05-11--scaling-antipatterns-write-amplification-rebuild-2.md` (**SCALE-B**: 4 P1, 3 P2, 2 P3)
- `audit-2026-05-11--scaling-antipatterns-write-amplification-rebuild-3.md` (**SCALE-C**: 2 P3)
- `audit-2026-05-11--scaling-antipatterns-write-amplification-rebuild-4.md` (**SCALE-D**: 4 P2, 2 P3)

---

## Post-baseline annotations (2026-05-12)

The commits on `origin/development` (`60f231c..feeab29`, 17 commits across PR #12–#25) landed between audit generation (2026-05-11) and this plan. **One regression bumps a finding's urgency:**

**Findings re-classified after the May 11-12 window:**

- `#SCALE-A#CACHE-1` (P2 → **P1 candidate**) — **REGRESSED.** PR #17 (`bef81ef`) switched `AffiliateProductCatalogService::fetchActiveCatalog` from Storefront API to Admin API without adding `rememberLocked`. The audit explicitly called out the Admin API's 1000-points/sec leaky bucket as a worse stampede target than Storefront. Two services (`AffiliateProductCatalogService` + `BrandCatalogService`) now stampede the **same** Admin API budget on cold cache. See updated Pattern 1 Step 1 below.
- `#SCALE-B#CACHE-1` (P1) — **Partial.** PR #12 (`a118f62`) added an alias-aware affiliate lookup at line 633–647 (an `orWhereExists` subquery). No `rememberLocked` wrap; the new alias subquery actually increases per-request work. Pattern 3 Step 2 remains as-written.

**New audit-worthy concerns introduced by these commits** — captured in the appendix at the bottom of this file.

### Pattern re-sequencing recommendation (cross-phase)

Given the `#SCALE-A#CACHE-1` regression and the related Phase 4 `#DB-D#SCALE-1` (route `queryAdminCatalog` through `ShopifyAdminClient`), the safe sequence is:

1. **First** — Pattern 1 (catalog services `rememberLocked` migration) — closes the stampede with zero behaviour change (pure cache-layer wrap).
2. **Then** — Phase 4 `#DB-D#SCALE-1` — routes through `ShopifyAdminClient` to add throttle-aware budget machinery. Behaviour changes (cold-cache requests may now block on budget acquire) but the stampede fix in step 1 reduces cold-cache concurrency, so the throttle waits are tolerable.

Doing #2 without #1 is what's risky: adding budget waits while concurrency is unbounded creates cold-cache blocking under load. **Pattern 2 (P1 silent data bug) still ships first overall** — only the order *between Pattern 1 and Phase 4 work* is what this note constrains.

---

# Part 1 — Foundational fixes

These five patterns are sequenced by a hybrid of severity and fix-leverage. Pattern 2 ships first because two P1 controllers are *currently shipping $0 commission figures* to brands' embedded Shopify dashboards — silent data loss can't wait behind cache hygiene. After that, the order returns to fix-leverage: smallest-fastest first, biggest impact per day of work.

**Order:** Pattern 2 (P1 silent data bug) → Pattern 4 (0.5-day cache-layer hardening) → Pattern 3 (P1+P2 Hydrogen caching wrap) → Pattern 1 (P2 catalog-service migration) → Pattern 5 (P3 analytics-invalidation cleanup).

## Pattern 2 — Migrate two embedded-app controllers off `CommissionMovement` accrual reads

**Closes 2 unique findings (2 P1):** SCALE-B#CACHE-3, SCALE-B#CACHE-4

**Effort:** ~1 day

### Root cause

Phase 4 (`supabase/migrations/20260506500000_drop_legacy_aggregates.sql`) deleted every accrual and reversal row from `commission_ledger_entries` (renamed to `commerce.commission_movements` by `20260506600000_rename_ledger_to_movements.sql`) and narrowed the surviving table to `entry_type IN ('payout','clawback','adjustment')`. The model's docblock (`app/Models/Retail/CommissionMovement.php:11–19`) confirms this scope.

Most consumers were rewritten to live-query `commerce.orders` + `commerce.order_items` + `commerce.brand_affiliate_rollup` during Phase 4 (`BrandCommerceAnalyticsController`, `AffiliateCommerceAnalyticsController`, `EmbeddedProductAnalyticsController`). Two controllers were missed:

| Controller | What it currently returns |
|------------|---------------------------|
| `EmbeddedSetupController::overview()` | `total_commission_cents`, `commission_30d_cents`, `revenue_30d_cents`, `recent_sales` — **all zero / empty for every brand**, regardless of actual sales |
| `EmbeddedOrderAnalyticsController::show()` | `has_affiliate: false` for every order, regardless of whether an affiliate was actually attributed |

Both are exposed in the embedded Shopify admin UI extensions. Brands looking at their commission dashboard or clicking into an order see "no affiliate activity" even when affiliates are actively generating sales — Partna's core value proposition appears broken.

### What to do

- [ ] **Step 1 — Rewrite `EmbeddedSetupController::overview()`** (`app/Http/Controllers/Api/Internal/EmbeddedSetupController.php:316–371`).
    - Replace all `CommissionMovement::where(...)->whereIn('status', ['pending','approved'])` queries with the live pattern from `BrandCommerceAnalyticsController::buildCommissionSummary()`: `SUM(commission_cents)` over `commerce.orders` where `status NOT IN (Order::EXCLUDED_FROM_AGGREGATES)`.
    - For "pending" (unpaid) commission: `commerce.orders WHERE brand_professional_id = $id AND status NOT IN (excluded) AND payout_id IS NULL`.
    - For `recent_sales`: `commerce.orders JOIN core.professionals` on `affiliate_professional_id` for the display name.
    - **While you're here:** wrap the rewritten payload in `CacheLockService::rememberLocked` keyed by a new `CacheKeyGenerator::embeddedSetupOverview(string $professionalId)` (pattern `"embedded:setup:overview:v1:{$professionalId}"`) with a 60s TTL + SWR. Bust via `AnalyticsCacheService::invalidateAnalytics()` on commerce writes. The `CacheLockService` is already injected into the controller — no new wiring. Closes SCALE-B#CACHE-3.
- [ ] **Step 2 — Rewrite `EmbeddedOrderAnalyticsController::show()`** (`app/Http/Controllers/Api/Internal/EmbeddedOrderAnalyticsController.php:47–57`).
    - Replace the `CommissionMovement::with('affiliateProfessional')->where('shopify_order_id', $orderId)` query with a `commerce.order_items JOIN commerce.orders` query, parallel to `EmbeddedProductAnalyticsController::build()` (lines 67–116 of that file — the direct model to follow).
    - Affiliate attribution: `commerce.orders.affiliate_professional_id` → join `core.professionals` for display name.
    - Per-line-item breakdown: `commerce.order_items.commission_rate`, `line_total_cents`, `commission_cents` (already mirrored from `line_items` JSONB by the trigger).
    - Status summary: map `commerce.orders.status` → display states, not per-entry statuses.
    - Closes SCALE-B#CACHE-4.
- [ ] **Step 3 — Add `CacheKeyGenerator::embeddedSetupOverview()` method.** DeepSeek's draft referenced this method as if it existed; it does not. Add it alongside the Step 1 fix.

### Plain English

During the Phase 4 database reorganisation we moved "commissions owed" from a separate tally table directly onto each order. Two controllers in the embedded Shopify admin app weren't updated, so they're still reading the old (now empty) table. As a result, every brand's "overview" panel inside Shopify shows $0 commission, and every individual order shows "no affiliate" — even when affiliates have generated real sales. This is a silent data bug: the app is technically running fine, but the numbers it shows brands are wrong by 100%. The fix is to point those two controllers at the same `commerce.orders` table the rest of the analytics already use.

### Why this is highest priority

Both findings are P1 silent data bugs that are currently shipping to production. Cache hardening pays back during incidents and load spikes — silent zeroes pay back never. If a brand opens their embedded dashboard, sees $0, and concludes Partna isn't tracking their affiliates, the damage is structural to the pilot relationship. Pattern 2 unblocks the credibility of every downstream analytics improvement.

---

## Pattern 4 — Jitter the `:stale` TTL in both cache writers

**Closes 2 unique findings (2 P2):** SCALE-D#CACHE-2, SCALE-D#CACHE-3

**Effort:** ~0.5 day

### Root cause

Both cache writers in the codebase — `CacheLockService::writeWithJitter` and `SiteCacheService::writePayloadWithStale` — apply ±20% jitter to the **primary** TTL but use a **fixed multiplier** for the stale TTL. When the fleet cold-fills a key after a deploy or Redis flush, primary copies spread across the jitter window correctly, but every `:stale` copy lands at the exact same wall-clock second and expires together one stale-multiplier-cycle later.

When that synchronized stale-expiry moment arrives, every SWR fast path returning stale simultaneously discovers the stale copy is gone and races for the recompute lock. The thundering herd the primary jitter prevents re-emerges on the stale key 10 minutes later (for `CacheLockService` at 60s TTL × 10× stale multiplier) or one stale-cycle later for `SiteCacheService::writePayloadWithStale` — the latter being the highest-traffic cache key in the system (`site:payload:{subdomain}:stale`, 95% of public traffic).

### What to do

- [ ] **Step 1 — Add jitter to `CacheLockService::writeWithJitter`** (`app/Services/Cache/CacheLockService.php`, the `int` branch).
    - Apply an independent `mt_rand` draw to `$staleTtl`:
        ```php
        $staleTtl = (int) round(
            $ttl * self::STALE_TTL_MULTIPLIER * (0.8 + mt_rand(0, 4000) / 10000.0)
        );
        ```
    - Keep the ±20% distribution proportional to the stale window (multiplied through the stale TTL, not the primary), so the spread is meaningful at long stale windows. Closes SCALE-D#CACHE-2.
- [ ] **Step 2 — Add jitter to `SiteCacheService::writePayloadWithStale`** (`app/Services/Cache/SiteCacheService.php`).
    - Same formula on `$staleTtl`:
        ```php
        $staleTtl = (int) round(
            (int) config('partna.cache.ttls.public_payload')
            * self::PAYLOAD_STALE_TTL_MULTIPLIER
            * (0.8 + mt_rand(0, 4000) / 10000.0)
        );
        ```
    - Closes SCALE-D#CACHE-3.
- [ ] **Step 3 — Extract a shared static helper.** Both classes now have identical jitter logic; pull it into a tiny `App\Services\Cache\Concerns\JitteredTtl` trait (or a static helper on `CacheLockService`) so the next cache writer added to the codebase inherits the discipline. Single source of truth.
- [ ] **Step 4 — Confirm `DateTimeInterface` skip behaviour.** Per SCALE-A#CACHE-1's insight, `writeWithJitter` skips jitter entirely when the TTL is a `DateTimeInterface` (only ints get jittered). All `rememberLocked` call sites in this plan must pass **int seconds**, not `now()->addMinutes(N)` deadlines, or Pattern 4's hardening is bypassed at the call site.

### Plain English

Every cached value has two copies — a fresh primary that expires fast and a stale backup that lives longer for fallback. The primary's expiry time is staggered randomly so not all fresh copies expire at the same second across the server fleet. But the stale backup uses a fixed deadline, so all backup copies *do* expire at the same second. When that second arrives on a popular cache key, every server worker that was relying on the stale fallback simultaneously discovers it's gone and tries to refresh — a small thundering herd 10 minutes after we thought we'd prevented one. Adding the same random spread to the backup copy closes this last synchronized-expiry hole.

### Why this is the second-fastest leverage point

0.5 day of work, two P2 findings, hardens the cache layer everyone else builds on. Patterns 1 and 3 add more `rememberLocked`-backed keys to the system — landing Pattern 4 first means every key added by later patterns inherits the fix automatically. Land this before adding new cache entries to avoid widening the synchronized-expiry surface.

---

## Pattern 3 — `rememberLocked` wrap for Hydrogen internal controllers

**Closes 3 unique findings (2 P1 · 1 P2):** SCALE-B#CACHE-1, SCALE-B#CACHE-2, SCALE-B#CACHE-7

**Effort:** ~1.5 days

### Root cause

Three Hydrogen-storefront-facing internal controllers serve high-read public traffic with **zero server-side caching**:

| Controller | Per-request work | Hit pattern |
|------------|------------------|-------------|
| `HydrogenAffiliateController::show()` | 13+ DB queries — `ProfessionalIntegration`, `Professional`, `BrandPartnerLink`, `Site`, all `Blocks`, gallery `SiteMedia` + `mediaVariants`, content images, links, bio, document, newsletter, services, booking | Every public storefront page view (end-customer browsing affiliate pages) |
| `HydrogenBrandConfigController::show()` | 5+ DB queries — `ProfessionalIntegration`, `Professional`, `Site`, `BrandStoreSettings`, `SiteMedia` | Every Hydrogen storefront initial render, every visitor, every brand |
| `HydrogenAffiliateProductsController::show()` | `AffiliateProductSelection`, `BrandPartnerLink`, `ProfessionalIntegration`, `Site`, custom-photo `SiteMedia` + `mediaVariants` | Every affiliate page view after initial load |

The data these endpoints assemble changes only on explicit dashboard edits — site settings, profile updates, gallery uploads, product curation. The canonical caching pattern (`CacheLockService::rememberLocked` with TTL + SWR + push-invalidation) is already established in `BrandCommerceAnalyticsController` and `AffiliateCommerceAnalyticsController`; these three controllers predate it.

### What to do

- [ ] **Step 1 — Add three new cache keys to `CacheKeyGenerator`.**
    - `hydrogenAffiliate(string $brandProfessionalId, string $affiliateHandle): string` → `"hydrogen:affiliate:v1:{$brandId}:{$handle}"`
    - `hydrogenBrandConfig(string $shopDomain): string` → `"hydrogen:brand-config:v1:{$shopDomain}"`
    - `hydrogenAffiliateProducts(string $affiliateId): string` → `"hydrogen:affiliate-products:v1:{$affiliateId}"`
- [ ] **Step 2 — Wrap `HydrogenAffiliateController::show()`** (`app/Http/Controllers/Api/Internal/HydrogenAffiliateController.php:44–82`).
    - Inject `CacheLockService` via the constructor.
    - Wrap the entire payload assembly in `$this->cacheLock->rememberLocked($key, 60, fn () => [...])`. Pass `60` (int seconds), not a `DateTimeInterface` — see Pattern 4 Step 4.
    - Keep the `Cache-Control: no-store` response header for now; SCALE-B#CACHE-9 follow-up handles CDN strategy.
    - Closes SCALE-B#CACHE-1.
- [ ] **Step 3 — Wrap `HydrogenBrandConfigController::show()`** (`app/Http/Controllers/Api/Internal/HydrogenBrandConfigController.php:27–70`).
    - Inject `CacheLockService`; wrap response body in `rememberLocked($key, 60, ...)`.
    - Closes SCALE-B#CACHE-2.
- [ ] **Step 4 — Wrap `HydrogenAffiliateProductsController::show()`** (`app/Http/Controllers/Api/Internal/HydrogenAffiliateProductsController.php:35–78`).
    - Same pattern: inject `CacheLockService`, wrap in `rememberLocked($key, 60, ...)`.
    - Closes SCALE-B#CACHE-7.
- [ ] **Step 5 — Add three push-invalidation methods to `SiteCacheService`.**
    - `forgetHydrogenAffiliate(string $siteId): void` — invoked by `SiteObserver`, `BlockObserver`, `SiteMediaObserver` on any site/block/media write. Calculate the affected key from the site's affiliate handle.
    - `forgetHydrogenBrandConfig(string $professionalId): void` — parallel to the existing `forgetBrandDesign`. Invoked from `BrandStoreSettings` observer (`app/Observers/Core/BrandStoreSettingsObserver.php` or `app/Observers/Retail/BrandStoreSettingsObserver.php` — both exist; check which fires for Shopify-integration writes) and the Shopify integration provision/teardown paths.
    - `forgetHydrogenAffiliateProducts(string $affiliateId): void` — invoked when `AffiliateProductSelection` rows change (add an observer if none exists yet, or hook the explicit forget into the curation write endpoints) and when custom-photo `SiteMedia` rows change (via `SiteMediaObserver`).
- [ ] **Step 6 — Verify `Cache-Control: no-store` preservation.** All three controllers serve into the Hydrogen storefront layer; the existing `no-store` on `HydrogenAffiliateController` was added in commit `b9de807` to prevent Oxygen/CDN from caching stale payload shapes across deploys. Keep it on all three until SCALE-B#CACHE-9 lands the deploy-keyed ETag strategy.

### Plain English

Three internal endpoints power every page render on every brand's Hydrogen storefront. Each one assembles its response by querying anywhere from 5 to 13 database tables fresh on every request — even though the underlying data changes maybe once a day per brand. We already have a proven caching pattern in use for the commerce analytics endpoints (one worker fetches, everyone else either waits briefly or gets the last-good value, and a write event clears the cache instantly). The fix is to apply the same pattern to these three endpoints. End-customers see no behavioural change; the database does a fraction of the work.

### Why this is the third-priority pattern

These three findings have the largest blast radius after Pattern 2 (every public page view hits them) but a low-risk fix: the pattern is mechanical and battle-tested elsewhere in the codebase. Sequencing after Pattern 4 means the new cache entries inherit jittered stale TTLs from day one.

---

## Pattern 1 — `Cache::memo()->remember` → `CacheLockService::rememberLocked` migration

**Closes 4 unique findings (4 P2):** SCALE-A#CACHE-1, SCALE-A#CACHE-2, SCALE-B#CACHE-5, SCALE-B#CACHE-6 (and SCALE-D#CACHE-4 which is the dup-bundle of SCALE-A#CACHE-1 + SCALE-A#CACHE-2)

**Effort:** ~1 day

### Root cause

The `Cache::memo()->remember()` API is a subtle antipattern. The surface looks identical to `CacheLockService::rememberLocked()`:

```php
Cache::memo()->remember($key, $ttl, fn () => $expensive())
```

But `Cache::memo()` returns an `Illuminate\Cache\MemoCache` instance whose `remember()` method first checks an in-memory request-scoped cache, then on miss delegates to **the bare Laravel `Cache::remember()`** — which has no distributed lock, no jitter, and no SWR layer. When a Redis key is cold (post-deploy, eviction, or a `Cache::forget` on a write), every PHP-FPM/Horizon worker independently executes the closure.

Four call sites currently use this pattern, three of them against **external API calls** with rate limits:

| File:line | Closure | Upstream cost |
|-----------|---------|---------------|
| `AffiliateProductCatalogService.php:192` (`fetchActiveCatalog`) | Shopify **Storefront** GraphQL paginated query | Shopify per-storefront rate limit |
| `BrandCatalogService.php:374` (`fetchBrandCatalog`) | Shopify **Admin** GraphQL paginated query | 1000 points/sec Admin API budget |
| `BrandCatalogService.php:835` (`brandCollectionGid` key) | Shopify Admin GraphQL lookup | Same Admin API budget |
| `HydrogenBrandDesignController.php:53` (`show`) | DB queries + `BrandDesignMediaService::listDesignMedia()` | DB only, but 5s TTL means frequent rebuilds |
| `EmbeddedProductAnalyticsController.php:43` (`show`) | `commerce.order_items` join + `BrandCatalogService::fetchBrandCatalog()` (Admin API) | DB + Shopify Admin |

The Shopify Admin API in particular has a 1000-point/second leak-refill bucket per store; a stampede of 3–4 parallel full-catalog fetches (brand admin with multiple tabs, embedded setup loading on the same page) can exhaust the bucket and trigger 429s.

Additionally, `Cache::memo()->remember` with a `DateTimeInterface` TTL (e.g. `now()->addMinutes(5)`) bypasses `CacheLockService::writeWithJitter`'s ±20% jitter — jitter is only applied to int-second TTLs. Even after migration, the call sites must pass int seconds.

### What to do

- [ ] **Step 1 — Migrate `AffiliateProductCatalogService::fetchActiveCatalog()`. URGENT — stampede risk worsened 2026-05-12.**
    - **Post-baseline note:** PR #17 (`bef81ef`) switched the underlying API from Storefront → Admin. The cache wrapper (`Cache::memo()->remember`, now at lines 190–197) was untouched. Cold-cache concurrent requests now race against the Admin API's 1000-pt/sec budget — and they share that budget with `BrandCatalogService::fetchBrandCatalog` (Step 2 below). Two services, same bucket, no lock. **Land Step 1 + Step 2 in one PR; don't ship them separately.** PR #17 also renamed the closure target: `queryStorefrontCatalog` → `queryAdminCatalog`.
    - Inject `CacheLockService` via the constructor.
    - Replace:
        ```php
        Cache::memo()->remember(
            CacheKeyGenerator::brandActiveCatalog($brandProfessionalId),
            now()->addMinutes(5),
            fn () => $this->queryAdminCatalog($brandProfessionalId),
        );
        ```
    - With:
        ```php
        $this->cacheLock->rememberLocked(
            CacheKeyGenerator::brandActiveCatalog($brandProfessionalId),
            300,
            fn () => $this->queryAdminCatalog($brandProfessionalId),
        );
        ```
    - **Critical:** pass `300` (int), not `now()->addMinutes(5)` — `DateTimeInterface` deadlines skip jitter.
    - Extend existing bust calls (verify line numbers post-PR #17/#24; method names unchanged) to also forget the SWR stale key: `Cache::forget(CacheKeyGenerator::brandActiveCatalog($id).':stale')`.
    - **Safe-sequencing note:** this is a pure cache-layer wrap — same cache key, same GraphQL query, same response shape, just adds a Redis lock around cold-cache regeneration. Zero behaviour change for callers. Land before Phase 4 `#DB-D#SCALE-1` (routing through `ShopifyAdminClient`) so the throttle waits added there don't compound on unbounded concurrency.
    - Closes SCALE-A#CACHE-1 / SCALE-D#CACHE-4 (Affiliate half).
- [ ] **Step 2 — Migrate `BrandCatalogService::fetchBrandCatalog()`.**
    - Inject `CacheLockService` via the constructor.
    - Replace `Cache::memo()->remember` at line 374 with `$this->cacheLock->rememberLocked(...)`. The config value `partna.cache.ttls.brand_admin_catalog` is already an int — jitter applies automatically.
    - Repeat for the `brandCollectionGid` key at line 835 (same root cause).
    - Extend all four bust sites (lines 588, 677, 711, 982) to also clear `CacheKeyGenerator::brandAdminCatalog($id).':stale'`.
    - Closes SCALE-A#CACHE-2 / SCALE-D#CACHE-4 (Brand half).
- [ ] **Step 3 — Migrate `HydrogenBrandDesignController::show()`.**
    - Inject `CacheLockService` (parallel to the existing `BrandDesignMediaService` injection).
    - Replace `Cache::memo()->remember($cacheKey, self::CACHE_TTL_SECONDS, ...)` with `$this->cacheLock->rememberLocked($cacheKey, self::CACHE_TTL_SECONDS, ...)`. TTL is already a const int (`5`); no DateTime concern.
    - Keep the 5s TTL — appropriate for rapid design-change propagation. Pattern 4 jitter is moot at 5s (the synchronized-expiry window is too narrow to matter); the SWR fast path is the real win.
    - Push-invalidation already exists via `SiteCacheService::forgetBrandDesign(string $siteId)` — no changes needed.
    - Closes SCALE-B#CACHE-5.
- [ ] **Step 4 — Migrate `EmbeddedProductAnalyticsController::show()`.**
    - Inject `CacheLockService` via the constructor.
    - Replace `Cache::memo()->remember($cacheKey, now()->addMinutes(5), ...)` with `$this->cacheLock->rememberLocked($cacheKey, 300, ...)`. **Pass `300` (int), not `now()->addMinutes(5)`.**
    - Add push-invalidation: extend `AnalyticsCacheService::invalidateAnalytics()` or the Shopify orders webhook handler to `Cache::forget("embedded:product-analytics:{$professionalId}:{$productId}")` when a new order for that product arrives. The key pattern is already correctly namespaced per brand + product, so targeted forget is straightforward.
    - Closes SCALE-B#CACHE-6.
- [ ] **Step 5 — Add a CI lint for `Cache::memo()->remember`.** A composer guard (similar to `guard:no-laravel-migrations`) that fails the build if `Cache::memo()->remember` appears in any new file under `app/`. Reason: the API surface is indistinguishable from `rememberLocked` at a glance, and future contributors will reach for `Cache::memo()` by Laravel convention. CI enforcement prevents regression.

### Plain English

There's a Laravel cache helper called `Cache::memo()->remember(...)` that looks exactly like our usual `rememberLocked` but skips the "only one worker fetches at a time" lock. Four places in the codebase use this helper to wrap calls to Shopify's APIs — and Shopify has rate limits. When the cached value expires and 20 affiliates load the same brand's catalog at the same moment, the current code hits Shopify 20 times instead of once. Sometimes Shopify pushes back with an error. The fix is to swap the helper for the proper locked version in those four files, and add an automated check that warns us if anyone reaches for the wrong helper in the future.

### Why this is the fourth-priority pattern

Four findings, all P2, all the same one-line swap plus constructor injection. The educational value is high — Step 5's CI lint prevents the same antipattern from recurring, the same way the `guard:no-laravel-migrations` rule protects the Supabase-migration boundary. After Patterns 2 and 4, this is the largest single sweep available; sequencing it after the cache layer is hardened (Pattern 4) means the migrated keys inherit jittered stale TTLs immediately.

---

## Pattern 5 — Analytics cache invalidation cleanup

**Closes 2 unique findings (2 P3):** SCALE-C#CACHE-2, SCALE-D#CACHE-6

**Effort:** ~0.5 day

### Root cause

`AnalyticsCacheService::invalidateAnalytics` (`app/Services/Cache/AnalyticsCacheService.php:96–107`) is a transitional method with three sections:

1. **`bumpAnalyticsVersion`** — correct, busts commerce keys atomically (single Redis `INCR`).
2. **Explicit `Cache::forget` loop for `affiliateProjections`** — necessary because `affiliateProjections` uses a static schema-version prefix (`v1`), not the dynamic runtime counter.
3. **90-day `deleteMultiple` loop for visits/clicks keys** — **dead code**. A codebase-wide grep (SCALE-C#CACHE-2 evidence) confirms `AnalyticsCacheService::getVisitStats` and `getClickStats` appear only in their definitions; **zero callers**. The keys the loop targets (`analytics:visits:{id}:{Ymd}:{Ymd}` and `analytics:clicks:{id}:{Ymd}:{Ymd}`) are never written. Every `deleteMultiple` call silently returns 0 for all 180 keys.

The 90-day loop costs 180 Redis keys per `invalidateAnalytics` call, run synchronously on the public site analytics ingest path (every `pageview`, `click`, `cartEvent` request). At pre-beta scale (< 100 events/day) negligible; at 1000 events/day it's 1000 wasted Redis round-trips with 180-key payloads, on the public HTTP request thread.

Commerce/Stripe paths call `bumpAnalyticsVersion` directly and bypass this dead loop entirely — only the public site ingest pays the cost.

### What to do

- [ ] **Step 1 — Delete the 90-day deletion loop** (`AnalyticsCacheService.php:96–107`).
    - Remove the `for ($i = 0; $i < 90; $i++) { ... }` block that populates `$keys[]` with `analyticsVisits` / `analyticsClicks` entries.
    - Remove the trailing `Cache::deleteMultiple(array_values(array_unique($keys)))` if `$keys` is no longer populated elsewhere.
    - Closes SCALE-C#CACHE-2.
- [ ] **Step 2 — Decide on `getVisitStats` / `getClickStats`.**
    - If these methods are intended for a future feature: leave them in place, add a docblock noting why they're unused, and reference the (now-removed) invalidation loop in a `// see invalidateAnalytics history` style comment for the future re-enabler. Cross-reference via commit message.
    - If they're abandoned: delete the methods. Cleaner.
    - SCALE-C#CACHE-2's adjudication note suggests the second option. Confirm with Josh.
- [ ] **Step 3 — Optionally dispatch `invalidateAnalytics` async on the public ingest path.**
    - SCALE-C#CACHE-2 raises this as a follow-up: even after Step 1, the remaining invalidation work (`bumpAnalyticsVersion` + projection forget loop) is synchronous on the public HTTP request thread. Move to `dispatchAfterResponse` or a short-lived queued job to decouple ingest P99 latency from Redis round-trip count.
    - **Defer** if pre-beta event volume is low (< 100/day). Revisit at pilot launch.
- [ ] **Step 4 — Audit remaining strategies per SCALE-D#CACHE-6.**
    - `analyticsSummary` (q3 keys) — verify whether still populated post-Phase-4. If yes, choose: embed dynamic version token (preferred) or add to explicit deletion list.
    - `affiliateProjections` — currently uses static schema prefix `v1` plus explicit `Cache::forget` loop. Migrating to dynamic version-token pattern is a separate refactor (~M effort); not in scope for this P3 cleanup unless trivial. Document the decision in a code comment.
    - Result: a single, documented invalidation strategy in `invalidateAnalytics`. Closes SCALE-D#CACHE-6.

### Plain English

The analytics cache cleanup method has three pieces of cleanup logic. One uses a clever version-number trick that's instant. One does an explicit list of keys to delete. The third spends a Redis round-trip vacuuming 180 keys across the last 90 days — but those 180 keys are never written by anything in the codebase (confirmed by searching every file). So the vacuum is cleaning empty rooms on every page visit. The fix is to delete that block. Optionally, we can also move the remaining work off the request thread so the ingest endpoint is faster.

### Why this is the fifth-priority pattern

Lowest-severity findings (both P3), but extremely high reading-confidence (grep showed zero callers) and trivial fix (delete the loop). Land it after Patterns 1–4 to avoid touching the same file that Pattern 1 may have touched for invalidation coordination of catalog services.

---

# Part 2 — Standalone fixes

These four findings don't fit one of the foundational patterns. Land them in any order after Part 1 — or interleave SCALE-B#CACHE-9 with Pattern 3 (it explicitly depends on SCALE-B#CACHE-1 from that pattern).

## SCALE-D#CACHE-1 · P2 — `SiteObserver::saved` duplicate `BrandPartnerLink` lookup

- **Where:** `app/Observers/Core/SiteObserver.php` (`saved` → `cascadeAffiliateKvSync`) + `app/Services/Cache/SiteCacheService.php` (`invalidateSite` affiliate loop)
- **Effort:** M (~2–4h)
- **What to do:**
    - Refactor `SiteObserver::saved` to collect `affiliate_professional_id` from `BrandPartnerLink` **once**, sharing the result between `invalidateSite` and `cascadeAffiliateKvSync`.
    - Two implementation options (pick one):
        1. Add a `$connectedAffiliateIds = null` parameter to `SiteCacheService::invalidateSite` and to `cascadeAffiliateKvSync`. The observer fetches the list once and threads it into both.
        2. Have `invalidateSite` return the queried affiliate IDs (already collected internally) and the observer reuses them for `cascadeAffiliateKvSync`.
    - **Critical correctness note:** beyond saving a DB query, the single-snapshot fix closes a small race window. Currently `invalidateSite` and `cascadeAffiliateKvSync` take two separate snapshots back-to-back; an affiliate invite landing in that window gets cache-invalidated but not KV-synced (or vice versa).
- **Plain English:** When a brand renames their subdomain, the system makes the exact same database call twice in a row — once to clear caches for connected affiliates and once to update the KV routing table. The fix is to make the call once and hand the result to both jobs. As a bonus, this closes a tiny timing hole where a new affiliate added during the rename could end up in one update but not the other.

## SCALE-B#CACHE-8 · P3 — `StaffAnalyticsController` runs 5 uncached aggregation queries per page load

- **Where:** `app/Http/Controllers/Api/Staff/StaffSite/StaffAnalyticsController.php:44–121` (`summary` method)
- **Effort:** S (~0.5–1h)
- **What to do:**
    - Wrap the `summary()` response body in `CacheLockService::rememberLocked` keyed by professional ID + date range string, with a 60–120s TTL.
    - No push-invalidation needed at staff-tool traffic levels; TTL-only is fine. Jitter is automatic for int TTLs after Pattern 4 lands.
    - Reference implementation: `StaffStatsController` already uses this pattern.
- **Plain English:** Each time a support person views a professional's analytics page, the server runs five separate statistical queries against the heaviest indexed tables in the schema — even if the page was just refreshed 10 seconds ago. A 60-second cache makes the page feel instant and reduces load on `analytics.site_visits` and `analytics.link_clicks`.

## SCALE-D#CACHE-5 · P3 — `ServiceObserver::runHooks` issues one `Professional` query per service save

- **Where:** `app/Observers/Core/ServiceObserver.php` (`runHooks` → `bust`)
- **Effort:** M (~2–4h)
- **What to do:**
    - **Sub-step A (mandatory):** Remove dead code per `project_booking_dropped.md`. `reevaluateBooking` (the `'booking'` section re-evaluation path) and the Square/Fresha sync dispatches are guarded by feature flags that are permanently `false`. Delete the unreachable branches; keep only `reevaluateEnabled('services')`.
    - **Sub-step B (optional, low priority at pre-beta scale):** Add a per-request debounce — track `professional_id`s already busted in this request via a static map; skip subsequent busts on the same professional.
    - **Sub-step C (deferred):** Only implement a full `BatchServiceChangeJob` batching system if a bulk-import endpoint or CSV importer is actually built. At current scale (brands editing services one at a time via UI), the 50-query scenario doesn't occur.
- **Plain English:** When a brand saves a service edit, the system looks up the professional's record, clears their caches, and re-checks visibility for *two* website sections — but one of those sections (booking) was dropped from the product. Step one: delete the dead booking-related code. Step two (only if we ever build a bulk-import tool): batch the save events into one cache-bust per import.

## SCALE-B#CACHE-9 · P3 — `HydrogenAffiliateController` `Cache-Control: no-store` blocks all edge caching

- **Where:** `app/Http/Controllers/Api/Internal/HydrogenAffiliateController.php:82`
- **Effort:** S (~0.5–1h) — **contingent on SCALE-B#CACHE-1 landing first** (Pattern 3, Step 2)
- **What to do:**
    - After Pattern 3 Step 2 puts Redis-backed `rememberLocked` on the controller, evaluate one of two paths:
        1. Change `Cache-Control: no-store` to `public, max-age=0, must-revalidate` plus an `ETag` derived from a deploy-version token (or the Redis cache key's version segment). Allows Oxygen/CDN edge nodes to serve `stale-while-revalidate` without leaking personalised data.
        2. Keep `no-store`, rely entirely on the Redis SWR layer. Simpler; appropriate if affiliate-page content is considered personalised (e.g. `linked` status check per visiting brand).
    - The original `no-store` was added in commit `b9de807` to prevent Oxygen/CDN from caching a stale payload shape across deploys. Either fix must address that concern: a deploy-keyed `ETag` (path 1) or no edge caching at all (path 2).
- **Plain English:** Today the affiliate storefront endpoint tells every server-along-the-way "don't cache me, ever" — which is safe but wastes a performance opportunity once we have a working server-side cache. After Pattern 3 lands the server cache, we can let the content-delivery network hold a copy too — making affiliate pages feel faster for visitors geographically far from our servers. A version tag in the cache header protects against stale layouts after deploys.

## SCALE-C#CACHE-1 · P3 — Booking milestone totals cache TTL-only

- **Where:** `app/Services/Notifications/CommerceNotificationService.php:134`
- **Effort:** S (~0.5–1h) — **likely defer indefinitely**
- **What to do:**
    - **Booking is dropped per `project_booking_dropped.md`.** If the booking feature stays paused, this finding is moot — leave the existing 60-second TTL behaviour as-is.
    - If booking is ever revived: add `Cache::forget(CacheKeyGenerator::bookingMilestoneTotals($professionalId))` after `$this->publisher->publish(...)` in `notifyBookingCompleted()` so milestone notifications fire on the triggering booking, not up to 60s later.
- **Plain English:** A booking-related milestone notification (e.g. "you reached 10 total bookings") could fire up to 60 seconds late because the booking count cache is TTL-only. Since the booking feature is paused, this is a tidy-up for whenever bookings are revisited — defer indefinitely.

---

# Appendix — Suggested PR bundling

Group findings by file overlap so reviewers see all touches on a surface in one diff. The pattern numbers below refer to Part 1 sections.

| PR bundle | Findings | Pattern |
|-----------|----------|---------|
| `embedded-app-commission-migration` | SCALE-B#CACHE-3 + SCALE-B#CACHE-4 | Pattern 2 |
| `cache-stale-ttl-jitter` | SCALE-D#CACHE-2 + SCALE-D#CACHE-3 | Pattern 4 |
| `hydrogen-internal-caching` | SCALE-B#CACHE-1 + SCALE-B#CACHE-2 + SCALE-B#CACHE-7 (+ SCALE-B#CACHE-9 as the CDN follow-on) | Pattern 3 |
| `catalog-services-rememberLocked` | SCALE-A#CACHE-1 + SCALE-A#CACHE-2 (collapses SCALE-D#CACHE-4) | Pattern 1 |
| `embedded-product-analytics-rememberLocked` | SCALE-B#CACHE-5 + SCALE-B#CACHE-6 | Pattern 1 |
| `analytics-invalidation-cleanup` | SCALE-C#CACHE-2 + SCALE-D#CACHE-6 | Pattern 5 |
| `site-observer-dedupe` | SCALE-D#CACHE-1 | standalone |
| `staff-analytics-cache` | SCALE-B#CACHE-8 | standalone |
| `service-observer-cleanup` | SCALE-D#CACHE-5 | standalone |

`catalog-services-rememberLocked` and `embedded-product-analytics-rememberLocked` are both Pattern 1, but they touch different files and have different upstream dependencies (Shopify Admin/Storefront APIs vs. local DB joins). Separating into two PRs keeps each diff focused; reviewing them together as the same pattern is still encouraged.

---

# Appendix — New audit-worthy concerns surfaced 2026-05-12

These items emerged from cross-referencing PR #12–#25 against this plan. They are not yet in the audit ledger; folding them into the next Phase 3 sweep is recommended.

## New Admin-API bypass call site in `queryAdminCatalog` (cross-references Phase 4 `#DB-D#SCALE-1`)

PR #17 (`bef81ef`) introduced `AffiliateProductCatalogService::queryAdminCatalog()` (`app/Services/Store/AffiliateProductCatalogService.php:597–777`). It uses raw `Http::timeout(20)` directly instead of routing through `ShopifyAdminClient` — no budget pre-acquire, no cost-header tracking, no `ShopifyThrottledException` handling.

**What to do:** track under Phase 4 `#DB-D#SCALE-1`. **Sequencing matters:** complete Pattern 1 Step 1 (above) first to close the stampede via `rememberLocked`. Only then route this through `ShopifyAdminClient` to add budget machinery. Doing the reverse — adding budget waits to an unbounded-concurrency caller — creates cold-cache blocking under load.

## Weekly KV fan-out (P2 — write amplification under existing Pattern coverage)

Commit `fedcb66` schedules `partna:backfill-subdomain-kv --all --queue` weekly (Sundays 04:00 UTC, `routes/console.php:174–184`). At 1,500 professionals (pilot target), this dispatches one `SyncSubdomainToKvJob` per professional + alias every week.

**What to do:** no new code change in this plan — the cron is correctly `onOneServer + withoutOverlapping`, and `SyncSubdomainToKvJob` is queued, so the burst is naturally spread by Horizon. But:
- Phase 2 `#LIFE-D#6` (Cloudflare `$backoff` on `SyncSubdomainToKvJob`) becomes more leveraged — keep Pattern C high in the merge order.
- `BackfillSubdomainKvCommand` itself uses `Professional::query()->whereNotNull('handle')->pluck('id')` (`app/Console/Commands/BackfillSubdomainKvCommand.php:42–46`). At 10K+ affiliates this becomes a memory concern. Add a follow-up: switch the command to `chunkById` over Professionals rather than `pluck()` the whole set. **Tier:** P3 today, P2 once affiliate fleet grows.

## Embedded-setup redispatch broadened (P2 — Pattern 3 watch)

Commit `66c8add` switched the missing-collection-handle check from `AND` to `OR` across all four handles (active, default, favourites, high_commission) and added `CreateShopifyCollectionsJob` to the explicit dispatch list. The job is `ShouldBeUnique + findOrCreate-by-title` per the inline comment, so idempotent in principle.

**What to do:** verify the `ShouldBeUnique` lock TTL is generous enough to cover the wizard-thrash scenario (brand reloads embedded app multiple times during setup → `provisionShopifyIntegration` fires per reload → redispatch fires per reload). If the `findOrCreate-by-title` cost on the Shopify Admin API exceeds budget under tight reload cycles, the audit's Pattern 3 sequencing matters more: caches landing first reduces the read-side amplification that triggers the reload cycle in the first place. Currently no live regression; flagging for verification.

## Two one-time Shopify migration commands consume Admin API budget (P3 — one-shot)

`MigrateMetafieldNamespaceCommand` (`8327d1f`) and `ReconcileSmartCollectionRulesCommand` (`1c03040`) perform per-brand Shopify Admin GraphQL writes. Not scheduled; one-shot per brand. Both use plain `->get()` to iterate `ProfessionalIntegration` rows — no `chunkById`, no progress checkpointing. A failure midway leaves partially-migrated state with no resume affordance.

**What to do:** at current fleet size (<10 connected brands), this is operationally fine. As the fleet grows past 50 brands, add `chunkById` iteration + a `--resume-from=<integration_id>` flag to both commands. **Tier:** P3 today, P2 at scale. Add to the runbook for the namespace migration window.

## `Cache::memo()->remember` regression risk increased — Pattern 1 Step 5 CI lint becomes more valuable

The two-services-share-one-Admin-API-budget situation created by PR #17 means the CI lint in Pattern 1 Step 5 is no longer "defense in depth" — it's actively protecting against the next contributor reaching for `Cache::memo()` on a third Admin-API caller and creating a three-way stampede.

**What to do:** prioritise Pattern 1 Step 5 alongside Step 1 in the same PR. Don't defer the lint to a follow-up.

---

# Verification

After each pattern lands, verify with:

```bash
composer test                    # full Pest suite
php artisan test --compact --filter=Cache         # cache-layer tests
php artisan test --compact --filter=Analytics     # analytics-layer tests
```

Pattern-specific spot checks:

- **Pattern 2 (commission migration):** open the embedded Shopify admin overview panel on a dev brand with non-zero attributed sales; confirm `total_commission_cents` and `recent_sales` show real numbers, not zero/empty.
- **Pattern 4 (stale TTL jitter):** Tinker `dump((new CacheLockService(...))->testJitter(60))` over 100 iterations; assert `$staleTtl` distribution spans ±20% of the stale window, not a single value.
- **Pattern 3 (Hydrogen caching):** load any storefront page twice in quick succession with Laravel Debugbar enabled; second hit should show 0 DB queries for the cached envelopes.
- **Pattern 1 (Cache::memo migration):** `rg "Cache::memo\(\)->remember" app/` should return zero matches after Step 5's CI lint lands.
- **Pattern 5 (analytics cleanup):** `rg "getVisitStats|getClickStats" app/` should return zero call sites; the 90-day loop should be gone from `invalidateAnalytics`.
