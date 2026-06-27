# Job/Queue Correctness Audit — 2026-06-13

**Branch:** development
**Lens:** Job/Queue Correctness — idempotency, retry safety, ShouldBeUnique, missing `$this->fail()`, retry storms
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-5`
**Source files audited:**
- `app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php`
- `app/Jobs/Notifications/SendEnquiryNotificationJob.php`
- `app/Jobs/Notifications/SendFeedbackEmailJob.php`
- `app/Jobs/Gdpr/ExportUserDataJob.php`
- `app/Jobs/Platforms/InstagramConnectJob.php`
- `app/Jobs/Moderation/NotifyOnCallStaffJob.php`
- `app/Jobs/Moderation/NotifyReportedUserJob.php`
- `app/Jobs/Moderation/NotifyReporterJob.php`
- `app/Jobs/Moderation/NotifyStaffOfCaseUpdateJob.php`
- `app/Jobs/Moderation/Concerns/HasActionLogLifecycle.php`
- `app/Jobs/Streaming/CheckStreamingLiveStatusJob.php`
- `app/Jobs/ProcessImageVariantsJob.php`
- `app/Jobs/ProcessVideoVariantsJob.php`
- `app/Jobs/Cache/AggregateCacheMetricsJob.php`
- `app/Jobs/Cloudflare/SyncSubdomainToKvJob.php`
- `app/Services/Notifications/EnquiryNotificationDispatcher.php`
- `app/Services/Analytics/AnalyticsEvent.php`
- `config/horizon.php`

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 8 complete
- P3 Low: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **JOB-1** · P1 — `DispatchEnquiryNotificationsJob` has no idempotency guard; retries produce duplicate notifications
    - **Where:** `app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php:33–53`
    - **Affects:** Professionals receiving contact-form enquiry notifications — duplicate in-app and email notifications on any Horizon retry.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add an `enquiry_dispatched_at` (or reuse `email_sent_at`) stamp on the `enquiries` row; in `handle()`, wrap the check-and-stamp in a `lockForUpdate` transaction before calling `$dispatcher->dispatch()`.
        - Mirror the pattern already in `SendEnquiryNotificationJob`: acquire a row-level lock, check the timestamp, stamp it while the lock is held, then dispatch outside the lock.
        - Alternatively, add a Redis idempotency key (`'enquiry-dispatched:'.$this->enquiryId`) via `Cache::add()` before calling the dispatcher, rolling it back on exception — same pattern as `SendFeedbackEmailJob`'s per-recipient key.
    - **Technical:** `DispatchEnquiryNotificationsJob` declares `$tries = 3`. Its `handle()` calls `$dispatcher->dispatch($enquiry, $block)` with no prior existence check. The `EnquiryNotificationDispatcher` fans out to every registered adapter in sequence; none of the adapters are aware of prior runs. The sibling `SendEnquiryNotificationJob` uses `lockForUpdate` + `email_sent_at` to guarantee at-most-once email delivery. No equivalent guard exists here. A Horizon retry after a partial dispatch — e.g., the in-app notification wrote to the DB but the mail adapter then threw — will fan out to all adapters again, including the in-app channel, producing a duplicate notification record. Category 1.
    - **Plain English:** The system takes a contact form submission and fans it out to multiple notification channels (the in-app bell, email, etc.). If one of those channel sends succeed but the job crashes before finishing, it retries — and the ones that already worked go out a second time. The professional gets two pings about the same enquiry. The fix is to stamp the enquiry "notifications sent" before the fan-out begins, so any retry sees the stamp and stops.
    - **Evidence:**
        ```php
        public function handle(EnquiryNotificationDispatcher $dispatcher): void
        {
            $enquiry = Enquiry::query()->find($this->enquiryId);
            if (! $enquiry) {
                return;
            }

            $block = Block::query()
                ->where('site_id', $enquiry->site_id)
                ->where('block_group', 'sections')
                ->where('block_type', 'contact')
                ->active()
                ->first();

            if (! $block) {
                return;
            }

            $dispatcher->dispatch($enquiry, $block);
        }
        ```

- [ ] **JOB-2** · P1 — `InstagramConnectJob` dispatches to a `scraping` queue not consumed by any Horizon supervisor — Instagram auto-connect is silently broken in all environments
    - **Where:** `app/Jobs/Platforms/InstagramConnectJob.php:64` / `config/horizon.php` (all three environments)
    - **Affects:** All professionals who click "Connect Instagram" — the connect pipeline starts (controller returns 202, connection row is set to `pending`), a job is dispatched, and then nothing happens. The connection stays `pending` indefinitely.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `supervisor-scraping` entry to `config/horizon.php` (or fold the `scraping` queue into `supervisor-default`) in all three environment blocks (`defaults`, `production`, `development`, `local`).
        - The supervisor must have a `timeout` ≥ 150 (the job's `$timeout`) — the current `supervisor-default` has `timeout = 60`, which would kill the job mid-Apify-scrape. A dedicated supervisor with `timeout = 180` is the safer fix.
        - Confirm the Horizon worker is redeployed after the config change.
    - **Technical:** `InstagramConnectJob::__construct` sets `$this->onQueue('scraping')`. The Horizon config defines supervisors covering: `moderation_high`, `notifications`, `mail`, `default`, `cloudflare`, `cache-warm`, `analytics`, `images`, `streaming`, `gdpr`, `videos`. The `scraping` queue appears in none of them — neither in `defaults` nor in any environment override. In development and local, `supervisor-1` explicitly lists its queues; `scraping` is absent. Jobs dispatched to an unmonitored Horizon queue land in Redis and are never dequeued. The connect flow returns 202 and the `IntegrationConnection` row is set to `last_refresh_status = 'pending'` by the controller — it will remain `pending` forever. Category 5.
    - **Plain English:** When a professional connects their Instagram account, the system hands the work to a background worker and says "done, we'll process it shortly." But the worker that should pick up this task doesn't exist — no one is listening to the queue it's assigned to. The task sits in the inbox forever and the professional's Instagram connection never completes. The fix is to configure a worker to actually listen to that inbox.
    - **Evidence:**
        ```php
        // InstagramConnectJob.php:59-65
        public function __construct(
            public readonly string $userId,
            public readonly string $username,
            public readonly string $connectionId,
        ) {
            $this->onQueue('scraping');
        }
        ```
        ```php
        // config/horizon.php — development supervisor-1 (no 'scraping' in any env):
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['moderation_high', 'notifications', 'mail', 'default', 'cloudflare', 'cache-warm', 'analytics', 'images', 'streaming'],
        ```

---

## P2 — Should fix

- [ ] **JOB-3** · P2 — `ExportUserDataJob` silently succeeds when the audit row is missing — lost GDPR request is invisible to operations
    - **Where:** `app/Jobs/Gdpr/ExportUserDataJob.php:45–51`
    - **Affects:** GDPR right-of-access compliance visibility — a data export request whose audit row was accidentally pruned or deleted produces no alert, no Nightwatch exception, and no `failed_jobs` entry.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Log::warning(...); return;` with `$this->fail(new \RuntimeException('ExportUserDataJob: audit row deleted before export ran, audit_id: '.$this->auditId))`.
        - This causes Horizon to write the job to `failed_jobs` and Nightwatch to fire an exception alert so operations can investigate and re-create the request.
    - **Technical:** `DataExportAudit::find($this->auditId)` returning `null` is logged at `warning` level and the job returns normally. Horizon marks it completed; the failed-jobs counter does not increment; Nightwatch (which alerts on exceptions and auto-detected slow routes/jobs, not on log lines) fires nothing. A GDPR right-of-access request that silently disappears is an unacceptable gap for a regulatory workflow. The job already has a robust status-guard for `COMPLETED`/`FAILED` rows (line 53) and excellent handling throughout the try/catch block; this single early-exit is the outlier. Category 2.
    - **Plain English:** Under data-protection law, if someone asks for a copy of their data we have to fulfill that request. This job does the work. But if the request paperwork gets lost before the job runs, the system says "all done!" even though nothing happened — and nobody is ever alerted. The fix is to sound an alarm so the team can see the request was lost and re-create it manually.
    - **Evidence:**
        ```php
        $audit = DataExportAudit::find($this->auditId);

        if (! $audit) {
            Log::warning('ExportUserDataJob: audit row not found', ['audit_id' => $this->auditId]);

            return;
        }
        ```

- [ ] **JOB-4** · P2 — `InstagramConnectJob` calls `markFailed()` + returns without `$this->fail()` — Horizon marks the job successful when the scraper returns no data
    - **Where:** `app/Jobs/Platforms/InstagramConnectJob.php:76–82`
    - **Affects:** Operations visibility — Horizon shows a green tick on every scraper-fail run; no Nightwatch exception fires; the failure class is invisible until a professional reports it.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - After `$this->markFailed($connection, 'apify_fetch_failed');`, either throw a new exception or call `$this->fail(new \RuntimeException('Instagram scraper returned no profile for @'.$this->username))` so Horizon records the failure.
        - The `failed()` method already does the right cleanup and `report()` call — it just never fires on this path.
    - **Technical:** When `$scraper->fetchProfile()` returns `null`, the job calls `markFailed()` (which sets `last_refresh_status = 'unavailable'` on the connection row) and then returns. No exception is thrown, so `failed(Throwable $e)` never executes, `report()` is never called, Nightwatch receives nothing, and the job appears in Horizon's completed list. A persistent scraper outage (Apify quota, Instagram block) would produce a stream of silent "successes." The `failed()` method already handles cleanup correctly — the issue is that this path bypasses it entirely. Category 2.
    - **Plain English:** If the Instagram data-fetching service returns nothing, the system quietly marks the professional's connection as "unavailable" and files the job under "completed." There's no alert, no error counter, nothing. The fix is to make it loudly fail so the on-call team can see a pattern and investigate.
    - **Evidence:**
        ```php
        $profile = $scraper->fetchProfile($this->username, $this->userId);

        if (! $profile) {
            $this->markFailed($connection, 'apify_fetch_failed');

            return;
        }
        ```

- [ ] **JOB-5** · P2 — `NotifyOnCallStaffJob` missing `ShouldBeUnique` and no completion guard — concurrent or retried dispatches send duplicate on-call alerts
    - **Where:** `app/Jobs/Moderation/NotifyOnCallStaffJob.php:20–59`
    - **Affects:** Admin staff receiving duplicate CSAM/escalation alerts during an incident — two identical pagers to every admin at the same time.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Implement `ShouldBeUnique` with `uniqueId(): string { return 'notify-oncall:'.$this->actionLogId; }` and `public int $uniqueFor = 300` to collapse concurrent dispatches.
        - Add a status check at the top of `handle()` after loading `$entry`: `if ($entry->status === 'completed') return;` — this prevents a retry after partial success from re-sending.
    - **Technical:** `HasActionLogLifecycle::markDispatched()` increments `attempts` and sets `dispatched_at = now()` unconditionally — it does not check whether the entry is already `completed`. Two concurrent workers both call `Notification::send($oncall, $notification)` because neither sees the other's `markCompleted`. Similarly, if `markCompleted` throws after `Notification::send()` succeeds, a retry repeats the notification. `ShouldBeUnique` addresses concurrent dispatch; the `completed` status check addresses retry re-send. Both are needed for full idempotency. Category 1 + 4.
    - **Plain English:** If the system accidentally queues this alert twice — or the job crashes after sending but before marking itself done — every admin gets the same urgent message twice. During a real incident that's a distraction. The fix is a "don't send this twice" gate at the start of the job.
    - **Evidence:**
        ```php
        class NotifyOnCallStaffJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
            use HasActionLogLifecycle;
            // No ShouldBeUnique; HasActionLogLifecycle::markDispatched() does not
            // check existing status before proceeding.
            public int $timeout = 30;
        ```
        ```php
        // HasActionLogLifecycle trait — no completion guard:
        protected function markDispatched(ActionLogEntry $entry): void
        {
            $entry->update([
                'status' => 'dispatched',
                'dispatched_at' => now(),
                'attempts' => $entry->attempts + 1,
            ]);
        }
        ```

- [ ] **JOB-6** · P2 — `NotifyReportedUserJob` missing `ShouldBeUnique` and no completion guard — duplicate "your content was hidden" / "account suspended" notifications to reported users
    - **Where:** `app/Jobs/Moderation/NotifyReportedUserJob.php:21–78`
    - **Affects:** Users on the receiving end of moderation actions — duplicate email/push notifications for the same moderation decision.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Implement `ShouldBeUnique` with `uniqueId(): string { return 'notify-reported-user:'.$this->actionLogId; }` and `public int $uniqueFor = 300`.
        - Add `if ($entry->status === 'completed') return;` at the top of `handle()` after loading `$entry`.
    - **Technical:** Same root cause as JOB-5 — `HasActionLogLifecycle` provides audit trail, not idempotency. `$user->notify($notification)` is called after `markDispatched` with no prior check for `status === 'completed'`. A retry after a partial success re-sends the moderation outcome to the user. A CSAM pipeline dispatch of the same action log entry by two concurrent workers would send two account-suspension emails. Category 1 + 4.
    - **Plain English:** If someone's account is suspended, they get an email explaining it. If the system hiccups and processes the same decision twice, they get two identical emails — which, during an already stressful moment, is confusing and unprofessional. The fix ensures exactly one notification reaches the user per moderation action.
    - **Evidence:**
        ```php
        class NotifyReportedUserJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
            use HasActionLogLifecycle;
            // No ShouldBeUnique; no status guard before user->notify()
            public int $timeout = 60;
        ```

- [ ] **JOB-7** · P2 — `NotifyReporterJob` missing `ShouldBeUnique` and no completion guard — duplicate "we reviewed your report" emails to reporters
    - **Where:** `app/Jobs/Moderation/NotifyReporterJob.php:19–54`
    - **Affects:** Users who submitted moderation reports — duplicate outcome email for each reporter on the case.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Implement `ShouldBeUnique` with `uniqueId(): string { return 'notify-reporter:'.$this->actionLogId; }` and `public int $uniqueFor = 300`.
        - Add `if ($entry->status === 'completed') return;` at the top of `handle()` after loading `$entry`.
    - **Technical:** `foreach ($reporters as $email) { Notification::route('mail', $email)->notify(...) }` iterates all reporters and sends with no idempotency check. The `HasActionLogLifecycle` pattern provides the same false sense of safety as JOB-5 and JOB-6. Category 1 + 4.
    - **Plain English:** When a moderation case is resolved, everyone who reported it gets an email saying "we looked into your report." A retry or double-dispatch sends every reporter two identical emails. The fix ensures only one "thank you for reporting" email goes out per case resolution.
    - **Evidence:**
        ```php
        class NotifyReporterJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
            use HasActionLogLifecycle;
            // No ShouldBeUnique; Notification::route('mail', $email)->notify() in a
            // foreach loop with no completion guard
            public int $timeout = 60;
        ```

- [ ] **JOB-8** · P2 — `NotifyStaffOfCaseUpdateJob` missing `ShouldBeUnique` — two concurrent dispatches for the same case send duplicate staff threshold alerts
    - **Where:** `app/Jobs/Moderation/NotifyStaffOfCaseUpdateJob.php:26–67`
    - **Affects:** Admin staff receiving duplicate "case hit 3 reports" notifications — double alerts create confusion about whether there are two separate cases.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Implement `ShouldBeUnique` with `uniqueId(): string { return 'staff-case-update:'.$this->caseId.':'.$this->getCaseSignalBucket(); }` — or simply `'staff-case-update:'.$this->caseId` with a short `uniqueFor` window (e.g. 60s) to coalesce burst dispatches for the same case.
        - Unlike JOB-5/6/7, this job doesn't use `HasActionLogLifecycle`, so there is no `completed_at` guard to add; `ShouldBeUnique` alone provides sufficient protection for the concurrent-dispatch scenario.
    - **Technical:** The `handle()` method re-loads the case fresh and checks `in_array($case->signal_count, $thresholds)`, which provides partial protection (a retry after `signal_count` has moved past the threshold is a no-op). However, two concurrent dispatches for the same case at the same `signal_count` both pass the threshold check and both call `Notification::send($oncall, new CaseCreatedStaffNotification($case))`. The job uses `ShouldQueueAfterCommit` to avoid phantom rows, but that does not prevent concurrent workers from both running the same job payload. Category 4.
    - **Plain English:** When a case gets reported for the third time, staff get a notification. If two workers happen to process this job at the exact same moment, staff get two identical "3 reports" alerts. The fix is to make the job lock out any twin running in the same short window.
    - **Evidence:**
        ```php
        class NotifyStaffOfCaseUpdateJob implements ShouldQueue, ShouldQueueAfterCommit
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
            // No ShouldBeUnique; threshold check at handle() time does not prevent
            // two concurrent workers from both passing it simultaneously.
            public int $tries = 3;
            public array $backoff = [10, 30, 60];
            public int $timeout = 30;
        ```

- [ ] **JOB-9** · P2 — `CheckStreamingLiveStatusJob` missing `WithoutOverlapping` — a delayed or slow run overlaps with the next scheduled dispatch, producing concurrent live-status writes
    - **Where:** `app/Jobs/Streaming/CheckStreamingLiveStatusJob.php:17–25`
    - **Affects:** Streaming live-status accuracy on public sitepages — two concurrent poll cycles writing conflicting live/offline states to the same cache keys.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Apply a `WithoutOverlapping` middleware via the scheduler: `->withoutOverlapping(120)` on the `Schedule::job(CheckStreamingLiveStatusJob::class)` call in `routes/console.php`.
        - Alternatively, implement `ShouldBeUnique` with `public int $uniqueFor = 120` (a constant unique key is correct here since this is a global poll, not per-user).
    - **Technical:** The job is scheduled every 2 minutes with `$timeout = 90`. Under normal conditions the 30-second gap prevents overlap. But if the `streaming` queue backs up (e.g., a Twitch API slowdown delays the previous run), the scheduler dispatches the next copy before the first has consumed its queued slot. `$tries = 1` means no retry amplification, but two concurrent runs still iterate the same block set and race on cache key writes. The comment in the job explicitly notes the scheduler vs. timeout gap but does not add a guard. Category 4.
    - **Plain English:** Every two minutes, this job asks Twitch and Kick "who's live right now?" and updates everyone's sitepage accordingly. If the job runs slower than normal — say because a streaming platform is lagging — the next scheduled check starts before the first finishes. Two jobs try to update the same "is streaming" status at the same time, like two assistants simultaneously editing the same spreadsheet cell. The fix is a "do not disturb" sign that prevents a second check from starting while the first is still running.
    - **Evidence:**
        ```php
        class CheckStreamingLiveStatusJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 1;
            public int $backoff = 0;
            // No ShouldBeUnique, no WithoutOverlapping
            public int $timeout = 90;
        ```

- [ ] **JOB-10** · P2 — `SendFeedbackEmailJob` silently discards the job when the feedback row is deleted — operational anomaly is invisible to Nightwatch
    - **Where:** `app/Jobs/Notifications/SendFeedbackEmailJob.php:53–59`
    - **Affects:** Operations visibility — if the `Feedback` row is deleted between dispatch and execution, the team never knows a feedback submission was lost.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `Log::warning(...); return;` with `$this->fail(new \RuntimeException('SendFeedbackEmailJob: feedback row deleted before email, feedback_id: '.$this->feedbackId))`.
        - Same fix as JOB-3 — a missing row is an anomaly worth an exception event, not a silent exit.
    - **Technical:** When `Feedback::find($this->feedbackId)` returns `null`, the job logs at `warning` and returns normally. Horizon marks it completed; Nightwatch (which alerts on thrown exceptions, not log lines) fires nothing. The rest of the job is well-designed — per-recipient Cache idempotency keys, exponential backoff (`[30, 120, 600]`), `$maxExceptions = 2` — making this early silent-exit inconsistent with the surrounding quality. Category 2.
    - **Plain English:** A professional submits feedback through the app. Before the system sends an email about it to the team, the feedback record is deleted. The system shrugs and says "job done" — nobody on the team ever knows that feedback came in and was lost. The fix is to raise an alarm so someone can investigate how a feedback record disappeared before being processed.
    - **Evidence:**
        ```php
        $feedback = Feedback::query()->with('user:id,primary_email')->find($this->feedbackId);
        if ($feedback === null) {
            Log::warning('SendFeedbackEmailJob: feedback row not found', [
                'feedback_id' => $this->feedbackId,
            ]);

            return;
        }
        ```

---

## P3 — Nice to have

- [ ] **JOB-11** · P3 — `ProcessImageVariantsJob` and `ProcessVideoVariantsJob` use flat backoff where exponential is warranted
    - **Where:** `app/Jobs/ProcessImageVariantsJob.php:35` · `app/Jobs/ProcessVideoVariantsJob.php:39`
    - **Affects:** Recovery time during R2 object-storage degradation — flat retries reapply load to an already-struggling storage layer instead of giving it room to recover.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - `ProcessImageVariantsJob`: replace `public int $backoff = 30;` with `public array $backoff = [30, 120, 300];`
        - `ProcessVideoVariantsJob`: replace `public int $backoff = 60;` with `public array $backoff = [60, 300, 900];` — matching the exponential profile already used by `DeleteMediaArtifactsJob` for the same R2 dependency.
    - **Technical:** Both jobs retry on transient R2 storage failures. A flat backoff means N attempts at the same interval — fine for quick hiccups but counter-productive during sustained degradation where hammering the storage layer prevents recovery. `ProcessImageVariantsJob` already uses a Redis in-flight lock and terminal-state guard, so exponential backoff changes only the retry interval, not correctness. `ProcessVideoVariantsJob` has the same lock pattern. Category 3.
    - **Plain English:** If the file-storage service has a rough few minutes, these jobs keep retrying at the exact same pace — like a customer ringing a bell faster when no one answers, instead of waiting a bit longer each time. The cleanup job for the same storage system already waits progressively longer between attempts. Applying the same patience here reduces stress on the storage service during an outage.
    - **Evidence:**
        ```php
        // ProcessImageVariantsJob.php
        public int $tries = 3;
        public int $backoff = 30;   // flat — same interval on every retry
        ```
        ```php
        // ProcessVideoVariantsJob.php
        public int $tries = 2;
        public int $backoff = 60;   // flat — same interval on every retry
        ```
