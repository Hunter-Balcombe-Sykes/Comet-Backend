- [ ] **#CACHE-1** · P3 — SquareServiceSyncService performs per-row Eloquent queries inside a single DB transaction during service sync
    - **Where:** app/Services/Square/SquareServiceSyncService.php:applySquareSnapshot (lines ~174–265)
    - **Affects:** Professionals with Square integration during catalog sync; sync latency grows linearly with service count.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Replace the per-row `Service::query()->where(...)->first()` / `::create()` loop with a single batch upsert (`DB::table('core.services')->upsert(...)`) keyed on `(professional_id, square_variation_id)`.
        - Bulk-fetch all potentially-matching services before the loop with one `whereIn('square_variation_id', $variationIds)` query, then index by variation_id in PHP.
        - Batch the full-sync missing-service deletion into a single `UPDATE ... SET deleted_at = NOW() WHERE professional_id = ? AND square_variation_id NOT IN (...)`.
    - **Technical:** The current `applySquareSnapshot` opens a `DB::transaction()` then runs one `SELECT` + one `INSERT` or `UPDATE` per Square row inside a `foreach`, plus a second pass for full-sync missing-service cleanup. For a catalog of 100 variations this is ~200 individual queries holding a transaction open. The canonical replacement is a single `upsert()` call on the `services` table—Postgres `ON CONFLICT` handles insert-or-update atomically, and a bulk soft-delete `UPDATE` avoids the per-row save+delete loop. At the target scale of 30 brands × ~50 services each this is still sub-second, so the tier is P3, but the pattern is the same shape as the old commerce rebuild antipattern (per-row processing under a transaction) at a smaller magnitude.
    - **Plain English:** When Square sends a catalog update, the sync service opens one long database transaction and then asks a hundred separate questions, one per service—"do you exist? no? create. yes? update." It's like checking a hundred guests into a hotel by walking to each room individually instead of handing the front desk a single list. At the current scale this is fine, but as the number of services per professional grows, the sync gets proportionally slower because every service means two more round-trips to the database.
    - **Evidence:**
        ```php
        DB::transaction(function () use (
            $professional, $squareRows, &$syncedVariationIds, &$syncedCount, &$deletedCount
        ): void {
            // ... existingCategories query ...
            Service::withoutEvents(function () use (..., $squareRows, ...): void {
                foreach ($squareRows as $row) {
                    // ...
                    $service = Service::query()
                        ->withTrashed()
                        ->where('professional_id', $professional->id)
                        ->where('square_variation_id', $variationId)
                        ->first();

                    if (! $service) {
                        $service = Service::query()->create([...]);
                    } else {
                        // ...
                        $service->save();
                    }
                    // ...
                }
            });
        });
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#CACHE-2** · P3 — FreshaServiceSyncService performs per-row Eloquent queries inside a single DB transaction during service sync
    - **Where:** app/Services/Fresha/FreshaServiceSyncService.php:syncFromFresha (lines ~76–165)
    - **Affects:** Professionals with Fresha integration during catalog sync; same per-row query amplification as the Square path.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Apply the same batch-upsert pattern recommended for SquareServiceSyncService: pre-fetch all existing services by `fresha_variation_id`, index in PHP, then issue one `upsert()` call with all incoming rows.
        - Wrap the per-service deletion loop in `Service::withoutEvents()` (matching Square's pattern) to suppress observer-triggered side effects when soft-deleting many services.
        - Add `deleted_origin` tracking (mirroring Square's `deleted_origin = 'square'`) so a manually-deleted service isn't resurrected by a subsequent Fresha delta sync.
    - **Technical:** `FreshaServiceSyncService::syncFromFresha()` runs the same `DB::transaction()` + per-row `Service::query()->where(...)->first()` / `::create()` pattern as Square, producing one SELECT + one write per Fresha service row. Additionally, the deletion branch calls `$service->save(); $service->delete()` without `Service::withoutEvents()`, so any registered Service model observers (cache invalidation, push-to-provider jobs) fire for each individually-deleted row. The canonical replacement is a single `upsert()` over the service rows plus a bulk `UPDATE` for deletions, with `withoutEvents()` guarding the batch. The divergence from Square's `deleted_origin` tracking also means a professional who manually deletes a service in Partna may see it reappear after the next Fresha sync.
    - **Plain English:** This is the same per-row database pattern as the Square sync, but for Fresha-connected professionals. It also has a sharper edge: when Fresha says "these services were deleted," the code deletes them one at a time and triggers every automated side effect (cache clears, push jobs) for each one individually. It's like ringing a fire alarm separately for every room in a building instead of pulling it once. At current scale it's harmless noise, but as the number of integrated professionals grows it creates unnecessary work for the job queue.
    - **Evidence:**
        ```php
        DB::transaction(function () use ($professional, $rows, &$syncedCount, &$deletedCount) {
            foreach ($rows as $row) {
                // ...
                $service = Service::query()
                    ->withTrashed()
                    ->where('professional_id', $professional->id)
                    ->where('fresha_variation_id', $variationId)
                    ->first();

                if (! $service) {
                    $service = Service::query()->create([...]);
                } else {
                    // ...
                    Service::withoutEvents(function () use (...) {
                        Service::query()->withTrashed()->where('id', $service->id)->update([...]);
                    });
                }
                // ...
            }
        });
        // Deletion loop — no withoutEvents() guard:
        foreach ($toDelete as $service) {
            $service->is_active = false;
            $service->save();
            $service->delete();
            $deletedCount++;
        }
        ```
    - `[DRAFT, confidence: 0.7]`

- [ ] **#CACHE-3** · P3 — Entitlements service uses per-request in-memory cache only; no shared cache across requests
    - **Where:** app/Services/Billing/Entitlements.php:22-24 (cache property), 26-37 (currentSubscription method)
    - **Affects:** Every API request, job, and route guard that checks entitlements—each re-queries the subscription for the same professional independently.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Wrap `currentSubscription()` in a `CacheLockService::rememberLocked` call with a short TTL (e.g., 30s + jitter), keyed by professional ID.
        - Push-invalidate the cache key from `ChangeProfessionalPlanAction` and the Stripe webhook handler when a subscription's plan or status changes.
        - Consider a version-token pattern (`entitlements_version:{professional_id}`) incremented on plan change so stale caches self-heal without explicit invalidation from every write path.
    - **Technical:** `Entitlements` memoizes `currentSubscription()` in a per-request `array` property (`$this->cache`). This eliminates N+1 within a single request but provides zero sharing across requests. Every inbound API call, Horizon job, and middleware guard that calls `hasPlan()` or `hasEntitlement()` runs a fresh `Subscription::query()->with('plan')->where(...)->first()` for the same professional. Subscriptions change infrequently (on plan upgrade/downgrade or billing period roll), so a shared cache with push-invalidation would eliminate >99% of these queries. At the target scale of 30 brands × 50 affiliates each (1,500 professionals), dashboard page loads that check multiple entitlements per render would hit the DB for the same row across every request, though the query itself is a single-row UUID lookup and not a scaling bottleneck at this size. The `rememberLocked` + push-invalidate pattern is already proven in `NotificationListingService` and `CommerceNotificationService`.
    - **Plain English:** Every time the app checks "can this user access feature X?", it walks to the database and asks, even if the same question was just answered for the same person two seconds ago. It's like a bouncer who checks your ID every time you walk through the door, even if you're just stepping out and back in. The fix is a short-term memory (a 30-second cache) shared across all the bouncers, with a rule that says "if their plan changes, forget what you memorized." The database query is fast—a single row lookup—so this isn't urgent, but eliminating it entirely is cheap and the pattern is already in use elsewhere in the codebase.
    - **Evidence:**
        ```php
        /** @var array<string, Subscription|null> Per-request cache keyed by professional ID */
        private array $cache = [];

        public function currentSubscription(Professional $professional): ?Subscription
        {
            $key = $professional->id;

            if (! array_key_exists($key, $this->cache)) {
                $this->cache[$key] = Subscription::query()
                    ->with('plan')
                    ->where('professional_id', $professional->id)
                    ->whereNull('ended_at')
                    ->latest('created_at')
                    ->first();
            }

            return $this->cache[$key];
        }
        ```
    - `[DRAFT, confidence: 0.6]`
