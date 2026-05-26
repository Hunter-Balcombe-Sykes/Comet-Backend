- [ ] **#JOB-1** · P1 — `SendTransactionalNotificationEmailJob` silently discards permanent failures, retries wasted
    - **Where:** app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php:51-55, 79-82, 86-89
    - **Affects:** Transactional email delivery for invites, commissions, and payouts — failures are invisible to operators.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$this->fail($e ?? new \RuntimeException('Permanent failure: no email on record'))` in the `! $email` branch.
        - Add `$this->fail(new \RuntimeException('Mailable instantiation failed for ' . $class))` in the `$mailable === null` branch.
        - Optionally: keep the feature-disabled and preference-disabled branches as no-fail early-returns (those are legitimate no-ops).
    - **Technical:** The job has `$tries = 3` and `$backoff = [30, 120, 300]`. Three early-return paths in `handle()` log and exit without calling `$this->fail()`: no email on record, mailable class doesn't exist, and mailable instantiation returns null. These are non-transient conditions — if the professional has no email at T=0, they won't have one at T=120 or T=300. Horizon marks the job succeeded after three no-op retries; no failed-jobs counter increment, no Nightwatch alert. For financially-sensitive categories (commission, payout), a silently dropped email is a trust defect. Laravel's `$this->fail()` method marks the job failed and fires the `failed()` hook, which already logs properly.
    - **Plain English:** Imagine a mailroom clerk who, when handed an envelope with no address, puts it back in the "retry later" pile three times before quietly throwing it away. The sender never knows it wasn't delivered. For commission and payout emails, that's money-related communication going silently missing.
    - **Evidence:**
        ```php
        if (! $email) {
            Log::warning('Notification email skipped: no email on record', [
                'professional_id' => $this->professionalId,
            ]);

            return;  // <-- no $this->fail(), job retries 3x then disappears
        }

        $mailable = $this->buildMailable($notification, $class);
        if ($mailable === null) {
            Log::warning('Notification email skipped: mailable instantiation failed', [
                'category' => $this->category,
                'class' => $class,
            ]);

            return;  // <-- no $this->fail(), same silent discard
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **#JOB-2** · P2 — `FanOutBrandStatusNotificationJob` missing `ShouldBeUnique` — concurrent runs dispatch double batches
    - **Where:** app/Jobs/Notifications/FanOutBrandStatusNotificationJob.php:33 (class declaration)
    - **Affects:** Queue resource waste; every connected affiliate gets duplicate `SendBrandStatusNotificationJob` instances when the fan-out runs twice concurrently.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `implements ShouldBeUnique` to the class declaration.
        - Add `public function uniqueId(): string { return 'fanout-brand-status:' . $this->brandProfessionalId . ':' . $this->brandStatus; }`.
    - **Technical:** This job iterates `brand_partner_links` via `chunkById` and dispatches one `SendBrandStatusNotificationJob` per affiliate. If two instances run concurrently (brand status flips twice quickly, or Horizon restarts during processing), both walk the same partner list and dispatch duplicate batches. Although the leaf job's dedupe key prevents duplicate notification rows, the queue still processes double the work. `ShouldBeUnique` with a `uniqueId()` keyed on `(brandProfessionalId, brandStatus)` serialises concurrent runs for the same status transition. The `$tries = 3` property is preserved — uniqueness only gates concurrency, not retries.
    - **Plain English:** If two mailroom workers grab the same mailing list at the same time, they both stuff envelopes for every name on the list. The duplicates get caught at the mailbox (the dedupe key), but twice the envelopes were stuffed and carried. A simple "only one worker on this list at a time" rule prevents the waste.
    - **Evidence:**
        ```php
        class FanOutBrandStatusNotificationJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;
            public int $backoff = 30;
            // ... no ShouldBeUnique interface, no uniqueId() method
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#JOB-3** · P2 — `SendStaffBroadcastEmailsJob` missing `ShouldBeUnique` — concurrent runs double the batch fan-out
    - **Where:** app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php:31 (class declaration)
    - **Affects:** Queue throughput; subscribers may see redundant processing (though actual duplicate emails are blocked by the leaf job's `insertOrIgnore` guard).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `implements ShouldBeUnique` to the class declaration.
        - Add `public function uniqueId(): string { return 'staff-broadcast:' . $this->notificationId; }`.
    - **Technical:** Same concurrency pattern as JOB-2. The job chunks through `EmailSubscription` rows and dispatches `SendStaffBroadcastEmailToSubscriberJob` batches. The leaf job has an `insertOrIgnore` on `broadcast_email_receipts` so actual double-sends are prevented. But if the fan-out runs twice concurrently, every subscriber gets two leaf jobs dispatched into the `mail` queue, doubling processing cost. `ShouldBeUnique` keyed on `notificationId` ensures only one fan-out instance processes a given broadcast.
    - **Plain English:** Same "two workers on the same mailing list" problem, but for platform-wide staff broadcasts. The leaf worker checks "have I already sent to this person?" before hitting send, so nobody gets duplicate emails — but the system still does double the preparation work for every recipient.
    - **Evidence:**
        ```php
        class SendStaffBroadcastEmailsJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;
            public array $backoff = [10, 30, 60];
            // ... no ShouldBeUnique interface, no uniqueId() method
        ```
    - `[DRAFT, confidence: 0.90]`

- [ ] **#JOB-4** · P3 — All retryable jobs lack `$maxExceptions` — failures exhaust full retry window before surfacing
    - **Where:** app/Jobs/Notifications/* and app/Jobs/Shopify/Gdpr/* (every job with `$tries >= 2`)
    - **Affects:** Incident response time — deterministically-failing jobs take minutes to surface instead of seconds.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `public int $maxExceptions = 2;` to every job that has `$tries >= 3`.
        - For jobs with `$tries = 2`, add `public int $maxExceptions = 1;`.
    - **Technical:** Without `$maxExceptions`, a job that throws on every attempt will exhaust all `$tries` over the full `$backoff` window. For `SendTransactionalNotificationEmailJob` (`$tries=3`, `$backoff=[30,120,300]`), that's up to 7.5 minutes before Horizon marks it failed and alerts fire. With `$maxExceptions=2`, the job fails after the second consecutive throw — much faster visibility. This pairs with JOB-1: once those permanent-failure paths call `$this->fail()`, `$maxExceptions` ensures the job doesn't waste retry slots on the same deterministic error.
    - **Plain English:** Right now, when a job hits an unrecoverable error on every attempt, it keeps retrying until it exhausts its full allowance — like a smoke detector that waits 7 minutes to go off after detecting smoke. Setting a "max consecutive exceptions" limit is like telling the detector to trigger after the second puff, not after the whole room is filled.
    - **Evidence:**
        ```php
        // SendTransactionalNotificationEmailJob — representative of all audited jobs
        public int $tries = 3;
        public array $backoff = [30, 120, 300];
        // public int $maxExceptions is absent — defaults to $tries (3)
        ```
    - `[DRAFT, confidence: 0.80]`

- [ ] **#JOB-5** · P3 — `EmailSubscription` `saved` hook dispatches job outside transaction safety
    - **Where:** app/Models/Core/Notifications/EmailSubscription.php:105-112
    - **Affects:** `SyncCustomerMarketingOptInJob` may run against a rolled-back `EmailSubscription` row, wasting a queue slot.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace the `static::saved()` hook dispatch with a `dispatchAfterResponse()` call, or
        - Move the dispatch to a service-layer call after the transaction commits (e.g., in `ContactCaptureService`), or
        - Add the `AfterCommit` trait equivalent by wrapping in `DB::afterCommit(...)`.
    - **Technical:** The `saved` Eloquent event fires immediately after `save()`, which may still be inside an open database transaction. `SyncCustomerMarketingOptInJob::dispatch()` pushes the job to Redis immediately. If the transaction rolls back, the `EmailSubscription` row never exists, but the job is already queued and will process. The job's `handle()` degrades gracefully — it calls `Customer::where(...)->first()` and returns early if no customer is found. So no data corruption, but wasted queue work and a confusing no-op in logs. Laravel's `dispatchAfterResponse()` or `DB::afterCommit()` would defer the dispatch until after the transaction commits (or the response is sent).
    - **Plain English:** Imagine a clerk stamps "SEND" on a task card and drops it in the outbox while the manager is still reviewing the paperwork. If the manager rejects the paperwork, the task card is already in the mailroom. The mailroom worker looks for the related file, can't find it, and shrugs — wasted a trip. The fix is to put the task card in the outbox only after the manager signs off.
    - **Evidence:**
        ```php
        protected static function booted(): void
        {
            static::saved(function (self $subscription) {
                if ($subscription->list_key === 'marketing' && $subscription->professional_id && $subscription->email) {
                    \App\Jobs\Notifications\SyncCustomerMarketingOptInJob::dispatch(
                        (string) $subscription->professional_id,
                        (string) $subscription->email,
                        $subscription->status === 'subscribed',
                    );
                }
            });
        }
        ```
    - `[DRAFT, confidence: 0.75]`
