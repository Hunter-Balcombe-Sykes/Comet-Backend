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
