
<!-- LENS: gaps | CHUNK: cache-infra -->

After walking every file in the provided scope, I find no reads that meet all three CCG criteria simultaneously. Here is the summary of why each candidate domain was cleared:

## Resolution & capability paths (Group A)

- **`ProfessionalCacheService`** — all lookups (`getIdByAuthId`, `getIdByHandle`, `getPayloadById`, `getByAuthId`, `getActiveServices`, `getDashboardServices`, `getBrandStoreSettings`, `getBrandPartnerStatus`, `getCustomerCount`) use `CacheLockService::rememberLocked` or `rememberLockedNullable`. No uncached hot reads.
- **`SiteCacheService::getPublicSitePayload`** — single-flight locked with Redis primary + `:stale` SWR. The warm-cache hydration queries (`resolveImageVariantUrlsInSite`, `enrichSiteWithBrandPartnerRadius`, `applyBrandImageFallbacks`) re-query `MediaVariant`, `Site`, `Professional`, `BrandPartnerLink`, and `BrandDesignMediaService` on every request. This is a cache-quality defect (payload not self-contained → `CCH` lens), not a cache-absence defect — the payload *is* cached.
- **`FeatureFlagService`** — registry, pro overrides, and brand overrides all cached via `CacheLockService::rememberLocked` with per-request memoisation as a fast path. No uncached reads.
- **`VerifySupabaseJwt`** — JWKS fetch cached via `CacheLockService::rememberLocked('supabase:jwks', ...)`, with APCu per-kid PEM caching inside `resolveSigningKey`. The auth-server fallback path is disabled by default (`jwks_fail_closed=true`); the fallback is an intentional source-of-truth call, not a candidate for caching.
- **Middleware**: `LoadCurrentProfessional` delegates to the cached `ProfessionalCacheService`. `VerifyShopifySessionToken`'s JTI counter is Redis-backed. `BrandFundingGate`/`RequirePlan`/`FeatureGate` delegate to services whose internals are not in the provided file list (cannot assess).

## Dashboard read paths (Group B)

- No dashboard controllers are present in the provided files. The services that back them (`ProfessionalCacheService`, `SiteCacheService`, `AnalyticsCacheService`) are all cache-infrastructure or invalidation classes — the read paths they expose to controllers are already wrapped.

## Synchronous vendor reads (Group C)

- No vendor service files (`app/Services/Shopify`, `app/Services/Stripe`, `app/Services/Square`, `app/Services/Cloudflare`) are present in the provided files. Vendor calls observed in the provided scope are either:
  - Inside observer write-paths (e.g. `SiteCacheService` bust methods resolving shop domains to clear Hydrogen cache keys) — write-side, not hot read paths.
  - Inside `VerifyTurnstileCaptcha` — each token is unique per request, making caching incorrect.

## Observers & commands

- All observer files are write-side cache invalidation. Console commands are one-shot/admin paths. Neither qualifies as a hot read path.

**Result: 0 CCG findings in the provided file set.** The architecture shows deliberate, consistent cache coverage on every hot read path visible in these files. The sibling lens `caching-gold-standard.md` (`CCH`) owns the quality concerns around the public-site payload re-hydration pattern; those are not cache-absence defects.

<!-- LENS: gaps | CHUNK: services-prof-stripe -->

- [ ] **#CCG-1** · P2 — Uncached aggregate payout-summary queries on affiliate/brand dashboard
    - **Where:** app/Services/Stripe/CommissionPayoutService.php:472-490
    - **Affects:** Every brand and affiliate loading their payout overview dashboard — two aggregate queries (SUM + COUNT + GROUP BY) hit the database on every page view with no cache layer.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap `getPayoutSummary()` in `CacheLockService::rememberLocked` with a key from `CacheKeyGenerator` (e.g. `payout_summary:{professional_id}`).
        - TTL should be short (60–120s) — payout state changes are low-frequency but the dashboard must reflect new payouts within a reasonable window.
        - Bust the key from `markCompleted()`, `failPayout()`, and `cancelExpiredPayout()` so completed/failed/cancelled payouts invalidate immediately.
    - **Technical:** `getPayoutSummary()` issues two `selectRaw('status, COUNT(*) … SUM(…)')->groupBy('status')` queries against `commerce.commission_payouts` — one scoped to the professional as brand, one as affiliate. For a long-tenured affiliate these aggregate over hundreds or thousands of payout rows on every dashboard load. The method has no `Cache::` call, no `rememberLocked` wrapper, and no docblock delegating caching upward (unlike `StripeTransactionFetcher` which explicitly says "caching is the controller's job"). This is a straight database aggregate on a hot dashboard path.
    - **Plain English:** Every time an affiliate or brand opens their earnings dashboard, we run two expensive "total up all my payouts" calculations against the database — even if nothing changed since the last page load. It's like asking an accountant to re-add every invoice from scratch every time you glance at the summary. The fix is a sticky note on the desk: "here's the total, recalculate only when a new invoice arrives."
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
            // …
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CCG-2** · P3 — Duplicate site_media COUNT query within single brand-onboarding checklist request
    - **Where:** app/Services/Professional/Brand/BrandOnboardingReadinessService.php:62-76 and app/Services/Professional/Brand/BrandStatusService.php:248-265
    - **Affects:** Brands loading the onboarding readiness checklist — the same `COUNT` query against `site.site_media` fires twice in one request lifecycle with identical parameters.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Pass the count from `checkSiteImages()` into `syncBrandStatus()` so `hasMinimumImages()` short-circuits (or accept a precomputed `$imageCount` parameter).
        - Alternatively, request-scoped memoisation via `once()` in `BrandStatusService::hasMinimumImages()` so the second call returns the cached result within the same request.
    - **Technical:** `BrandOnboardingReadinessService::getChecklist()` calls `$this->checkSiteImages($site)` which issues a `SiteMedia::…->count()` query, then calls `$this->syncBrandStatus($professional)` which flows into `BrandStatusService::sync()` → `determine()` → `isOnboardingReady()` → `hasMinimumImages()` — and `hasMinimumImages()` issues the identical `SiteMedia::…->count()` query a second time. Same pool, purpose, media_type, and active/deleted filters. This is not an N+1 bug (no loop) but a repeated identical aggregate within one request. Impact is bounded — one extra COUNT per checklist page view — so P3.
    - **Plain English:** When a brand checks their setup checklist, we ask the database "how many images have I uploaded?" twice in a row — once for the checklist item itself and again for the overall status check. It's like asking the same question to the same person twice in the same conversation because the second question-asker didn't hear the first answer. The fix is to write the answer down on a scratchpad for the duration of the request.
    - **Evidence:**
        ```php
        // BrandOnboardingReadinessService::checkSiteImages()
        $count = $site
            ? SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DESIGN)
                ->where('purpose', SiteMedia::PURPOSE_PLACEHOLDER)
                ->where('media_type', SiteMedia::MEDIA_TYPE_IMAGE)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->count()
            : 0;
        ```
        ```php
        // BrandStatusService::hasMinimumImages() — same query, called later in same request
        $count = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_DESIGN)
            ->where('purpose', SiteMedia::PURPOSE_PLACEHOLDER)
            ->where('media_type', SiteMedia::MEDIA_TYPE_IMAGE)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->count();
        ```
    - `[DRAFT, confidence: 0.90]`

<!-- LENS: gaps | CHUNK: services-commerce -->

- [ ] **#CCG-1** · P2 — Uncached Shopify Admin API calls for favourites collection products on hot affiliate catalog path
    - **Where:** app/Services/Store/BrandCatalogService.php — `fetchCollectionProducts()` (no cache wrapper)
    - **Affects:** Every affiliate catalog request (`/api/affiliate/products`) that tags products with the `in_favourites` flag; multiple synchronous Shopify GraphQL calls per request.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the call in `AffiliateProductCatalogService::fetchCollectionGids()` with `CacheLockService::rememberLocked` using a key from `CacheKeyGenerator` keyed on brand ID + collection handle (e.g. `brand_favourites_gids:{brandId}`).
        - Set a short TTL (60–120s) and bust on any product add/remove from the favourites collection via the existing `BrandCatalogService` write methods.
    - **Technical:** `fetchCollectionProducts()` paginates through every product in a Shopify collection via Admin API GraphQL (50 per page). For a brand with 200 favourites, that's 4 synchronous ~200ms vendor calls per affiliate catalog load. The resolved GID list is identical for every affiliate of the same brand — perfect `rememberLocked` candidate. The collection GID itself is already cached via `resolveCollectionGid()`, but the product membership list underneath is recomputed from Shopify on every request.
    - **Plain English:** Every time an affiliate opens their catalog, we call Shopify's servers to ask "which products are in the brand's favourites collection?" — and we make that same call for every affiliate of the same brand, over and over. It's like calling the warehouse to ask for the same inventory list every time a different sales rep walks in the door. Cache it once and share it.
    - **Evidence:**
        ```php
        // BrandCatalogService::fetchCollectionProducts() — no cache, paginates Shopify Admin API
        private function fetchCollectionProducts(ProfessionalIntegration $integration, string $collectionGid): array
        {
            $resolved = $this->resolveCredentials($integration);
            $products = [];
            $cursor = null;

            do {
                $variables = ['id' => $collectionGid, 'first' => self::PRODUCTS_PER_PAGE];
                if ($cursor !== null) {
                    $variables['after'] = $cursor;
                }

                $response = $this->graphql($resolved['shop_domain'], $resolved['access_token'], self::COLLECTION_PRODUCTS, $variables);
                // ... pagination loop
            } while ($hasNextPage && $cursor !== null);

            return $products;
        }
        ```
        ```php
        // AffiliateProductCatalogService::fetchCollectionGids() — calls the uncached method
        private function fetchCollectionGids(ProfessionalIntegration $integration, string $metadataKey): array
        {
            // ...
            $products = $this->brandCatalogService->fetchCollectionProducts($integration, $collectionGid);
            return array_map(fn (array $p) => $p['gid'] ?? '', $products);
        }
        ```
        ```php
        // Called from getCatalogWithSelections() — the hot affiliate catalog endpoint
        $favouritesGids = $this->fetchCollectionGids($integration, 'favourites_collection_handle');
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#CCG-2** · P3 — Repeated BrandPartnerLink query within a single affiliate catalog request
    - **Where:** app/Services/Store/AffiliateProductCatalogService.php — `resolveAffiliateBrandIntegration()` (line ~203) + `getCatalogWithSelections()` (line ~290)
    - **Affects:** Affiliate catalog endpoint; one redundant database query per request.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Have `resolveAffiliateBrandIntegration()` return the `BrandPartnerLink` (or its `custom_photos_enabled` value) alongside the existing `brand_professional_id` and `integration`.
        - Consume that pre-fetched value in `getCatalogWithSelections()` instead of re-querying.
    - **Technical:** `resolveAffiliateBrandIntegration()` queries `BrandPartnerLink` to resolve the brand ID and validate the connection. Later in the same request, `getCatalogWithSelections()` queries the same `BrandPartnerLink` row again solely to read `custom_photos_enabled`. This is a textbook request-scoped memoisation gap — the first query's result is discarded and re-fetched 50 lines later. Fix is a pure in-process optimisation: return the link (or the boolean) from the resolver, no Redis needed.
    - **Plain English:** The code asks the database "which brand is this affiliate connected to?" and gets back a full answer card. Then 50 lines later, it throws away that card and asks the database again for the same information to check one extra checkbox on it. Keep the card and read the checkbox from it.
    - **Evidence:**
        ```php
        // First query — in resolveAffiliateBrandIntegration()
        $link = BrandPartnerLink::query()
            ->where('affiliate_professional_id', $affiliate->id)
            ->first();
        // ... returns brand_professional_id and integration, discards $link
        ```
        ```php
        // Second query — later in getCatalogWithSelections(), same request
        $link = BrandPartnerLink::query()
            ->where('affiliate_professional_id', $affiliate->id)
            ->where('brand_professional_id', $brandId)
            ->first();
        $customPhotosEnabled = (bool) ($link?->custom_photos_enabled ?? false);
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#CCG-3** · P2 — Uncached multi-aggregate analytics read on affiliate projections dashboard
    - **Where:** app/Services/Analytics/AffiliateProjectionsService.php — `build()` (no `CacheLockService::rememberLocked` wrapper)
    - **Affects:** Affiliate dashboard projections widget (run-rate, momentum, YTD, best month, year-end forecast) — 5 aggregate queries per request with zero caching.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap `build()` in `CacheLockService::rememberLocked` with a key from `CacheKeyGenerator` (e.g. `affiliate_projections:{professionalId}`) and a moderate TTL (120–300s).
        - Bust the key on any order ingest that touches `commerce.brand_affiliate_rollup` for this affiliate, or accept the TTL staleness (projections are inherently lagging indicators).
    - **Technical:** `build()` fires 5 separate aggregate queries per call: a windowed SUM with jsonb_agg and COUNT DISTINCT FILTER, a prior-window SUM, a YTD SUM, a window-function best-month query with DISTINCT ON and date_trunc, and a history-days lookup. These run against the rollup table which is indexed, but the combination of jsonb_agg, window functions, and DISTINCT ON per request adds up at the scale target (10K affiliates × periodic dashboard checks). The result set is per-affiliate and changes only when new rollup rows land — a 2–5 minute cache is both safe and effective.
    - **Plain English:** Every time an affiliate opens their earnings projections dashboard, we run five separate calculator operations against the database — even if they refresh 30 seconds later and nothing has changed. It's like redoing your taxes from scratch every time you check your bank balance. Cache the result for a few minutes and only recalculate when new money actually arrives.
    - **Evidence:**
        ```php
        public function build(Professional $professional, ?int $windowDaysOverride = null): array
        {
            // ... resolve window bounds ...

            $perCurrency = $this->fetchPerCurrencyAggregates(       // Query 1: SUM + jsonb_agg + COUNT DISTINCT FILTER
                $professional->id, $windowFrom, $windowTo, $windowDays
            );
            $priorByCurrency = $this->fetchPriorWindowAggregates(   // Query 2: SUM
                $professional->id, $priorWindowFrom, $priorWindowTo
            )->keyBy('currency_code');
            $ytdByCurrency = $this->fetchYtdAggregates(             // Query 3: SUM
                $professional->id, $yearStart
            )->keyBy('currency_code');
            $bestMonthByCurrency = $this->fetchBestMonthPerCurrency( // Query 4: window function + DISTINCT ON + date_trunc
                $professional->id, $yearStart
            )->keyBy('currency_code');
            // ... resolveDataHistoryDays also fires Query 5 ...
            // No Cache:: or CacheLockService anywhere in the method.
        }
        ```
    - `[DRAFT, confidence: 0.8]`

<!-- LENS: gaps | CHUNK: services-rest-models -->

After thoroughly examining every file in the provided scope, applying the strict three-part bar (hot path + expensive + multi-caller) to each read, I cannot identify any finding that clears all three criteria. The codebase already has `CacheLockService::rememberLocked` wrapping the genuinely hot+expensive reads (notification listings, notification preference resolution, booking milestone aggregates). Vendor API calls (Square, Fresha, Twitch, Kick, Cloudflare) are confined to background jobs and token refresh paths — never on hot request paths. The remaining uncached reads are single indexed lookups or relationship traversals that fall below the "expensive" threshold, or sit on infrequent admin/webhook paths that fall below the "hot path" threshold. No CCG findings to report.

<!-- LENS: gaps | CHUNK: jobs -->

No findings in the provided files. All files are under `app/Jobs/` — asynchronous queue workers, scheduled cron handlers, or one-shot install jobs. None sit on the hot paths defined by this lens (dashboard controllers, resolution middleware, capability lookups, public-site payload builders, or services called synchronously from those paths).

The scope groups listed in the lens (Groups A–C) point to `app/Services/`, `app/Http/Controllers/Api/`, `app/Http/Middleware/`, and `app/Http/Resources/` — none of which were included in the audit file list. Without files from those directories, the three-part bar (hot path + expensive + multi-caller) cannot be satisfied by any read in the supplied material.

<!-- LENS: gaps | CHUNK: ctrl-prof-a -->

- [ ] **#CCG-1** · P2 — Brand affiliate list read has two uncached queries with `whereHas` subquery
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateController.php:32-52
    - **Affects:** Every brand loading their connected affiliates dashboard page — two separate database round-trips per request.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap the combined links+sites resolution in `CacheLockService::rememberLocked($key, 60, fn() => ...)` with a key from `CacheKeyGenerator`.
        - Invalidate the cache key on `BrandPartnerLink` create/delete and on `Site`/`Professional` changes that affect the resolved shape.
    - **Technical:** The `index()` method issues a `BrandPartnerLink` query then plucks affiliate IDs and re-queries `Site` via `whereIn` + `whereHas('professional', ...)` with a complex dual-read OR clause (`account_type != 'brand'` OR `professional_type != 'brand'`). `whereHas` executes a correlated subquery — two separate round-trips, no single-flight or TTL wrap. At 200 brands × 50 affiliates this is a hot multi-tenant read with no cache layer anywhere in the path.
    - **Plain English:** Every time a brand opens their "My Affiliates" page, Partna runs two separate database searches back-to-back. The second search digs through sites and professionals with extra filtering. Caching this result for 60 seconds would collapse hundreds of identical queries per minute into one.
    - **Evidence:**
        ```php
        $links = BrandPartnerLink::query()
            ->where('brand_professional_id', $brandId)
            ->orderByDesc('updated_at')
            ->get(['affiliate_professional_id', 'slot', 'custom_photos_enabled', 'site_url', 'updated_at']);

        $affiliateIds = $links
            ->pluck('affiliate_professional_id')
            ->unique()
            ->values()
            ->all();

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
    - `[DRAFT, confidence: 0.95]`

- [ ] **#CCG-2** · P2 — Invite list resolves partnered-elsewhere status with unindexed `LOWER()` scans per page
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateInviteController.php:72-113
    - **Affects:** Brand dashboard invite list — two unindexed function-call queries on every page load.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Cache the `resolveEmailsPartneredWithOtherBrands` result per-brand with `CacheLockService::rememberLocked` and a short TTL (30–60s).
        - Invalidate on invite accept/decline or new `BrandPartnerLink` creation.
    - **Technical:** The method runs `DB::raw('LOWER(primary_email)')` and `DB::raw('LOWER(public_contact_email)')` inside `whereIn`, then a second `BrandPartnerLink` query. PostgreSQL cannot use a standard B-tree index on `LOWER()` without an explicit expression index — these are table scans. Although scoped to `O(per_page)` emails, each page still scans the professionals table with function-evaluated predicates. No cache wraps this resolution.
    - **Plain English:** When a brand opens their invite list, Partna checks whether each invited email is already partnered with a different brand. It does this by running two searches that have to read every row in the professionals table because the search can't use the standard index. Caching this "who's already taken" map for a minute would avoid that work on every page.
    - **Evidence:**
        ```php
        $professionals = Professional::query()
            ->where(function ($query) use ($emails) {
                $query->whereIn(DB::raw('LOWER(primary_email)'), $emails)
                    ->orWhereIn(DB::raw('LOWER(public_contact_email)'), $emails);
            })
            ->get(['id', 'primary_email', 'public_contact_email']);

        // ... then:
        $partneredProfessionalIds = BrandPartnerLink::query()
            ->whereIn('affiliate_professional_id', $professionals->pluck('id')->all())
            ->where('brand_professional_id', '!=', $brandProfessionalId)
            ->pluck('affiliate_professional_id')
            ...
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#CCG-3** · P2 — Billing summary runs aggregate `SUM`/`COUNT` on `commerce.orders` with no cache
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandBillingSummaryController.php:31-40
    - **Affects:** Brand dashboard billing widget — aggregate scan on orders table every time a brand without a payment method loads the page.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the blocked-orders aggregate in `CacheLockService::rememberLocked($key, 60, fn() => ...)` with a key scoped to `brand_professional_id`.
        - Bust the key on order status changes (approved→paid) and when the brand adds a payment method.
    - **Technical:** When `$hasCard` is false, the controller issues `Order::query()->where(...)->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(commission_cents), 0) AS pending_cents')->first()`. This is an aggregate scan over all approved, unpaid, non-refunded orders for the brand — the same cost as a dashboard summary query, but with no TTL, no single-flight lock, and no invalidation strategy. Brands without a Stripe payment method hit this on every dashboard open.
    - **Plain English:** For brands that haven't added a payment method yet, every time they open their billing summary the system runs a "count and sum all unpaid orders" calculation from scratch. A 60-second cache would serve the same answer for every refresh in that window.
    - **Evidence:**
        ```php
        $blockedData = Order::query()
            ->where('brand_professional_id', $brand->id)
            ->where('status', 'approved')
            ->whereNull('payout_id')
            ->where('refund_cents', 0)
            ->where('rate_source', '!=', 'pending')
            ->selectRaw('COUNT(*) AS cnt, COALESCE(SUM(commission_cents), 0) AS pending_cents')
            ->first();
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#CCG-4** · P2 — Brand orders list runs a 4-table LEFT JOIN with complex status filtering, uncached
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandOrdersController.php:38-56
    - **Affects:** Brand dashboard orders page — every page load runs a multi-join aggregate-style query.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap the paginated query in `CacheLockService::rememberLocked($key, 30, fn() => ...)` with a key scoped to `brand_id + page + status_filter`.
        - Invalidate on order webhook ingest (new order, status change) via the existing analytics cache bust pathway.
        - Consider a shorter TTL (30s) because orders change more frequently than summary data.
    - **Technical:** The `index()` method builds a query joining `commerce.orders`, `core.professionals`, `core.customers`, and `commerce.commission_payouts` with multiple `leftJoin` clauses, a `whereNotIn` exclusion list, and an `applyStatusFilter` that appends raw `WHERE` conditions for derived lifecycle status (pending/processing/paid/reversed). This is a reporting-style query running on every page of the paginated orders list with no cache — the same paginated result set is recomputed for every brand viewing page 1 of their orders.
    - **Plain English:** The brand's orders page joins four database tables together every time it loads, even if nothing has changed since the last refresh. A 30-second cache would let the first viewer pay the cost and everyone else get a fast answer.
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
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#CCG-5** · P2 — Affiliate brand discovery runs `whereHas` subquery + follow-up queries for logos and connected brands
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandPartnerController.php:103-150
    - **Affects:** Affiliate dashboard brand discovery page — three separate query rounds per page load.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Cache the paginated brand list + logo URLs in `CacheLockService::rememberLocked` with a key scoped to `page + per_page`.
        - Invalidate on brand profile changes (visibility, brand_status) and logo uploads.
        - The "always include connected brands" extra query can be merged into the cache key or handled as a separate small cache.
    - **Technical:** The `index()` method issues: (1) a paginated `Professional` query with `whereHas('brandProfile', ...)` — a correlated subquery filtering by `affiliate_visibility` and `brand_status`; (2) a `BrandDesignMediaService::getLogoFullUrls()` call for resolved site IDs; (3) a `BrandPartnerLink` query to find already-connected brands not in the visible set, followed by another `Professional` query and another `getLogoFullUrls` call. None of these are cached. The brand discovery page is the affiliate's primary browsing surface — high traffic during onboarding.
    - **Plain English:** When an affiliate opens the brand discovery page to find brands to partner with, Partna runs three rounds of database searches: one for the brand list, one for the brand logos, and one to make sure already-connected brands still show up. This happens fresh on every page load. A short cache would serve the same discovery results to every affiliate browsing that page.
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

        // ... then extra queries for connected brands:
        $extraBrands = Professional::query()
            ->whereIn('id', $connectedIds->all())
            ->where(function ($q): void { ... })
            ->with('site')
            ->get();
        ```
    - `[DRAFT, confidence: 0.90]`

<!-- LENS: gaps | CHUNK: ctrl-prof-b-staff -->

- [ ] **CCG-1** · P2 — Affiliate orders list runs a 4-table LEFT JOIN on every dashboard load with no cache layer
    - **Where:** app/Http/Controllers/Api/Professional/Affiliate/AffiliateOrdersController.php:67-79
    - **Affects:** Every affiliate loading their Orders tab (primary dashboard view); each load re-executes a multi-table join across commerce.orders, core.professionals, core.customers, and commerce.commission_payouts.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap the first-page (no-filter) query path in `CacheLockService::rememberLocked` with a `CacheKeyGenerator` key scoped to `affiliate_professional_id + status + page`, TTL 30s.
        - Invalidate the cache key from the Shopify order webhook handler when a new order arrives for that affiliate, or let the short TTL naturally expire.
        - Limit the cache to the most common page-1 / no-filter combination; paginated deep pages and filtered views can remain uncached to avoid unbounded key cardinality.
    - **Technical:** The query performs `DB::table('commerce.orders')->leftJoin('core.professionals', ...)->leftJoin('core.customers', ...)->leftJoin('commerce.commission_payouts', ...)->where('o.affiliate_professional_id', ...)->orderByDesc('o.occurred_at')->paginate(...)`. Even with indexes on the join columns, PostgreSQL must merge four relations, apply the status exclusion filter (`whereNotIn` on `EXCLUDED_FROM_AGGREGATES`), sort by `occurred_at`, and run a `COUNT(*)` for pagination metadata — per dashboard tab visit. The result set for a given affiliate is stable within a 30s window (new orders arrive via webhook, not on every page load), making it a strong `rememberLocked` candidate. The `status` filter and pagination cursor expand the key space, so the cache should target only the default first-page view to stay bounded.
    - **Plain English:** Every time an affiliate opens their Orders tab, Partna asks the database to stitch together four separate lists (orders, brands, customers, payouts), filter out excluded statuses, sort everything by date, and count the total — even if nothing has changed since they last looked 10 seconds ago. A short memory (30-second cache) would let rapid tab-flipping and refreshes reuse the same answer without redoing all that stitching work.
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
    - `[DRAFT, confidence: 0.7]`

<!-- LENS: gaps | CHUNK: ctrl-public-internal -->

- [ ] **#CCG-1** · P2 — Uncached Square `/v2/locations` call on public booking config endpoint
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicBookingController.php:288–289 (resolvePrimaryLocation), called from config() at line 80
    - **Affects:** Every visitor opening a booking section on a public mini-site; the same Square locations payload is re-fetched on every request with no cache layer.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `resolvePrimaryLocation()` result in `CacheLockService::rememberLocked` with a key from `CacheKeyGenerator` (e.g. `square_locations_{$professionalId}`) and a 5–10 minute TTL.
        - Bust the key in any Square catalog webhook handler that touches location data (or on explicit dashboard reconnect), so a location rename propagates within the TTL window.
    - **Technical:** `resolvePrimaryLocation()` issues `$this->squareApiClient->request($professional, 'GET', '/v2/locations')` synchronously inside the `config()` method. The result — a single active location's id/name/currency — is identical for every concurrent visitor to the same professional's booking section. Square locations change only on rare administrative edits, making this a textbook `rememberLocked` candidate. The canonical fix is a short-TTL cache + push-invalidate on the relevant Square webhook.
    - **Plain English:** Every time a visitor opens the booking section on a Partna mini-site, the server calls Square to ask "what locations does this professional have?" The answer almost never changes, but we make the call anyway — on every single visitor. That's like calling a store to ask their address every time a customer walks in, instead of writing it on a sticky note and reusing it for a few minutes.
    - **Evidence:**
        ```php
        // PublicBookingController.php:80 — config() calls resolvePrimaryLocation
        $location = $this->resolvePrimaryLocation($professional);
        
        // PublicBookingController.php:288–289 — resolvePrimaryLocation() hits Square API
        $response = $this->squareApiClient->request($professional, 'GET', '/v2/locations');
        $locations = is_array($response['locations'] ?? null) ? $response['locations'] : [];
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CCG-2** · P2 — Uncached Square `fetchAppointmentServiceVariations` call on public booking services endpoint
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicBookingController.php:103–104 (services method)
    - **Affects:** Every visitor loading the services list in a public booking section; the same service catalog is re-fetched from Square on every request.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the `fetchAppointmentServiceVariations` call in `CacheLockService::rememberLocked` with a per-professional key (`square_services_{$professionalId}`) and a 5-minute TTL.
        - Invalidate the key from `SquareCatalogWebhookController` (which already receives `catalog.version.updated` events) so a service edit in Square surfaces within seconds rather than waiting for TTL expiry.
    - **Technical:** The `services()` method calls `$this->squareApiClient->fetchAppointmentServiceVariations($professional, null)` with no cache wrapper. This fetches the full bookable-service catalog from the Square API synchronously on every request. The service catalog is identical for all concurrent visitors to the same professional and only changes when the professional edits services in Square. The `SquareCatalogWebhookController` already handles `catalog.version.updated` webhooks — adding cache invalidation there gives push-based freshness without any new webhook subscriptions.
    - **Plain English:** Every visitor browsing a professional's bookable services triggers a live call to Square's servers to ask "what services do you offer?" The answer is the same for everyone looking at the same professional, and it only changes when the professional edits their Square catalog. We should cache the answer for a few minutes and update it only when Square tells us something changed — like keeping a menu on the counter instead of calling the kitchen for every customer.
    - **Evidence:**
        ```php
        // PublicBookingController.php:103–104 — uncached Square API fetch
        $fetched = $this->squareApiClient->fetchAppointmentServiceVariations($professional, null);
        $rows = is_array($fetched['services'] ?? null) ? $fetched['services'] : [];
        ```
    - `[DRAFT, confidence: 0.85]`
