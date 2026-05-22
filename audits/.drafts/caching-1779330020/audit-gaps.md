`★ Insight ─────────────────────────────────────`
Three important verification findings before adjudicating:
1. **DeepSeek CCG-3 (services-commerce) is a false positive** — `AffiliateProjectionsController` already wraps `build()` in `CacheLockService::rememberLocked` with configurable TTL (line 56-60). The service itself is a pure computation layer; caching belongs at the call site, and it's there.
2. **Square/booking findings fail the "active path" test** — both Square endpoints are behind `feature:smart_booking` middleware, and CLAUDE.md memory records that booking was dropped 2026-05-11. These don't meet the "hot path" criterion.
3. **The LOWER() email scan (CCG-2 in invite controller) is a schema/index issue**, not a cache-gap — the email list input is per-page and changes per request, making a stable cache key impossible without a different data structure entirely.
`─────────────────────────────────────────────────`

# Caching Coverage-Gap (CCG) Audit — 2026-05-21

**Branch:** development
**Lens:** Whole-backend caching COVERAGE-GAP audit. A finding must be defensible as hot AND expensive AND multi-caller.
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Http/Controllers/Api/Professional/Affiliate/AffiliateOrdersController.php
- app/Http/Controllers/Api/Professional/Brand/BrandAffiliateController.php
- app/Http/Controllers/Api/Professional/Brand/BrandAffiliateInviteController.php
- app/Http/Controllers/Api/Professional/Brand/BrandBillingSummaryController.php
- app/Http/Controllers/Api/Professional/Brand/BrandOrdersController.php
- app/Http/Controllers/Api/Professional/Brand/BrandPartnerController.php
- app/Http/Controllers/Api/PublicSite/PublicBookingController.php
- app/Services/Analytics/AffiliateProjectionsService.php
- app/Services/Professional/Brand/BrandOnboardingReadinessService.php
- app/Services/Professional/Brand/BrandStatusService.php
- app/Services/Store/AffiliateProductCatalogService.php
- app/Services/Store/BrandCatalogService.php
- app/Services/Stripe/CommissionPayoutService.php

## Progress

- P0 Blockers: 0 of 0 complete
- P2 Medium: 0 of 7 complete

---

## P2 — Should fix

- [ ] **#CCG-1** · P2 — Payout summary runs two grouped aggregates on every payout-list load, uncached
    - **Where:** app/Services/Stripe/CommissionPayoutService.php:843-860, called from app/Http/Controllers/Api/Professional/Stripe/StripeConnectController.php:390
    - **Affects:** Every brand and affiliate loading their payout history page — two `SELECT status, COUNT(*), SUM(...)  GROUP BY status` scans fire on every request with no TTL, no single-flight lock, and no invalidation strategy.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap `getPayoutSummary()` in `CacheLockService::rememberLocked` with a `CacheKeyGenerator` key scoped to `professional_id` and a 60–120s TTL.
        - Bust the key from `markCompleted()`, `failPayout()`, and `cancelExpiredPayout()` so terminal-state transitions surface immediately rather than waiting for TTL expiry.
    - **Technical:** `getPayoutSummary()` fires two separate `selectRaw('status, COUNT(*) as count, SUM(...) as total_cents')->groupBy('status')->get()` queries against `commerce.commission_payouts` — one scoped to the professional as brand, one as affiliate. Both are full-table aggregate scans (groupBy forces a sequential pass or hash-aggregate over the indexed partition). The controller does not wrap the call in any cache layer. `AnalyticsCacheService::bumpAnalyticsVersion` is already called from `markCompleted()`, making invalidation straightforward. The method has no docblock delegating caching upward (unlike `StripeTransactionFetcher`), so it is not architecture-by-convention that the caller caches it.
    - **Plain English:** Every time a brand or affiliate opens their payout history page, the system re-adds up every payout from scratch — totals by status, count by status — even if nothing changed since their last visit. Think of it as re-running a full accounting report every time someone glances at the summary line. A 60-second memory for that report makes the page snappy without ever showing numbers that are more than a minute stale.
    - **Evidence:**
        ```php
        public function getPayoutSummary(Professional $professional): array
        {
            $asBrand = CommissionPayout::query()
                ->where('brand_professional_id', $professional->id)
                ->selectRaw('status, COUNT(*) as count, SUM(gross_commission_cents) as total_cents')
                ->groupBy('status')
                ->get()
                ->keyBy('status');

            $asAffiliate = CommissionPayout::query()
                ->where('affiliate_professional_id', $professional->id)
                ->selectRaw('status, COUNT(*) as count, SUM(net_payout_cents) as total_cents')
                ->groupBy('status')
                ->get()
                ->keyBy('status');
        ```

- [ ] **#CCG-2** · P2 — Billing summary aggregate fires on every brand dashboard load for card-less brands
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandBillingSummaryController.php:31-40
    - **Affects:** Brands without a Stripe payment method on file — every dashboard open runs a `COUNT(*) + SUM(commission_cents)` aggregate over `commerce.orders` with no cache layer.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `$blockedData` query in `CacheLockService::rememberLocked` with a key scoped to `brand_professional_id` and a 60s TTL.
        - Bust the key from the Shopify order webhook handler (when new approved orders land) and from the Stripe payment-method webhook (when a brand adds their first card, the `$hasCard` check will short-circuit anyway, making the bust a no-op in practice).
    - **Technical:** When `$hasCard` is false, the controller fires `Order::query()->where(...)->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(commission_cents), 0) AS pending_cents')->first()`. This is an aggregate scan over all `approved`, `payout_id IS NULL`, `refund_cents = 0`, `rate_source != 'pending'` orders for the brand. The result is identical for every page view within a 60s window. No `Cache::` call, `rememberLocked`, or upstream delegation is present. Pre-launch brands without a card hit this on every dashboard open; the fix is a short TTL + push-invalidate on order webhook ingest.
    - **Plain English:** For brands that haven't connected a payment method yet, every time they open their billing summary the system counts and sums every unpaid order from scratch. This is like asking the warehouse manager to physically re-count every pending invoice each time you check the dashboard. Cache the count for 60 seconds and recalculate only when new orders arrive.
    - **Evidence:**
        ```php
        $blockedData = (object) ['cnt' => 0, 'pending_cents' => 0];
        if (! $hasCard) {
            $blockedData = Order::query()
                ->where('brand_professional_id', $brand->id)
                ->where('status', 'approved')
                ->whereNull('payout_id')
                ->where('refund_cents', 0)
                ->where('rate_source', '!=', 'pending')
                ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(commission_cents), 0) AS pending_cents')
                ->first();
        }
        ```

- [ ] **#CCG-3** · P2 — Brand affiliates list runs two queries including a correlated whereHas subquery on every page load
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateController.php:32-52
    - **Affects:** Every brand loading their "My Affiliates" page — two uncached database round-trips per request, including a `whereHas('professional', ...)` correlated subquery.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap the combined links + sites resolution in `CacheLockService::rememberLocked` with a key scoped to `brand_professional_id` and a 60s TTL.
        - Bust the cache key on `BrandPartnerLink` create/delete and on `Professional` status changes that affect the affiliated shape. The existing `BrandPartnerLinkLifecycleService::disconnect()` is the natural bust site.
    - **Technical:** `index()` issues a `BrandPartnerLink` query, then plucks affiliate IDs and re-queries `Site` via `whereIn(...)->whereHas('professional', ...)`. The `whereHas` generates a correlated subquery joining `core.professionals` against the account-type dual-read OR clause needed during the §28.1 migration window. Two separate round-trips, no single-flight or TTL wrap. Note that `BrandAffiliateController` already injects `CacheLockService` in its constructor — the infrastructure is in place; only the read path lacks a `rememberLocked` call.
    - **Plain English:** Every time a brand opens the "My Affiliates" page, Partna runs two separate database searches to build the list — first to find all connected affiliate IDs, then to look up their profiles with an extra filter. Storing the result for 60 seconds would serve any rapid reloads or concurrent sessions from a single database round-trip.
    - **Evidence:**
        ```php
        $links = BrandPartnerLink::query()
            ->where('brand_professional_id', $brandId)
            ->orderByDesc('updated_at')
            ->get(['affiliate_professional_id', 'slot', 'custom_photos_enabled', 'site_url', 'updated_at']);
        // ...
        $sitesByProfessionalId = Site::query()
            ->with(['professional'])
            ->whereIn('professional_id', $affiliateIds)
            ->whereHas('professional', function ($query): void {
                $query
                    ->where('status', 'active')
                    ->where(function ($q): void {
                        $q->where('account_type', '!=', 'brand')
                            ->orWhere(function ($q2): void {
                                $q2->whereNull('account_type')->where('professional_type', '!=', 'brand');
                            });
                    });
            })
            ->get()
            ->keyBy('professional_id');
        ```

- [ ] **#CCG-4** · P2 — Favourites collection membership fetches paginated Shopify Admin API on every affiliate catalog load
    - **Where:** app/Services/Store/AffiliateProductCatalogService.php — `fetchCollectionGids()` → `BrandCatalogService::fetchCollectionProducts()`
    - **Affects:** Every affiliate loading their product catalog — the favourites collection membership list (`in_favourites` flag) is resolved via paginated Shopify Admin GraphQL calls on every request, while the main catalog is correctly cached.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap `fetchCollectionGids()` in `CacheLockService::rememberLocked` with a key from `CacheKeyGenerator` scoped to `brand_professional_id + metadataKey` (e.g. `brand_collection_gids:{brandId}:favourites`) and a 60–300s TTL.
        - Bust the key when brands add or remove products from the favourites collection via `BrandCatalogService::addProductsToCollection()` / `removeProductsFromCollection()`.
    - **Technical:** `getCatalogWithSelections()` calls `fetchCollectionGids($integration, 'favourites_collection_handle')`, which calls `brandCatalogService->resolveCollectionGid($integration, $handle)` (correctly cached) and then immediately calls `brandCatalogService->fetchCollectionProducts($integration, $collectionGid)` (not cached). `fetchCollectionProducts()` paginates through all products in the Shopify collection via Admin API GraphQL at 50 per page — for a brand with 200 favourites, that is 4 synchronous ~200ms vendor calls per affiliate catalog load. The result (a list of GIDs) is identical for every affiliate of the same brand. The collection GID is cached; the product membership list one layer underneath is not.
    - **Plain English:** Every time an affiliate opens their product catalog, the server calls Shopify to ask "which products are in the brand's favourites list?" — even if another affiliate of the same brand just loaded the same page a second ago. Shopify's servers have to answer this call every single time. Storing the answer for a few minutes and sharing it across all of a brand's affiliates would turn dozens of Shopify calls per minute into one.
    - **Evidence:**
        ```php
        // fetchCollectionGids() — calls uncached fetchCollectionProducts
        $products = $this->brandCatalogService->fetchCollectionProducts($integration, $collectionGid);
        return array_map(fn (array $p) => $p['gid'] ?? '', $products);
        ```
        ```php
        // BrandCatalogService::fetchCollectionProducts — no cache, paginates Shopify Admin API
        do {
            $variables = ['id' => $collectionGid, 'first' => self::PRODUCTS_PER_PAGE];
            if ($cursor !== null) {
                $variables['after'] = $cursor;
            }
            $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::COLLECTION_PRODUCTS, $variables);
            // ... pagination loop
        } while ($hasNextPage && $cursor !== null);
        ```

- [ ] **#CCG-5** · P2 — Brand discovery page runs three query rounds on every affiliate page load, uncached
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandPartnerController.php:103-150
    - **Affects:** Affiliate dashboard brand discovery page — three separate query rounds per page load: paginated `Professional` with `whereHas`, a logo media service call, and a supplemental query for already-connected brands.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Cache the paginated brand list + resolved logo URLs in `CacheLockService::rememberLocked` with a key scoped to `page + per_page` (the discovery page is the same for every affiliate browsing it).
        - Bust on brand profile changes (`affiliate_visibility`, `brand_status`) and on logo uploads via the existing `BrandDesignMediaService` write path.
        - The "always include connected brands" extra query is affiliate-specific and should remain uncached (or use a separate short-TTL key scoped to `affiliate_professional_id`).
    - **Technical:** `index()` issues: (1) `Professional::query()->whereHas('brandProfile', fn($q) => ...)` — a paginated correlated subquery filtering by `affiliate_visibility` and `brand_status`; (2) `$mediaService->getLogoFullUrls($pageSiteIds)` to resolve logo URLs; (3) conditionally, a second `Professional::query()->whereIn('id', $connectedIds->all())` plus another `getLogoFullUrls` call for already-connected brands not in the visible set. None of these are cached. The first two rounds are identical for every affiliate browsing the same page — a textbook `rememberLocked` candidate.
    - **Plain English:** Every affiliate who opens the "Find a Brand" page triggers three database lookups: one to get the brand list, one to fetch brand logos, and sometimes a third to make sure already-connected brands show up. All three happen fresh on every page load even if two affiliates load the exact same page simultaneously. Caching the brand list and their logos for a few minutes would collapse all those identical lookups into one.
    - **Evidence:**
        ```php
        $page = Professional::query()
            ->where(function ($q): void {
                $q->where('account_type', 'brand')
                    ->orWhere(function ($q2): void {
                        $q2->whereNull('account_type')->where('professional_type', 'brand');
                    });
            })
            ->where('status', 'active')
            ->whereHas('brandProfile', fn ($q) => $q->where('affiliate_visibility', 'public')
                ->where('brand_status', BrandStatus::ReadyForAffiliates->value))
            ->with('site')
            ->orderByRaw('COALESCE(display_name, handle) asc')
            ->paginate($perPage);

        $logoUrls = $mediaService->getLogoFullUrls($pageSiteIds);

        // ... then extra queries for connected brands not already in $brands:
        $extraBrands = Professional::query()
            ->whereIn('id', $connectedIds->all())
            // ...
            ->get();
        $extraLogoUrls = $mediaService->getLogoFullUrls(...);
        ```

- [ ] **#CCG-6** · P2 — Brand orders list runs a 4-table LEFT JOIN with pagination on every page load, uncached
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandOrdersController.php:38-56
    - **Affects:** Brand dashboard orders page — every page load (and every tab-flip back to the page) re-executes a multi-join query across `commerce.orders`, `core.professionals`, `core.customers`, and `commerce.commission_payouts`, plus a `COUNT(*)` for pagination metadata.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap the first-page no-filter path in `CacheLockService::rememberLocked` with a key scoped to `brand_professional_id + status_filter + page`, TTL 30s.
        - Limit caching to page 1 and common status filters to avoid unbounded key cardinality.
        - Bust on order webhook ingest for the brand via the existing analytics cache invalidation pathway (`AnalyticsCacheService::bumpAnalyticsVersion`).
    - **Technical:** `index()` builds a query with four `leftJoin` clauses (`commerce.orders`, `core.professionals`, `core.customers`, `commerce.commission_payouts`), applies a `whereNotIn` exclusion list, conditionally appends raw `WHERE` conditions from `applyStatusFilter`, sorts by `occurred_at`, and calls `->paginate($perPage)` — which issues both the data query and a `SELECT COUNT(*)`. Even with indexes on join columns, PostgreSQL must merge four relations per request. The result for a given brand/status/page combination is stable within a 30s window (new orders arrive via webhook, not on every page load).
    - **Plain English:** Every time a brand opens their orders page, the server stitches together four separate tables (orders, affiliates, customers, payouts), applies filters, sorts by date, and counts the total — from scratch, every time. If the same brand reloads the page twice in 30 seconds, that's two identical expensive operations. A short-term memory of 30 seconds would make rapid reloads feel instant.
    - **Evidence:**
        ```php
        $query = DB::table('commerce.orders as o')
            ->leftJoin('core.professionals as aff', 'aff.id', '=', 'o.affiliate_professional_id')
            ->leftJoin('core.customers as c', 'c.id', '=', 'o.customer_id')
            ->leftJoin('commerce.commission_payouts as cp', 'cp.id', '=', 'o.payout_id')
            ->where('o.brand_professional_id', $brandProfessionalId)
            ->whereNotIn('o.status', Order::EXCLUDED_FROM_AGGREGATES)
            ->select($this->rowColumns())
            ->orderByDesc('o.occurred_at');

        $this->applyStatusFilter($query, $statusFilter);

        $paginator = $query->paginate($perPage);
        ```

- [ ] **#CCG-7** · P2 — Affiliate orders list runs the same 4-table LEFT JOIN on every dashboard tab load, uncached
    - **Where:** app/Http/Controllers/Api/Professional/Affiliate/AffiliateOrdersController.php:67-79
    - **Affects:** Every affiliate loading their Orders tab — structurally identical to BrandOrdersController: four-table LEFT JOIN + pagination `COUNT(*)` per request, no cache layer.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Apply the same fix as CCG-6: `CacheLockService::rememberLocked` with a key scoped to `affiliate_professional_id + status_filter + page`, TTL 30s, limited to the default first-page / no-filter combination.
        - Bust the key from the Shopify order webhook handler when a new order for this affiliate is ingested.
        - Consider a shared cache-key helper so BrandOrdersController (CCG-6) and AffiliateOrdersController stay in sync — they are structurally identical read paths.
    - **Technical:** `index()` issues `DB::table('commerce.orders as o')->leftJoin('core.professionals as brand', ...)->leftJoin('core.customers as c', ...)->leftJoin('commerce.commission_payouts as cp', ...)->where('o.affiliate_professional_id', ...)->orderByDesc('o.occurred_at')->paginate(...)`. Same four-relation merge plus pagination `COUNT(*)` as CCG-6, scoped to affiliate rather than brand. The affiliate Orders tab is the primary earnings view and is the first thing affiliates open on each session. The `status` filter and page number expand the key space, so caching should target the default landing state to remain bounded.
    - **Plain English:** Same problem as the brand orders page (CCG-6), just from the affiliate's perspective. Every time an affiliate checks their orders, the database has to rebuild the same joined list from scratch. Fix both pages together — they share the same underlying query shape and the same fix applies.
    - **Evidence:**
        ```php
        $query = DB::table('commerce.orders as o')
            ->leftJoin('core.professionals as brand', 'brand.id', '=', 'o.brand_professional_id')
            ->leftJoin('core.customers as c', 'c.id', '=', 'o.customer_id')
            ->leftJoin('commerce.commission_payouts as cp', 'cp.id', '=', 'o.payout_id')
            ->where('o.affiliate_professional_id', $affiliateProfessionalId)
            ->whereNotIn('o.status', Order::EXCLUDED_FROM_AGGREGATES)
            ->select($this->rowColumns())
            ->orderByDesc('o.occurred_at');

        $this->applyStatusFilter($query, $statusFilter);

        $paginator = $query->paginate($perPage);
        ```

## Suggested Bundled Sessions

**Bundle A — Orders list cache layer (CCG-6 + CCG-7)**
Both controllers share the same 4-table query shape, the same status-filter state machine, and the same invalidation event (Shopify order webhook). Implement a shared `CacheKeyGenerator` entry (e.g. `ordersListPage(string $scopeType, string $scopeId, ?string $status, int $page)`) and wire both controllers in one session. Estimated total effort: M–L.

**Bundle B — Dashboard aggregate caches (CCG-1 + CCG-2)**
Both are simple `rememberLocked` wraps on aggregate queries with clear invalidation events already in the codebase. Neither requires schema changes. Pair them to share the `CacheKeyGenerator` convention review. Estimated total effort: S–M.
