- [ ] **#LIFE-1** · P1 — ExportFinalizerJob sends email before marking audit completed; retry re-sends duplicate email
    - **Where:** app/Jobs/Exports/ExportFinalizerJob.php (handle method: Mail::send before markCompleted)
    - **Affects:** Any brand professional requesting a commission export. At scale (200 brands × occasional exports), a transient crash during finalization sends duplicate "Your export is ready" emails, which is confusing and erodes trust.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `Mail::send` after `$audit->markCompleted(...)` so a crash between send and mark retries cleanly — the second run sees STATUS_COMPLETED and returns immediately.
        - Or adopt the broadcast-email pattern from `SendStaffBroadcastEmailToSubscriberJob`: insert a receipt row before sending, and gate on the receipt for at-most-once delivery.
    - **Technical:** The STATUS_COMPLETED guard at the top of `handle()` only catches fully-completed runs. A retry that lands between `Mail::send` and `markCompleted` passes the guard (status is still "processing"), re-uploads the file (harmless overwrite), and re-sends the email. The canonical Stripe payout fix (`#STRIPE-2`) established that functions with multiple outcomes need distinct paths — here the email send and the state transition are ordered for at-least-once email, but the guard only catches the terminal state, not the mid-flight crash window.
    - **Plain English:** Imagine a waiter who marks an order "delivered" only after the customer has signed for it. If the waiter trips between handing over the food and marking the delivery, the kitchen sees the order as still pending and sends a second plate. The fix is to mark the order delivered first, then hand over the food — if something goes wrong after marking, the customer can ask for a replacement, which is better than getting two plates and being confused.
    - **Evidence:**
        ```php
        Mail::to($audit->recipient_email)->send(new CommissionExportReadyMail(
            signedUrl: $signedUrl,
            role: $audit->role,
            format: $audit->format,
            filters: $audit->filters ?? [],
            recordCount: $meta['row_count'],
            ttlDays: $ttlDays,
            expiresAt: now()->addDays($ttlDays),
        ));

        $audit->markCompleted(
            filePath: $remoteFinalPath,
            size: $meta['size'],
            sha256: $meta['sha256'],
        );
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#LIFE-2** · P1 — ExportProfessionalDataJob has read-modify-write race on status transition
    - **Where:** app/Jobs/Gdpr/ExportProfessionalDataJob.php (handle method: status check then markProcessing)
    - **Affects:** Any professional requesting a GDPR data export. At pilot scale this is rare, but a race between two dispatches (e.g. Horizon scale-out or retry overlapping original) could double-process the export — uploading two zips, sending two emails, and leaving the audit row in an indeterminate state.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the status check + `markProcessing()` in a `DB::transaction` with `lockForUpdate` on the audit row, matching the canonical `lockForUpdate` pattern from `SendEnquiryNotificationJob` (which already does this correctly).
        - Or use a single atomic `UPDATE ... WHERE status NOT IN ('completed','failed')` query and check `rowCount()` before proceeding.
    - **Technical:** The guard `in_array($audit->status, [COMPLETED, FAILED])` and the subsequent `$audit->markProcessing()` are two separate statements with no lock between them. Two concurrent workers can both read `status = 'queued'`, both pass the guard, and both call `markProcessing()`. The second worker then proceeds through the entire export pipeline — zip creation, R2 upload, email send — duplicating work. The canonical `lockForUpdate + UNIQUE` pattern (`5735525`) requires a row-level lock for this exact read-modify-write shape.
    - **Plain English:** Two receptionists both check the same appointment book at the same time, see the slot is empty, and both book a different client into it. Now two people show up for one slot. The fix is to have only one person hold the pen at a time — the second person has to wait and when they look, they see it's already filled.
    - **Evidence:**
        ```php
        if (in_array($audit->status, [DataExportAudit::STATUS_COMPLETED, DataExportAudit::STATUS_FAILED], true)) {
            return;
        }

        // Professional may have been hard-deleted between dispatch and run ...

        $audit->markProcessing();
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#LIFE-3** · P2 — Gdpr/RedactCustomerJob has read-modify-write race on status transition (same shape as LIFE-2)
    - **Where:** app/Jobs/Shopify/Gdpr/RedactCustomerJob.php (handle method: status check then update)
    - **Affects:** GDPR redaction requests from Shopify. A race between two dispatches could double-anonymise a customer — the second run sees `redacted_at` already set and skips, so impact is limited, but the `gdpr_requests` status and professional-level cleanup (`email_subscriptions` delete, `booking_events` scrub) could race.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Same fix as LIFE-2: wrap the status guard + `STATUS_PROCESSING` update in a `lockForUpdate` transaction on the GdprRequest row.
    - **Technical:** Same read-modify-write anti-pattern as LIFE-2. The status check and status update are not atomic. The `whereNull('redacted_at')` guard on the Customer query provides some protection for the Customer row itself, but the sibling cleanup paths (`email_subscriptions` delete, `booking_events` scrub) are executed unconditionally and could be double-run. The canonical `lockForUpdate + UNIQUE` pattern applies.
    - **Plain English:** Same "two receptionists booking the same slot" scenario as the GDPR export job. Here, the second receptionist sees the appointment's already been handled (because the customer's file is stamped "redacted"), but they still go ahead and clean the waiting room and shred documents that the first receptionist already handled. No harm done, but it's wasted work that could collide under heavier load.
    - **Evidence:**
        ```php
        if (in_array($gdpr->status, [GdprRequest::STATUS_COMPLETED, GdprRequest::STATUS_SKIPPED], true)) {
            return;
        }

        $gdpr->update(['status' => GdprRequest::STATUS_PROCESSING]);
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **#LIFE-4** · P2 — Gdpr/RedactShopJob has read-modify-write race on status transition (same shape as LIFE-2/LIFE-3)
    - **Where:** app/Jobs/Shopify/Gdpr/RedactShopJob.php (handle method: status check then update)
    - **Affects:** Shopify shop/redact GDPR requests. A race could double-execute the narrow-scope cleanup — access token is already nulled on the first pass, but `AffiliateProductSelection` delete and customer anonymisation could race.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Same fix: `lockForUpdate` on the GdprRequest row before the status transition.
    - **Technical:** Identical read-modify-write gap to LIFE-2 and LIFE-3. The access token nullification on first run provides a partial guard (subsequent runs would 401 or skip), but the `AffiliateProductSelection::delete()` and `anonymiseShopifyCustomers()` are idempotent at the data level — the real risk is wasted I/O at scale, not corruption. Still, the canonical pattern should be applied uniformly.
    - **Plain English:** Same pattern — two workers both start the cleanup, one finishes first (disconnecting the power), the second arrives and finds everything already turned off but still walks through all the rooms flipping switches that are already down. Inefficient but not destructive.
    - **Evidence:**
        ```php
        if (in_array($gdpr->status, [GdprRequest::STATUS_COMPLETED, GdprRequest::STATUS_SKIPPED], true)) {
            return;
        }

        $gdpr->update(['status' => GdprRequest::STATUS_PROCESSING]);
        ```
    - `[DRAFT, confidence: 0.70]`

- [ ] **#LIFE-5** · P2 — Gdpr/ExportCustomerDataJob has read-modify-write race on status transition (same shape as LIFE-2)
    - **Where:** app/Jobs/Shopify/Gdpr/ExportCustomerDataJob.php (handle method: status check then update)
    - **Affects:** GDPR customer data export requests from Shopify. Same race shape as LIFE-2 — duplicate email to the merchant, duplicate processing.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Same fix: `lockForUpdate` on the GdprRequest row.
    - **Technical:** Same read-modify-write gap. The guard and the `STATUS_PROCESSING` update happen in separate statements without a lock.
    - **Plain English:** Same two-receptionists problem, different appointment type. The GDPR data export desk has the same booking-book double-check issue.
    - **Evidence:**
        ```php
        if (in_array($gdpr->status, [GdprRequest::STATUS_COMPLETED, GdprRequest::STATUS_SKIPPED], true)) {
            return;
        }

        $gdpr->update(['status' => GdprRequest::STATUS_PROCESSING]);
        ```
    - `[DRAFT, confidence: 0.70]`

- [ ] **#LIFE-6** · P2 — PushServiceToFreshaJob swallowed retry exhaustion — Nightwatch never sees permanent failures
    - **Where:** app/Jobs/Fresha/PushServiceToFreshaJob.php (failed method)
    - **Affects:** Professional accounts using Fresha booking integration. At 200 brands with ~5 using Fresha, the blast radius is small, but a permanently-failing push means service updates silently stop syncing — the professional's Fresha catalog drifts from Partna with no alert.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e);` before `Log::warning(...)` in the `failed()` method, matching the canonical `Log-with-context` pattern used by Stripe jobs (e.g. `ExecuteCommissionPayoutJob::failed()`).
    - **Technical:** `Log::warning` writes a structured breadcrumb to cloud logs, but Nightwatch alerting triggers on exceptions and auto-detected slow jobs/routes, NOT on log queries. Without `report($e)`, the exception never reaches Laravel's exception handler, so Nightwatch never fires an alert for a permanently-exhausted Fresha push job. The canonical Stripe payout fix (`#STRIPE-2`, `35c6f31`) established that every `failed()` must call `report($e)` so retry exhaustion is observable by notification_id/professional_id.
    - **Plain English:** When the Fresha sync completely fails after all retries, it writes a note in a logbook but doesn't turn on the warning light on the operations dashboard. If nobody happens to be reading that specific logbook page, the Fresha integration silently breaks and nobody notices until a professional complains. The fix is to also flip the warning switch.
    - **Evidence:**
        ```php
        public function failed(\Throwable $e): void
        {
            Log::warning('Fresha push service job failed', [
                'service_id' => $this->serviceId,
                'action' => $this->action,
                'message' => $e->getMessage(),
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#LIFE-7** · P2 — SyncFreshaCatalogDeltaJob swallowed retry exhaustion — Nightwatch never sees permanent failures
    - **Where:** app/Jobs/Fresha/SyncFreshaCatalogDeltaJob.php (failed method)
    - **Affects:** Same as LIFE-6 — Fresha catalog delta sync failures are invisible to Nightwatch.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e);` before `Log::warning(...)` in `failed()`.
    - **Technical:** Same missing `report($e)` pattern as LIFE-6. The catalog delta sync fetches Fresha's service catalog and syncs it into Partna — a permanent failure means the professional's services are stale indefinitely.
    - **Plain English:** Same silent-warning-light problem but for the catalog sync direction (Fresha → Partna) instead of the push direction (Partna → Fresha).
    - **Evidence:**
        ```php
        public function failed(\Throwable $e): void
        {
            Log::warning('Fresha catalog sync job failed', [
                'business_id' => $this->businessId,
                'begin_time' => $this->beginTime,
                'full_sync' => $this->fullSync,
                'message' => $e->getMessage(),
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#LIFE-8** · P2 — PushServiceToSquareJob swallowed retry exhaustion — Nightwatch never sees permanent failures
    - **Where:** app/Jobs/Square/PushServiceToSquareJob.php (failed method)
    - **Affects:** Professional accounts using Square booking integration. Same blast radius and drift risk as LIFE-6 but for Square.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e);` before `Log::warning(...)` in `failed()`.
    - **Technical:** Same missing `report($e)` pattern. Canonical: every `failed()` must call `report($e)` so Nightwatch surfaces retry exhaustion.
    - **Plain English:** Square's version of the silent-warning-light problem from LIFE-6.
    - **Evidence:**
        ```php
        public function failed(\Throwable $e): void
        {
            Log::warning('Square push service job failed', [
                'service_id' => $this->serviceId,
                'action' => $this->action,
                'message' => $e->getMessage(),
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#LIFE-9** · P2 — SyncSquareCatalogDeltaJob swallowed retry exhaustion — Nightwatch never sees permanent failures
    - **Where:** app/Jobs/Square/SyncSquareCatalogDeltaJob.php (failed method)
    - **Affects:** Same as LIFE-8, catalog sync direction.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e);` before `Log::warning(...)` in `failed()`.
    - **Technical:** Same missing `report($e)` pattern.
    - **Plain English:** Square's catalog-sync version of the same silent-warning-light problem.
    - **Evidence:**
        ```php
        public function failed(\Throwable $e): void
        {
            Log::warning('Square catalog sync job failed', [
                'merchant_id' => $this->merchantId,
                'begin_time' => $this->beginTime,
                'full_sync' => $this->fullSync,
                'message' => $e->getMessage(),
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#LIFE-10** · P2 — CheckStreamingLiveStatusJob swallowed retry exhaustion — Nightwatch never sees permanent failures
    - **Where:** app/Jobs/Streaming/CheckStreamingLiveStatusJob.php (failed method)
    - **Affects:** Streaming live-status polling (Twitch/Kick). At 200 brands with ~50 using streaming blocks, this runs every 2 minutes. A permanently-failed poll means live-status badges on affiliate sitepages go stale with no alert.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e);` before `Log::error(...)` in `failed()`, matching the canonical `Log-with-context` pattern.
    - **Technical:** Same missing `report($e)` pattern. This job runs on `tries=1`, so `failed()` fires immediately on any exception — making the missing `report($e)` more impactful because a single transient error (e.g. Twitch API 5xx) kills the polling cycle silently.
    - **Plain English:** The job that checks whether streamers are live runs every 2 minutes. If it completely fails, it writes a note in the logbook but doesn't turn on the warning light. The next 2-minute cycle will try again, but if there's a persistent problem (like an API key expiring), nobody finds out until streamers or their viewers complain about stale "offline" badges.
    - **Evidence:**
        ```php
        public function failed(\Throwable $e): void
        {
            Log::error('streaming.job_failed', ['message' => $e->getMessage()]);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#LIFE-11** · P3 — ProcessShopifyShopUpdateJob logs warning without report() when integration record is missing
    - **Where:** app/Jobs/Shopify/ProcessShopifyShopUpdateJob.php (handle method, integration-not-found branch)
    - **Affects:** Shopify shop/update webhook processing. A missing integration is unexpected — it means a webhook arrived for a shop we think we're not connected to. The warning is logged but Nightwatch won't alert.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report(new \RuntimeException(...))` alongside the `Log::warning` so Nightwatch surfaces the anomaly. This is an unexpected state (webhook for a non-existent integration) and should trigger an alert, not just a silent log entry.
    - **Technical:** The missing-integration branch is an anomaly — it means Shopify sent a shop/update webhook for a professional_id that has no matching ProfessionalIntegration row. This could indicate a Shopify app reinstall that bypassed our OAuth flow, or a data integrity issue. `Log::warning` alone won't trigger a Nightwatch alert (Nightwatch alerts on exceptions, not log queries). The canonical `Log-with-context` pattern requires surfacing anomalies as exceptions so they're visible in the operations dashboard.
    - **Plain English:** If Shopify sends us a "shop updated" notification for a store we don't think is connected to us, that's strange — it's like getting a package delivery notification for a house you don't own. Right now, that strangeness gets written in a logbook but nobody gets paged. The fix is to also sound the alarm so the operations team can investigate.
    - **Evidence:**
        ```php
        if (! $integration) {
            Log::warning('Shopify shop/update: no integration record found.', [
                'professional_id' => $this->professionalId,
            ]);

            return;
        }
        ```
    - `[DRAFT, confidence: 0.70]`

- [ ] **#LIFE-12** · P2 — CreateShopifyAffiliateDiscountJob has TOCTOU race between discount-existence check and creation
    - **Where:** app/Jobs/Shopify/CreateShopifyAffiliateDiscountJob.php (handle method: automaticDiscountAlreadyInstalled check then createAutomaticDiscount)
    - **Affects:** Brands connecting Shopify at scale (200 brands). Two concurrent dispatches of the OAuth install chain could both check for existing discount, both see none, and both attempt `discountAutomaticAppCreate`. Shopify likely rejects the duplicate, but the second attempt wastes an API call and logs a confusing error. `ShouldBeUnique` narrows the window to the `uniqueFor` expiry edge case plus any cross-worker race.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the check + create in a single try/catch that handles the Shopify "discount already exists" error gracefully — treat it as success rather than throwing.
        - Or pass an idempotency key derived from the integration ID + function ID to `discountAutomaticAppCreate` so Shopify dedupes the second call server-side.
    - **Technical:** The pattern `if exists → skip; else → create` has a TOCTOU window between the existence query and the create mutation. `ShouldBeUnique` with `uniqueFor=300` prevents same-integration concurrency within a 5-minute window, but if the unique lock expires just as the create fires (edge case), or if Shopify's eventual-consistency index hasn't caught up to a prior create, the second attempt fails. The canonical `idempotency key` pattern requires passing a deterministic key to the vendor so the platform handles dedup, rather than relying on client-side check-then-create.
    - **Plain English:** Imagine two assistants both calling a restaurant to book the same table at the same time. Both call, both ask "is Table 5 free?", both hear "yes", and both try to book it. The restaurant's system catches the double-booking, but one assistant gets an error message and has to clean it up. The fix is to give each booking a unique confirmation number so the restaurant can tell it's the same booking attempt and just say "already confirmed" instead of "error."
    - **Evidence:**
        ```php
        if ($this->automaticDiscountAlreadyInstalled($shopDomain, $accessToken, $apiVersion, $functionId)) {
            $integration->mergeProviderMetadata(['partna_discount_state' => 'registered']);
        } else {
            $this->createAutomaticDiscount($shopDomain, $accessToken, $apiVersion, $functionId);
            $integration->mergeProviderMetadata(['partna_discount_state' => 'registered']);
        }
        ```
    - `[DRAFT, confidence: 0.65]`

- [ ] **#LIFE-13** · P2 — CreateShopifyCollectionsJob has TOCTOU race between collection-existence check and creation (same shape as LIFE-12)
    - **Where:** app/Jobs/Shopify/CreateShopifyCollectionsJob.php (findOrCreateCollection method: COLLECTIONS_QUERY existence check then COLLECTION_CREATE)
    - **Affects:** Brands connecting Shopify. Same TOCTOU window as LIFE-12. Two concurrent dispatches could both query for collection existence, both find none, and both create — producing duplicate collections. `ShouldBeUnique` mitigates but doesn't fully close.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Same as LIFE-12: catch the "collection already exists" error from Shopify and treat it as success, or use idempotency keys.
    - **Technical:** Same check-then-create TOCTOU anti-pattern. Shopify's collection title namespace is per-store, so duplicate creations would produce two collections with the same title — confusing for the brand and potentially breaking the collection-handle metafield references that downstream jobs depend on. The canonical `idempotency key` pattern should apply.
    - **Plain English:** Same two-assistants-booking-a-table problem, but for creating collections on a Shopify store. If two helpers both create the same collection, the brand ends up with duplicate folders in their Shopify admin, and the downstream "which collection is the Active Products one?" lookup picks one arbitrarily — possibly the empty duplicate.
    - **Evidence:**
        ```php
        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::COLLECTIONS_QUERY, [
            'query' => "title:'{$def['title']}'",
            'first' => 1,
        ]);

        $edges = $response->json('data.collections.edges', []);
        if (! empty($edges)) {
            // ... return existing
        }

        // Create the collection
        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::COLLECTION_CREATE, [
            'input' => $input,
        ]);
        ```
    - `[DRAFT, confidence: 0.65]`

- [ ] **#LIFE-14** · P2 — CreateShopifySalesChannelJob has TOCTOU race between publication-existence check and creation (same shape as LIFE-12/LIFE-13)
    - **Where:** app/Jobs/Shopify/CreateShopifySalesChannelJob.php (handle method: findExistingPublicationId then PUBLICATION_CREATE)
    - **Affects:** Brands connecting Shopify. Same TOCTOU pattern. Duplicate publication creation is less harmful (Shopify likely rejects the duplicate name), but the wasted API call and potential error log noise are avoidable.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Same approach: treat "publication already exists" as success, or use idempotency keys.
    - **Technical:** Same check-then-create gap. The sales channel publication name is unique per store on Shopify, so a duplicate would be rejected with a userError — the job would throw on the second attempt. This is caught by the outer `try/catch` and triggers a retry, which then finds the first run's publication and succeeds. So the blast radius is one wasted retry cycle, not a permanent failure. Lower severity than LIFE-12/LIFE-13 but same root cause.
    - **Plain English:** Same booking-two-tables problem but for a publication channel. Less harmful because Shopify's system catches the duplicate, but it still wastes an API call and a retry cycle that could delay the brand's setup.
    - **Evidence:**
        ```php
        $existingPublicationId = $this->findExistingPublicationId($shopDomain, $accessToken, $apiVersion);
        if ($existingPublicationId !== null) {
            // ... return early
        }

        // Create publication
        $response = $this->graphql($shopDomain, $accessToken, $apiVersion, self::PUBLICATION_CREATE, [
            'input' => ['autoPublish' => false],
        ]);
        ```
    - `[DRAFT, confidence: 0.60]`
