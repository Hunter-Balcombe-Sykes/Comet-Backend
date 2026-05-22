- [ ] **#LIFE-1** · P0 — GDPR webhook dedup row created before job dispatch; transient queue failure silences retries permanently
    - **Where:** app/Http/Controllers/Api/Webhooks/Shopify/ShopifyGdprWebhookController.php:126-155
    - **Affects:** All Shopify GDPR compliance requests (customers_data_request, customers_redact, shop_redact) — missed data export or deletion in response to a legitimate GDPR subject access request becomes a regulatory non-compliance.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Either wrap the job dispatch in a try/catch and delete the `GdprRequest` row on failure (mirroring the `runHandlerWithFailureCleanup` pattern from `ValidatesStripeWebhookPayload`), OR
        - Move the `firstOrCreate` below the successful dispatch so the dedup row is only committed after the job is queued.
    - **Technical:** The `wasRecentlyCreated` guard on the `firstOrCreate` call is consumed immediately. If `ExportCustomerDataJob::dispatch()` throws (e.g., Redis down), the controller returns a 500, Shopify retries, but the `GdprRequest` row already exists with `payload_hash`, so `wasRecentlyCreated` is `false` and the job is never re-dispatched. The identical pattern was fixed in the Stripe webhook trait (`STRP-C` / `35c6f31`) by deleting the dedup row inside a catch so the retry can re-process.
    - **Plain English:** Imagine a compliance officer stamps a receipt “received” the moment a letter arrives, then tries to hand it to the processing team. If the hand-off fails (the team’s door is locked), the receipt is already stamped — when the letter is re-delivered later, the clerk sees the stamp and tosses the letter, so the request is never actually fulfilled. The fix is to stamp the receipt *after* the hand-off succeeds, or tear it up if the hand-off fails.
    - **Evidence:**
        ```php
        $audit = GdprRequest::firstOrCreate(
            ['payload_hash' => $hash],
            [
                'topic' => $topic,
                'shop_domain' => $shopDomain,
                'shopify_shop_id' => is_numeric($payload['shop_id'] ?? null) ? (int) $payload['shop_id'] : null,
                'payload' => $payload,
                'status' => GdprRequest::STATUS_RECEIVED,
                'received_at' => now(),
            ],
        );

        if ($audit->wasRecentlyCreated) {
            match ($topic) {
                GdprRequest::TOPIC_CUSTOMERS_DATA_REQUEST => ExportCustomerDataJob::dispatch($audit->id),
                GdprRequest::TOPIC_CUSTOMERS_REDACT => RedactCustomerJob::dispatch($audit->id),
                GdprRequest::TOPIC_SHOP_REDACT => RedactShopJob::dispatch($audit->id),
            };
            // …
        }

        return $this->success(['received' => true], 202);
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#LIFE-2** · P1 — Square payment idempotency key is a fresh UUID per request; retries cause duplicate charges
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicBookingController.php:273-275
    - **Affects:** Affiliates who take paid bookings via Square — a network hiccup during checkout (client retry, proxy timeout) can result in the customer’s card being charged twice for the same appointment.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Derive the idempotency key from a deterministic value that is stable for the same booking attempt — e.g. `sha1("booking:{$bookingId}:{$version}")` or a UUID generated once when the booking page mounts and passed through the client — instead of `(string) Str::uuid()` called afresh on every checkout request.
    - **Technical:** Square uses the idempotency key to dedup identical create-payment calls. A new random UUID on every request means each retry looks like a brand-new payment to Square, so if the first payment succeeded but the HTTP response was lost, the client’s retry opens a second charge. The canonical pattern is a deterministic idempotency key derived from the business-operation identity, not a per‑request nonce. This is the same shape as the Stripe Transfer idempotency key fix in the payout pipeline.
    - **Plain English:** Every time you press “Pay”, you generate a random receipt number. If the internet drops after the payment goes through but before you see the confirmation, and you press “Pay” again, it looks like a completely new purchase to the bank — you get charged twice. The fix is to give the payment a predictable label (like the appointment ID), so the bank knows “this is the same attempt, ignore the repeat.”
    - **Evidence:**
        ```php
        $paymentResponse = $this->squareApiClient->request($professional, 'POST', '/v2/payments', [], [
            'idempotency_key' => (string) Str::uuid(),
            'source_id' => (string) $validated['sourceId'],
            'amount_money' => [
                'amount' => $priceCents,
                'currency' => $currencyCode !== '' ? $currencyCode : 'AUD',
            ],
            // …
        ]);
        ```
    - `[DRAFT, confidence: 1.0]`

- [ ] **#LIFE-3** · P1 — No reconcile job for missed Shopify orders/paid webhooks; stuck orders will never finalize
    - **Where:** app/Http/Controllers/Api/Webhooks/Shopify/ShopifyOrderWebhookController.php (webhook-only pipeline; no sibling reconcile job found across the codebase)
    - **Affects:** Every commerce order — at 1M orders/year (~3K/day), even a 0.1% webhook delivery gap means ~3 orders/day fail to accrue commission, leaving affiliates underpaid and brands with growing revenue drift.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Write a daily `ReconcileStuckOrdersJob` that polls Shopify for orders that are `paid` but don’t have a corresponding `order_events` row, and processes them through the same job as the webhook handler.
        - Ensure the reconcile path logs every rescued event so operations can measure webhook loss rate over time.
    - **Technical:** Shopify webhooks are at-least-once delivery, but not guaranteed delivery — re-delivery attempts stop after ~48 hours. The Stripe payout pipeline shipped `ReconcileStuckTransferringPayoutsJob` (`0de1f2f`) exactly for this family of dependency. The current orders pipeline depends solely on the webhook; there is no backstop for the gap between a Shopify-side state change and our local copy of it.
    - **Plain English:** You asked the post office to notify you every time a package arrives, but they only promise to *try* — sometimes the notification gets lost. Without a backup plan, some packages sit on your shelf forever. The fix is a daily “check the shelf” worker that asks the post office, “Did anything arrive that you forgot to tell me about?”
    - **Evidence:**
        ```php
        // ShopifyOrderWebhookController relies entirely on the webhook arriving:
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
        // No complementary ReconcileShopifyOrdersJob exists in the repository.
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#LIFE-4** · P2 — `custom_photos_enabled` cache invalidation misses the `:stale` twin
    - **Where:** app/Http/Controllers/Api/Internal/EmbeddedProductSettingsController.php:127-129
    - **Affects:** Hydrogen storefronts that consume the brand-product custom-photos permission — after a brand flips the toggle, the stale window (up to ~50 minutes) still serves the old value, so the affiliate sees stale permission until the stale twin expires naturally.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Cache::forget($key.':stale');` alongside the primary-key forget for `brandProductCustomPhotos`, matching the pattern used elsewhere in the same controller for `embeddedProductSettings`.
    - **Technical:** `CacheLockService::rememberLocked` writes a `:stale` clone with a 10× TTL so that during a brief primary miss the stale copy can be served (SWR). When the write path manually busts the primary but forgets the stale twin, the stale clone lives on and will be returned by the `rememberLocked` method until it expires. The established pattern (`f5450d8`) requires busting both halves on the write path; this controller does it for the settings key but not for `custom_photos_enabled`.
    - **Plain English:** You tell the warehouse “the new inventory sheet is ready — throw away the old copy.” The warehouse throws away yesterday’s sheet, but keeps a backup from two weeks ago, and starts handing that out to anyone who asks. The fix is to throw away the backup at the same time you throw away the main copy.
    - **Evidence:**
        ```php
        if ($field === 'custom_photos_enabled') {
            Cache::forget(CacheKeyGenerator::brandProductCustomPhotos($professionalId, $productGid));
            // Missing: Cache::forget(CacheKeyGenerator::brandProductCustomPhotos($professionalId, $productGid) . ':stale');
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#LIFE-5** · P2 — Concurrent booking webhook/replay can double-write analytics events due to read-then-write on `analytics.booking_events`
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicBookingController.php:512-530
    - **Affects:** Booking analytics dashboards — if the same booking event is re-announced (webhook replay, Square retry) concurrently with the checkout response, duplicate analytics rows can appear, inflating booking counts.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `UNIQUE` constraint on `square_booking_id` (or `(professional_id, square_booking_id)`) so the database serialises the insert, and catch the `UniqueConstraintViolationException` to handle idempotency cleanly.
    - **Technical:** The method reads `existingEventId` from the table without any lock, then either updates or inserts with a freshly minted UUID. If two threads race, both can find no `existingEventId` and both attempt to insert; without a unique index, both succeed and produce duplicate rows. The canonical race-safe idempotency pattern requires a `UNIQUE` constraint on the business key and a typed catch (`UniqueConstraintViolationException`). The Stripe payout pipeline used this for commission movements.
    - **Plain English:** Two cashiers both check “is this customer already in the system?” at the same moment, both see “no,” and both create a new entry. Suddenly the customer appears twice. The fix is to put a “no two customers can have the same passport number” rule in the database, so the second cashier gets an immediate “already exists” nudge.
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
        // …
        if ($existingEventId) {
            DB::table('analytics.booking_events')
                ->where('id', $eventId)
                ->update($attributes);
        } else {
            DB::table('analytics.booking_events')
                ->insert(array_merge($attributes, ['id' => $eventId, 'created_at' => now()]));
        }
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#LIFE-6** · P2 — Supabase email hook lacks idempotency; retries send duplicate auth emails
    - **Where:** app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php:36-44
    - **Affects:** Every user who triggers a Supabase auth email (sign-up confirmation, password reset, magic link, invite). Supabase retries the hook on transient failures, so each retry can deliver a second copy of the same email.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `Cache::add` dedup gate keyed on the hook’s `webhook-id` header (mirroring the Shopify webhook pattern) so that a repeated delivery is acknowledged with 200 but does not re‑send the mail.
    - **Technical:** The controller validates the signature (in middleware), but performs no deduplication before calling `Mail::send()`. Supabase’s hook system retries on non-2xx responses, but also may retry on network‑side timeouts even if the first send succeeded — making it effectively an at‑least‑once delivery. The canonical webhook dedup pattern (used in all Shopify controllers) places an atomic `Cache::add` before any side‑effect so the second identical delivery returns 200 immediately without repeating the side‑effect.
    - **Plain English:** A postman delivers the same letter twice because the first time the doorbell was broken. Without a “letter already received” checklist by the door, the household opens the second envelope too — the recipient gets two copies of the same message. The fix is to stamp the letter as “delivered” the moment it arrives, so the postman sees the stamp next time and moves on.
    - **Evidence:**
        ```php
        // No dedup guard – Mail::send runs on every authenticated request:
        try {
            $mailable = $this->resolveMailable($actionType, $recipientEmail, $displayName, $verifyUrl, $token);
            // …
            Mail::send($mailable);
            return response()->json(['ok' => true, 'handled' => true]);
        }
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#LIFE-7** · P2 — Synchronous Square API calls in the booking checkout path add multi‑second latency to a user‑facing endpoint
    - **Where:** app/Http/Controllers/Api/PublicSite/PublicBookingController.php:checkout() (creates customer, booking, then payment inline)
    - **Affects:** End‑users completing a booking — at peak, multiple synchronous round‑trips to Square’s API (~200–800 ms each) block the HTTP worker, increasing p99 latency and risking request timeouts.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Decouple the non‑reversible writes: create the Square booking synchronously (needed for payment), but offload payment to a queue job so the user’s browser isn’t held open waiting for the `/v2/payments` call to complete. Return a 202 with a status‑poll URL or optimistic UX while the payment processes.
    - **Technical:** The canonical pattern shipped in the Stripe payout pipeline is Master Pattern 16: vendor I/O that can be deferred must run in a queue job. The payment call itself depends on the booking ID, but that ID is available synchronously — the payment can be dispatched after the booking is created, and the client can poll or receive a push notification. This is especially important under load from hundreds of affiliates, where a single slow Square response (1‑2 s) will climb the p99 and begin to time out.
    - **Plain English:** You walk into a store, pick an item, and the cashier says “give me your credit card, I need to go to the bank vault downtown to run it.” While they’re gone, you and everyone behind you waits. The fix is to take your card details, start the transaction, and let you leave — the bank will call you when it’s done.
    - **Evidence:**
        ```php
        // All three Square calls happen synchronously in the request:
        $customerResponse = $this->squareApiClient->request($professional, 'POST', '/v2/customers', [], $customerPayload);
        $bookingResponse  = $this->squareApiClient->request($professional, 'POST', '/v2/bookings', …);
        $paymentResponse  = $this->squareApiClient->request($professional, 'POST', '/v2/payments', …);
        ```
    - `[DRAFT, confidence: 0.9]`
