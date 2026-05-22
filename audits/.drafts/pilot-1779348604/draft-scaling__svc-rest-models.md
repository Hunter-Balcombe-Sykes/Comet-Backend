- [ ] **#CACHE-1** · P2 — FreshaServiceSyncService per-row write amplification during sync
    - **Where:** app/Services/Fresha/FreshaServiceSyncService.php:80-195
    - **Affects:** Database load during Fresha catalog syncs; one sync event produces N × ~3–4 individual Eloquent queries inside a single DB transaction.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Pre-load existing Service and ServiceCategory rows into in-memory maps before entering the foreach loop, matching the Square service's `$activeCategoryIdByKey` pattern.
        - Collect upsert payloads in an array and issue a single bulk upsert (or `insertOrIgnore` + targeted updates) after the loop, rather than individual `save()` / `create()` / `update()` per row.
        - Move the `Service::query()->max('sort_order')` call outside the loop — compute the base once and increment locally.
    - **Technical:** The `DB::transaction(function () use (...) { foreach ($rows as $row) { ... } })` block issues separate `Service::query()->max('sort_order')`, `ServiceCategory::query()->where()->first()`, `Service::query()->withTrashed()->where()->first()`, and `Service::query()->create()` calls for every row. For 50+ services this is 200+ round-trips inside a single transaction — the sort_order `max()` alone is a full aggregate scan per row. The Square sibling service pre-computes the base sort order once and uses `$nextGlobalSortOrder++` inside the loop, avoiding this entirely; Fresha should adopt the same approach.
    - **Plain English:** Imagine checking every item on a shelf one at a time, walking to the back of the store to ask the stock manager "what's the highest shelf number?" before placing each item, then writing it on a paper form — all inside one locked room that nobody else can enter until you're done. For 50 items that's 50 trips to the back and 50 locked-room sessions. The Square integration already does this efficiently by asking once upfront; Fresha needs the same treatment.
    - **Evidence:**
        ```php
        // app/Services/Fresha/FreshaServiceSyncService.php:~130-145
        if (! $service) {
            $maxSort = Service::query()
                ->where('professional_id', $professional->id)
                ->max('sort_order') ?? -1;

            $service = Service::query()->create([
                'professional_id' => $professional->id,
                // ...
                'sort_order' => $maxSort + 1,
                // ...
            ]);
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CACHE-2** · P2 — SquareServiceSyncService per-row write amplification during sync
    - **Where:** app/Services/Square/SquareServiceSyncService.php:215-370
    - **Affects:** Database load during Square catalog syncs; same per-row query pattern as Fresha although the sort-order computation is already amortised.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Pre-load existing Services keyed by `square_variation_id` into an in-memory map before the loop, eliminating the per-row `where('square_variation_id', $variationId)->first()` query.
        - Batch the `save()` and `restore()` calls — collect updated models in an array and `saveMany` after the loop, or issue a single multi-row upsert.
    - **Technical:** The `applySquareSnapshot()` method correctly computes `$nextGlobalSortOrder` once before the loop and uses `Service::withoutEvents()` for the entire block, so the worst N+1 aggregate is already fixed. However, each row still issues a separate `Service::query()->where('square_variation_id', ...)->first()` lookup and an individual `save()` / `create()`. For a full sync of 100+ service variations this is 100+ SELECTs and 100+ INSERT/UPDATEs inside a single `DB::transaction`. A pre-loaded map keyed by variation_id would collapse the SELECT count to 1.
    - **Plain English:** The Square sync already asks the stock manager once upfront for the highest shelf number (good), but still walks to every shelf individually, checks the label, and fills out a separate form for each item while the room stays locked. Consolidating into one bulk form-filling session would unlock the room much faster for everyone else.
    - **Evidence:**
        ```php
        // app/Services/Square/SquareServiceSyncService.php:~280-335
        Service::withoutEvents(function () use (
            $professional, $squareRows, &$syncedVariationIds, ...
        ): void {
            foreach ($squareRows as $row) {
                // ...
                $service = Service::query()
                    ->withTrashed()
                    ->where('professional_id', $professional->id)
                    ->where('square_variation_id', $variationId)
                    ->first();
                // ... individual save() or create() per row
            }
        });
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CACHE-3** · P3 — FreshaServiceSyncService sort_order max() issued per row (N+1 aggregate scan)
    - **Where:** app/Services/Fresha/FreshaServiceSyncService.php:133-135, 158-161
    - **Affects:** Fresha catalog sync duration; each row triggers a full `MAX(sort_order)` aggregate on the `site.services` table.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Move the `Service::query()->max('sort_order')` call above the foreach loop and store it as `$nextSort`, then increment per created row (`$nextSort++`) — exactly the pattern `SquareServiceSyncService::applySquareSnapshot()` already uses.
    - **Technical:** `Service::query()->where('professional_id', ...)->max('sort_order')` inside the loop body is a full aggregate query executed N times. The Square sibling pre-computes `$nextGlobalSortOrder` once and uses a local increment, so this Fresha regression is a straightforward copy-paste fix. At 50 services this saves 49 redundant aggregate scans; at 500 services the difference is a second or more of lock-held transaction time.
    - **Plain English:** Each time a new service is added during a sync, the code asks the database "what's the highest shelf number across the whole store?" instead of remembering the answer from the last time it asked three milliseconds ago. The Square integration already remembers — Fresha just needs the same one-line fix.
    - **Evidence:**
        ```php
        // app/Services/Fresha/FreshaServiceSyncService.php:~133-135 (create path)
        $maxSort = Service::query()
            ->where('professional_id', $professional->id)
            ->max('sort_order') ?? -1;
        $service = Service::query()->create([...'sort_order' => $maxSort + 1, ...]);
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#CACHE-4** · P2 — FreshaServiceSyncService creates fire Eloquent events while Square counterpart suppresses them
    - **Where:** app/Services/Fresha/FreshaServiceSyncService.php:130-148 vs app/Services/Square/SquareServiceSyncService.php:244-335
    - **Affects:** Potential observer cascade — if ServiceObserver dispatches a `pushServiceToFresha` job on `created`, each new service row triggers an outbound Fresha API call inside the DB transaction.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Wrap the entire Fresha create path in `Service::withoutEvents()` to match the Square implementation, OR ensure the observer is designed to no-op when `fresha_last_synced_at` is set during the same request cycle.
        - Add a sync-in-progress flag on the ProfessionalIntegration to short-circuit any downstream push job dispatched by the observer during a pull sync.
    - **Technical:** The Square `applySquareSnapshot()` wraps its entire `foreach` in `Service::withoutEvents()`, so no observer fires during a catalog pull. The Fresha equivalent only suppresses events on the update path (`Service::withoutEvents(function () { ...->update(...) })`) but calls `Service::query()->create()` and `$service->restore()` outside that guard. Both `create` and `restore` fire Eloquent model events. If `ServiceObserver` dispatches a `pushServiceToFresha` job (or calls it synchronously), this creates a bidirectional sync loop: pull creates a Service → observer pushes to Fresha → Fresha responds → another event fires. At minimum it wastes an HTTP round-trip per new service; at worst it creates an infinite ping-pong.
    - **Plain English:** When Fresha tells Partna "here are 10 new services," Partna creates them and immediately taps Fresha on the shoulder saying "hey, I just created a service, let me tell you about it." Fresha already knows — it's the one who sent the list. The Square integration politely suppresses these taps during a sync; Fresha forgot to.
    - **Evidence:**
        ```php
        // Fresha — create fires events:
        $service = Service::query()->create([...]);  // line ~140

        // Fresha — restore also fires events:
        if ($service->trashed()) {
            $service->restore();  // line ~155
        }

        // Square — entire block suppresses events:
        Service::withoutEvents(function () use (...) {
            foreach ($squareRows as $row) {
                // ... create, save, restore all inside withoutEvents guard
            }
        });
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#CACHE-5** · P3 — CommerceNotificationService synchronous fan-out to brand professionals per booking
    - **Where:** app/Services/Notifications/CommerceNotificationService.php:108-128
    - **Affects:** Booking webhook handler latency; each brand partner gets a synchronous `publisher->publish()` call that does a DB `insertOrIgnore` (plus an optional email job dispatch) inline.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Collect all publish intents for the booking event into an array and call `$this->publisher->publishMany()` once, or dispatch a lightweight fan-out job that publishes to all recipients asynchronously.
        - At minimum, move the milestone check (`notifyBookingMilestonesForProfessional`) outside the per-brand loop — it's already at the end and runs once, but the brand loop adds N synchronous DB writes per booking.
    - **Technical:** The foreach over `$brandProfessionalIds` calls `$this->publisher->publish()` for each brand, which does a capability-gate DB lookup (`Professional::find()`) plus a `DB::table()->insertOrIgnore()` per recipient. At pre-beta scale (1 brand per affiliate) this is 2 publishes per booking; at the target scale of 30 brands this stays low. The fan-out shape is still a synchronous per-recipient DB write loop inside what could become a webhook handler — if booking volume grows faster than brand count, this pattern would amplify. The recommended replacement is either `publishMany()` (already exists on NotificationPublisher) or an async job.
    - **Plain English:** For every booking, the system writes the notification to the professional's inbox, then walks over to each brand partner's mailbox and slips in a copy one at a time, checking ID at each door. For one brand this is two quick stops; for fifty it's a noticeable walk. The `publishMany` mail-cart already exists — we're just not using it here.
    - **Evidence:**
        ```php
        // app/Services/Notifications/CommerceNotificationService.php:~108-128
        foreach ($brandProfessionalIds as $brandProfessionalId) {
            $brandBody = $amountPaidCents > 0
                ? "{$affiliateName} received a booking for {$serviceLabel} ({$amountLabel})."
                : "{$affiliateName} received a booking for {$serviceLabel}.";

            $this->publisher->publish(
                professionalId: $brandProfessionalId,
                frontendType: 'Info',
                category: 'analytics_milestones',
                title: 'New partner booking',
                body: $brandBody,
                dedupeKey: 'booking:brand:'.$brandProfessionalId.':'.$eventKey,
                ctaUrl: '/account/commerce?section=analytics&booking='.$eventKey,
                retentionConfigKey: 'analytics_milestones',
            );
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#CACHE-6** · P3 — FreshaServiceSyncService push-to-Fresha makes up to 3 synchronous HTTP calls per service save
    - **Where:** app/Services/Fresha/FreshaServiceSyncService.php:230-280
    - **Affects:** Response latency when a professional saves a service with Fresha smart-sync enabled; the request thread blocks on up to 3 sequential Fresha API calls (create/update, retrieve-on-conflict, retry).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Offload the push to a queued job (`PushServiceToFreshaJob`) so the professional's save request returns immediately. The job can retry with backoff.
        - Alternatively, adopt the same `withoutEvents` + async-dispatch pattern the commerce rebuild uses for webhook handlers — commit local state first, reconcile external state asynchronously.
    - **Technical:** The push path calls `createService` / `updateService`, catches 400/409, calls `retrieveService`, then calls `updateService` again. All three HTTP calls are synchronous and block the response to the professional's browser. Square's counterpart (`pushServiceToSquare`) has the same shape but Square's API latency is typically lower. At the target scale (30 brands × occasional service edits) this isn't a throughput bottleneck, but every service save during a Fresha outage or slowdown will hang the professional's dashboard for 30+ seconds (30s HTTP timeout × up to 3 calls). A queued job eliminates the request-thread blocking entirely.
    - **Plain English:** When a professional edits a service and clicks save, the system sometimes needs to phone Fresha three times in a row to get the latest catalog version before making the change. While it's on the phone, the professional stares at a spinner. If Fresha's line is busy, that spinner could spin for 30 seconds. A better approach is to say "got it, we'll call them in a moment" and let the professional get back to work while the system handles the phone calls in the background.
    - **Evidence:**
        ```php
        // app/Services/Fresha/FreshaServiceSyncService.php:~250-275
        try {
            $upserted = $this->freshaApiClient->updateService($professional, $serviceId, $serviceData);
        } catch (FreshaApiException $e) {
            if ($e->status !== 400 && $e->status !== 409) {
                throw $e;
            }
            $latest = $this->freshaApiClient->retrieveService($professional, $serviceId);
            if (! $latest) { throw $e; }
            if (isset($latest['version'])) {
                $serviceData['version'] = (int) $latest['version'];
            }
            $upserted = $this->freshaApiClient->updateService($professional, $serviceId, $serviceData);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CACHE-7** · P2 — SquareServiceSyncService push-to-Square makes up to 3 synchronous HTTP calls per service save
    - **Where:** app/Services/Square/SquareServiceSyncService.php:115-175
    - **Affects:** Same as CACHE-6 but for Square-connected professionals; response latency on service save when Square smart-sync is enabled.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Offload the push to `PushServiceToSquareJob` so the save endpoint returns immediately. The job can retry the retrieve-then-upsert dance with exponential backoff.
    - **Technical:** Identical antipattern shape as Fresha — `upsertCatalogObject` → catch 400/409 → `retrieveCatalogObject` → `upsertCatalogObject` again, all synchronous on the request thread. Square's API is typically faster than Fresha's but the same 30s HTTP timeout applies. At 30 brands × occasional service edits this is low risk today; the fix is the same one-line dispatch swap to a queued job.
    - **Plain English:** Same three-phone-call problem as Fresha, just to a different number. The fix is the same — hang up and let a background worker redial.
    - **Evidence:**
        ```php
        // app/Services/Square/SquareServiceSyncService.php:~130-155
        try {
            $upserted = $this->squareApiClient->upsertCatalogObject($professional, $catalogObject);
        } catch (SquareApiException $e) {
            if ($e->status !== 400 && $e->status !== 409) {
                throw $e;
            }
            $latest = $this->squareApiClient->retrieveCatalogObject($professional, $itemId);
            if (! $latest) { throw $e; }
            $catalogObject['version'] = isset($latest['version']) ? (int) $latest['version'] : $catalogObject['version'] ?? null;
            // ...
            $upserted = $this->squareApiClient->upsertCatalogObject($professional, $catalogObject);
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CACHE-8** · P3 — FreshaApiClient and SquareApiClient pagination loops hold request threads for unbounded durations
    - **Where:** app/Services/Fresha/FreshaApiClient.php:67-99, app/Services/Square/SquareApiClient.php:43-135
    - **Affects:** Catalog sync job duration; both clients use `do { ... } while ($cursor !== null)` with no upper bound on iterations.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a hard iteration cap (e.g. 50 pages) with a logged warning if exceeded — a professional with 5,000+ catalog items would otherwise paginate 50+ times per sync.
        - Consider adding a `page_limit` parameter to `fetchAppointmentServiceVariations` / `fetchServices` so callers can cap the sync depth per run.
    - **Technical:** Both API clients implement cursor-based pagination with unbounded `do...while` loops. At the target scale (a typical Square/Fresha business has 10–100 catalog items, yielding 1–2 pages) this is harmless. A large enterprise with 10,000+ catalog items would paginate 50–100 times per sync, holding the calling job (and its DB transaction in the sync service) open for the cumulative latency of all API calls plus processing. A hard cap with observability (log + Nightwatch alert) prevents a pathological large catalog from silently degrading the sync worker.
    - **Plain English:** The sync process keeps turning pages of a catalog until it reaches the end, no matter how thick the book is. For a 20-page catalog this is fine; for a 500-page catalog it ties up a worker for minutes. A bookmark cap says "we'll read up to 50 pages this visit and pick up the rest next time."
    - **Evidence:**
        ```php
        // app/Services/Square/SquareApiClient.php:~43-135
        do {
            // ... API call ...
            $cursor = is_string($data['cursor'] ?? null) ? $data['cursor'] : null;
        } while ($cursor !== null);

        // app/Services/Fresha/FreshaApiClient.php:~67-99
        do {
            // ... API call ...
            $cursor = $data['cursor'] ?? $data['meta']['cursor'] ?? null;
        } while ($cursor);
        ```
    - `[DRAFT, confidence: 0.75]`
