- [ ] **#OBS-1** · P1 — `NotificationPublisher::publish()` drops notifications silently when required fields are empty
    - **Where:** app/Services/Notifications/NotificationPublisher.php:89-105
    - **Affects:** Every caller of `publish()` — booking notifications, brand status changes, invite expiries, weekly analytics, onboarding nudges. If a bug in calling code produces an empty `$professionalId`, `$title`, `$body`, or `$dedupeKey`, the notification vanishes with zero paper trail.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Log::warning(...)` calls at each early-return site with the method arguments as context so the dropped notification is searchable.
        - Consider adding a `report()` call at the `$dedupeKey === ''` guard — an empty dedupe key is always a caller bug and should trigger an alert.
    - **Technical:** Three silent `return` branches (empty professionalId, empty title/body, empty dedupeKey) run before `insertOrIgnore` ever executes. No exception, no log, no Nightwatch event, no Horizon failure. If a payout notification is assembled by a future service and the dedupe key string interpolates to empty, the professional never learns about their money and ops has no signal. The `publish()` method is the single chokepoint for the entire in-app notification pipeline — guarding it with at-least-a-log is a high-leverage change.
    - **Plain English:** The system's notification delivery has three silent trapdoors at the front door. If a developer accidentally passes an empty customer name or blank title, the notification is thrown away with no record anywhere — not in logs, not in error trackers, not in the database. It's like a postal worker who quietly bins any letter without a return address instead of stamping it "return to sender."
    - **Evidence:**
        ```php
        $professionalId = trim($professionalId);
        if ($professionalId === '') {
            return;
        }

        $title = trim($title);
        $body = trim($body);
        if ($title === '' || $body === '') {
            return;
        }

        $dedupeKey = trim($dedupeKey);
        if ($dedupeKey === '') {
            // Require a non-empty dedupe key — callers should always provide one.
            return;
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#OBS-2** · P1 — `NotificationPublisher::publishMany()` drops bulk notifications silently when all items are invalid
    - **Where:** app/Services/Notifications/NotificationPublisher.php:168-175 (foreach skip) and :186-188 (empty rows return)
    - **Affects:** Fan-out callers that use `publishMany()` for bulk notification delivery (brand-affiliate invite batches, staff broadcasts). A bug that produces all-empty fields results in zero notifications with zero evidence.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Log a warning when an individual item is skipped inside the foreach loop (include the item index and reason).
        - Log a warning when `$rows === []` after filtering — the caller sent `$items` but nothing was publishable.
    - **Technical:** The foreach loop silently `continue`s past items with empty professionalId, title, body, or dedupeKey. If every item in the batch is invalid, `$rows` stays empty and the method returns at the `if ($rows === [])` guard. No log, no Nightwatch trace. Contrast with `publish()` which at least has the single-caller visibility — `publishMany()` callers are orchestrating bulk dispatches and have no per-item feedback loop. A warning log with the count of skipped items would close the observability gap.
    - **Plain English:** Same trapdoor as the single-publish method, but multiplied. If someone queues up 200 brand notifications and a bug makes every single one invalid, the system silently does nothing. The operator who clicked "send" sees a success message, but zero emails went out. A simple log saying "200 items provided, 0 published" would catch this immediately.
    - **Evidence:**
        ```php
        foreach ($items as $item) {
            // ...
            if ($professionalId === '' || $title === '' || $body === '' || $dedupeKey === '') {
                continue;
            }
            // ...
        }

        if ($rows === []) {
            return;
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#OBS-3** · P2 — `CommerceNotificationService::notifyBookingCompleted()` swallows all exceptions, hiding notification failures from callers
    - **Where:** app/Services/Notifications/CommerceNotificationService.php:104-108
    - **Affects:** Booking completion flow — if the notification publish or milestone check fails, the booking webhook/controller that called this method continues as if everything worked. Nightwatch sees the exception (via `report()`), but the business operation succeeds without the notification being delivered.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Evaluate whether booking notifications are critical enough to let the exception propagate (causing a 500 to the webhook caller, which triggers a retry).
        - If the current "best-effort" semantics are correct, add structured context to the `Log::warning` call — include the `$eventKey`, `$bookingId`, and `$serviceName` so ops can correlate the dropped notification to a specific booking.
    - **Technical:** The catch-all `catch (\Throwable $e)` calls `report($e)` (visible in Nightwatch) and logs a warning, but the method returns void and the caller proceeds normally. The booking event itself is already persisted — only the notification is lost. Nightwatch shows the exception, but there's no automated way to know "a booking happened but the professional wasn't told." The current `Log::warning` context only has `professional_id` and `message` — adding `event_key` and `booking_id` would let ops trace which specific booking lost its notification.
    - **Plain English:** When a booking notification fails, Nightwatch gets a copy of the error, but the rest of the system acts like everything's fine. The professional never sees the "new booking" notification. It's like a cash register that still prints the receipt but silently fails to ring the customer-facing bell — the sale went through, but the customer doesn't know.
    - **Evidence:**
        ```php
        } catch (\Throwable $e) {
            report($e);
            Log::warning('Booking notifications failed', [
                'professional_id' => $context['professional_id'] ?? null,
                'message' => $e->getMessage(),
            ]);
        }
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **#OBS-4** · P2 — `SendStaffBroadcastEmailsJob::handle()` returns silently when the notification is not found
    - **Where:** app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php:43-46
    - **Affects:** Staff broadcast email fan-outs. If the notification is deleted between when the job is dispatched and when it runs, the job silently does nothing — no log, no Horizon failure, no Nightwatch event. The staff member who sent the broadcast never knows it didn't fan out.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `Log::warning(...)` call mirroring the pattern used in `SendEnquiryNotificationJob` and `FanOutBrandStatusNotificationJob` — include `notification_id` and `list_key` as context.
    - **Technical:** Every other notification job in the codebase (`SendEnquiryNotificationJob`, `FanOutBrandStatusNotificationJob`, `ExportCustomerDataJob`, `RedactCustomerJob`) logs a warning when its target entity is not found. `SendStaffBroadcastEmailsJob` is the outlier — it returns silently. A race condition (notification deleted via API while the job is queued) would produce a green Horizon dashboard but a broadcast that never went out to any subscriber. Nightwatch would have zero record.
    - **Plain English:** If a staff member schedules a broadcast email and then deletes the notification before the send starts, the job quietly does nothing. No error, no log, no "this broadcast was never sent" alert. Every other similar job in the system leaves a note when this happens — this one doesn't.
    - **Evidence:**
        ```php
        $notification = Notification::query()->find($this->notificationId);
        if (! $notification) {
            return;
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#OBS-5** · P2 — `SendStaffBroadcastEmailToSubscriberJob::handle()` returns silently when notification or subscription is not found
    - **Where:** app/Jobs/Notifications/SendStaffBroadcastEmailToSubscriberJob.php:53-60
    - **Affects:** Individual subscriber deliveries within a staff broadcast batch. If either the notification or the subscription row is deleted between batch dispatch and job execution, the job silently returns — no log, no trace. A batch of 200 jobs could have several silently no-op without the batch owner knowing.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `Log::warning(...)` at both early-return sites, including `notification_id` and `subscription_id`.
    - **Technical:** Same pattern as OBS-4, but in the leaf job. Because this job runs inside a `Bus::batch()` with `allowFailures()`, a silent return is indistinguishable from a successful send — the batch shows "completed" with zero failures. A warning log would let ops search for `subscription_id` and distinguish "subscriber unsubscribed between dispatch and run" from "batch dispatched but subscription row went missing." The `failed()` method on this job only fires on exceptions — a silent return never reaches it.
    - **Plain English:** Each subscriber in a broadcast gets their own mini-job. If that job can't find the subscriber's record, it just stops with no note. From the dashboard, the batch looks perfect — all succeeded. But some emails were never sent, and there's no way to know which ones.
    - **Evidence:**
        ```php
        $notification = Notification::query()->find($this->notificationId);
        if (! $notification) {
            return;
        }

        $sub = EmailSubscription::query()->find($this->subscriptionId);
        if (! $sub) {
            return;
        }
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **#OBS-6** · P3 — `SendStaffBroadcastEmailsJob` does not declare a queue, running on `default` instead of a named queue
    - **Where:** app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php:28-34 (constructor)
    - **Affects:** Queue priority and starvation risk — the job's chunkById loop over potentially thousands of subscribers runs on the `default` queue, competing with every other unassigned job. The batches it dispatches correctly go to `mail`, but the coordinator job itself is undifferentiated.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$this->onQueue('notifications');` in the constructor to match the pattern used by `FanOutBrandStatusNotificationJob`, `InviteExpirySweepJob`, and `NudgeStuckOnboardingJob` — all fan-out coordinator jobs in the codebase.
    - **Technical:** Every other fan-out coordinator job in the provided files explicitly assigns itself to the `notifications` queue in its constructor. `SendStaffBroadcastEmailsJob` is the outlier. On the `default` queue, it can be starved by lower-priority work or, conversely, its chunkById loop can delay time-sensitive default-queue jobs. In practice the risk is low (the coordinator only does lightweight chunking), but the inconsistency with the rest of the codebase means a future operator tuning queue weights might miss this job.
    - **Plain English:** All the other "fan-out" coordinator jobs have a label telling the queue system what kind of work they are. This one doesn't — it gets lumped into the "everything else" bucket. It'll probably work fine, but if the system gets busy and someone needs to prioritize notification sends, this job won't be grouped with its siblings.
    - **Evidence:**
        ```php
        public function __construct(
            public string $notificationId,
            public string $listKey = 'sidest_updates'
        ) {}
        // Compare with FanOutBrandStatusNotificationJob:
        // public function __construct(...) {
        //     $this->onQueue('notifications');
        // }
        ```
    - `[DRAFT, confidence: 0.85]`
