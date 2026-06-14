
<!-- ═══ LENS: job-queue-correctness | CHUNK: jobs ═══ -->

- [ ] **JOB-1** · P1 — `DispatchEnquiryNotificationsJob` has no idempotency guard; retries produce duplicate notifications
    - **Where:** app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php:33-55
    - **Affects:** Professionals receiving contact-form enquiry notifications — duplicate emails on any retry.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add an `email_sent_at` / `notifications_dispatched_at` column to the `enquiries` table (mirroring `SendEnquiryNotificationJob`'s pattern) and check it atomically with `lockForUpdate` before dispatching.
        - Alternatively, track a dedup key in Redis/Cache so the `EnquiryNotificationDispatcher` can skip already-processed enquiries.
    - **Technical:** `DispatchEnquiryNotificationsJob` has `$tries = 3` and calls `$dispatcher->dispatch($enquiry, $block)` with no prior existence check. Its sibling `SendEnquiryNotificationJob` uses `lockForUpdate` + `email_sent_at` stamp to guarantee at-most-once delivery — this job lacks any equivalent. A Horizon retry after a partial dispatch (mail sent but job crashed before returning) will re-deliver. Category 1.
    - **Plain English:** Think of this like a mailroom that puts the same letter in the outbox every time someone bumps the table. If the worker stumbles mid-task, the letter goes out, but the worker doesn't remember it already sent — so when it retries, the recipient gets a second copy. The fix is to stamp each enquiry "notifications sent" before walking away, so a retry sees the stamp and stops.
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
    - `[DRAFT, confidence: 0.85]`

- [ ] **JOB-2** · P2 — `RecordAnalyticsEventJob` serializes full event payload to Redis — potential PII leak
    - **Where:** app/Jobs/Analytics/RecordAnalyticsEventJob.php:37-40
    - **Affects:** GDPR compliance — analytics events may contain visitor IPs, user agents, or other personal data sitting in Redis queue storage.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Audit `AnalyticsEvent::toArray()` to confirm whether the payload includes IP addresses, user agents, or other PII.
        - If it does, strip those fields before dispatch and enrich them from the request context inside `handle()` instead, or scrub them entirely before serialization.
    - **Technical:** The constructor accepts `public readonly array $payload` — the entire `AnalyticsEvent::toArray()` output is serialized to Redis. The `SerializesModels` trait only applies to Eloquent models; raw arrays are serialized by value. If `AnalyticsEvent` carries `ip_address`, `user_agent`, or similar visitor identifiers, they land in Redis — a GDPR concern since queue storage is not designed for PII retention. Category 6.
    - **Plain English:** Imagine writing a visitor's name, IP address, and what pages they looked at onto a sticky note and leaving it on the break-room counter. That's what's happening here — the full visitor record is copied into Redis where any system with access can read it. The fix is to only put a reference number on the sticky note and look up the details when it's time to do the work.
    - **Evidence:**
        ```php
        /** @param  array<string, mixed>  $payload  AnalyticsEvent::toArray() */
        public function __construct(public readonly array $payload)
        {
            $this->onQueue((string) config('partna.analytics_queue.name', 'analytics'));
        }
        ```
    - `[DRAFT, confidence: 0.65]`

- [ ] **JOB-3** · P2 — `ExportUserDataJob` silently succeeds when audit row is deleted between dispatch and execution
    - **Where:** app/Jobs/Gdpr/ExportUserDataJob.php:58-61
    - **Affects:** GDPR right-of-access audit trail — a deleted audit row means a data export request disappeared without a trace, and Horizon shows the job as successful.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Call `$this->fail(new \RuntimeException('audit row deleted before export ran'))` instead of returning silently.
        - This ensures the failed-jobs counter increments and Nightwatch surfaces the anomaly.
    - **Technical:** When `DataExportAudit::find($this->auditId)` returns null, the job logs a warning and returns. Horizon marks the job successful; no entry appears in `failed_jobs`; Nightwatch receives no exception event. A GDPR export request whose audit row was pruned or accidentally deleted is invisible to operations. Category 2.
    - **Plain English:** If someone requests their data under GDPR, we create a paper trail. If that paper trail gets thrown away before we fulfill the request, the system shrugs and says "job done!" — even though nothing happened. The fix is to raise a flag so the team can see the request was lost and re-create it.
    - **Evidence:**
        ```php
        $audit = DataExportAudit::find($this->auditId);

        if (! $audit) {
            Log::warning('ExportUserDataJob: audit row not found', ['audit_id' => $this->auditId]);

            return;
        }
        ```
    - `[DRAFT, confidence: 0.8]`

- [ ] **JOB-4** · P2 — `InstagramConnectJob` swallows scraper failure — job succeeds but connection stays broken
    - **Where:** app/Jobs/Platforms/InstagramConnectJob.php:79-84
    - **Affects:** Professionals whose Instagram auto-connect silently fails — the connection row shows `unavailable` but operations has no alert.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - After `markFailed($connection, ...)`, call `$this->fail($e ?? new \RuntimeException('Instagram scraper returned no profile'))` so Nightwatch fires.
        - Alternatively, throw after `markFailed` to let the queue retry mechanism handle it naturally.
    - **Technical:** When `$scraper->fetchProfile()` returns null, the job calls `$this->markFailed($connection, 'apify_fetch_failed')` which updates the connection row, then returns without throwing or calling `$this->fail()`. Horizon sees a successful job. But this is a real operational failure — the Instagram connect pipeline didn't complete. Nightwatch's alerting is exception-driven; a silent return means this class of failure is invisible until a user reports it. Category 2.
    - **Plain English:** It's like a delivery driver who finds the package damaged mid-route, marks it "undeliverable" on their clipboard, and then clocks out as if the shift was normal. The customer sees no delivery, and the dispatcher never knows anything went wrong. The fix is to radio in the failure so someone can act on it.
    - **Evidence:**
        ```php
        $profile = $scraper->fetchProfile($this->username, $this->userId);

        if (! $profile) {
            $this->markFailed($connection, 'apify_fetch_failed');

            return;
        }
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **JOB-5** · P2 — `NotifyOnCallStaffJob` missing `ShouldBeUnique` — concurrent dispatches send duplicate on-call notifications
    - **Where:** app/Jobs/Moderation/NotifyOnCallStaffJob.php:28-30
    - **Affects:** On-call staff receiving duplicate CSAM/escalation alerts during an incident.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `implements ShouldBeUnique` and a `uniqueId()` method returning `'moderation-oncall:'.$this->actionLogId`.
        - Add `public int $uniqueFor = 300` to coalesce rapid re-dispatches.
    - **Technical:** The job's `handle()` calls `Notification::send($oncall, $notification)` with no dedup check. The `HasActionLogLifecycle` trait's `markDispatched` increments an attempt counter but doesn't prevent a second concurrent worker from calling `Notification::send`. Two overlapping dispatches for the same `actionLogId` send duplicate push/email notifications to all admin staff. Category 4.
    - **Plain English:** If the fire alarm button gets pressed twice in quick succession, the station sends two identical evacuation orders to every firefighter's pager. The fix is a simple gate: "only one alarm per incident, please."
    - **Evidence:**
        ```php
        class NotifyOnCallStaffJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
            use HasActionLogLifecycle;
            // No ShouldBeUnique interface
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **JOB-6** · P2 — `NotifyReportedUserJob` missing `ShouldBeUnique` — duplicate moderation notifications to reported users
    - **Where:** app/Jobs/Moderation/NotifyReportedUserJob.php:32-34
    - **Affects:** Users receiving duplicate "your content was hidden" or "your account was suspended" notifications.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `implements ShouldBeUnique` with `uniqueId()` returning `'moderation-notify-user:'.$this->actionLogId`.
        - Add `public int $uniqueFor = 300`.
    - **Technical:** Same pattern as `NotifyOnCallStaffJob` — `$user->notify($notification)` is called without any idempotency guard or duplicate-prevention. Two concurrent dispatches from the moderation pipeline produce duplicate user-facing notifications, which is a poor experience for someone already receiving a moderation action. Category 4, also category 1.
    - **Plain English:** A user whose post was hidden gets an email saying so. If the system hiccups and processes the same decision twice, they get two identical emails — turning a one-time notification into a confusing, repetitive experience. The fix ensures only one copy of each moderation notice reaches the user.
    - **Evidence:**
        ```php
        class NotifyReportedUserJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
            use HasActionLogLifecycle;
            // No ShouldBeUnique interface
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **JOB-7** · P2 — `NotifyReporterJob` missing `ShouldBeUnique` — duplicate outcome notifications to reporters
    - **Where:** app/Jobs/Moderation/NotifyReporterJob.php:31-33
    - **Affects:** Users who reported content — duplicate "we reviewed your report" emails.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `implements ShouldBeUnique` with `uniqueId()` returning `'moderation-notify-reporter:'.$this->actionLogId`.
        - Add `public int $uniqueFor = 300`.
    - **Technical:** Iterates over `$reporters` and calls `Notification::route('mail', $email)->notify(...)`. No dedup key or idempotency stamp. Two concurrent dispatches for the same decision will email every reporter twice. Category 4 + category 1.
    - **Plain English:** Same as the user-notification finding but for the person who filed the report. They get two "thanks, we looked into it" emails instead of one. It erodes trust in the reporting system.
    - **Evidence:**
        ```php
        class NotifyReporterJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
            use HasActionLogLifecycle;
            // No ShouldBeUnique interface
        ```
    - `[DRAFT, confidence: 0.9]`

- [ ] **JOB-8** · P2 — `NotifyStaffOfCaseUpdateJob` missing `ShouldBeUnique` — duplicate staff alerts on case creation
    - **Where:** app/Jobs/Moderation/NotifyStaffOfCaseUpdateJob.php:33-35
    - **Affects:** Admin staff receiving duplicate "new case created" notifications at threshold signal counts.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `implements ShouldBeUnique` with `uniqueId()` returning `'moderation-staff-case:'.$this->caseId`.
        - Add `public int $uniqueFor = 300`.
    - **Technical:** Dispatched whenever a case is created or its `signal_count` grows. The `handle()` checks `in_array($case->signal_count, $thresholds)` but fetches the case fresh — so a retry of the same dispatch sees the same `signal_count` and re-sends. No `ShouldBeUnique` means two rapid dispatches for the same case produce two identical staff notifications. Category 4.
    - **Plain English:** When a case hits 3 reports, staff get notified. If the system accidentally queues that notification twice, staff see two identical alerts and wonder if there are two separate cases. The fix ensures each case only triggers one notification per threshold.
    - **Evidence:**
        ```php
        class NotifyStaffOfCaseUpdateJob implements ShouldQueue, ShouldQueueAfterCommit
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
            // No ShouldBeUnique interface
        ```
    - `[DRAFT, confidence: 0.85]`

- [ ] **JOB-9** · P2 — `CheckStreamingLiveStatusJob` missing `WithoutOverlapping` — overlapping scheduled runs race on live-status writes
    - **Where:** app/Jobs/Streaming/CheckStreamingLiveStatusJob.php:23-25
    - **Affects:** Streaming status accuracy on public sitepages — two concurrent poll cycles writing live-status for the same streamer at the same time.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `WithoutOverlapping` middleware via `->withoutOverlapping('streaming-live-status', 120)` in the scheduler, or implement the `ShouldBeUnique` interface with a 120s window.
    - **Technical:** Scheduled every 2 minutes via `routes/console.php`. The job's `$timeout = 90` leaves a 30s gap between runs in the ideal case. But if a run takes longer than 120s (e.g., Twitch API slowdown), the next scheduled dispatch starts while the previous one is still running — two instances iterate the same blocks and write live-status to the same cache keys concurrently. Category 4.
    - **Plain English:** This job checks who's streaming every two minutes. If one check takes longer than expected — say Twitch is slow to respond — the next check starts before the first finishes, like two people trying to update the same whiteboard at the same time. The fix is a "do not disturb" sign that prevents overlap.
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
    - `[DRAFT, confidence: 0.7]`

- [ ] **JOB-10** · P2 — `SendFeedbackEmailJob` silently discards job when feedback row is deleted
    - **Where:** app/Jobs/Notifications/SendFeedbackEmailJob.php:59-63
    - **Affects:** Operations visibility — a deleted feedback row means the feedback was lost, but Horizon shows success.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Call `$this->fail(new \RuntimeException('feedback row deleted before email could be sent'))` so the failed-jobs counter and Nightwatch reflect the anomaly.
    - **Technical:** When `Feedback::query()->find($this->feedbackId)` returns null, the job logs a warning and returns. Same pattern as JOB-3 — the job is silently marked successful when it actually did nothing. Feedback from the in-app form disappearing between dispatch and execution is an operational anomaly worth surfacing. Category 2.
    - **Plain English:** A user submits feedback through the app. Before the system emails the team about it, the feedback record gets deleted. The system shrugs and says "all done" — the team never knows feedback was submitted and lost.
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
    - `[DRAFT, confidence: 0.8]`

- [ ] **JOB-11** · P3 — `ProcessImageVariantsJob` / `ProcessVideoVariantsJob` use flat backoff where exponential is warranted
    - **Where:** app/Jobs/ProcessImageVariantsJob.php:40 and app/Jobs/ProcessVideoVariantsJob.php:41
    - **Affects:** Recovery time during R2 object-storage degradation — flat retries hammer the storage layer instead of backing off.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Replace `public int $backoff = 30` with `public array $backoff = [30, 120, 300]` (image) and `public int $backoff = 60` with `[60, 300, 900]` (video).
    - **Technical:** Both jobs retry on transient storage failures (R2 unavailability). A flat backoff means 3 rapid retries at the same interval, which is fine for quick hiccups but counterproductive during sustained degradation — it adds load to an already-struggling storage layer. Exponential backoff gives R2 breathing room. The `DeleteMediaArtifactsJob` already uses exponential `[60, 300, 900]` for the same R2 dependency. Category 3.
    - **Plain English:** If the file-storage system is having a rough minute, these jobs keep knocking on its door every 30 seconds — like a impatient customer ringing the bell faster instead of waiting. The videos cleanup job already waits longer between attempts. The image and video processing jobs should do the same.
    - **Evidence:**
        ```php
        // ProcessImageVariantsJob
        public int $tries = 3;
        public int $backoff = 30;
        ```
        ```php
        // ProcessVideoVariantsJob
        public int $tries = 2;
        public int $backoff = 60;
        ```
    - `[DRAFT, confidence: 0.75]`

- [ ] **JOB-12** · P3 — `AggregateCacheMetricsJob` uses flat backoff and is dispatched to `default` queue without a uniqueness guard
    - **Where:** app/Jobs/Cache/AggregateCacheMetricsJob.php:32-37
    - **Affects:** Minor — duplicate log entries and potential double-reporting of SLO violations if the hourly scheduler misfires.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `implements ShouldBeUnique` with `uniqueId()` returning the bucket string (e.g., `'cache-metrics:'.$bucket`).
        - Add `$maxExceptions = 2` to short-circuit permanent Redis read failures.
    - **Technical:** Scheduled hourly. With `$tries = 3` and `$backoff = 30` (flat), a failed Redis read retries 3 times at 30s intervals. No `ShouldBeUnique` means if the scheduler accidentally dispatches twice (rare but possible during deploys), both jobs read and report the same bucket. The SLO check calls `report()` which creates Nightwatch exception events — duplicate runs create duplicate alerts. Category 4 + 3.
    - **Plain English:** Every hour, this job checks the cache scoreboard and logs the stats. If it gets queued twice by accident, it reads the same scoreboard twice and potentially sounds the same alarm twice. The fix ensures only one copy runs per hour.
    - **Evidence:**
        ```php
        class AggregateCacheMetricsJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;
            public int $backoff = 30;
            // No ShouldBeUnique, no maxExceptions
        ```
    - `[DRAFT, confidence: 0.6]`
