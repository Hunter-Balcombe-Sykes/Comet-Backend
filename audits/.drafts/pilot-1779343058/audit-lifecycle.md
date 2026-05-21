# Lifecycle Audit — 2026-05-21

**Branch:** development
**Lens:** Whole-backend PILOT audit — lifecycle lens (idempotency, state-machine correctness, vendor-integration hygiene, job observability, cache coherence)
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Http/Controllers/Api/`
- `app/Services/`
- `app/Jobs/`
- `app/Observers/`
- `app/Services/Cache/CacheKeyGenerator.php`
- `supabase/migrations/`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 13 complete
- P2 Medium: 0 of 18 complete
- P3 Low: 0 of 9 complete

---

## P1 — Fix before pilot launch

- [ ] **#LIFE-1** · P1 — GDPR webhook dedup row committed before job dispatch; queue failure silences all Shopify retries permanently
    - **Where:** app/Http/Controllers/Api/Webhooks/Shopify/ShopifyGdprWebhookController.php:120–137
    - **Affects:** All three GDPR compliance webhooks (`customers_data_request`, `customers_redact`, `shop_redact`). A Redis outage at the moment of delivery creates a row with `wasRecentlyCreated = true` but no dispatched job; every Shopify retry finds `wasRecentlyCreated = false` and returns 202 without dispatching — the compliance action never runs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap dispatch in a try/catch; on failure delete the `GdprRequest` row so the next Shopify retry can re-process cleanly, OR
        - Move `firstOrCreate` below the dispatch call so the dedup row is only committed after the job is successfully queued.
        - Log distinctly when dispatch fails so Nightwatch surfaces the outage.
    - **Technical:** The `firstOrCreate` on `payload_hash` is consumed immediately. If `ExportCustomerDataJob::dispatch()` throws (Redis unavailable), the 500 response causes Shopify to retry — but the row is already in Postgres, so `wasRecentlyCreated` is `false` and the retry path is a no-op 202. This is the same pattern fixed in the Stripe webhook trait by deleting the dedup row inside the catch. At 200 brands, any queue blip during a GDPR event silently fails the compliance action forever.
    - **Plain English:** The system stamps a compliance request as "received" the moment it arrives, then tries to hand it to the processing team. If the handoff fails because the queue is down, Shopify tries again — but sees the "received" stamp and assumes it's a duplicate, so it never actually processes the request. The fix is to only stamp it "received" after the handoff succeeds, or tear up the stamp if the handoff fails.
    - **Evidence:**
        ```php
        $audit = GdprRequest::firstOrCreate(
            ['payload_hash' => $hash],
            ['topic' => $topic, 'status' => GdprRequest::STATUS_RECEIVED, ...],
        );

        if ($audit->wasRecentlyCreated) {
            match ($topic) {
                GdprRequest::TOPIC_CUSTOMERS_DATA_REQUEST => ExportCustomerDataJob::dispatch($audit->id),
                GdprRequest::TOPIC_CUSTOMERS_REDACT => RedactCustomerJob::dispatch($audit->id),
                GdprRequest::TOPIC_SHOP_REDACT => RedactShopJob::dispatch($audit->id),
            };
        }
        // No try/catch around dispatch — queue failure leaves the row committed
        // and all subsequent retries silently no-op.
        ```

- [ ] **#LIFE-2** · P1 — `ExportFinalizerJob` sends email before marking audit completed; retry re-sends duplicate notification email
    - **Where:** app/Jobs/Exports/ExportFinalizerJob.php (handle method)
    - **Affects:** Any brand professional requesting a commission export. A transient crash between `Mail::send` and `markCompleted` causes the next retry to pass the STATUS_COMPLETED guard, re-upload the file, and re-send the email.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move `$audit->markCompleted(...)` to run before `Mail::send` — if the mail send fails, the user can request a re-send manually; a double email is worse than a delayed one.
        - Or adopt the broadcast-email receipt pattern: insert an `export_email_receipts` row before sending and gate on its existence.
    - **Technical:** The STATUS_COMPLETED guard at the top of `handle()` only catches fully-completed prior runs. A retry in the crash window between send and mark-completed passes the guard, re-uploads the file (harmless overwrite), and re-sends the email. Reordering to mark-then-send is idempotent: a crash after `markCompleted` but before `Mail::send` leaves a job retrying that sees `STATUS_COMPLETED` and exits immediately, without sending again — the worst case is one missed email instead of a duplicate.
    - **Plain English:** A waiter marks the order delivered after handing over the food. If they trip between the handoff and the marking, the kitchen sends a second plate. Marking first means a trip means no second plate — the customer can ask for one, but they won't get two.
    - **Evidence:**
        ```php
        Mail::to($audit->recipient_email)->send(new CommissionExportReadyMail(
            signedUrl: $signedUrl,
            // ...
        ));

        $audit->markCompleted(
            filePath: $remoteFinalPath,
            size: $meta['size'],
            sha256: $meta['sha256'],
        );
        ```

- [ ] **#LIFE-3** · P1 — `ExportProfessionalDataJob` status check and `markProcessing()` are not atomic; concurrent workers double-process GDPR exports
    - **Where:** app/Jobs/Gdpr/ExportProfessionalDataJob.php (handle method)
    - **Affects:** Professionals requesting GDPR data exports. Two concurrent dispatches (Horizon scale-out, retry overlapping original) both pass the status guard and both execute the export pipeline — two zips, two emails, indeterminate audit state.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the status guard + `markProcessing()` in a `DB::transaction` with `lockForUpdate` on the audit row, matching `SendEnquiryNotificationJob` which already uses this pattern correctly.
        - Or use a single atomic `UPDATE … WHERE status NOT IN ('processing','completed','failed') RETURNING id` and only proceed if a row was updated.
    - **Technical:** The `in_array($audit->status, [COMPLETED, FAILED])` check and `$audit->markProcessing()` are two sequential statements with no database lock between them. Two workers can both read `status = 'queued'`, both pass the guard, and both call `markProcessing()`. The canonical `lockForUpdate + UNIQUE` pattern (`5735525`) is the standard fix for this read-modify-write shape in this codebase.
    - **Plain English:** Two receptionists check the same appointment slot at the same time, both see it's empty, and both book a different client. Putting a lock on the appointment book so only one receptionist can write at a time prevents this.
    - **Evidence:**
        ```php
        if (in_array($audit->status, [DataExportAudit::STATUS_COMPLETED, DataExportAudit::STATUS_FAILED], true)) {
            return;
        }
        // Race window: second worker passes guard before first calls markProcessing()
        $audit->markProcessing();
        ```

- [ ] **#LIFE-4** · P1 — `BrandAffiliateInviteController::claim()` executes three sequential mutations without a transaction; invite is consumed but account type stays unchanged on partial failure
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateInviteController.php (claim method)
    - **Affects:** Affiliates claiming brand invites. If `claimInvite` succeeds (status → accepted) but `transition` throws `InvalidAccountTypeTransition`, the invite token is consumed and the affiliate gets a 422 — they cannot retry the token because it's already claimed, and their account type hasn't changed.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap claim + transition + site sync in `DB::transaction()`. If the transition throws, the transaction rolls back and the invite token remains available for retry.
        - If `claimInvite` does external I/O that can't be rolled back, add a compensating `releaseInvite` call in the catch block.
        - Log distinctly when the transition fails after a successful claim — the "claimed but broken" state is currently invisible to Nightwatch.
    - **Technical:** The three operations — claim invite, transition account type, sync site settings — are a mini-aggregate that must succeed or fail together. Any throw between steps leaves rows in an inconsistent state. The canonical fix is a `DB::transaction` around the full aggregate, matching the pattern used in `AccountTypeTransitionService` for other multi-step mutations.
    - **Plain English:** The front desk marks your room as occupied, then tries to cut your key card. If the key-card machine breaks, you have a room on paper but can't get in — and you can't check in again because the room is "taken." Wrapping both steps in one atomic operation means either you get the room and the key, or neither — and you can try again.
    - **Evidence:**
        ```php
        try {
            $claimedInvite = $inviteService->claimInvite($invite, $professional); // consumed
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        try {
            $transitionService->transition(
                $professional->fresh() ?? $professional,
                AccountType::Partner
            );
        } catch (InvalidAccountTypeTransition $e) {
            return $this->error($e->getMessage(), 422); // invite already consumed
        }
        ```

- [ ] **#LIFE-5** · P1 — `BrandStoreSettingsController::update()` commits local DB state before Shopify metafield sync; a Shopify failure leaves dashboard and storefront out of sync
    - **Where:** app/Http/Controllers/Api/Professional/Store/BrandStoreSettingsController.php (update method)
    - **Affects:** Brands updating store settings (commission rate, accent colour, theme variant). The dashboard shows new values but Hydrogen still reads the old values from Shopify metafields — the brand's storefront is out of sync with no self-healing path.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Reorder: run the Shopify metafield sync first; only commit `brand_store_settings` and `site.settings.design` if the sync succeeds.
        - Or queue the sync and return 202 Accepted, with a `last_sync_state` column the dashboard can poll.
        - Add a `last_settings_sync_error` metadata field so drift is surfaced to ops and a reconcile job can retry.
    - **Technical:** The method performs: (1) `updateOrCreate` on `brand_store_settings` → Postgres committed, (2) async Oxygen deployment, (3) `$site->save()` for design tokens, (4) `setShopMetafields` call to Shopify. If step 4 returns `userErrors`, the method returns 422 — but steps 1–3 are already durable. Hydrogen reads from Shopify metafields, so the storefront silently serves stale values until the next successful save. At 200 brands, a Shopify partial outage during a settings-push wave silently drifts every concurrent update.
    - **Plain English:** Updating your storefront is like updating both your internal filing cabinet and the public noticeboard at the same time. The code updates the filing cabinet first, then tries the noticeboard. If the noticeboard is locked, it apologizes but your filing cabinet already has the new info. Anyone who reads the noticeboard (your customers via Hydrogen) still sees the old version.
    - **Evidence:**
        ```php
        // 1. Local DB write — already committed
        $settings = BrandStoreSettings::updateOrCreate(
            ['professional_id' => $pro->id],
            $dbFields
        );
        // 3. Site design tokens — already committed
        $site->settings = $settings;
        $site->save();

        // 4. Shopify sync — runs after local state is durable
        if ($needsShopifySync) {
            if (! $result['success']) {
                return $this->error($msg, 422); // local state already committed!
            }
        }
        ```

- [ ] **#LIFE-6** · P1 — `dispatchImageJob` swallows `Throwable` and returns void; queue failures create permanently-stuck `processing_state = pending` rows with no recovery path
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandGalleryController.php (dispatchImageJob); app/Http/Controllers/Api/Professional/Store/AffiliateProductPhotoController.php (dispatchImageJob)
    - **Affects:** Brand gallery and affiliate product photo uploads. A queue connection failure after `SiteMedia::create` leaves the row stuck in `pending` forever — no reconcile job, no stale-state alert.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Propagate dispatch failure to the caller: if dispatch throws, either delete the `SiteMedia` row (rollback) or return a 202 with a `retry_url` rather than silent 200.
        - Add a daily `ProcessStuckPendingImagesJob` that finds `SiteMedia` rows with `processing_state = pending` older than 15 minutes and re-dispatches or marks failed.
        - At minimum, add `processing_dispatched: false` to the error log so Nightwatch distinguishes "dispatch failure" from "processing failure."
    - **Technical:** `upload()` creates the row, stores the file, updates the path, calls `dispatchImageJob`, and returns success — regardless of whether dispatch worked. The catch block logs the error but swallows it, so the caller returns 200. The `SiteMedia` row exists but no job ever transitions it to `ready`. At 200 brands uploading images, a single queue blip during an upload wave creates orphaned rows that never resolve.
    - **Plain English:** Putting a letter in a mailbox with a broken pickup mechanism. The box confirms it's inside, but the mail truck never comes. The letter sits there forever. The fix is to either check that the truck actually came, or have someone check the box every hour for stuck letters.
    - **Evidence:**
        ```php
        private function dispatchImageJob(string $imageId, string $originalPath, string $basePath): void
        {
            try {
                if ($processInline) {
                    ProcessImageVariantsJob::dispatchSync(...);
                } else {
                    ProcessImageVariantsJob::dispatch(...);
                }
            } catch (Throwable $e) {
                Log::error('Brand gallery: image processing dispatch failed', [
                    'image_id' => $imageId,
                    'error' => $e->getMessage(),
                    // Missing: professional_id, processing_dispatched: false
                ]);
                // Swallowed — caller returns 200 regardless
            }
        }
        ```

- [ ] **#LIFE-7** · P1 — `ProfessionalAnalyticsController::summary()` catches all `QueryException` instances and returns empty data; real query bugs are invisible to Nightwatch
    - **Where:** app/Http/Controllers/Api/Professional/Analytics/ProfessionalAnalyticsController.php (summary, shopSummary methods)
    - **Affects:** Dashboard analytics. Five separate `catch (QueryException)` blocks with no SQLSTATE filter silently convert any query bug — syntax error, constraint violation, schema mismatch — into empty charts, masking real production defects until user reports surface them.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Narrow each catch to `catch (QueryException $e)` with `if (($e->errorInfo[0] ?? null) === '42P01') { return collect(); } throw $e;` — `42P01` is "undefined table," the only expected failure here.
        - For the link_clicks and cart_events tables specifically, pre-flight with `Schema::hasTable()` once per request and skip the query block entirely.
    - **Technical:** `AffiliateCommerceAnalyticsController::buildPayoutSummary` already correctly narrows to SQLSTATE `42703` (undefined column). The analytics summary methods catch ALL `QueryException` instances. A broken migration reaching production, or a `DB::table('analytics.link_clicks')->where(...)` with a mistyped column, produces zero Nightwatch signal — every dashboard silently shows empty charts instead of triggering an alert.
    - **Plain English:** A fire alarm that silences itself for every kind of trigger — smoke, heat, a dead battery, a spider in the sensor — is useless. This catch block treats "the table doesn't exist yet" (expected during rollout) the same as "the query has a syntax error" (a real bug). Narrowing the catch to the expected case lets real bugs through to the alarm.
    - **Evidence:**
        ```php
        try {
            $clicksAgg = DB::table('analytics.link_clicks')
                ->where('professional_id', $professional->id)
                ->whereBetween('occurred_at', [$from, $to])
                ->selectRaw('COUNT(*) as total_clicks')
                ->first();
        } catch (QueryException) {
            // Catches ALL QueryException — syntax errors, broken migrations, typos
            $clicksAgg = (object) ['total_clicks' => 0, ...];
        }
        ```

- [ ] **#LIFE-8** · P1 — `processPayoutBatch` returns `null` for three distinct outcomes; caller cannot distinguish "cancelled" from "in flight" from "already processing"
    - **Where:** app/Services/Stripe/CommissionPayoutService.php:596–670
    - **Affects:** `ExecuteCommissionPayoutJob` retry logic and operational logging. At ~10K daily payout jobs, conflated `null` returns produce incorrect retry decisions and unactionable logs — ops cannot tell whether a `null` return is normal (BECS T+2 re-queue) or a bug (revalidation cancelled all orders).
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Change the return type from `?bool` to a typed enum or DTO: `Completed | InFlight | Cancelled | AlreadyProcessing`.
        - Update `ExecuteCommissionPayoutJob::handle()` to branch on the result type and emit distinct log strings: `AlreadyProcessing` → `info`, `Cancelled` → `notice`, `InFlight` → `info`.
    - **Technical:** Three distinct code paths all return `null`: (1) revalidation cancelled all orders, (2) payout is already processing with a live PI, (3) PI accepted by Stripe and awaiting webhook. The caller sees `null` in all three cases. Case 1 means "stop retrying," case 2 means "do nothing, wait for webhook," case 3 means "in flight." At the scale target, BECS T+2 settlement means every BECS payout re-queues at least once — distinguishing normal re-queues from cancellations is operationally critical.
    - **Plain English:** This function says "done" by returning nothing — but "nothing" means three different things: the payout was cancelled, it's already processing, or Stripe accepted it and is handling it. The code that calls this function can't tell which one happened. At thousands of payouts per day, this makes debugging failures nearly impossible.
    - **Evidence:**
        ```php
        // Path 1: revalidation cancelled all orders
        if ($payout === null) { return null; } // ← "cancelled"

        // Path 2: already processing with PI
        if ($payout->status === 'processing' && $payout->payment_intent_id !== null) {
            return null; // ← "already in flight, no-op"
        }

        // Path 3: PI accepted, awaiting webhook
        return null; // ← "in flight, webhook will resolve"
        ```

- [ ] **#LIFE-9** · P1 — No daily reconcile job for stuck `processing` payouts; missed webhooks strand payouts permanently
    - **Where:** app/Services/Stripe/CommissionPayoutService.php (processPayoutBatch, line 623); absence of a reconcile job across the codebase
    - **Affects:** Any payout whose `payment_intent.succeeded` webhook is not delivered. At ~10K daily payout jobs and Stripe's documented at-least-once (occasionally zero) delivery, even a 0.1% miss rate means ~10 stranded payouts per day that stay in `processing` indefinitely with live PIs.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create `ReconcileStuckProcessingPayoutsJob` (mirror of `ReconcileStuckTransferringPayoutsJob` which already exists) that queries payouts with `status = 'processing'` and `payment_intent_id IS NOT NULL` older than N hours, calls `PaymentIntent::retrieve()` on Stripe, and transitions based on actual PI status.
        - Log every reconciled event so ops can measure webhook loss rate over time.
        - Use the existing idempotent `markPaymentIntentSucceeded` / `markPaymentIntentFailed` methods — they must not re-trigger notifications.
    - **Technical:** `processEligiblePayouts` re-queues processing payouts daily, but `processPayoutBatch` has an explicit no-op guard for `status = 'processing' && payment_intent_id != null`. Re-queuing is harmless but useless — the no-op guard means no transition ever happens without the webhook. `ReconcileStuckTransferringPayoutsJob` already implements the pattern for a different payout stage; this is the identical shape for the `processing` stage.
    - **Plain English:** When Stripe finishes a payment it sends a "success" postcard. Some postcards get lost. The daily job that re-queues stuck payouts says "if it's already being processed, do nothing." We need a separate job that phones Stripe directly to ask "is this payment actually done?" and updates our records. Without it, lost postcards mean stranded money with no recovery.
    - **Evidence:**
        ```php
        // Daily re-queue achieves nothing because of this no-op guard:
        if ($payout->status === 'processing' && $payout->payment_intent_id !== null) {
            return null; // ← re-queue achieves nothing; no ReconcileStuckProcessingPayoutsJob exists
        }
        ```

- [ ] **#LIFE-10** · P1 — No reconcile job for missed Shopify `orders/paid` webhooks; stranded orders never accrue commission
    - **Where:** app/Http/Controllers/Api/Webhooks/Shopify/ShopifyOrderWebhookController.php (webhook-only pipeline); no complementary reconcile job found in `app/Jobs/`
    - **Affects:** Every commerce order. At 1M orders/year (~3K/day), even a 0.1% webhook delivery gap means ~3 orders/day where the affiliate earns no commission and the brand's revenue rollup drifts. Shopify stops retrying after ~48h, so delivery gaps become permanent.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Write a daily `ReconcileStuckOrdersJob` that polls Shopify's REST API for orders in `financial_status = paid` within the last 48 hours that have no corresponding `commerce.order_events` row (keyed by `shopify_event_id`), and processes them through `ProcessShopifyOrderWebhookJob::dispatchSync`.
        - Log every rescued event so ops can measure webhook loss rate over time.
    - **Technical:** The Stripe payout pipeline shipped `ReconcileStuckTransferringPayoutsJob` for exactly this class of dependency on at-least-once webhook delivery. The order pipeline has no equivalent. The `commerce.order_events` table is keyed by `shopify_event_id` for webhook idempotency, making a reconcile query straightforward: fetch Shopify orders by `updated_at` window, cross-reference against `order_events.shopify_event_id`, dispatch the delta.
    - **Plain English:** You asked the post office to notify you every time a package arrives — they promise to try, but not to guarantee. Without a daily "check the shelf" task that asks Shopify "did anything arrive you forgot to tell me about?", some packages sit on the shelf forever, the affiliate never gets paid for them, and nobody notices until the affiliate complains.
    - **Evidence:**
        ```php
        // Entire order pipeline depends on webhook arriving:
        protected function dispatchWebhookJob(
            ProfessionalIntegration $integration,
            array $payload,
            string $eventId,
        ): void {
            ProcessShopifyOrderWebhookJob::dispatch(
                (string) $integration->professional_id,
                $payload,
                $eventId,
            );
        }
        // No ReconcileShopifyOrdersJob exists anywhere in app/Jobs/
        ```

- [ ] **#LIFE-11** · P1 — `AffiliateProductCatalogService::queryAdminCatalog()` bypasses `ShopifyAdminClient`, including its throttle, budget tracking, and retry logic
    - **Where:** app/Services/Store/AffiliateProductCatalogService.php (queryAdminCatalog method)
    - **Affects:** Every affiliate browsing the brand's product catalog. Calls flood Shopify's API without consuming the shared per-shop token budget, risking HTTP 429 errors that spill over to ALL Shopify operations (webhook registration, metafield writes, teardown) for every tenant sharing the same app.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the inline `Http::post` with `$this->client->graphql()` (the injected `ShopifyAdminClient`) so the Redis-backed token bucket, cost estimation, and `THROTTLED` retry apply automatically.
        - Remove the fallback URL construction; `ShopifyAdminClient` handles the endpoint.
    - **Technical:** `queryAdminCatalog` builds its own `Http` request directly against `https://{$shopDomain}/admin/api/{$apiVersion}/graphql.json`, bypassing `ShopifyAdminClient::graphql()` which pre-acquires budget and reconciles throttle state. Sibling methods in the same file already call `$this->client->graphql(...)`. At 200 brands × ~50 affiliates browsing catalogs concurrently, ungoverned requests exhaust the per-shop cost budget and trigger 429s for all other operations on that shop.
    - **Plain English:** Every brand's delivery trucks go through a shared traffic controller so the loading dock never jams. But when an affiliate looks up a product, we send a truck straight to the dock without telling the controller. At a few brands this works; at 200 brands with 50 affiliates each browsing simultaneously, the dock overloads and every other delivery fails.
    - **Evidence:**
        ```php
        // queryAdminCatalog — bypasses ShopifyAdminClient entirely:
        $response = Http::timeout(20)
            ->acceptJson()
            ->withHeaders([
                'X-Shopify-Access-Token' => $accessToken,
            ])
            ->post($url, [
                'query' => $query,
                'variables' => $variables,
            ]);
        // Sibling methods in the same file use: $this->client->graphql(...)
        ```

- [ ] **#LIFE-12** · P1 — `StaffNotificationController::store` creates notification rows without an idempotency key; double-click or network retry sends duplicate broadcast emails to all subscribers
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php:72–80
    - **Affects:** Every professional/affiliate subscribed to staff announcements (~40K daily notification volume). A double-click or network retry creates two identical notification rows and dispatches two `SendStaffBroadcastEmailsJob` — every recipient gets the same announcement email twice.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Accept a client-generated `idempotency_key` in the request body and add a `UNIQUE` index on `(idempotency_key)` with `INSERT … ON CONFLICT DO NOTHING`.
        - Or add a `UNIQUE` constraint on `(professional_id, title, body, created_at::date)` as a server-side dedup.
    - **Technical:** Without a unique constraint backing the write, a retried POST creates a duplicate row and the downstream `SendStaffBroadcastEmailsJob` double-fires — two emails per retry for every subscriber. The `NotificationPublisher`'s per-key dedup only prevents re-publishing the same key, not duplicate row creation upstream of it.
    - **Plain English:** A staff member clicks "Send Announcement" and the browser hangs — they click again. The system creates two identical announcements and sends two emails to every user on the platform. At 40,000 daily notifications, a single accidental double-click spams the entire user base. The fix is to stamp each announcement with a unique receipt number the database can recognise and reject a second time.
    - **Evidence:**
        ```php
        $notification = Notification::query()->create([
            ...$data,
            'category' => $data['category'] ?? null,
            // No idempotency_key — duplicate insert on retry
        ]);

        if ($sendEmail && $notification->professional_id === null) {
            SendStaffBroadcastEmailsJob::dispatch($notification->id, $emailListKey);
        }
        ```

- [ ] **#LIFE-13** · P1 — `CloudflareDnsService::ensureCname` has a TOCTOU race between the existence check and record creation; concurrent brand deployments silently skip DNS provisioning
    - **Where:** app/Services/Cloudflare/CloudflareDnsService.php (ensureCname, upsertCname, upsertTxt)
    - **Affects:** Subdomain provisioning during brand storefront setup. Two concurrent deploys (retry storm, parallel install jobs) both call `ensureCname`, both find `null` from `findRecord`, both `POST` to Cloudflare. The second creation fails (duplicate), returns `null` to the caller, and the caller assumes DNS provisioning failed — when the record actually exists.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap `ensureCname` in a Redis lock scoped to `'cf_dns:{$name}'` so only one caller creates the record at a time, mirroring the single-flight lock in `SquareTokenService::refreshAccessToken`.
        - Treat Cloudflare's `duplicate record` error as success (return the existing record's ID) rather than returning `null`.
    - **Technical:** `findRecord` then `POST` is a classic check-then-act race. Two concurrent calls both call `findRecord`, both see `null`, both `POST`. Cloudflare creates the first CNAME and returns success; the second `POST` either fails with a duplicate error (silently swallowed, returns `null`) or creates a second record. The caller receives `null` and assumes DNS provisioning failed, potentially aborting the deployment. At 200 brands, brand storefront setup is the primary path — every concurrent deploy races.
    - **Plain English:** Setting up a new website address requires creating a DNS entry. If two setup processes run at the same time, both check "does this entry exist?" both see "no," and both try to create it. One succeeds, the other fails silently — and the setup thinks DNS failed even though it actually worked. The fix is a simple lock so only one process tries to create at a time.
    - **Evidence:**
        ```php
        public function ensureCname(string $name, string $target, bool $proxied = true): ?string
        {
            if (! $this->hasCredentials()) { return null; }

            $existing = $this->findRecord('CNAME', $name); // check
            if ($existing !== null) { return $existing['id']; }

            $response = Http::withToken($this->apiToken)
                ->post($this->zonesUrl('/dns_records'), [...]);
            // Race: second caller also passed the check and also posts here
            if (! $response->successful()) {
                Log::error('CloudflareDnsService: failed to create CNAME record.', [...]);
                return null; // Caller assumes DNS failed — but record was created by first caller
            }
        }
        ```

---

## P2 — Should fix

- [ ] **#LIFE-14** · P2 — `staffAnalyticsSummary` cache key does not embed the version token; stale staff dashboards survive `bumpAnalyticsVersion()` if any caller forgets the suffix
    - **Where:** app/Services/Cache/CacheKeyGenerator.php:406–409
    - **Affects:** Staff-facing analytics dashboards — stale summaries survive version bumps if any call site forgets to append `:v{version}`. Every other analytics key embeds the version internally.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Embed the version token inside `staffAnalyticsSummary` itself, mirroring `brandCommerceAnalytics`: read `analyticsSummaryVersion($professionalId)` and interpolate it into the returned string.
        - Remove the version-suffix instruction from the docblock; update call sites to drop the manual suffix.
    - **Technical:** The docblock explicitly says "Append `:v{analyticsSummaryVersion}` at call-site." Every other analytics key (`brandCommerceAnalytics`, `affiliateCommerceAnalytics`) reads `Cache::get(self::analyticsSummaryVersion($professionalId), 0)` internally. `bumpAnalyticsVersion()` is the single invalidation point — but it only works for keys that embed the version. Any call site that forgets the suffix serves stale data for the key's full TTL.
    - **Plain English:** Every door in the building auto-locks when the guard presses a button — except the staff dashboard door, which only locks if whoever used it last remembered to turn the deadbolt. Same button, but the staff door isn't wired in.
    - **Evidence:**
        ```php
        // ✅ brandCommerceAnalytics — version embedded internally
        public static function brandCommerceAnalytics(string $professionalId, string $from, string $to): string
        {
            $version = \Illuminate\Support\Facades\Cache::get(self::analyticsSummaryVersion($professionalId), 0);
            return "analytics:commerce:brand:v7:{$professionalId}:{$version}:{$from}:{$to}";
        }

        // ❌ staffAnalyticsSummary — version left to caller
        public static function staffAnalyticsSummary(string $professionalId, string $from, string $to): string
        {
            return "staff:analytics:summary:{$professionalId}:{$from}:{$to}";
        }
        ```

- [ ] **#LIFE-15** · P2 — `SiteObserver::cascadeAffiliateKvSync` dispatches `SyncSubdomainToKvJob` in a synchronous O(N) loop; blocks HTTP response at scale
    - **Where:** app/Observers/Core/SiteObserver.php (cascadeAffiliateKvSync method)
    - **Affects:** Brand site saves (subdomain change, publish toggle). At 50 affiliates this adds ~50ms of Redis writes; at 500 affiliates the HTTP response is blocked for hundreds of milliseconds.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Extract the loop into a single `CascadeAffiliateKvSyncJob` that chunks `BrandPartnerLink` rows internally (500/chunk), mirroring `InvalidateBrandAffiliatesCacheJob`.
        - Replace the `->each(... dispatch ...)` loop with `CascadeAffiliateKvSyncJob::dispatch($brandProfessionalId)`.
    - **Technical:** `SiteObserver::saved` already dispatches `InvalidateBrandAffiliatesCacheJob::dispatch($professionalId)` for cache invalidation — that job chunks internally. The KV sync path `cascadeAffiliateKvSync` was never updated to match and still does `->each(fn => SyncSubdomainToKvJob::dispatch(...))`. Each dispatch is a Redis LPUSH; at 500 affiliates that's ~500 sequential Redis commands inside a single observer execution.
    - **Plain English:** When a brand changes their website address, the system calls each affiliate individually to update their routing record — one at a time, waiting between each call. The fix is to hand the whole list to one background worker instead of making 500 phone calls on the brand's dime.
    - **Evidence:**
        ```php
        private function cascadeAffiliateKvSync(string $brandProfessionalId): void
        {
            BrandPartnerLink::query()
                ->where('brand_professional_id', $brandProfessionalId)
                ->pluck('affiliate_professional_id')
                ->each(function (string $affiliateId): void {
                    SyncSubdomainToKvJob::dispatch($affiliateId); // O(N) Redis writes in observer
                });
        }

        // Same observer — cache invalidation sibling already uses correct chunked pattern:
        InvalidateBrandAffiliatesCacheJob::dispatch($professionalId);
        ```

- [ ] **#LIFE-16** · P2 — `BrandAffiliateInviteObserver` sends invite emails via `Mail::send()` synchronously; bulk CSV imports block for cumulative SMTP latency
    - **Where:** app/Observers/Core/BrandAffiliateInviteObserver.php (created method)
    - **Affects:** Bulk CSV invite imports. Each `Mail::send()` blocks the observer for the duration of an SMTP round-trip. At 100+ invites in a single CSV upload, the request hangs for seconds.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `Mail::send(new AffiliateInvitedMail(...))` to `Mail::to($recipientEmail)->queue(new AffiliateInvitedMail(...))`. The Mailable already supports queuing; no Mailable-side changes needed.
    - **Technical:** The rest of the codebase already uses the queue pattern — `NotifyHandleAliasExpiry` does `Mail::to($email)->queue(...)`. At 200 brands running seasonal affiliate CSV imports (200 invites at a time), cumulative SMTP latency of ~0.5s/email = 100s request hang.
    - **Plain English:** When a brand uploads a spreadsheet of 100 affiliate invites, the system sends each email before saying "done." At half a second per email, the brand waits 50 seconds staring at a spinner. The rest of the system already hands emails to a delivery queue and moves on — this one spot just forgot.
    - **Evidence:**
        ```php
        // BrandAffiliateInviteObserver.php — synchronous
        Mail::send(new AffiliateInvitedMail(
            recipientEmail: $recipientEmail,
            // ...
        ));

        // Correct pattern already in use elsewhere:
        Mail::to($email)->queue(new HandleAliasExpiringMail($alias, $bucket));
        ```

- [ ] **#LIFE-17** · P2 — `catch (QueryException)` + SQLSTATE string comparison used in three locations instead of typed `UniqueConstraintViolationException`
    - **Where:** app/Services/Professional/Brand/BrandAffiliateInviteService.php:333–342; app/Services/Professional/SiteProvisioningService.php:113–117; app/Services/Stripe/CommissionAdjustmentService.php:82–88
    - **Affects:** Affiliate invite creation, subdomain allocation, and commission adjustments. The SQLSTATE string comparison is fragile across PDO driver changes and less statically analysable; the typed catch is the Laravel 10+ stable API.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace all three `catch (QueryException $e) { if ($e->getCode() === '23505')` guards with `catch (UniqueConstraintViolationException $e)`. Remove any private `isUniqueViolation()` helper methods.
    - **Technical:** Laravel 10+ provides `Illuminate\Database\UniqueConstraintViolationException` as a typed subclass of `QueryException`. The typed catch is version-stable, self-documenting, and can be statically analysed. The commission adjustment path is on the financial write path; every new developer who copies the `getCode() === '23505'` pattern into a higher-stakes context inherits the fragility.
    - **Plain English:** Identifying someone by their exact height in millimetres instead of their name. It works until the measuring tape changes. The typed exception is the name — stable and unambiguous.
    - **Evidence:**
        ```php
        // BrandAffiliateInviteService.php
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23505') { throw $exception; }
        }

        // SiteProvisioningService.php
        private function isUniqueViolation(QueryException $e): bool {
            return $e->getCode() === '23505';
        }

        // CommissionAdjustmentService.php
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw new DuplicateAdjustmentException($reference, previous: $e);
            }
            throw $e;
        }
        ```

- [ ] **#LIFE-18** · P2 — Swallowed `ApiErrorException` in Stripe payment-method detachment; orphaned payment methods accumulate silently on Stripe
    - **Where:** app/Services/Stripe/StripeConnectService.php:702–706, 719–722, 737–739
    - **Affects:** Brand payment-method management. When a brand removes a saved card or BECS mandate, Stripe-side detachment failures are invisible — the brand sees "card removed" but Stripe still has the PM attached.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Log at `Log::warning` with `professional_id`, `payment_method_id`, and the Stripe error message before continuing. (`warning` not `error` — the local state is cleaned up regardless.)
    - **Technical:** `removeBrandPaymentMethod()` catches `ApiErrorException` with an empty block. A Stripe `paymentMethods->detach()` failure (network blip, rate limit, Stripe outage) is silently swallowed. The orphaned PM on Stripe is invisible to ops. The canonical verbatim vendor error capture pattern requires logging vendor errors so Nightwatch can surface them.
    - **Plain English:** When a brand removes their saved card, we tell Stripe to delete it. If Stripe is having a bad day and refuses, the code shrugs and continues — the card is removed from our database but stays on Stripe's side. Nobody knows. The fix is to log the failure so the ops team can see it.
    - **Evidence:**
        ```php
        try {
            $this->stripe->paymentMethods->detach($becsId);
        } catch (ApiErrorException) {
            // Completely empty — vendor failure is invisible
        }
        ```

- [ ] **#LIFE-19** · P2 — `CommissionVoidService::sendPerPayoutWarnings` relies solely on notification-pipeline dedup; duplicate warnings fire if pipeline cache is evicted or rebuilt
    - **Where:** app/Services/Stripe/CommissionVoidService.php:622–663 (sendPerPayoutWarnings)
    - **Affects:** Affiliates approaching payout void deadlines. If the cron overlaps, is manually triggered, or the notification pipeline's dedup cache is evicted, duplicate "10 days left" / "2 days left" warnings fire.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `payout_warnings_sent` JSONB column on `commerce.commission_payouts` with shape `{day10: timestamp, day2: timestamp}`. Before publishing, check the JSONB; after publishing, set the timestamp. This dedup survives pipeline rebuilds.
    - **Technical:** The dedup mechanism is `dedupeKey: "stripe_warning.payout.{$key}.{$payout->id}"` inside `NotificationPublisher`. That mechanism works today but its durability is tied to the notification system's retention config. The canonical pattern stores sent timestamps on the payout row itself — a single read already in the chunk loop — making dedup durable and independent of any external system.
    - **Plain English:** The system avoids sending the same "10 days left" warning twice by relying on a separate reminder system's built-in memory. If that memory gets cleared (config change, migration), the warnings go out again. Writing "sent" directly on the payout record makes the payout itself the permanent record of what was sent.
    - **Evidence:**
        ```php
        $this->publisher->publish(
            professionalId: $payout->affiliate_professional_id,
            // ...
            dedupeKey: "stripe_warning.payout.{$key}.{$payout->id}",
            // Dedup lives in notification pipeline — not on the payout row
        );
        ```

- [ ] **#LIFE-20** · P2 — `BrandStatusService::isStorefrontReachable()` makes a synchronous 5s HTTP call on cache miss; vendor latency propagates to dashboard response p99
    - **Where:** app/Services/Professional/Brand/BrandStatusService.php:280–303
    - **Affects:** Brand dashboard and onboarding flow. `sync()` calls `isStorefrontReachable()` after every mutation that could change brand status — Shopify OAuth callback, store settings save. A cold cache adds up to 5s to these user-facing responses.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Dispatch the reachability check to a queued job that updates a `storefront_reachable` boolean on the brand profile. `determine()` reads the cached boolean instead of making an HTTP call.
        - Keep the short-TTL cache (60s reachable, 15s unreachable) as the job's write path rather than the request's.
    - **Technical:** The 60s/15s cache mitigates repeated checks, but the first check after a cache miss blocks the calling request. At 200 brands onboarding and updating settings, this cache-miss penalty is hit regularly. The same principle that keeps Stripe calls out of DB transactions (commit `59655e8d`) applies to keeping outbound HTTP calls out of synchronous user-facing request paths.
    - **Plain English:** When the system checks whether a brand's storefront is live, it sends an HTTP request while the brand waits for the dashboard to load. The first check is cached for 60 seconds, but if the cache is empty, the brand waits up to 5 seconds. Moving the check to a background job and reading a stored result instead eliminates this wait.
    - **Evidence:**
        ```php
        $response = Http::withOptions([
            'allow_redirects' => false,
            'timeout' => 5,
            'connect_timeout' => 3,
        ])->get($url);

        $reachable = $response->successful();
        Cache::put($cacheKey, $reachable, $reachable ? 60 : 15);
        ```

- [ ] **#LIFE-21** · P2 — `ShopifyDataResyncService::resync()` has a read-modify-write race on `provider_metadata`; concurrent resyncs silently lose each other's timestamps
    - **Where:** app/Services/Shopify/ShopifyDataResyncService.php (resync method, inside `DB::transaction`)
    - **Affects:** Brand settings merged into `provider_metadata` (webhook_ids, storefront_token, last_resynced_at). Two near-simultaneous resyncs both read the same metadata, each merge their own key, and the second save overwrites the first merge.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Call `$integration->lockForUpdate()` inside the transaction before reading `provider_metadata`. Re-read the metadata after locking to avoid a stale snapshot.
    - **Technical:** `mergeProviderMetadata` loads the current JSONB column, merges in the new key, and saves — a classic read-modify-write. Without `lockForUpdate`, two concurrent transactions can both read the same metadata, each merge their own timestamp, and the second save completely overwrites the first's merge (lost update). The canonical `lockForUpdate + UNIQUE` pattern serialises the two merges at the database level.
    - **Plain English:** Two people editing the same spreadsheet cell at the same time. Each grabs the current value, adds their note, and saves. The last save wins and the first person's note disappears. A lock on the cell makes the second person wait and see the updated value.
    - **Evidence:**
        ```php
        $diff = DB::connection('pgsql')->transaction(function () use ($integration, ...) {
            $diff = $this->autoFill->resyncFromShopData($integration, $shopData);
            // Race window: another resync can read metadata here
            $integration->mergeProviderMetadata([
                'last_resynced_at' => $lastResyncedAt,
            ]);
        });
        ```

- [ ] **#LIFE-22** · P2 — `BrandDesignImporter::fetchActiveThemeSettings()` swallows all `\Throwable` exceptions with no logging; theme-fetch failures are invisible
    - **Where:** app/Services/Shopify/BrandDesignImporter.php (fetchActiveThemeSettings, two catch blocks)
    - **Affects:** Onboarding brands where the Shopify Admin GraphQL or Asset API is temporarily unavailable — the brand imports successfully but receives no theme settings, and nobody knows why.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Log each caught exception at `Log::warning` with context: `['shop_domain' => $shopDomain, 'step' => 'themesQuery|assetFetch', 'exception' => $e]` including `professional_id` for Nightwatch correlation.
    - **Technical:** Both `catch (\Throwable)` blocks return `['_theme_name' => null, 'current' => []]` without a single `Log::` call. A transient Shopify outage or permission error silently degrades the brand design import. The `// NOTE: Map these fields based on actual Fresha API response structure` comments in the same service indicate this path already has known unknowns — silent failure compounds them.
    - **Plain English:** A team member who quietly drops a broken part in the bin without telling anyone. The production line keeps moving, but the final product has a weird wobble and nobody can trace it back to the dropped piece because there's no note.
    - **Evidence:**
        ```php
        try {
            $themesResponse = $this->client->graphql(...);
        } catch (\Throwable) {
            return ['_theme_name' => null, 'current' => []]; // No log, no alert
        }
        ```

- [ ] **#LIFE-23** · P2 — `ShopifyBulkOperationLock` TTL of 3600s strands a shop for an hour after a worker crash
    - **Where:** app/Services/Shopify/Client/ShopifyBulkOperationLock.php (acquire method)
    - **Affects:** Every bulk operation (metafield backfill, product sync) per shop. A single worker crash before `release()` blocks all subsequent bulk work for that shop for up to 3600s — far longer than `waitForBulkOperation`'s 600s maximum.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Lower the default `bulk_lock_ttl_seconds` config to 610s (600s max operation time + 10s margin) so the lock auto-expires shortly after the operation would have finished.
        - Or implement a heartbeat extension inside `waitForBulkOperation` that bumps the key TTL while the operation is still running.
    - **Technical:** The lock is acquired with `Redis::set(key, '1', 'EX', 3600, 'NX')`. The happy path always releases after `waitForBulkOperation` (at most 600s). A worker crash before release leaves the key for the full 3600s. Setting the TTL to `max_operation_time + small_margin` ensures the lock auto-cleans even without an explicit release.
    - **Plain English:** A building master key gets accidentally locked inside the only room it opens. The room is cleaned within 10 minutes, but the key timer says it's lost for an hour. The fix is to tell the timer "if nobody has come back after 10 minutes, the key is free anyway."
    - **Evidence:**
        ```php
        public function acquire(string $shopDomain, ?int $ttlSeconds = null): bool
        {
            $ttl = $ttlSeconds ?? (int) config(
                'services.shopify.throttle.bulk_lock_ttl_seconds', 3600); // ← 6× longer than max operation
            $result = Redis::set($this->key($shopDomain), '1', 'EX', $ttl, 'NX');
        }
        ```

- [ ] **#LIFE-24** · P2 — `AffiliateProductCatalogService::seedDefaultSelections()` has a TOCTOU race; concurrent seeding creates duplicate product selections
    - **Where:** app/Services/Store/AffiliateProductCatalogService.php (seedDefaultSelections method)
    - **Affects:** Affiliates whose brand connection triggers seeding from two sources simultaneously (connection job + manual UI action). Both calls observe the same missing GIDs and both call `create`, producing duplicate rows.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `in_array` + `create` loop with an upsert (`INSERT … ON CONFLICT DO NOTHING`) to make the operation idempotent.
        - Or add a `UNIQUE` constraint on `(affiliate_professional_id, brand_professional_id, shopify_product_gid)`.
    - **Technical:** `seedDefaultSelections` fetches all existing GIDs, then iterates defaults and creates any not in the list. Without a lock or unique constraint, two concurrent calls both observe the same missing GIDs, both exit the `in_array` guard, and both call `create`. The canonical `lockForUpdate + UNIQUE` pattern would either serialise the calls with a row lock or let the database reject the duplicate via a `UNIQUE` constraint.
    - **Plain English:** Two club bouncers both check the guest list at the same time. Both see Alice isn't listed, so both add her. Now the list has two Alices. The fix is to have one bouncer hold the list while the other waits, or to tell the paper "if Alice is already there, don't write her again."
    - **Evidence:**
        ```php
        $existingGids = AffiliateProductSelection::query()
            ->where('affiliate_professional_id', $affiliate->id)
            ->where('brand_professional_id', $brandProfessionalId)
            ->pluck('shopify_product_gid')->all();

        foreach ($defaultGids as $gid) {
            if (in_array($gid, $existingGids, true)) { continue; } // TOCTOU window
            AffiliateProductSelection::create([...]);
        }
        ```

- [ ] **#LIFE-25** · P2 — Kick and Twitch API clients return `[]` on auth failure; silent failure shows every streamer as offline with no Nightwatch alert
    - **Where:** app/Services/Streaming/KickApiClient.php (getLiveHandles); app/Services/Streaming/TwitchApiClient.php (getLiveHandles)
    - **Affects:** Live-status badges on affiliate sitepages. A revoked or expired OAuth token silently shows every streamer as offline — `Log::critical` writes a breadcrumb but does not trigger a Nightwatch alert.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Throw a typed `StreamingAuthException` instead of returning `[]`. Let the poller catch it and `report($e)` so Nightwatch surfaces the alert.
        - An empty `[]` currently means both "no one is live" and "auth is broken" — the caller cannot distinguish them.
    - **Technical:** `getLiveHandles` returns `[]` when `$this->tokens->getToken('kick|twitch')` is null and logs `Log::critical`. The caller writes `isLive = false` for every handle in the batch — the public profile shows every streamer as offline. Nightwatch alerts on exceptions, not log queries. A typed throw triggers `report()` → Nightwatch.
    - **Plain English:** When Kick's or Twitch's login token expires, instead of alerting us something's broken, the system tells everyone "all your streamers are offline." Every fan sees empty live indicators. The fix is to throw a specific error so Nightwatch pages us, instead of pretending nothing's wrong.
    - **Evidence:**
        ```php
        // KickApiClient.php
        $token = $this->tokens->getToken('kick');
        if (! $token) {
            Log::critical('streaming.auth_failure', ['platform' => 'kick']);
            return []; // Indistinguishable from "nobody is live"
        }

        // TwitchApiClient.php — identical pattern
        $token = $this->tokens->getToken('twitch');
        if (! $token) {
            Log::critical('streaming.auth_failure', ['platform' => 'twitch']);
            return [];
        }
        ```

- [ ] **#LIFE-26** · P2 — `CloudflareDnsService` returns `null` on API failure; callers cannot distinguish "dev mode, no credentials" from "Cloudflare is down"
    - **Where:** app/Services/Cloudflare/CloudflareDnsService.php (ensureCname, upsertCname, upsertTxt — all return `?string`)
    - **Affects:** Hydrogen storefront subdomain provisioning. A Cloudflare API 5xx during brand deploy returns `null` with only a `Log::error` breadcrumb — the deploy continues without DNS and the storefront is unreachable with no Nightwatch alert.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Throw a typed `CloudflareDnsException` on non-404 API failures. Only return `null` for `! $this->hasCredentials()` (the legitimate dev-mode path).
        - The calling job should catch the exception and retry with backoff or fail the deployment with a clear error.
    - **Technical:** Every public method returns `null` on failure, and every caller treats `null` as "DNS provisioning didn't happen — skip it." The `hasCredentials()` guard is the only legitimate null path. Cloudflare 5xx, network timeout, and permission errors all return `null` with only a `Log::error` breadcrumb — no Nightwatch alert. At 200 brands, a Cloudflare outage during a deploy wave silently leaves storefront subdomains unresolvable.
    - **Plain English:** When DNS setup fails (Cloudflare is down, network error), the code quietly returns "nothing" and the deployment continues as if it worked. The brand's new storefront is live but nobody can reach it. The code treats "we're in dev mode with no credentials" the same as "Cloudflare is on fire" — they should trigger very different responses.
    - **Evidence:**
        ```php
        if (! $response->successful()) {
            Log::error('CloudflareDnsService: failed to create CNAME record.', [
                'name' => $name, 'target' => $target,
                'status' => $response->status(), 'body' => $response->body(),
            ]);
            return null; // Indistinguishable from hasCredentials() == false
        }
        ```

- [ ] **#LIFE-27** · P2 — `Supabase email hook has no idempotency; hook retries on transient failure send duplicate auth emails
    - **Where:** app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php:59–71
    - **Affects:** Every user who triggers a Supabase auth email (sign-up confirmation, password reset, magic link, invite). Supabase retries the hook on transient failures, so each retry delivers a second copy of the same email.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `Cache::add` dedup gate keyed on the hook's `webhook-id` header (or `token_hash` + `action_type`) before calling `Mail::send()`, so a repeated delivery returns 200 immediately without re-sending. Mirror the Shopify webhook dedup pattern.
    - **Technical:** The controller validates the signature in middleware but performs no deduplication. Supabase's hook system retries on non-2xx responses, and may also retry on network-side timeouts even if the first `Mail::send` succeeded — making it effectively at-least-once delivery. At 200 brands with active sign-up flows, a Resend SMTP blip triggers Supabase retries that double every auth email.
    - **Plain English:** The postman delivers the same letter twice because the doorbell was broken on the first attempt. Without a "letter already received" checklist, the household opens the second envelope and gets confused by the duplicate. The fix is to stamp the letter "delivered" the moment it arrives so the postman moves on.
    - **Evidence:**
        ```php
        // No dedup gate — Mail::send runs on every authenticated request:
        try {
            $mailable = $this->resolveMailable($actionType, $recipientEmail, $displayName, $verifyUrl, $token);
            Mail::send($mailable);
            return response()->json(['ok' => true, 'handled' => true]);
        }
        ```

- [ ] **#LIFE-28** · P2 — `custom_photos_enabled` cache invalidation misses the `:stale` twin; stale read-through serves old permission for up to the stale TTL
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php:173–174
    - **Affects:** Hydrogen storefronts consuming the brand-product custom-photos permission. After a brand flips the toggle, the stale cache twin lives on for its full extended TTL (~50 min default) and is returned by `rememberLocked`'s SWR path.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Cache::forget($key . ':stale');` alongside the primary-key forget for `brandProductCustomPhotos`, matching the pattern used elsewhere in the same controller for `embeddedProductSettings`.
    - **Technical:** `CacheLockService::rememberLocked` writes a `:stale` clone with a 10× TTL for SWR. The write path busts the primary key but not the stale clone, so the stale clone is returned until it expires naturally. The established bust-both-halves pattern (`f5450d8`) is applied to `embeddedProductSettings` in the same controller but was missed for `custom_photos_enabled`.
    - **Plain English:** You tell the warehouse to throw away the old inventory sheet. The warehouse throws away yesterday's sheet but keeps a backup from two weeks ago, and starts handing that out instead. The fix is to throw away the backup at the same time.
    - **Evidence:**
        ```php
        if ($field === 'custom_photos_enabled') {
            Cache::forget(CacheKeyGenerator::brandProductCustomPhotos($professionalId, $productGid));
            // Missing: Cache::forget(CacheKeyGenerator::brandProductCustomPhotos($professionalId, $productGid) . ':stale');
        }
        ```

- [ ] **#LIFE-29** · P2 — GDPR job status guards are not atomic; concurrent dispatches can double-process redaction and export requests
    - **Where:** app/Jobs/Shopify/Gdpr/RedactCustomerJob.php; app/Jobs/Shopify/Gdpr/RedactShopJob.php; app/Jobs/Shopify/Gdpr/ExportCustomerDataJob.php (handle methods)
    - **Affects:** Shopify GDPR compliance jobs. Two concurrent dispatches (Horizon scale-out, retry overlapping original) both pass the status guard and both proceed into the cleanup pipeline.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the status guard + `STATUS_PROCESSING` update in a `DB::transaction` with `lockForUpdate` on the `GdprRequest` row in all three jobs, matching the canonical `lockForUpdate + UNIQUE` pattern.
    - **Technical:** All three jobs share the same read-modify-write pattern: `in_array($gdpr->status, [STATUS_COMPLETED, STATUS_SKIPPED])` check → `$gdpr->update(['status' => STATUS_PROCESSING])`, with no database lock between them. Two workers can both read `status = 'received'`, both pass the guard, and both begin processing. Impact varies: `RedactCustomerJob` idempotency is partially protected by `whereNull('redacted_at')` on customer queries, but the professional-level cleanup paths (email_subscriptions delete, booking_events scrub) execute unconditionally.
    - **Plain English:** Three different compliance jobs share the same "is this already being handled?" check, but none of them hold the clipboard while they check. Two workers can grab the clipboard at the same moment, both see "not handled," and both start work. The fix is a simple database lock: only one worker can read the status and claim the job at a time.
    - **Evidence:**
        ```php
        // All three jobs — identical unguarded pattern:
        if (in_array($gdpr->status, [GdprRequest::STATUS_COMPLETED, GdprRequest::STATUS_SKIPPED], true)) {
            return;
        }
        $gdpr->update(['status' => GdprRequest::STATUS_PROCESSING]);
        // No lockForUpdate — second worker can race past the guard
        ```

- [ ] **#LIFE-30** · P2 — `CheckStreamingLiveStatusJob::failed()` missing `report($e)`; permanent streaming poll failures are invisible to Nightwatch
    - **Where:** app/Jobs/Streaming/CheckStreamingLiveStatusJob.php (failed method)
    - **Affects:** Streaming live-status polling (Twitch/Kick). This job runs every 2 minutes; `tries=1` means `failed()` fires immediately on any exception. A single transient error kills the polling cycle silently.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e);` before `Log::error(...)` in `failed()`, matching `ExecuteCommissionPayoutJob::failed()` which already uses this pattern.
    - **Technical:** Nightwatch alerts on exceptions, not log queries. `Log::error` without `report($e)` writes a breadcrumb to cloud logs but does not trigger an alert. With `tries=1`, a permanently-failing poll (Twitch API key expired) kills the live-status feature for all streaming handles on the platform silently.
    - **Plain English:** When the job that checks whether streamers are live fails completely, it writes a note in the logbook but doesn't flip the warning switch on the operations dashboard. If there's a persistent problem (like an API key expiring), nobody finds out until viewers complain about stale "offline" badges. The fix is to also flip the switch.
    - **Evidence:**
        ```php
        public function failed(\Throwable $e): void
        {
            Log::error('streaming.job_failed', ['message' => $e->getMessage()]);
            // Missing: report($e); — Nightwatch never alerts on this failure
        }
        ```

- [ ] **#LIFE-31** · P2 — Staff feature-flag controllers repeat `abort_if($request->attributes->get('partna_staff') === null, 401)` inline in every method instead of using middleware
    - **Where:** app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagController.php:23,32,42,52; app/Http/Controllers/Api/Staff/FeatureFlag/StaffFeatureFlagOverrideController.php:21,37,82
    - **Affects:** Staff feature-flag endpoints. A new method added without the inline check is silently open — the actor resolves to `null` and downstream code type-errors or silently bypasses authorization.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract the check into a `staff.auth` middleware or add it to the existing `staff.admin` middleware group. Apply it once in `routes/api/staff.php` via a route group.
    - **Technical:** The same gate repeated in every method with no central enforcement. A missing check on a new controller method means the endpoint accepts requests without a staff actor. The canonical pattern is middleware for route-level enforcement.
    - **Plain English:** Every staff-only door has its own keypad with the same code. If someone adds a new door and forgets to install the keypad, the door is unlocked. The fix is one lock on the hallway entrance instead of one on every door.
    - **Evidence:**
        ```php
        // StaffFeatureFlagController — repeated 4 times:
        abort_if($request->attributes->get('partna_staff') === null, 401, 'Unauthenticated');

        // StaffFeatureFlagOverrideController — repeated 3 times:
        abort_if($request->attributes->get('partna_staff') === null, 401, 'Unauthenticated');
        ```

---

## P3 — Nice to have

- [ ] **#LIFE-32** · P3 — `SectionVisibilityService::reevaluateEnabled()` has a read-modify-write race; a concurrent item deletion leaves a section marked visible for one page load
    - **Where:** app/Services/Professional/SectionVisibilityService.php:307–324
    - **Affects:** Storefront section-block visibility. The race window is a single page load and self-corrects on the next block save. Cosmetic impact only.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either wrap the read + check + write in a `lockForUpdate` transaction, or accept eventual consistency and add a comment noting the intentional lack of lock.
    - **Technical:** Classic read-modify-write: load block → EXISTS queries → save. Between EXISTS and save, another transaction can delete the last gallery image, making `is_enabled = true` stale. Self-corrects on any subsequent block write. Blast radius: one section renders empty for a single request.
    - **Plain English:** The system checks "does this section have content?" then marks it visible. Between checking and saving, the last piece of content could be deleted — the section stays marked visible for one page load, then corrects itself the next time anything writes to it.
    - **Evidence:**
        ```php
        $block = Block::query()->where(...)->first();           // read
        [$canBeEnabled] = $this->checkVisibilityRequirements(...); // fresh EXISTS queries
        if ((bool) $block->is_enabled !== $canBeEnabled) {
            $block->is_enabled = $canBeEnabled;
            $block->save();                                    // write, no lock
        }
        ```

- [ ] **#LIFE-33** · P3 — `ProfessionalLinkBlockController::authorizeCustomLinks()` uses inline config-backed `abort_unless` instead of a Policy ability
    - **Where:** app/Http/Controllers/Api/Professional/SiteManagement/ProfessionalLinkBlockController.php (authorizeCustomLinks method)
    - **Affects:** Custom link creation for non-brand accounts. Functional but inconsistent with the project's authorization doctrine — `Gate::before` hooks and policy-level tests can't reach inline `abort_unless`.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `createCustomLink` ability to `BlockPolicy` that checks `config("partna.account_type_defaults.{$type}.custom_links_allowed")`, and call `$this->authorizeForUser($pro, 'createCustomLink', $site)`.
    - **Evidence:**
        ```php
        private function authorizeCustomLinks(Professional $pro): void
        {
            $type = $pro->account_type?->value ?? mb_strtolower(trim((string) ($pro->professional_type ?? '')));
            abort_unless(
                (bool) config("partna.account_type_defaults.{$type}.custom_links_allowed", false),
                403,
                'Custom links are not available on your account type.'
            );
        }
        ```

- [ ] **#LIFE-34** · P3 — `ProfessionalUploadController` uses `'pro_id'` log key instead of canonical `'professional_id'`; breaks Nightwatch correlation
    - **Where:** app/Http/Controllers/Api/Professional/Uploads/ProfessionalUploadController.php:81–87
    - **Affects:** Nightwatch log correlation — a professional's upload logs cannot be joined with their Stripe/webhook/notification logs when the key name differs.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Rename `'pro_id'` to `'professional_id'` in all log calls within this controller. Sweep `app/` for other `'pro_id'` log keys and canonicalize.
    - **Evidence:**
        ```php
        Log::info('Media upload started', [
            'pro_id' => $pro->id, // ← should be 'professional_id'
            'site_id' => $site->id,
            'pool' => $pool,
            'media_type' => $mediaType,
        ]);
        ```

- [ ] **#LIFE-35** · P3 — `AffiliateOrdersController::parseStatusFilter` uses inline `abort_unless` for query parameter validation instead of a Form Request
    - **Where:** app/Http/Controllers/Api/Professional/Affiliate/AffiliateOrdersController.php:123–127
    - **Affects:** API contract consistency. Invalid `?status=` values get a raw `abort_unless` 422 without the project's structured error envelope.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract into a `ListAffiliateOrdersRequest` Form Request with a `rules()` method.
    - **Evidence:**
        ```php
        private function parseStatusFilter(Request $request): ?string
        {
            $status = $request->query('status');
            if ($status === null || $status === '') { return null; }
            abort_unless(in_array($status, ['pending', 'processing', 'paid', 'reversed'], true), 422, 'Invalid status filter.');
            return (string) $status;
        }
        ```

- [ ] **#LIFE-36** · P3 — `StaffInviteController::assertBrandWithFunding` is an inline gate called before 4 methods; a new write endpoint that omits the call bypasses the funding check
    - **Where:** app/Http/Controllers/Api/Staff/ProfessionalSiteManagement/StaffInviteController.php:228–249
    - **Affects:** 4 staff invite write endpoints (store, bulk, importCsv, resend). If a 5th is added without calling `assertBrandWithFunding`, staff could send invites for a brand without a payment method.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Extract into a route middleware (`staff.invite.brand_with_funding`) applied to the route group for staff invite writes.
    - **Evidence:**
        ```php
        // Called at the top of store(), bulk(), importCsv(), resend():
        if ($error = $this->assertBrandWithFunding($professional)) {
            return $error;
        }
        ```

- [ ] **#LIFE-37** · P3 — `BrandAffiliateInviteController` has 6 methods with redundant `if (! $professional->isBrand())` checks alongside `brand.only` middleware
    - **Where:** app/Http/Controllers/Api/Professional/Brand/BrandAffiliateInviteController.php (index, store, availability, bulk, importCsv, destroy)
    - **Affects:** Authorization surface audibility. Middleware + inline checks create dual enforcement that makes it unclear which is load-bearing — a developer adding a new method may trust only the middleware.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Verify `brand.only` middleware is applied on these routes, remove the inline checks, and add a test that 403s non-brand requests via the middleware path alone.
    - **Evidence:**
        ```php
        public function index(Request $request): JsonResponse
        {
            $professional = $this->currentProfessional($request);
            if (! $professional->isBrand()) {
                return $this->error('Only brand accounts can view affiliate invites.', 403);
            }
        }
        ```

- [ ] **#LIFE-38** · P3 — `StaffNotificationController::store` maps `$data['type']` through `severityForFrontendType()` which can return `null`; no DB `CHECK` constraint prevents null severity from persisting
    - **Where:** app/Http/Controllers/Api/Staff/StaffSite/StaffNotificationController.php:50–51
    - **Affects:** Notification severity filtering — a null severity slips through to the database, making notification-severity filtering unreliable.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `CHECK (severity IN ('info', 'warning', 'critical'))` constraint on the `notifications.severity` column, or coerce `null` to `'info'` in the controller before `create`.
    - **Evidence:**
        ```php
        $data['type'] = Notification::normalizeFrontendType($data['type'] ?? null, $data['severity'] ?? null);
        $data['severity'] = Notification::severityForFrontendType($data['type']); // can return null

        $notification = Notification::query()->create([
            ...$data, // null severity inserts without rejection
        ]);
        ```

- [ ] **#LIFE-39** · P3 — `CloudflareDnsService` logs full Cloudflare API response bodies; unbounded log payload size at scale
    - **Where:** app/Services/Cloudflare/CloudflareDnsService.php (all error log calls)
    - **Affects:** Nightwatch log ingestion volume. Every failed DNS call logs the full Cloudflare response body (can be verbose HTML or JSON error pages).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Truncate the logged body to the first 500 characters: `'body' => mb_substr($response->body(), 0, 500)`.
    - **Evidence:**
        ```php
        Log::error('CloudflareDnsService: failed to create CNAME record.', [
            'name' => $name,
            'target' => $target,
            'status' => $response->status(),
            'body' => $response->body(), // unbounded — can be kilobytes of HTML
        ]);
        ```

`★ Insight ─────────────────────────────────────`
Three structural patterns account for the majority of findings across this audit:

1. **Commit-before-dispatch ordering** (LIFE-1, LIFE-2, LIFE-6): The safest default is "dispatch, then commit the receipt." Committing the dedup/audit row first means queue failures silence retries permanently. In all three cases, reversing the order (or wrapping in a try/catch that tears down the committed row) closes the gap with a one-line change.

2. **Null-conflation on multi-outcome functions** (LIFE-8, LIFE-25, LIFE-26): `null` as a return type is a false economy — it collapses distinct outcomes into one signal, making callers unable to log, retry, or alert accurately. The codebase already has the fix pattern (typed result enums/DTOs in the Stripe payout work); it just wasn't applied consistently to newer code paths.

3. **Reconcile-job gaps** (LIFE-9, LIFE-10): Every state transition that depends on an external webhook (Stripe, Shopify) needs a sibling reconcile job. The Stripe payout pipeline already has `ReconcileStuckTransferringPayoutsJob` as the template — the pattern exists, it just needs to be applied to the order pipeline and the processing-payout stage.
`─────────────────────────────────────────────────`
