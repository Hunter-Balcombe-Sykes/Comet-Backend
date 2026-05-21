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
