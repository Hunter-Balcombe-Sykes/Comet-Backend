- [ ] **SCALE-1** · P2 — Initial Shopify provisioning jobs all fired onto the default queue  
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedSetupController.php (provisionShopifyIntegration, foreach dispatch block)  
    - **Affects:** Brand onboarding pipeline — 6 jobs per new brand, potentially 1200 jobs at once for a cohort of 200 brands.  
    - **Effort:** S (~0.5–1h)  
    - **What to do:**
        - Add `->onQueue('shopify')` to every `::dispatch()` inside the provisioning loop.
        - Ensure the `shopify` queue has its own supervisor with enough workers in `config/horizon.php` so it doesn't starve the default queue.
    - **Technical:** Without a queue assignment, each job (RegisterShopifyWebhooksJob, CreateStorefrontAccessTokenJob, …) lands on the `default` Redis queue. Six jobs × many concurrent brand provisions can temporarily choke the queue, delaying other critical jobs like payments and webhooks. A dedicated `shopify` queue isolates this burst.
    - **Plain English:** Think of the queue as a single checkout lane. Six heavyweight tasks are being pushed through for every new store that signs up. If lots of stores sign up at once, that lane gets blocked for everyone else. Giving these tasks their own lane keeps the main checkout moving.
    - **Evidence:**
        ```php
        foreach ($jobs as $jobClass) {
            try {
                $jobClass::dispatch((string) $integration->id);
            } catch (\Throwable $e) {
                Log::warning('Failed to dispatch embedded integration setup job', [...
            }
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **SCALE-2** · P2 — Square catalog webhook falls back to inline vendor sync when queue dispatch fails  
    - **Where:** app/Http/Controllers/Api/Webhooks/SquareCatalogWebhookController.php (__invoke, catch block after dispatch failure)  
    - **Affects:** Square webhook ingestion — under a queue outage (e.g. Redis down), every `catalog.version.updated` webhook blocks a worker for the duration of a Square API call instead of acknowledging fast.
    - **Effort:** M (~2–4h)  
    - **What to do:**
        - Remove the inline `syncFromSquare()` fallback; just log an error and return 200. Square will auto-retry the webhook when the queue recovers.
        - If absolutely necessary, fire a lightweight async job (e.g. a null-safe stub) and let the regular retry mechanism handle the rest.
    - **Technical:** When `SyncSquareCatalogDeltaJob::dispatch()` throws (e.g. Redis connection lost), the current code performs a synchronous Square catalog sync within the same HTTP request, holding the webhook worker open for several seconds. This creates a backpressure storm if many webhooks arrive during a queue degradation — the server threads become a bottleneck, amplifying the outage.
    - **Plain English:** Normally when a catalog update happens, we quickly acknowledge it and hand off the work to a background queue. But if that handoff fails (say, the queue is temporarily unavailable), the system tries to do the heavy work right there on the spot, making the person waiting (Square) wait. That clogs up the processing line and causes a pile-up. Instead, we should just say “got it, I’ll try again later” and rely on the built-in retry.
    - **Evidence:**
        ```php
        try {
            SyncSquareCatalogDeltaJob::dispatch($merchantId, null, false);

            return $this->success(['received' => true, 'queued' => true]);
        } catch (\Throwable $dispatchError) {
            // ... logs and then inline sync:
            $stats = $syncService->syncFromSquare($professional, fullSync: false);
            return $this->success([
                'received' => true,
                'queued' => false,
                'synced_inline' => true,
                ...
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **SCALE-3** · P2 — Bulk affiliate-product-selection purge job dispatched without a dedicated queue  
    - **Where:** app/Http/Controllers/Api/Webhooks/Shopify/ShopifyAppUninstalledWebhookController.php (after the DB transaction, `PurgeAffiliateProductSelectionsJob::dispatch`)  
    - **Affects:** Deletion of affiliate product selections after a brand uninstalls the Shopify app. One job per uninstall, but the job locks and deletes many rows.  
    - **Effort:** S (~0.5–1h)  
    - **What to do:**
        - Dispatch `PurgeAffiliateProductSelectionsJob` onto a `shopify` or `purging` queue: `->onQueue('shopify')`.  
        - Ensure that queue’s workers are configured so it won’t block other jobs if a large purge slows it down.
    - **Technical:** The webhook controller fires this job onto the default queue after a successful uninstall transaction. The job (from its own comments) chunks deletes to avoid long row-locks, but it can still be resource-heavy. Without isolation, a large purge could starve other critical jobs on the same queue. A dedicated queue keeps the impact contained.
    - **Plain English:** When a brand removes the app, a cleanup job is spawned to delete all their saved product preferences. This job is put into the same “fast lane” (the default queue) as payment confirmations and other time-sensitive tasks. If the cleanup is slow, it can hold up the entire lane. Moving it to its own lane prevents that traffic jam.
    - **Evidence:**
        ```php
        $result = DB::transaction(function () use ($shopDomain) { ... });
        // after commit
        PurgeAffiliateProductSelectionsJob::dispatch($result['professional_id']);
        ```
    - `[DRAFT, confidence: 0.8]`
