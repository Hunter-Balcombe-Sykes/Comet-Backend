- [ ] **SCALE-1** · P2 — ProfessionalGalleryController::index() returns an unpaginated list of all active gallery images
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalGalleryController.php:23-31
    - **Affects:** Any professional with a large gallery — response can grow to hundreds or thousands of images, causing memory pressure, slow JSON serialisation, and frontend render lag.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Paginate the gallery endpoint (`->paginate(50)`) or cap with a configurable limit.
        - Return a paginated resource that includes `current_page`, `last_page`, and `total`.
    - **Technical:** The `->get()` without any `limit` or pagination loads every active `SiteMedia` row for the site. At the scale target (200 brands × 50 affiliates) each professional could upload many images; even modest galleries of 200 images produce a large JSON payload that is serialised on every dashboard visit. Adding pagination keeps the response bounded and cacheable.
    - **Plain English:** Imagine every time you open your phone's photo gallery it tries to load every photo you've ever taken in one go. That works fine with 10 photos but gets painfully slow with 500. We need to show photos in pages, not all at once.
    - **Evidence:**
        ```php
        $images = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_GALLERY)
            ->where('is_active', true)
            ->with('mediaVariants')
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();          // ← no pagination, unbounded
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **SCALE-2** · P2 — ProfessionalServiceController::index() returns an unpaginated list of all services
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalServiceController.php:47-64 (fallback path)
    - **Affects:** Professionals with many services — the dashboard services list can become slow to load, and the response payload can grow large.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Paginate the fallback query (the hot path is cached, but the cached query still materialises all rows).
        - Ensure the cache key used by `getDashboardServices` respects a sensible max-items limit or paginates.
    - **Technical:** The uncached path (when filters or grouping are applied) issues `Service::query()->… ->get()` with no `paginate()`. At the scale target a professional could have hundreds of services (especially with Sync-from-vendor flows). The hot path uses `getDashboardServices` which may also return an unbounded collection — fetch-and-cache of all rows puts memory pressure on Redis and the PHP process.
    - **Plain English:** Loading every product in your catalogue at once, even when you only want to see the first page, is like a restaurant bringing every dish from the kitchen when you only asked for the menu. We should deliver pages, not the whole catalogue.
    - **Evidence:**
        ```php
        $services = $servicesQuery->orderBy('sort_order')->orderBy('created_at')->get();
        // no paginate() — unbounded result set
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **SCALE-3** · P3 — ProfessionalServiceCategoryController::index() returns an unpaginated list of all categories
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalServiceCategoryController.php:17-29
    - **Affects:** Professionals with many service categories — the response can include dozens of categories, but typically the cardinality is low.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the query with `->paginate(50)` or add a `limit(100)` for safety.
    - **Technical:** A `->get()` without pagination is harmless today because most professionals have <20 categories. At the scale target (200 brands, each with potentially many custom categories from bulk imports) the number could grow, so a paginated response future-proofs the endpoint.
    - **Plain English:** This is like listing all folders in a filing cabinet at once. Most people have only a few folders, but as the cabinet grows, it's safer to show them page by page.
    - **Evidence:**
        ```php
        $categories = $q->orderBy('sort_order')->orderBy('created_at')->get();
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **SCALE-4** · P3 — BrandAffiliateController::snapshot() loads all CommissionPayouts for an affiliate without pagination
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateController.php:146-149
    - **Affects:** Brand dashboard affiliate detail modal — as an affiliate accumulates years of payouts, the response payload can grow to hundreds of rows.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `->get()` with a limited query (`->latest()->take(100)`) or paginate the recent-payouts section.
        - The `recentPayouts` slice already limits to 5, but the full collection is still hydrated from the database.
    - **Technical:** The `CommissionPayout::query()->… ->get()` loads every payout for the brand-affiliate pair. At the scale target an affiliate active for years will have hundreds of payout rows. The frontend only displays the last 5, so loading all rows is wasted database, network, and memory work.
    - **Plain English:** It's like asking the bank for your last 5 transactions and they print out your entire history since you opened the account just to cross off all but the last 5. Wasteful — just ask for the recent ones directly.
    - **Evidence:**
        ```php
        $payouts = CommissionPayout::query()
            ->where('brand_professional_id', $brandId)
            ->where('affiliate_professional_id', $affiliateId)
            ->get();   // ← unbounded, then sliced in memory
        ```
    - `[DRAFT, confidence: 0.9]`
