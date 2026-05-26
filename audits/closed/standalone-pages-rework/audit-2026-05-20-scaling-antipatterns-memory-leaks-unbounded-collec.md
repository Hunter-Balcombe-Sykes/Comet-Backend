`★ Insight ─────────────────────────────────────`
DeepSeek correctly identified all three patterns and the evidence quotes all match verbatim in the source. Key calibration notes: SCALE-1 has real impact during the OAuth install chain for large-catalog brands (P1 confirmed); SCALE-2 is a monitoring job whose failure scenario requires a mass Stripe outage (re-tier P3); SCALE-3's jitter is already present but O(N) job fan-out still warrants P2.
`─────────────────────────────────────────────────`

# Scaling, Memory, and Unbounded Collections Audit — 2026-05-20

**Branch:** development
**Lens:** scaling antipatterns memory leaks unbounded collections
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Jobs/Shopify/BackfillBrandHasEnabledVariantsJob.php
- app/Jobs/Stripe/MonitorManualRefundQueueJob.php
- app/Services/Cache/SiteCacheService.php
- app/Jobs/Cache/InvalidateConnectedAffiliateCachesJob.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 1 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **SCALE-1** · P1 — BackfillBrandHasEnabledVariantsJob loads entire brand catalog into memory and writes sequentially
    - **Where:** app/Jobs/Shopify/BackfillBrandHasEnabledVariantsJob.php (entire `handle()` method)
    - **Affects:** Brands with large product catalogs (500+ products) during the OAuth install chain; the job may exhaust the 120s timeout, leaving `has_enabled_variants` only partially seeded, which causes the "Active Products" smart collection to resolve incorrectly until a manual backfill is run.
    - **Effort:** L (~1–2d)
    - **What to do:**
        - Verify whether `BrandCatalogService::fetchBrandCatalog()` paginates internally via cursor-based Shopify GraphQL pagination. If it returns a flat array of all products, refactor it to accept a cursor and yield page-sized batches (~50–100 products).
        - Process each page in the job, dispatching a chained continuation job (or using `self::dispatch($integrationId)->delay(...)` with a stored cursor in `provider_metadata`) so each page runs within its own 120s window.
        - Add Shopify rate-limit awareness (the Admin GraphQL API has a point-cost bucket); include a `sleep()` or exponential back-off between `writeHasEnabledVariants` calls if the response signals throttle pressure.
        - Store a `has_enabled_variants_backfill_cursor` in `provider_metadata` so retries and continuation jobs resume from where they left off rather than re-fetching the full catalog.
    - **Technical:** `fetchBrandCatalog($brand)` returns the full catalog as a PHP iterable held in memory. The `foreach` then calls `writeHasEnabledVariants()` — one Admin GraphQL mutation per product — sequentially. The comment in the file acknowledges the constraint: "120s covers ~500 products comfortably," implying 501+ products will time out. A ShouldBeUnique lock with `uniqueFor = 600` means a timed-out job blocks retries for 10 minutes. At 1,000 products × ~200ms/mutation = 200s wall time; at 3,000 products this is ~600s, five times the job timeout. The `backfill_state` would be written as `partial` on every timeout-retry cycle and the job would never complete. Shopify's 2025-01 Admin API GraphQL budget is 1,000 points/second; `metafieldsSet` costs ~10 points, so 100 writes/second is the ceiling — adding rate-limit handling prevents HTTP 429s from silently leaving `failures++` counts that also write `partial` state.
    - **Plain English:** During setup, we need to stamp a "yes, this product has enabled options" label on every item in a brand's store. The current code grabs every single product at once, then stamps them one at a time. For a small store with 200 products, it finishes in time. For a store with 2,000 products, it runs out of time partway through, leaves the job half-done, and retries — but picks up from the beginning again, not where it stopped. The collection smart filters (what affiliates see as "active") stay broken until an admin manually reruns the backfill. The fix is to work through the catalog in batches, like a worker going shelf-by-shelf instead of pulling the whole warehouse at once.
    - **Evidence:**
        ```php
        try {
            $catalog = $catalogService->fetchBrandCatalog($brand);
        } catch (\Throwable $e) {
            // ...
            throw $e;
        }

        foreach ($catalog as $product) {
            $gid = $product['gid'] ?? '';
            if ($gid === '') {
                continue;
            }

            // ...

            try {
                $result = $catalogService->writeHasEnabledVariants($integration, $gid, $hasEnabled);
        ```

---

## P2 — Should fix

- [ ] **SCALE-3** · P2 — `SiteCacheService::invalidateSite` loads all connected affiliate IDs and subdomains into memory, then dispatches one queue job per affiliate
    - **Where:** app/Services/Cache/SiteCacheService.php:`invalidateSite()` (the final `if ($professionalId !== '')` block)
    - **Affects:** Queue throughput when a brand with many connected affiliates edits their site; every brand save dispatches O(N) jobs simultaneously, competing with commerce webhooks and notification fan-outs on the `default` queue.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the in-memory `pluck()->all()` + `pluck()->all()` + `foreach dispatch` with a single `InvalidateConnectedAffiliateCachesJob` that accepts the `brand_professional_id` and internally uses `chunkById(500, ...)` to dispatch per-affiliate deletes without materialising the full ID list in the parent call-site.
        - Alternatively, introduce a cache-version token keyed by `brand_professional_id` (e.g. `cache:affiliate-version:{brandId}`) that all affiliate public-payload keys incorporate. Invalidation becomes one atomic `Cache::increment()` instead of N job dispatches. This requires the cache key shape in `CacheKeyGenerator::publicSitePayload` to embed a brand-version segment when the subdomain belongs to an affiliate.
        - If the per-job dispatch pattern is kept, cap the fan-out with a guard: if `count($connectedProfessionalIds) > 1000`, log a warning and dispatch a single chunked job instead of N individual jobs, preventing a sudden queue spike.
    - **Technical:** `BrandPartnerLink::query()->pluck()->all()` materialises the full array of affiliate UUIDs; `Site::query()->whereIn()->pluck()->all()` materialises the full array of subdomain strings. For a brand with 500 affiliates this means 500 strings in memory and 500 `InvalidateConnectedAffiliateCachesJob::dispatch()` calls in a single request. Each dispatch writes to Redis; 500 sequential Redis writes from a PHP-FPM worker blocks that worker thread for the duration. The 0–30s random jitter delay is a good mitigation for cold-miss stampedes, but it does not reduce the queue write volume. At the scale target (200 brands × 50 affiliates) each brand edit produces up to 50 dispatches — acceptable today. At 200 brands × 500 affiliates the pattern produces up to 500 dispatches per brand edit, and these compound with SiteObserver fires (which call `invalidateSite` on every `saved` event).
    - **Plain English:** When a brand edits anything on their page — even fixing a typo — the system sends a separate cleanup message to every affiliate connected to that brand. Today with 50 affiliates that's 50 messages, which is fine. As the platform grows, a popular brand could have thousands of affiliates, and that brand fixing a typo would suddenly flood the message queue with thousands of simultaneous tasks, slowing down order processing and notification delivery for everyone. The fix is to send one message that says "brand X changed — go clean up all their affiliates" rather than one message per affiliate.
    - **Evidence:**
        ```php
        $connectedProfessionalIds = BrandPartnerLink::query()
            ->where('brand_professional_id', $professionalId)
            ->pluck('affiliate_professional_id')
            ->all();

        $connectedSubdomains = Site::query()
            ->whereIn('professional_id', $connectedProfessionalIds)
            ->pluck('subdomain')
            ->filter(fn ($subdomain): bool => is_string($subdomain) && trim($subdomain) !== '')
            ->map(fn ($subdomain): string => strtolower((string) $subdomain))
            ->all();

        foreach ($connectedSubdomains as $connectedSubdomain) {
            InvalidateConnectedAffiliateCachesJob::dispatch($connectedSubdomain)
                ->delay(now()->addSeconds(random_int(0, 30)));
        }
        ```

---

## P3 — Nice to have

- [ ] **SCALE-2** · P3 — `MonitorManualRefundQueueJob` loads all stuck payouts without a query limit
    - **Where:** app/Jobs/Stripe/MonitorManualRefundQueueJob.php:36–41
    - **Affects:** Ops visibility; in a mass Stripe outage scenario where many payouts are simultaneously flagged `needs_manual_refund`, the unbounded `->get()` could materialise a large Eloquent collection and produce a log entry that exceeds Nightwatch's ingestion limits.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `->limit(200)` before `->get()` to bound the collection.
        - After the limit, perform a separate `->count()` query and include the total in the log warning when `$total > 200` so ops knows additional payouts exist beyond what was printed.
    - **Technical:** `CommissionPayout::query()->...->get()` has no limit clause. Under normal conditions the result set is tiny (a handful of flagged payouts). In a pathological scenario — Stripe platform outage, mass refund wave — many payouts could be flagged simultaneously, materialising a large Eloquent collection and a proportionally large serialised log entry. Because this is a daily digest job with `tries = 1` and `timeout = 120`, a very large result set will not cause a timeout in practice; the real risk is log volume. The fix is trivial and makes the behaviour explicit.
    - **Plain English:** This is a daily report that lists all commission payouts needing manual attention from the team. Currently it has no page limit — it would try to print every stuck payout in one shot. In normal times there are only a handful, so it's fine. If Stripe had a major outage and hundreds of payouts got stuck at once, the report would be impossibly long and potentially break the logging system. Adding a limit of 200 entries with a note saying "and N more" makes the report robust without changing its usefulness.
    - **Evidence:**
        ```php
        $open = CommissionPayout::query()
            ->where('needs_manual_refund', true)
            ->whereNotIn('status', ['cancelled', 'failed'])
            ->with(['brandProfessional:id,display_name', 'affiliateProfessional:id,display_name'])
            ->orderBy('updated_at')
            ->get();
        ```
