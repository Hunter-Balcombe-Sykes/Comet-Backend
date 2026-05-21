- [ ] **LIFE-1** · P1 — Affiliate catalog queries bypass the ShopifyAdminClient, including its throttling, retry, and budget tracker
    - **Where:** app/Services/Store/AffiliateProductCatalogService.php — `queryAdminCatalog()` method
    - **Affects:** Every affiliate browsing products at peak (up to 40K daily notifications / catalog reads). Calls flood Shopify’s API without respecting the shared budget, risking rate‑limit errors for ALL tenants using the same Shopify app.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the inline `Http::post` with a call to `$this->client->graphql()` (the injected `ShopifyAdminClient`), so the single‑shop budget, cost estimation, and THROTTLED retry apply.
        - Remove the fallback `$fallback` logic that re‑constructs the URL; `ShopifyAdminClient` handles the endpoint.
    - **Technical:** `queryAdminCatalog` builds its own `Http` request directly against `https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json`, bypassing `ShopifyAdminClient::graphql()` which pre‑acquires budget from the Redis‑backed token bucket, reconciles throttle state, and retries on `THROTTLED`. At the scale target of 200 brands × ~50 affiliates browsing catalogs concurrently, these ungoverned requests can exhaust Shopify’s cost budget and trigger HTTP 429 for all other operations (webhook registration, metafield writes, teardown). The fix is to call `$this->client->graphql(ShopDomain::fromUntrusted($shopDomain), $accessToken, $apiVersion, $query, $variables)` — the client already exists in sibling methods.
    - **Plain English:** Think of a warehouse with a shared dock for all tenants. Every brand’s delivery trucks go through a central traffic controller that schedules them so no one jam occurs. But when an affiliate wants to see the product catalog, we send a truck straight to the dock without telling anyone. At a few brands it works; with hundreds, the dock gets overloaded and nobody’s deliveries get through. The fix is to route the affiliate’s truck through the same traffic controller already in place.
    - **Evidence:**
        ```php
        // AffiliateProductCatalogService.php, in queryAdminCatalog:
        try {
            $response = Http::timeout(20)
                ->acceptJson()
                ->withHeaders([
                    'X-Shopify-Access-Token' => $accessToken,
                ])
                ->post($url, [
                    'query' => $query,
                    'variables' => $variables,
                ]);
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **LIFE-2** · P2 — Concurrent resyncs can overwrite each other’s metadata in ShopifyDataResyncService
    - **Where:** app/Services/Shopify/ShopifyDataResyncService.php — `resync()` method, inside the `DB::transaction()`
    - **Affects:** Brand settings that are merged into `provider_metadata` (e.g. `webhook_ids`, `storefront_token`, `last_resynced_at`). Two near‑simultaneous resyncs (e.g. manual + automated) can silently lose one’s changes.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Call `$integration->lockForUpdate()` inside the transaction before reading any part of `provider_metadata`.
        - Re‑read the metadata after locking to avoid a stale snapshot.
    - **Technical:** The `resync` fetches shop data outside the transaction, then inside the transaction calls `$integration->mergeProviderMetadata(['last_resynced_at' => …])`. `mergeProviderMetadata` loads the current JSONB column, merges in the new key, and saves — a classic read‑modify‑write. Without `lockForUpdate`, two concurrent transactions can both read the same metadata, each merge their own timestamp, and the second save completely overwrites the first’s merge (lost update). The canonical `lockForUpdate + UNIQUE` pattern requires locking the row before the read phase so that PostgreSQL serialises the two merges.
    - **Plain English:** Imagine two people editing the same spreadsheet cell at the same time. Each grabs the current value, adds their note, and saves. The last save wins and the first person’s note disappears. The fix is to put a lock on the cell so only one person can edit at a time — the second person waits and then sees the updated value.
    - **Evidence:**
        ```php
        // In resync():
        $diff = DB::connection('pgsql')->transaction(function () use ($integration, …) {
            $diff = $this->autoFill->resyncFromShopData($integration, $shopData);
            // Race window: another resync can read metadata here
            $integration->mergeProviderMetadata([
                'last_resynced_at' => $lastResyncedAt,
            ]);
            …
        });
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-3** · P2 — Swallowed exceptions in BrandDesignImporter lack any logging, making theme‑fetch failures invisible
    - **Where:** app/Services/Shopify/BrandDesignImporter.php — `fetchActiveThemeSettings()` method, two `catch (\Throwable)` blocks
    - **Affects:** On‑boarding brands where the Shopify Admin GraphQL or Asset API is temporarily failing — the brand imports successfully but receives no theme settings, and nobody knows why.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Log each caught exception at `warning` level with context: `['shop_domain' => $shopDomain, 'integration_id' => ..., 'step' => 'themesQuery|assetFetch', 'exception' => $e]`.
        - Ensure the log includes `professional_id` (or integration UUID) for Nightwatch correlation.
    - **Technical:** Both `catch (\Throwable)` blocks return an empty `['_theme_name' => null, 'current' => []]` without a single `Log::` call. A transient Shopify outage or permission problem therefore silently degrades the brand design import, leaving the brand with no corner radius / spacing hints and no indication that anything went wrong. The canonical `Log-with-context` pattern requires that any swallowed exception be recorded so Nightwatch can surface it and operators can trace the root cause.
    - **Plain English:** It’s like a team member who quietly drops a broken part in the bin without telling anyone. The production line keeps moving, but the final product has a weird wobble, and nobody can trace it back to the dropped piece because there’s no note. Adding a quick note to the log says “at step X, this part broke; we carried on, but here’s why.”
    - **Evidence:**
        ```php
        // In fetchActiveThemeSettings:
        try {
            $themesResponse = $this->client->graphql(…);
        } catch (\Throwable) {
            return ['_theme_name' => null, 'current' => []];
        }
        // Same for asset fetch later.
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **LIFE-4** · P2 — ShopifyBulkOperationLock TTL (3600s) can stall a shop’s bulk operations for an hour after a worker crash
    - **Where:** app/Services/Shopify/Client/ShopifyBulkOperationLock.php — `acquire()` method
    - **Affects:** Every bulk operation (metafield backfill, product sync) that uses `ShopifyAdminClient::bulkQuery` / `bulkMutation`. A single worker crash blocks all subsequent bulk work for that shop until the Redis key expires.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Lower the default `bulk_lock_ttl_seconds` to 600s (the maximum `waitForBulkOperation` timeout) plus a small margin (e.g. 610s) so the lock auto‑cleans even if the release path is missed.
        - Alternatively, implement a heartbeat extension inside `waitForBulkOperation` that bumps the key’s TTL while the operation is still running.
    - **Technical:** The lock is acquired with `Redis::set(key, '1', 'EX', 3600, 'NX')`. The happy path always releases after `waitForBulkOperation` finishes (at most 600s). If the worker crashes before reaching the release, the lock stays for the full 3600 seconds, during which any `bulkQuery` or `bulkMutation` for the same shop immediately throws `“bulk operation already in progress”`. This is a soft‑lockout that can persist across Horizon restarts; the canonical remedy is to set the TTL no longer than the maximum expected operation time, so the lock naturally expires.
    - **Plain English:** Imagine a building with a master key that gets left inside the only room it opens. The room is cleaned within 10 minutes, but the key’s timer says it’s lost for an hour. For that hour nobody can enter. The fix is to tell the timer “if nobody has come back after 10 minutes, the key is free anyway.”
    - **Evidence:**
        ```php
        public function acquire(string $shopDomain, ?int $ttlSeconds = null): bool
        {
            $ttl = $ttlSeconds ?? (int) config(
                'services.shopify.throttle.bulk_lock_ttl_seconds', 3600);
            $result = Redis::set($this->key($shopDomain), '1', 'EX', $ttl, 'NX');
            ...
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **LIFE-5** · P2 — Brand reinstall can leave webhook registration permanently “queued” if job dispatches fail silently
    - **Where:** app/Services/Shopify/BrandSignupService.php — `handleReinstall()` method
    - **Affects:** Any brand that reinstalls while the Redis queue is down or the Horizon worker is unavailable. Its `webhook_registration_state` stays `queued`, but no webhooks are ever registered, causing silent miss of order/payment webhooks.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Introduce a daily reconcile job (`ReconcileQueuedWebhookRegistrations`) that scans `professional_integrations` with `webhook_registration_state = 'queued'` and re‑dispatches `RegisterShopifyWebhooksJob` (or marks them `failed` after N days).
        - Add a stuck‑state alert for integrations that have been `queued` for > 1 hour.
    - **Technical:** `handleReinstall` updates the integration row to `webhook_registration_state = 'queued'`, then calls `dispatchInstallJobs`. Inside that method, each job dispatch is wrapped in a try‑catch that logs a warning but continues. If the entire queue is unreachable, all dispatches fail silently, yet the integration remains in `queued` forever. The canonical `daily reconcile job` pattern (`0de1f2f`) ensures that any state that depends on a vendor webhook has a sibling cron job that fills in missed deliveries — here the “delivery” is our own dispatch, but the same concept applies.
    - **Plain English:** After you plug in a new lamp, you flip the switch and assume it turned on. If the power is out you just walk away, and the lamp sits dark. A maintenance run should check the lamp once a day and try the switch again. The fix is a daily check that looks for lamps still marked “waiting for power” and tries the switch.
    - **Evidence:**
        ```php
        $integration->update([
            …
            'webhook_registration_state' => 'queued',
        ]);

        $this->dispatchInstallJobs((string) $integration->id);
        // Inside dispatchInstallJobs:
        foreach ($jobs as $jobClass) {
            try {
                $jobClass::dispatch($integrationId);
            } catch (\Throwable $e) {
                Log::warning('Failed to dispatch Shopify install job', …);
            }
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **LIFE-6** · P3 — ShopifyMetrics logs omit professional ID and request context, breaking Nightwatch correlation
    - **Where:** app/Services/Shopify/Client/ShopifyMetrics.php — every log call
    - **Affects:** Operators debugging a single brand’s Shopify API experience; all logs appear as anonymous global traffic.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `professional_id` (or `brand_professional_id`) and `request_id` to the structured context accepted by `ShopifyMetrics` methods; pass them from callers where available.
        - At minimum, accept an `integration_id` so the log can join back to a tenant.
    - **Technical:** The `shopify.client.*` log lines are the primary diagnostic surface for Shopify API health, but they contain only `shop_domain`, `wait_ms`, `actual_cost`, etc. In a multi‑tenant system, Nightwatch cannot group these events by brand or trace them back to an API request. The canonical `Log-with-context` pattern requires that every `Log::` call from a vendor client carries a tenant identifier so operators can filter to “what happened for brand X.”
    - **Plain English:** The dashboard of a delivery van shows speed, fuel, and location, but never the van’s licence plate. When 50 vans are on the road and one is struggling, you can’t tell which van to help. The fix is to write the licence plate on every dashboard screen.
    - **Evidence:**
        ```php
        public function throttled(string $shopDomain, int $waitMs, int $attempt): void
        {
            Log::warning('shopify.client.throttled', [
                'shop_domain' => $shopDomain,
                'wait_ms' => $waitMs,
                'attempt' => $attempt,
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **LIFE-7** · P2 — Affiliate product selection seeding can create duplicate rows under concurrent operations
    - **Where:** app/Services/Store/AffiliateProductCatalogService.php — `seedDefaultSelections()` method
    - **Affects:** Affiliates whose brand connection triggers seeding from two sources (e.g. connection job + manual UI action) simultaneously; they end up with duplicated product selections.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the check‑then‑insert loop in a database transaction with `lockForUpdate` on the relevant `affiliate_product_selections` rows, or add a `UNIQUE` constraint on `(affiliate_professional_id, brand_professional_id, shopify_product_gid)`.
        - Alternatively, replace the `in_array` + `create` with an upsert (`INSERT … ON CONFLICT DO NOTHING`) to make the operation idempotent.
    - **Technical:** `seedDefaultSelections` fetches all existing GIDs, then iterates over defaults and creates any that aren’t in the list. Without a lock or unique constraint, two concurrent calls will both observe the same missing GIDs, both exit the `in_array` guard, and both call `create`, producing duplicate rows. The canonical `lockForUpdate + UNIQUE` pattern would either serialize the two calls with a row lock or let the database reject the duplicate via a `UNIQUE` constraint.
    - **Plain English:** Two club bouncers each check the guest list at the same time. Both see that Alice isn’t on the list, so they both add her name. Now the list has two Alices. The fix is either to have one bouncer hold the list while the other waits, or to tell the paper “if Alice is already there, don’t write her again.”
    - **Evidence:**
        ```php
        $existingGids = AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $affiliate->id)
            ->where('brand_professional_id', $brandProfessionalId)
            ->pluck('shopify_product_gid')->all();

        foreach ($defaultGids as $gid) {
            if (in_array($gid, $existingGids, true)) {
                continue;
            }
            AffiliateProductSelection::create([
                'affiliate_professional_id' => $affiliate->id,
                'brand_professional_id' => $brandProfessionalId,
                'shopify_product_gid' => $gid,
                …
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **LIFE-8** · P3 — Hydrogen deployment dispatch may cause duplicate GitHub Actions runs if debounce window is exceeded
    - **Where:** app/Services/Shopify/HydrogenDeploymentService.php — `dispatchDeployment()` method
    - **Affects:** Brands that trigger a deploy (e.g. saving Oxygen credentials), then immediately trigger another before the 60‑second debounce expires — the second call skips, but if the first fails and is retried after >60s, a duplicate deploy lands.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Include a deterministic `client_payload` containing a UUID derived from the `professional_id` + a deploy counter, so GitHub Actions can recognise and skip duplicate runs.
        - Alternatively, extend the debounce TTL to match the maximum expected deploy time (e.g. 5 minutes).
    - **Technical:** The service uses a `Cache::add` debounce with a 60‑second lock to collapse rapid saves, but after the lock expires there is nothing stopping a retried dispatch from firing a second workflow. GitHub Actions `workflow_dispatch` does not natively deduplicate; if two dispatches land with the same inputs, they create two separate workflow runs, potentially resulting in two concurrent deployments that clash. The canonical `lockForUpdate + UNIQUE` pattern for external API calls suggests passing a client‑side idempotency key (here a unique `client_payload`) so the receiving side can ignore duplicates.
    - **Plain English:** It’s like asking a builder to start a job. You say “if you’re already building, ignore me” for one minute. But if you call back two minutes later, the builder starts a second crew on the same house. The fix is to include a unique job number with your request, so even if you call twice, the builder sees “oh, job #42 is already open” and doesn’t start a second gang.
    - **Evidence:**
        ```php
        if (! Cache::add("hydrogen:deploy:debounce:{$professionalId}", true, 60)) {
            Log::info('HydrogenDeployment: debounced rapid dispatch.');
            return;
        }
        // …
        $response = Http::withToken($token)
            ->withHeaders([…])
            ->post($url, [
                'ref' => $ref,
                'inputs' => ['professional_id' => $professionalId],
            ]);
        ```
    - `[DRAFT, confidence: 0.70]`
