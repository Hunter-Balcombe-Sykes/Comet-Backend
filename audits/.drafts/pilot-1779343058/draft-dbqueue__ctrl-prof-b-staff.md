- [ ] **SCALE-1** · P3 — `SquareIntegrationController::syncServicesNow` makes a synchronous, full-catalog Square API call on the request thread
    - **Where:** app/Http/Controllers/Api/Professional/SquareIntegration/SquareIntegrationController.php:241-267
    - **Affects:** Professional-initiated manual Square sync — the request thread holds a PHP-FPM worker for the duration of the Square round-trip.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the inline `$syncService->syncFromSquare($pro, fullSync: true)` call with a queued job (e.g., `SyncSquareCatalogDeltaJob` with `fullSync: true`) so the worker returns immediately.
        - Return a `202 Accepted` with a polling endpoint so the frontend can surface progress instead of blocking the user.
    - **Technical:** Category 3 — Connection pool & transaction scoping. The controller calls `$syncService->syncFromSquare($pro, fullSync: true)` inline, which performs a full Square API catalog pull synchronously. At the scale target (200 brands), a single brand with 500+ catalog items experiences a multi-second request that ties up one of the limited PHP-FPM workers. The endpoint is user-initiated and infrequent, but the blocking shape is unnecessary — the `connect()` method in the same controller already demonstrates the correct async pattern by dispatching `SyncSquareCatalogDeltaJob`. The fix is to unify the manual-refresh path onto the same async dispatch.
    - **Plain English:** Imagine a support hotline where the agent stays on the phone while a file downloads from a slow third-party server. The agent can't help anyone else during that time. Moving the download to a background job lets the agent hang up and call back when it's done. Same fix here — fire off the sync and let the user check back later instead of staring at a spinner.
    - **Evidence:**
        ```php
        // app/Http/Controllers/Api/Professional/SquareIntegration/SquareIntegrationController.php:256-266
        try {
            $stats = $syncService->syncFromSquare($pro, fullSync: true);
        } catch (SquareApiException $e) {
            [$message, $status] = $this->buildSquareErrorMessage($e);
            // ...
            return $this->error($message, $status);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **SCALE-2** · P3 — `FreshaIntegrationController::syncServicesNow` makes a synchronous, full-catalog Fresha API call on the request thread
    - **Where:** app/Http/Controllers/Api/Professional/FreshaIntegration/FreshaIntegrationController.php:229-248
    - **Affects:** Professional-initiated manual Fresha sync — same blocking shape as SCALE-1.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the inline `$syncService->syncFromFresha($pro, fullSync: true)` with a queued job (reuse `SyncFreshaCatalogDeltaJob` with `fullSync: true`).
        - Return `202 Accepted` with a polling mechanism consistent with the Square fix.
    - **Technical:** Category 3 — identical pattern to SCALE-1 but for Fresha. The `connect()` method already dispatches `SyncFreshaCatalogDeltaJob` async; `syncServicesNow` should do the same instead of blocking the request thread. Impact is low (infrequent manual action) but the inconsistency with the connect path is a maintenance smell.
    - **Plain English:** Same phone-support analogy — the agent waits on hold for Fresha's server instead of queuing the work and moving on to the next call.
    - **Evidence:**
        ```php
        // app/Http/Controllers/Api/Professional/FreshaIntegration/FreshaIntegrationController.php:240-245
        try {
            $stats = $syncService->syncFromFresha($pro, fullSync: true);
        } catch (FreshaApiException $e) {
            [$message, $status] = $this->buildFreshaErrorMessage($e);
            return $this->error($message, $status);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **SCALE-3** · P3 — `StaffShopifyEventReplayController::invoke` makes a synchronous Shopify REST call followed by a synchronous job dispatch on the request thread
    - **Where:** app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffShopifyEventReplayController.php:143-174
    - **Affects:** Staff replaying a single Shopify webhook event — worker held for Shopify round-trip + order-processing job.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Dispatch `ProcessShopifyOrderWebhookJob` asynchronously (`dispatch()` not `dispatchSync()`) and return `202 Accepted`.
        - Surface the existing `dispatchSync` dedup-safe design to the async path — the job's unique index on `shopify_event_id` already guarantees idempotency regardless of sync vs async dispatch.
    - **Technical:** Category 3 — the controller fetches the order from Shopify synchronously (`$this->shopifyClient->rest(...)`), then calls `ProcessShopifyOrderWebhookJob::dispatchSync(...)`. Together these hold a worker for Shopify's response time (500ms–5s) plus the job's DB writes. The endpoint is staff-only and rate-limited to 3/min per event, so blast radius is tiny. The deduplication guarantees (unique partial index on `shopify_event_id`, LWW upsert on `commerce.orders`) are connection-agnostic and work identically on async dispatch.
    - **Plain English:** A staff member clicks "replay webhook" and their browser tab hangs until Shopify responds AND the order finishes saving. Moving the save to a background job lets the staff member get immediate confirmation and move on.
    - **Evidence:**
        ```php
        // app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffShopifyEventReplayController.php:161-174
        ProcessShopifyOrderWebhookJob::dispatchSync(
            brandProfessionalId: (string) $professional->id,
            orderPayload: $orderPayload,
            shopifyEventId: $shopifyEventId,
            source: 'manual',
        );
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **SCALE-4** · P3 — CSV subscriber exports run unbounded cursor queries without a row limit
    - **Where:** app/Http/Controllers/Api/Professional/Notifications/ProfessionalEmailSubscriptionController.php:162-174 and app/Http/Controllers/Api/Staff/StaffSite/StaffEmailSubscriberController.php:274-286
    - **Affects:** Brand or staff exporting a subscriber list — a brand with 50K+ subscribers ties up a worker for the full export duration.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a reasonable `->limit(50000)` or `->take(config('partna.exports.subscribers.max_rows', 100000))` to the export query so a runaway list doesn't hold a worker indefinitely.
        - Consider dispatching large exports to a background job that emails a download link (matching the existing `CommissionExportService` pattern).
    - **Technical:** Category 2 — unbounded result sets. Both `export()` methods use `$query->cursor()` (good for memory) but the query has no `limit` or `take`. At the scale target, a heavily-marketed brand could accumulate 50K+ subscribers. `cursor()` streams rows one-by-one so memory is ~constant, but the PHP-FPM worker is occupied for the entire streaming duration, which could be 30–60s for a large list. This blocks other requests that share the worker pool. The fix is a configurable row cap or dispatching to a job for large lists.
    - **Plain English:** A brand with 50,000 email subscribers clicks "Export." Their browser starts downloading a CSV, and behind the scenes, one of the server's limited request-handling slots is tied up for a full minute streaming every row. Capping the export or moving it to a background job prevents one big download from hogging a slot other users need.
    - **Evidence:**
        ```php
        // app/Http/Controllers/Api/Professional/Notifications/ProfessionalEmailSubscriptionController.php:162-173
        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'full_name', 'status', 'subscribed_at', 'unsubscribed_at']);
            foreach ($query->cursor() as $row) {   // ← no ->limit()
                fputcsv($out, [...]);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **SCALE-5** · P3 — `StaffServiceManagementController::reorderLayout` issues N+1 individual UPDATE statements inside a transaction with an advisory lock
    - **Where:** app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffServiceManagementController.php:199-234
    - **Affects:** Staff reordering a professional's service layout — for 100+ services the transaction holds locks across 100+ individual queries.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the per-row `update()` loop with a single bulk-upsert (e.g., a CTE or multi-row `UPDATE ... FROM (VALUES ...)` statement) so the DB executes one query instead of N.
        - As a lower-cost stopgap, ensure `statement_timeout` is set on the transaction so a stuck lock doesn't cascade.
    - **Technical:** Category 1 — N+1 pattern (write-side). The `reorderLayout` method opens a transaction with `pg_advisory_xact_lock`, then iterates `foreach ($payload['categories'] as $catBlock)` and `foreach ($catBlock['service_ids'] as $i => $serviceId)` issuing one `UPDATE` per category and one `UPDATE` per service. For a professional with 10 categories and 100 services, this is 110 individual `UPDATE` statements inside a single serialized transaction. Each query round-trips to Postgres, and the advisory lock blocks any other layout mutation for the same professional. Impact is bounded (staff-only, single-professional scope, max maybe 200-300 services), but the code shape is fragile if the per-professional cap ever rises.
    - **Plain English:** Rearranging someone's service menu fires off one database update per item — if they have 100 services, that's 100 separate round trips, all while holding an "under construction" sign that blocks anyone else from touching the same menu. Bundling all the updates into a single batch is both faster and shorter-holding.
    - **Evidence:**
        ```php
        // app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffServiceManagementController.php:219-232
        foreach ($payload['categories'] as $catBlock) {
            $catId = $catBlock['id'] ?? null;
            if ($catId !== null) {
                ServiceCategory::query()->where(...)->where('id', $catId)
                    ->update(['sort_order' => $categorySort++]);
            }
            foreach ($catBlock['service_ids'] as $i => $serviceId) {
                Service::query()->where(...)->where('id', $serviceId)
                    ->update(['category_id' => $catId, 'sort_order' => $i]);
            }
        }
        ```
    - `[DRAFT, confidence: 0.70]`
