- [ ] **#WHK-1** · P0 — Square catalog webhook silently returns 200 when both dispatch AND inline sync fail, permanently losing catalog change events
    - **Where:** `app/Http/Controllers/Api/Webhooks/SquareCatalogWebhookController.php:85-93`
    - **Affects:** Any Square merchant whose catalog changes (service added, renamed, deleted). When the queue is unreachable AND the inline sync also fails, the change is silently dropped — Square sees 200 and never retries. The brand's booking page shows stale services indefinitely.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Return 500 (not 200) when the inline sync fallback fails, so Square retries the delivery.
        - Optionally add an `alertable` log level (e.g. `Log::error`) for Nightwatch to surface the double-failure immediately.
    - **Technical:** The catch block for `SyncSquareCatalogDeltaJob::dispatch()` attempts an inline sync via `syncFromSquare()`. When that inline sync also throws, the code logs a warning and returns `$this->success(...)`. Per the vendor contract, Square stops retrying on any 2xx. The catalog change event is permanently lost — the only recovery is a full manual re-sync of the merchant's catalog. The gold standard (cat 3) requires that dispatch failure returns 500 so the vendor retries; returning 200 on a double-failure is a direct violation.
    - **Plain English:** Think of this like a package delivery. The delivery truck (queue) is broken, so the driver tries to walk the package over by hand (inline sync). If they trip and drop it in a river, the current code smiles at Square and says "got it, thanks!" — so Square never sends another package. The catalog change is just… gone.
    - **Evidence:**
        ```php
        } catch (\Throwable $syncError) {
            Log::warning('Square webhook inline sync failed', [
                'merchant_id' => $merchantId,
                'message' => $syncError->getMessage(),
            ]);

            // Return 200 to prevent noisy webhook retries; error is logged for investigation.
            return $this->success([
                'received' => true,
                'queued' => false,
                'synced_inline' => false,
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.95]`

- [ ] **#WHK-2** · P1 — Supabase Email Hook controller has no idempotency guard, duplicate emails can fire on webhook retry
    - **Where:** `app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php:45-68`
    - **Affects:** End users receiving auth emails (signup confirm, password reset, magic link, invite). A Supabase retry of the same hook delivery sends the email twice. For signup confirmation codes, the second code invalidates the first — the user sees the new code, enters it, and gets "invalid OTP" because Supabase already rotated the token.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Read the `webhook-id` header from the request and use `Cache::add("supabase:email-hook:{$id}", true, 86400)` before calling `Mail::send()`.
        - On a cache-miss (duplicate), return `response()->json(['ok' => true, 'handled' => true])` without re-sending.
    - **Technical:** Supabase Auth Hooks use the Standard Webhooks spec — the `webhook-id` header is the vendor's idempotency key. The SupabaseAuthHookController (MFA verification) already implements this pattern: reads `webhook-id`, dedups via `Cache::add`, returns `continue` on duplicate. The email hook controller has no such guard. A network blip where Supabase doesn't receive our 200 triggers a retry; `Mail::send()` fires again, sending a duplicate email. For OTP-based confirmations this is user-visible breakage because the second code replaces the first in Supabase's backend.
    - **Plain English:** You know how when you click "resend code" and get a new code, the old one stops working? This bug means Supabase can silently "resend" the code on your behalf if there's a tiny network hiccup. The old code in your inbox stops working, you try the new one, and the app rejects you. It's like the post office delivering the same letter twice but swapping the contents of the envelope between deliveries.
    - **Evidence:**
        ```php
        // SupabaseEmailHookController — no webhook-id read, no dedup anywhere
        public function __invoke(Request $request): JsonResponse
        {
            $payload = $request->json()->all();
            // ... extract fields ...

            try {
                // ... Mail::send($mailable) — called unconditionally on every invocation
                return response()->json(['ok' => true, 'handled' => true]);
            } catch (\Throwable $e) {
                // ...
            }
        }
        ```
        ```php
        // SupabaseAuthHookController — correctly deduplicates (evidence the pattern exists)
        if ($id !== '' && ! Cache::add(
            "supabase:auth-hook:{$id}",
            true,
            (int) config('partna.cache.ttls.webhook_idempotency'),
        )) {
            return response()->json(['decision' => 'continue']);
        }
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#WHK-3** · P1 — Supabase Email Hook controller delegates HMAC verification to undocumented middleware; no in-controller signature check
    - **Where:** `app/Http/Controllers/Api/Internal/SupabaseEmailHookController.php:45-47`
    - **Affects:** The Supabase Send Email Hook endpoint. If the middleware referenced in the docblock is missing or misconfigured, forged requests can trigger arbitrary email sends through the Partna Resend pipeline — spam, phishing, and Resend reputation damage.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Mirror the `SupabaseAuthHookController` pattern: read `webhook-id`, `webhook-timestamp`, and `webhook-signature` headers, call `$this->hookService->verifySignature(...)` on the raw body, and return 401 on failure.
        - Remove the docblock claim that middleware handles it; make the verification self-contained and auditable inside the controller method.
    - **Technical:** Supabase Auth Hooks use the Standard Webhooks spec for HMAC. The Auth Hook controller (`SupabaseAuthHookController`) verifies the signature explicitly in the method body using `$request->getContent()` (raw body) before any JSON parsing. The Email Hook controller has a docblock comment claiming "Signature is verified by the surrounding middleware" but performs `$request->json()->all()` immediately — parsing the body before any in-controller verification. If the middleware does not exist on this route, the endpoint accepts unsigned payloads. Even if middleware does exist, the dual-pattern (middleware vs. in-controller) creates a maintenance hazard: a future refactor that removes middleware from one hook type wouldn't flag the email hook as vulnerable.
    - **Plain English:** Imagine two front doors to the same building. One has a deadbolt the security guard checks every time (Auth Hook). The other has a sticky note saying "the alarm company handles this" — but nobody can find the alarm company's control panel (Email Hook). If that sticky note is wrong, anyone can walk in. The fix is to install the same deadbolt on both doors.
    - **Evidence:**
        ```php
        /**
         * Receives Supabase Send Email Hook events and dispatches the appropriate
         * Partna Mailable so all auth emails ride the same Resend pipeline as our
         * transactional mail. Signature is verified by the surrounding middleware.
         * ...
         */
        class SupabaseEmailHookController extends ApiController
        {
            public function __invoke(Request $request): JsonResponse
            {
                $payload = $request->json()->all(); // body parsed BEFORE any in-controller verification
                // ...
            }
        }
        ```
        ```php
        // SupabaseAuthHookController — in-controller verification (the standard to match)
        $id = (string) $request->header('webhook-id', '');
        $timestamp = (string) $request->header('webhook-timestamp', '');
        $signature = (string) $request->header('webhook-signature', '');
        $rawBody = $request->getContent();

        if (! $this->hookService->verifySignature($id, $timestamp, $signature, $rawBody)) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **#WHK-4** · P2 — Stripe Platform charge.refunded handler silently skips clawback reconciliation when refund arrives before payout completion
    - **Where:** `app/Http/Controllers/Api/Webhooks/Stripe/StripePlatformWebhookController.php:162-194`
    - **Affects:** Commission clawback rows for destination-charge payouts. When Stripe delivers the `charge.refunded` webhook before the `payment_intent.succeeded` webhook has been processed, the clawback row doesn't exist yet — the handler logs and returns without persisting Stripe's authoritative `application_fee_refund_cents` / `transfer_reversal_cents`. The clawback row is later created with the local estimate, and the ±1¢ drift is never corrected.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - When the clawback row is not found, store the Stripe reconciliation values in a pending column on the payout row (or a deduplicated side table keyed on `(payout_id, refund_id)`) so the clawback-creation code path can read them.
        - Alternatively, defer the clawback update to a job that retries with backoff, checking for the clawback row's eventual existence.
    - **Technical:** Stripe's `charge.refunded` and `payment_intent.succeeded` are independent event streams from the same PaymentIntent; they can arrive out-of-order. The current handler does `CommissionClawback::query()->where('payout_id', $payoutId)->where('refund_id', $stripeRefund->id)->first()` — if null, it logs `no_local_row` and returns. The values from Stripe that would correct our local proportional estimate are discarded. The gold standard (cat 6) requires out-of-order tolerance: late-arriving data must not be silently dropped. A `charge.refunded` arriving before `payment_intent.succeeded` is a normal Stripe behavior, not an anomaly.
    - **Plain English:** Stripe sometimes tells us "the refund was for $10.37 in fees" before it tells us "the payment went through." Right now, if the refund message arrives first, we shrug and throw away the exact dollar amount Stripe gave us. Later, when the payment message arrives, we guess the fee amount ourselves — and our guess is usually off by a penny. The fix is to write the exact number on a sticky note attached to the payout, so when the payment message eventually arrives, we can use Stripe's number instead of our guess.
    - **Evidence:**
        ```php
        $clawback = CommissionClawback::query()
            ->where('payout_id', $payoutId)
            ->where('refund_id', $stripeRefund->id)
            ->first();

        if ($clawback === null) {
            Log::info('stripe.platform.clawback_drift.no_local_row', [
                'payout_id' => $payoutId,
                'refund_id' => $stripeRefund->id,
            ]);

            return; // Stripe's reconciliation values are discarded here
        }
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#WHK-5** · P2 — Square catalog webhook has no raw payload archival; replay or forensic investigation requires re-requesting from Square
    - **Where:** `app/Http/Controllers/Api/Webhooks/SquareCatalogWebhookController.php:46-93`
    - **Affects:** Operations team investigating a catalog sync failure. Without the raw event payload, determining whether Square sent bad data, we parsed it wrong, or the sync job had a bug requires guesswork. A replay of past events is impossible without asking Square to re-send.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Create a `square.catalog_events` table with a UNIQUE index on `event_id` and a JSONB `payload` column.
        - After HMAC verification passes, INSERT the raw payload (with idempotency via the UNIQUE constraint) before dispatching the sync job.
    - **Technical:** The gold standard (cat 7/cat 9) requires that every vendor webhook payload be archived raw in a dedicated events table. The Shopify order pipeline stores raw payloads in `commerce.order_events`; the Stripe pipeline stores them in `billing.webhook_events`. Square has no equivalent — the event_id is used for cache dedup only, and the JSON payload is decoded, partially read, and discarded. A forensic investigation into a catalog sync bug has no source data to examine.
    - **Plain English:** This is like a security camera that only saves the timestamp of when someone walked by, not the actual footage. When something goes wrong — a product disappeared from the booking page — you know WHEN Square sent the update, but you can't see WHAT they sent. You have to call Square and ask them to re-send the footage, assuming they still have it.
    - **Evidence:**
        ```php
        // Payload is decoded for field extraction, never stored raw
        $payload = $request->json()->all();
        // ...
        $eventId = trim((string) ($payload['event_id'] ?? ''));
        // eventId is used for cache dedup only:
        if ($eventId !== '') {
            $dedupeKey = 'square:webhook:event:'.$eventId;
            if (! Cache::add($dedupeKey, true, ...)) {
                return $this->success(['received' => true, 'duplicate' => true]);
            }
        }
        // No INSERT into a catalog_events table — payload is discarded after this request
        ```
    - `[DRAFT, confidence: 0.85]`
