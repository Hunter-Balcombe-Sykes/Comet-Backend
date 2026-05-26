- [ ] **#CACHE-1** · P2 — `analytics.booking_events` table is UPDATEd on retry, overwriting original event data and losing audit trail
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicBookingController.php:1158-1210
    - **Affects:** Booking analytics/audit — cannot reconstruct the lifecycle of a booking (accepted → completed → cancelled) because each delivery overwrites `status`, `raw_payload`, and related fields on the single row.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Split into append-only `booking_events` (immutable, one row per delivery) and a separate mutable projection `booking_state` keyed by `square_booking_id` for "current status."
        - Keep the table's `UNIQUE` constraint on `(professional_id, square_booking_id)` on the projection, not the event log, so retries produce additional event rows instead of mutating the original.
    - **Technical:** The `recordBookingAnalyticsAndNotify` method resolves an existing `eventId` via `square_booking_id`, then either inserts or updates a single row in `analytics.booking_events`. The update branch overwrites `raw_payload` (the full validated checkout payload + resolved service shape), `status`, `amount_paid_cents`, and `updated_at`. A booking that transitions from `accepted` → `completed` on a later Square webhook loses the `accepted` snapshot. At pre-beta volumes (single-digit bookings/day/professional) this is harmless; at the 30×50×100 target with retries and status-change webhooks, it destroys the ability to reconcile Square-side lifecycle against local records. Canonical replacement: append-only event log + a mutable projection for "current status."
    - **Plain English:** Think of this like a receipt printer that, instead of printing a new receipt when an order status changes, finds the original receipt and overwrites it with a Sharpie. You lose the history — you can't see that it was "accepted" before it was "completed." For a handful of bookings this doesn't matter; at scale, it makes it impossible to audit what happened when.
    - **Evidence:**
        ```php
        $existingEventId = null;
        if ($bookingId !== '') {
            $existingEventId = DB::table('analytics.booking_events')
                ->where('professional_id', $professionalId)
                ->where('square_booking_id', $bookingId)
                ->value('id');
        }

        $eventId = is_string($existingEventId) && trim($existingEventId) !== ''
            ? trim($existingEventId)
            : (string) Str::uuid();

        // ... builds $attributes including 'raw_payload', 'status', 'amount_paid_cents' ...

        if ($existingEventId) {
            DB::table('analytics.booking_events')
                ->where('id', $eventId)
                ->update($attributes);  // overwrites original state
        } else {
            DB::table('analytics.booking_events')
                ->insert(array_merge($attributes, ['id' => $eventId, 'created_at' => now()]));
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#CACHE-2** · P3 — `Cache::has` + `Cache::put` TOCTOU race in `PublicShopifyStorefrontController` where `Cache::add` would be atomic
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicShopifyStorefrontController.php:111-117
    - **Affects:** Storefront token creation dedup — under concurrent requests for a brand whose `storefront_token` is empty, two `CreateStorefrontAccessTokenJob` instances may be dispatched instead of one.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `if (! Cache::has(...))` guard + `Cache::put(...)` with a single `if (Cache::add($jobKey, true, 600))` call.
        - Keep the `Log::info` inside the `if` block — `Cache::add` returning `true` means this is the first claimant.
    - **Technical:** The `storefrontConfig` method checks `Cache::has($jobKey)` (a read), and if false, dispatches the job then writes `Cache::put($jobKey, true, 600)`. Between the read and write, a second concurrent request can also see `has() === false` and also dispatch. The rest of the codebase uses `Cache::add($key, true, TTL)` — Redis `SETNX` — which returns `false` when the key already exists, making the check-and-set atomic. `CreateStorefrontAccessTokenJob` likely has `ShouldBeUnique`, so the double-dispatch is deduplicated at the queue, but the extra Redis write + log noise is avoidable. Impact is negligible at pre-beta (brands rarely hit this concurrently); at the 30-brand target it's still noise-level.
    - **Plain English:** This is like two receptionists both checking a shared calendar, seeing an empty slot, and both booking it — then the system later notices the double-booking and cancels one. We have a tool (`Cache::add`) that locks the slot at the moment of booking so only one receptionist can claim it. The fix is swapping two lines for one that does the check-and-claim in a single step.
    - **Evidence:**
        ```php
        // Storefront token missing — dispatch creation job (with dedup)
        if ($storefrontToken === '') {
            $jobKey = 'storefront-token-job:'.$integration->id;
            if (! Cache::has($jobKey)) {
                Log::info('Storefront token missing, dispatching creation job.', [
                    'integration_id' => (string) $integration->id,
                ]);
                CreateStorefrontAccessTokenJob::dispatch((string) $integration->id);
                Cache::put($jobKey, true, 600);
            }

            return response()->json([
                'status' => 'pending',
                'message' => 'Storefront token is being created. Try again in a few seconds.',
            ], 202);
        }
        ```
    - `[DRAFT, confidence: 0.95]`
