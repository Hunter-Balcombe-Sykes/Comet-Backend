- [ ] **CACHE-1** · P2 — BrandProfileObserver dispatches FanOutBrandStatusNotificationJob on every brand_status change, risking unbounded notification fan-out
    - **Where:** app/Observers/Core/BrandProfileObserver.php:48
    - **Affects:** Affiliates linked to a brand (all get a notification), and the DB (one row per receipt per affiliate).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the eager dispatch with a lazy approach: generate `NotificationReceipt` rows only when the affiliate fetches their dashboard, or use a queue-based job that creates receipts in batches.
        - If lazy is not feasible, replace `FanOutBrandStatusNotificationJob` with a chunked job that limits the per-job batch to 500 and continues as a chain.
    - **Technical:** This observer fires inside the request cycle (after commit). The dispatched job likely creates one `notification_receipt` per affiliate. At scale (30 brands × 50 affiliates), a single status change can write 1,500 rows synchronously (or via many serial job dispatches). The canonical pattern is to defer receipt creation until first-read or to use a single batched queue job, reducing write amplification and job fan-out.
    - **Plain English:** Imagine someone updating their store status and instantly sending a handwritten letter to every single one of their 50 affiliate partners. It works, but it’s expensive and slow. Instead, we can just prepare one notice and hand it to each partner the next time they log in to their dashboard.
    - **Evidence:**
        ```php
            // From BrandProfileObserver::updated
            FanOutBrandStatusNotificationJob::dispatch($brandProfessionalId, $newStatus);
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **CACHE-2** · P2 — SiteObserver fans out one SyncSubdomainToKvJob per affiliate on brand subdomain change, multiplying queue jobs without batching
    - **Where:** app/Observers/Core/SiteObserver.php:133–137
    - **Affects:** Queue load and Cloudflare KV API rate limits; every brand subdomain change triggers one job per linked affiliate.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `each` loop with a single batch job (e.g., `SyncAffiliateKvForBrandJob`) that processes all affiliates in one execution, chunked internally.
        - Reuse the existing `InvalidateBrandAffiliatesCacheJob` pattern, which already does batched invalidation via a single queue job.
    - **Technical:** When a brand’s `subdomain` changes, `SiteObserver::saved` calls `cascadeAffiliateKvSync()`, which fetches all affiliate IDs via `BrandPartnerLink` and dispatches a separate `SyncSubdomainToKvJob` for each. For 50 affiliates, that’s 50 jobs enqueued in a single request lifecycle. The replacement is a single queued job that iterates affiliates in chunks, reducing queue fan-out and potential KV write contention.
    - **Plain English:** When a brand changes its website address, the system currently sends 50 separate delivery vans (one per partner) to update the routing table. It’s smarter to load all changes onto one van that makes multiple stops — same result, far less traffic.
    - **Evidence:**
        ```php
            // from SiteObserver::cascadeAffiliateKvSync
            BrandPartnerLink::query()
                ->where('brand_professional_id', $brandProfessionalId)
                ->pluck('affiliate_professional_id')
                ->each(function (string $affiliateId): void {
                    SyncSubdomainToKvJob::dispatch($affiliateId);
                });
        ```
    - `[DRAFT, confidence: 0.9]`
