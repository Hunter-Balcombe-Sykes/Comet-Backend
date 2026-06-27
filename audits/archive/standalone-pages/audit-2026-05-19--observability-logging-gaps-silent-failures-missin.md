`★ Insight ─────────────────────────────────────`
The key observability invariant in this codebase: `report($e)` routes exceptions to Nightwatch as named, stack-traced events. `Log::error(...)` only writes a string to the cloud log. Horizon's failed-job counter increments for both, but only `report($e)` triggers Nightwatch exception alerts — meaning a `failed()` method with only `Log::error` produces a job failure that shows up in Horizon's counts but has no associated exception in Nightwatch.
`─────────────────────────────────────────────────`

# Observability Audit — 2026-05-19

**Branch:** development
**Lens:** Observability: logging gaps, silent failures, missing Nightwatch instrumentation
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- `app/Http/Controllers/Api/Webhooks/SquareCatalogWebhookController.php`
- `app/Http/Controllers/Api/Webhooks/FreshaCatalogWebhookController.php`
- `app/Jobs/Shopify/ProcessShopifyOrderWebhookJob.php`
- `app/Jobs/Stripe/ExecuteCommissionPayoutJob.php`
- `app/Jobs/Stripe/ReconcileStuckPayoutsJob.php`
- `app/Jobs/Stripe/VoidableCommissionsAndWarningsJob.php`
- `app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php`
- `app/Jobs/Notifications/SendBrandStatusNotificationJob.php`
- `app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php`
- `app/Jobs/Notifications/NudgeStuckOnboardingJob.php`
- `app/Jobs/Notifications/SendWeeklyAnalyticsNotificationJob.php`
- `app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php`
- `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **#OBS-1** · P1 — Square and Fresha webhook inline-sync fallback silently 200s on failure with no Nightwatch reporting
    - **Where:** `app/Http/Controllers/Api/Webhooks/SquareCatalogWebhookController.php:102-115` and `app/Http/Controllers/Api/Webhooks/FreshaCatalogWebhookController.php:112-126`
    - **Affects:** Any brand with an active Square or Fresha integration whose Redis queue is temporarily unavailable. The webhook dispatch fails, the inline sync also fails, the vendor receives 200 and stops retrying, and the catalog update is permanently dropped with no Nightwatch alert.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($syncError)` before the `Log::warning` call in both inline-sync catch blocks.
        - Consider returning a 5xx response instead of 200 when both the queue dispatch and the inline sync fail — vendors will retry on 5xx, restoring the at-least-once guarantee. The comment "Return 200 to prevent noisy webhook retries" is the wrong tradeoff: noise from retries is better than silent data loss.
    - **Technical:** Both controllers follow the same fallback pattern: `dispatch()` throws → attempt inline sync → if inline sync also throws → `Log::warning(...)` → return `$this->success(...)` (HTTP 200). The outer catch block reaches `report()` nowhere, so Nightwatch never fires an exception alert. Horizon's failed-jobs counter is also unaffected because no job actually failed — it was never enqueued. The symptom is a structurally healthy Horizon dashboard hiding a broken catalog pipeline.
    - **Plain English:** Think of it like a mail delivery service: if the delivery truck breaks down, the driver tries to hand-deliver the package themselves. If that also fails, the current code sends the sender a confirmation saying "Delivered!" — even though nothing arrived. The fix is to send back "Delivery failed, please try again" so the sender retries, and to alert the manager so someone knows the truck needs fixing.
    - **Evidence:**
        ```php
        // SquareCatalogWebhookController.php:102-115 (identical pattern in FreshaCatalogWebhookController)
        try {
            $stats = $syncService->syncFromSquare($professional, fullSync: false);
            // ...
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

---

## P2 — Should fix

- [x] **#OBS-2** · P2 — Four notification job `failed()` methods log only, never call `report($e)` — Nightwatch misses named exception events
    - **Where:** `app/Jobs/Notifications/SendBrandStatusNotificationJob.php:77`, `app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php:117`, `app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php:122`, `app/Jobs/Notifications/NudgeStuckOnboardingJob.php:137`
    - **Affects:** Nightwatch alerting for notification delivery failures. Horizon's failed-job counter increments correctly, but no named exception reaches Nightwatch, so there is no stack trace, no alert rule to wire, and no exception grouping across incidents.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e)` as the first line of each `failed()` method, matching the established pattern in `ExecuteCommissionPayoutJob`, `ProcessShopifyOrderWebhookJob`, `VoidableCommissionsAndWarningsJob`, and `SendStaffBroadcastEmailsJob`.
        - No other changes needed — the structured `Log::error` context in each method is good and should be kept.
    - **Technical:** In Laravel, `failed()` is called after a job exhausts its retry budget. Calling `report($e)` routes the exception to the registered exception handler, which forwards it to Nightwatch as a named exception with a full stack trace. Without it, Nightwatch sees the job failure only as a queue metric, not as an inspectable exception event. The inconsistency is clear from surveying the job suite: financial jobs (`ExecuteCommissionPayoutJob`, `VoidableCommissionsAndWarningsJob`) and infrastructure jobs (`SyncSubdomainToKvJob`, `SendStaffBroadcastEmailsJob`) all call `report($e)` in `failed()`, but four notification jobs don't. Notification delivery failures — especially for `SendTransactionalNotificationEmailJob` which covers payout and commission notifications — warrant the same visibility.
    - **Plain English:** When a background task permanently fails after all retries, the system writes it down in two places: a simple count in the job-queue dashboard (Horizon), and a detailed incident report with the full error story (Nightwatch). Currently, notification failures only update the simple count — no incident report is filed. That means if commission payout emails stop sending, there's no alert, no stack trace to investigate, and no way to set up an automated notification. The fix is a one-line addition to each of the four affected tasks.
    - **Evidence:**
        ```php
        // SendBrandStatusNotificationJob.php:77 — representative of all four
        public function failed(\Throwable $e): void
        {
            Log::error('SendBrandStatusNotificationJob failed', [
                'affiliate_professional_id' => $this->affiliateProfessionalId,
                'brand_professional_id' => $this->brandProfessionalId,
                'brand_status' => $this->brandStatus,
                'message' => $e->getMessage(),
            ]);
            // missing: report($e)
        }

        // SendTransactionalNotificationEmailJob.php:117
        public function failed(\Throwable $e): void
        {
            Log::error('Transactional notification email failed', [
                'notification_id' => $this->notificationId,
                'category' => $this->category,
                'professional_id' => $this->professionalId,
                'message' => $e->getMessage(),
            ]);
            // missing: report($e)
        }
        ```

- [ ] **#OBS-3** · P2 — `ReconcileStuckPayoutsJob` swallows per-payout Stripe API errors without `report($e)` — persistent Stripe degradation is invisible to Nightwatch
    - **Where:** `app/Jobs/Stripe/ReconcileStuckPayoutsJob.php:71-80`
    - **Affects:** Observability of Stripe API failures during stuck-payout reconciliation. If Stripe's PaymentIntents API is degraded, every payout in the reconciliation batch logs an error and continues, the final summary shows `errored: N`, but no Nightwatch exception is raised and no alert fires.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `report($e)` inside the inner `catch` block, before `$errored++`. The `continue` is correct behaviour — process remaining payouts — but the exception should still reach Nightwatch.
        - Optionally: if `$errored === $stuck->count()` at the end of the loop (total failure), call `$this->fail(new \RuntimeException(...))` to surface the whole job as a failure rather than a silent-partial-success.
    - **Technical:** The job's top-level `failed()` method correctly calls `report($e)` for exceptions that terminate the job entirely. However, the inner per-payout loop catches individual Stripe API failures, logs them at `Log::error`, increments a counter, and continues. This is the right control-flow choice, but without `report($e)` those exceptions are invisible to Nightwatch. In a Stripe degradation scenario, the job "succeeds" (returns normally, no job failure), the cloud log has error entries, but Nightwatch shows a healthy job run. The `$errored` count in the final `Log::info` summary is the only signal — and it requires actively reading logs to notice.
    - **Plain English:** This job's job is to check on stuck payments by calling Stripe directly. If Stripe is having problems and can't answer, the current code quietly notes it in the log and moves on. The job itself shows as "completed successfully" even if every single Stripe check failed. The fix makes each Stripe failure file a proper incident report so that if Stripe goes down, an alert fires immediately rather than being discovered during a manual log review.
    - **Evidence:**
        ```php
        // ReconcileStuckPayoutsJob.php:71-80
        foreach ($stuck as $payout) {
            try {
                $pi = $stripe->paymentIntents->retrieve($payout->payment_intent_id);
            } catch (\Throwable $e) {
                Log::error('ReconcileStuckPayoutsJob retrieve failed', [
                    'payout_id' => $payout->id,
                    'payment_intent_id' => $payout->payment_intent_id,
                    'error' => $e->getMessage(),
                ]);
                $errored++;
                // missing: report($e)
                continue;
            }
        ```

`★ Insight ─────────────────────────────────────`
The financial job suite (`ExecuteCommissionPayoutJob`, `VoidableCommissionsAndWarningsJob`, `ReconcileStuckPayoutsJob`) is clearly the highest-care tier in the codebase — all have `$tries`, `$backoff`, `$timeout`, `ShouldBeUnique` where needed, and `failed()` with `report($e)`. The notification job suite was written more lightly and the `report($e)` pattern didn't carry over consistently. This is a common divergence in maturing codebases: financial paths get the rigorous treatment first, infrastructure paths catch up later.
`─────────────────────────────────────────────────`
