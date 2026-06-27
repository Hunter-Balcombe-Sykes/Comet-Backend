Good. No reconciliation job exists. The sweep query confirms `whereIn('status', ['pending', 'processing'])` includes 'processing' payouts. All evidence verified.

`★ Insight ─────────────────────────────────────`
The sweep-dispatched job + BECS T+2 race is the strongest finding: `failPayout` sets `processed_at` (so the NEXT sweep excludes the payout), but the ALREADY-DISPATCHED job is still in the Horizon queue and will run after the payout transitions to 'failed'. Since BECS failures arrive at 48h and Stripe's idempotency window is only 24h, the retry creates a genuinely NEW PaymentIntent — a duplicate charge against the brand.
`─────────────────────────────────────────────────`

---

# Stripe v2 Payout Pipeline Audit — 2026-05-14

**Branch:** development
**Lens:** Stripe v2 overhaul: any defect that could prevent payouts from completing — Connect status sync, destination-charge PI shape, webhook validation, race conditions, status caching, idempotency
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Services/Stripe/CommissionPayoutService.php
- app/Services/Stripe/CommissionPayoutRefundService.php
- app/Services/Stripe/StripeConnectService.php
- app/Services/Stripe/CommissionVoidService.php
- app/Services/Stripe/StripeBillingService.php
- app/Http/Controllers/Api/Webhooks/StripePlatformWebhookController.php
- app/Http/Controllers/Api/Webhooks/StripeConnectWebhookController.php
- app/Http/Controllers/Api/Webhooks/StripeWebhookController.php
- app/Jobs/Stripe/ExecuteCommissionPayoutJob.php
- app/Jobs/Stripe/ProcessCommissionPayoutsJob.php
- app/Jobs/Stripe/MonitorManualRefundQueueJob.php
- app/Jobs/Stripe/VoidableCommissionsAndWarningsJob.php
- app/Jobs/Stripe/VoidExpiredPayoutsJob.php
- app/Jobs/Stripe/VoidPendingCommissionsForLinkJob.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 1 of 1 complete ✅
- P2 Medium: 2 of 2 complete ✅
- P3 Low: 1 of 1 complete ✅

---

## P1 — Fix before pilot launch

- [x] **#STRP-1** · P1 — BECS failure race: sweep-dispatched job runs after payout is 'failed', creates duplicate PaymentIntent outside Stripe's 24h idempotency window
    - **Where:** app/Jobs/Stripe/ExecuteCommissionPayoutJob.php:56 and app/Services/Stripe/CommissionPayoutService.php:368–384
    - **Affects:** Brands on BECS (`au_becs_debit`) payment method. When a BECS PI fails at T+2 and the daily sweep happened to dispatch a job for that payout in the seconds before the failure webhook marked it `processed_at`, the queued job runs against a `'failed'` payout and falls through to a fresh `paymentIntents->create` call. Because BECS failure arrives at ~48 hours and Stripe's idempotency key TTL is only 24 hours, the same idempotency key now creates a second PI — charging the brand again for commission it already disputed.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - In `ExecuteCommissionPayoutJob::handle()`, extend the early-return guard from `$payout->status === 'completed'` to cover all terminal states: `in_array($payout->status, ['completed', 'failed', 'cancelled'], true)`.
        - In `CommissionPayoutService::processPayoutBatch()`, add a matching guard at the top of the method as defence-in-depth: `if (in_array($payout->status, ['failed', 'cancelled'], true)) { return false; }`.
        - Both changes together close both the direct call-site gap and the job dispatch race.
    - **Technical:** `processEligiblePayouts` queries `whereIn('status', ['pending', 'processing'])` and dispatches an `ExecuteCommissionPayoutJob` per payout. `failPayout` (called by the `payment_intent.payment_failed` webhook handler) sets `processed_at = now()` and `status = 'failed'`, excluding the payout from the *next* sweep — but not from a job already sitting in the Horizon queue. `ExecuteCommissionPayoutJob::handle()` re-fetches the payout fresh and only guards on `'completed'` (line 56). It then calls `processPayoutBatch`, which has three early returns: `completed`, `processing && PI present`, and `pending`. A `'failed'` payout matches none of them and proceeds to brand/affiliate validation, then to `paymentIntents->create` with key `pi_{payout_id}` (no retry suffix since `retry_count` was not incremented). For BECS, the original PI was created ~48h earlier, well outside Stripe's 24h idempotency cache; Stripe creates a second PI. The race window between sweep dispatch and webhook arrival is seconds — narrow but guaranteed to close on any day a BECS payout fails.
    - **Plain English:** Think of a bank instruction that gets cancelled by your finance team (the "payment failed" notice), but a stamped cheque already in the post from an earlier print run still reaches the bank. Normally the post is quick enough that the cheque arrives before the cancellation is processed. But BECS is like a two-day postal service — by the time the failure notice clears, the original cheque guarantee has expired and a new cheque is printed and sent anyway. Adding one extra check ("is this payment already cancelled?") before printing any cheque closes the gap permanently.
    - **Evidence:**
        ```php
        // ExecuteCommissionPayoutJob::handle() — only guards 'completed', not 'failed'/'cancelled':
        $payout = CommissionPayout::find($this->payoutId);

        if (! $payout || $payout->status === 'completed') {
            return;  // ← 'failed' and 'cancelled' fall through to processPayoutBatch
        }

        $result = $payoutService->processPayoutBatch($payout);
        ```
        ```php
        // CommissionPayoutService::processPayoutBatch() — no terminal-state guard:
        if ($payout->status === 'completed') {
            return true;
        }

        if ($payout->status === 'processing' && $payout->payment_intent_id !== null) {
            return null;
        }

        if ($payout->status === 'pending') {
            $payout = $this->revalidatePayoutOrders($payout);
            if ($payout === null) {
                return null;
            }
        }

        // Falls through to PI creation for 'failed' and 'cancelled':
        $brand = Professional::find($payout->brand_professional_id);
        $affiliate = Professional::find($payout->affiliate_professional_id);
        ```

---

## P2 — Should fix

- [x] **#STRP-2** · P2 — Webhook event row committed before handler executes across all three Stripe controllers; transient handler failure permanently silences the event
    - **Where:** app/Http/Controllers/Api/Webhooks/StripePlatformWebhookController.php:142–157 (`verifyAndDedupe`); same pattern in StripeConnectWebhookController.php and StripeWebhookController.php
    - **Affects:** Any `payment_intent.succeeded` or `payment_intent.payment_failed` event (payout stuck in `'processing'` forever), `account.updated` / `checkout.session.completed` (Connect sync silently dropped), or subscription lifecycle events (subscription state not updated) where the downstream handler throws a transient exception — DB deadlock, write timeout, transient service fault.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Preferred — delete-on-failure: wrap the handler invocation in a `try/catch`; on any `\Throwable`, delete the `WebhookEvent` row (`$webhookEvent->delete()`) before re-throwing, so Stripe's retry sees no dedup hit and can re-process.
        - Alternative — commit after: restructure `verifyAndDedupe` to return the parsed `$event` without writing the `WebhookEvent` row; write the row only after the handler returns successfully (within the same request, not a separate transaction).
        - Defence-in-depth regardless: add a nightly scheduled command that queries `CommissionPayout::where('status','processing')->where('updated_at','<',now()->subDays(3))` and re-fetches each PI's status from Stripe via `paymentIntents->retrieve`, calling `markPaymentIntentSucceeded` or `markPaymentIntentFailed` as appropriate (this also closes STRP-3 at the same time).
    - **Technical:** `verifyAndDedupe` performs `WebhookEvent::firstOrCreate` and saves the payload before returning the parsed `Event` to the caller. The caller's `match ($event->type)` handler runs after. If that handler throws — due to a DB deadlock in `markPaymentIntentSucceeded`, a write failure in `syncAccountStatus`, or any other transient fault — Laravel returns a 500 to Stripe. Stripe retries. On retry, `firstOrCreate` finds the existing row, `wasRecentlyCreated === false`, and immediately returns `response()->json(['received' => true])`. Stripe sees 200 and stops retrying. The event is permanently silenced. This ack-before-process anti-pattern exists identically in all three Stripe webhook controllers (`StripePlatformWebhookController`, `StripeConnectWebhookController`, `StripeWebhookController`).
    - **Plain English:** This is like signing the delivery receipt before opening the box. If you open it and find it's broken, you've already told the delivery company "received in good order" — they won't send it again. The fix is to only sign once you've confirmed everything inside is intact. Because all three Stripe endpoints share the same flow, a single transient database error on any Stripe event type could cause that event to be silently lost forever.
    - **Evidence:**
        ```php
        // StripePlatformWebhookController::verifyAndDedupe() — row committed before handler runs:
        $webhookEvent = WebhookEvent::firstOrCreate(
            ['stripe_event_id' => $event->id],
            ['event_type' => $event->type, 'processed_at' => now()]
        );

        if (! $webhookEvent->wasRecentlyCreated) {
            return response()->json(['received' => true]);  // ← Stripe sees 200, stops retrying
        }

        $webhookEvent->forceFill(['payload' => json_decode($payload, true)])->save();

        return $event;  // ← handler runs after this returns; if handler throws, event is already acked
        ```
        ```php
        // __invoke() — handler runs after verifyAndDedupe has already acked:
        match ($event->type) {
            'payment_intent.succeeded' => $this->handlePaymentIntentSucceeded($event->data->object),
            'payment_intent.payment_failed' => $this->handlePaymentIntentFailed($event->data->object),
            'charge.refunded' => $this->handleChargeRefunded($event->data->object),
            'charge.dispute.created' => $this->handleChargeDisputeCreated($event->data->object),
            default => Log::debug('Unhandled Stripe platform snapshot event', ['type' => $event->type]),
        };
        ```

- [x] **#STRP-3** · P2 — No background reconciliation for payouts stuck in `'processing'`; lost webhooks cause permanent stall with no auto-recovery
    - **Where:** app/Services/Stripe/CommissionPayoutService.php:374–377 (re-dispatch guard); no reconciliation job exists in app/Jobs/Stripe/
    - **Affects:** Any `processing` payout where `payment_intent.succeeded` was never delivered — due to network partition during Stripe's retry window, or the dedup race described in STRP-2. The payout stays in `'processing'` indefinitely; the daily sweep re-dispatches jobs for it but each job immediately returns null due to the idempotency guard. Neither the brand nor the affiliate sees the payout resolve.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a scheduled reconciliation job (daily, after the main sweep) that queries `CommissionPayout::where('status','processing')->whereNotNull('payment_intent_id')->where('updated_at','<',now()->subDays(3))->get()`.
        - For each payout, call `$this->stripe->paymentIntents->retrieve($payout->payment_intent_id)` and branch on `$pi->status`: `'succeeded'` → `markPaymentIntentSucceeded`; `'requires_payment_method'`/`'canceled'` → `markPaymentIntentFailed`; `'processing'` → leave and log a heartbeat.
        - A `pi_checked_at` timestamp column (or reusing `updated_at` with a `touchQuietly` pattern) keeps re-check cost proportional to how many payouts are genuinely stuck.
        - The 3-day delay threshold aligns with BECS's T+2 settlement window plus one buffer day; card PIs typically resolve same-day so no card payout should ever be 3 days old in `'processing'`.
    - **Technical:** `processPayoutBatch` correctly returns null for `'processing'` payouts with a `payment_intent_id` set, preventing duplicate PI creation during Stripe's idempotency window. But this correctness property creates a dead-end: the only thing that advances a `'processing'` payout is a delivered `payment_intent.*` webhook. If that webhook is permanently lost (Stripe's retry window exhausted, or permanently silenced by STRP-2's dedup race), the payout has no recovery path. The sweep continues to dispatch jobs for it (`whereIn('status', ['pending', 'processing'])`, `whereNull('processed_at')`), but each job immediately no-ops. A daily round-trip to Stripe asking "what actually happened to this PI?" is the standard recovery pattern for BECS-accepting platforms.
    - **Plain English:** Imagine you sent a wire transfer and your bank told you "we'll text you when it clears." If the text never arrives — because your phone was off or the network dropped it — you'd never know if the money landed. You'd keep checking your sent-transfers list and see "pending" forever. The fix is a daily automated call to the bank: "Hey, can you tell me the real status of transfer #XYZ?" That call can then update your records to match reality without waiting for a notification that may never come.
    - **Evidence:**
        ```php
        // Re-dispatch guard is correct for idempotency, but leaves no recovery path when webhook is lost:
        if ($payout->status === 'processing' && $payout->payment_intent_id !== null) {
            return null;  // ← right, but dead-ends permanently if payment_intent.succeeded never arrives
        }
        ```
        ```php
        // Daily sweep re-dispatches 'processing' payouts but each job immediately hits the guard above:
        $existingPending = CommissionPayout::query()
            ->whereIn('status', ['pending', 'processing'])
            ->whereNull('processed_at')
            ->where('eligible_after', '<=', now())
            ->orderBy('eligible_after')
            ->limit(500)
            ->get();
        ```

---

## P3 — Nice to have

- [x] **#STRP-4** · P3 — `charge.refunded` webhook discards Stripe's actual fee/transfer allocation; estimated splits in clawback rows never reconciled
    - **Where:** app/Http/Controllers/Api/Webhooks/StripePlatformWebhookController.php:275–287 (`handleChargeRefunded`) and app/Services/Stripe/CommissionPayoutRefundService.php (`clawbackCompletedPayout`)
    - **Affects:** Financial reconciliation at monthly close. The `CommissionClawback` row records estimated `application_fee_refund_cents` / `transfer_reversal_cents` computed from the payout's fee ratio. Stripe's actual allocation (carried in the `charge.refunded` webhook's refund object) may differ by 1 cent due to rounding. Over hundreds of refunds these per-refund discrepancies compound into reconciliation noise. No alert fires on the drift.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In `handleChargeRefunded`, after resolving `$payoutId`, retrieve the first refund from `$charge->refunds->data[0]` (expand if needed) and extract `application_fee_refund->amount` and the associated transfer reversal amount.
        - Look up the `CommissionClawback` row for `(payout_id, charge_id)` and compare against stored estimates.
        - Log a `Log::warning('stripe.platform.clawback_drift', [...])` when the discrepancy exceeds 1 cent; optionally update the row with Stripe's authoritative values.
        - The source code's own docblock already identifies this as a known gap ("A future enhancement could diff…").
    - **Technical:** `clawbackCompletedPayout` computes `$feeRatio = $payout->platform_fee_cents / $payout->gross_commission_cents` and derives `$feeRefundCents = round($refundCents * $feeRatio)`, storing this in the clawback row before any webhook arrives. Stripe applies its own internal rounding when issuing the `application_fee_refund` and `transfer_reversal`, which may differ from the local estimate by ±1 cent. The `handleChargeRefunded` handler logs the `amount_refunded` total but never reads `$charge->refunds->data[0]->application_fee_refund->amount` or `$charge->refunds->data[0]->transfer_reversal->amount`, leaving the estimated values in the DB as the permanent record.
    - **Plain English:** This is like estimating how much GST was in a refund by doing your own arithmetic, then throwing away the actual tax receipt when it arrives in the post. The estimate is probably right to the nearest cent, but if you're ever audited, your maths is not the official record — and over hundreds of refunds, those missing pennies create genuine bookkeeping work at month-end. The fix is to read the receipt once it arrives and note any discrepancy.
    - **Evidence:**
        ```php
        private function handleChargeRefunded(object $charge): void
        {
            $payoutId = $charge->metadata?->sidest_payout_id ?? null;
            if (! $payoutId) {
                return;
            }

            Log::info('stripe.platform.charge_refunded', [
                'charge_id' => $charge->id,
                'payout_id' => $payoutId,
                'amount_refunded' => $charge->amount_refunded ?? null,
                'refunded' => $charge->refunded ?? null,
            ]);
            // Stripe's actual application_fee_refund + transfer_reversal amounts are
            // available on $charge->refunds but never read or reconciled.
        }
        ```
        ```php
        // clawbackCompletedPayout — local estimate written at refund-create time:
        $feeRatio = $payout->gross_commission_cents > 0
            ? $payout->platform_fee_cents / $payout->gross_commission_cents
            : 0.0;
        $feeRefundCents = (int) round($refundCents * $feeRatio);
        $transferReversalCents = $refundCents - $feeRefundCents;

        $this->insertClawbackRow($payout, $order, $shopifyRefundId, [
            'application_fee_refund_cents' => $feeRefundCents,
            'transfer_reversal_cents' => $transferReversalCents,
            // ← these estimates are never reconciled against the webhook's ground truth
        ]);
        ```
