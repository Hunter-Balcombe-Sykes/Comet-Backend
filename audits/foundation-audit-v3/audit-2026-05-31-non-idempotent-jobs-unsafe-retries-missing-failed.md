Good. Verification complete:
- 10 jobs confirmed without `failed()` (5 in Moderation, RecordAnalyticsEventJob, DispatchEnquiryNotificationsJob — matching QUEUE-1/2/3)
- 3 jobs confirmed with `failed()` but missing `report($e)` (ProcessImageVariantsJob, ProcessVideoVariantsJob, ExportUserDataJob — matching QUEUE-4/5)
- Moderation grep confirms: `NotifyReportedUserJob`, `NotifyReporterJob`, `NotifyStaffOfCaseUpdateJob` have NO queue assignment; `NotifyOnCallStaffJob` and enforcement jobs already use `moderation_high` — confirming QUEUE-7
- `NotifyOnCallStaffJob` IS on `moderation_high` — DeepSeek's QUEUE-2 evidence omitted this but the core claim (no `failed()`) is correct

Key adjudication decisions:
1. **QUEUE-2 re-tiered P1→P2**: These are notification jobs (enforcement already happened in separate jobs); CSAM pipeline is dormant per MEMORY.md; consequence is observability/audit gap, not enforcement failure.
2. **QUEUE-6 fix rewritten**: DeepSeek's proposed fix is architecturally impossible — the DB stores only `deletion_token_hash`, so you cannot reconstruct the raw token from the DB to form the URL. A correct fix pre-computes the URL+hash pair before dispatch.
3. **QUEUE-3 double-dispatch concern dropped**: `DispatchEnquiryNotificationsJob` leaf jobs (`SendEnquiryNotificationJob`, `SendEnquiryConfirmationJob`) both have `lockForUpdate` + `email_sent_at` idempotency guards. Coordinator retry does not double-notify.

`★ Insight ─────────────────────────────────────`
Laravel's `failed()` method and `report()` serve distinct purposes that are often confused: `failed()` is the *structural* hook called after retry exhaustion — it runs cleanup and state transitions. `report()` is the *observability* bridge to Nightwatch's exception aggregator. Having `failed()` without `report()` means you get local cleanup but no external alerting. Having neither means silent failure and a permanently-stuck audit trail.
`─────────────────────────────────────────────────`

---

# Queue & Job Lifecycle Audit — 2026-05-31

**Branch:** development
**Lens:** Non-idempotent jobs, unsafe retries, missing failed() handlers, wrong queue lane, unbounded backoff, missing report() in failure paths, at-least-once duplicate delivery, serialized PII in job payloads
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Jobs/Account/SendAccountDeletionRequestMailJob.php
- app/Jobs/Analytics/RecordAnalyticsEventJob.php
- app/Jobs/Cache/AggregateCacheMetricsJob.php
- app/Jobs/Cache/WarmPublicSiteCacheJob.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Jobs/Cloudflare/RetireSubdomainFromKvJob.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Concerns/HasCloudflareRetryPolicy.php
- app/Jobs/DeleteMediaArtifactsJob.php
- app/Jobs/Gdpr/ExportUserDataJob.php
- app/Jobs/Moderation/NotifyOnCallStaffJob.php
- app/Jobs/Moderation/NotifyReportedUserJob.php
- app/Jobs/Moderation/NotifyReporterJob.php
- app/Jobs/Moderation/NotifyStaffOfCaseUpdateJob.php
- app/Jobs/Moderation/PurgeModerationCacheJob.php
- app/Jobs/Moderation/QuarantineMediaJob.php
- app/Jobs/Moderation/SuspendSiteJob.php
- app/Jobs/Moderation/SuspendUserJob.php
- app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php
- app/Jobs/Notifications/SendEnquiryConfirmationJob.php
- app/Jobs/Notifications/SendEnquiryNotificationJob.php
- app/Jobs/Notifications/SendFeedbackEmailJob.php
- app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php
- app/Jobs/Notifications/SendStaffBroadcastEmailToSubscriberJob.php
- app/Jobs/Notifications/SendSubscriptionConfirmationJob.php
- app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php
- app/Jobs/Notifications/SyncCustomerMarketingOptInJob.php
- app/Jobs/ProcessImageVariantsJob.php
- app/Jobs/ProcessVideoVariantsJob.php
- app/Jobs/Streaming/CheckStreamingLiveStatusJob.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 7 complete

---

## P1 — Fix before pilot launch

- [ ] **#QUEUE-1** · P1 — 4 moderation enforcement jobs are missing `failed()` handlers
    - **Where:** app/Jobs/Moderation/SuspendSiteJob.php, app/Jobs/Moderation/SuspendUserJob.php, app/Jobs/Moderation/QuarantineMediaJob.php, app/Jobs/Moderation/PurgeModerationCacheJob.php
    - **Affects:** All moderation enforcement outcomes — user suspensions/bans, site hiding, media quarantines. When retry-exhaustion occurs, the enforcement action may not have completed, the audit log row is stuck at `dispatched` indefinitely, and no Nightwatch exception event fires. A banned user could remain `active` with no alert.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `failed(Throwable $e): void` method to each of the 4 jobs that calls `report($e)` first.
        - In `SuspendSiteJob::failed()` and `SuspendUserJob::failed()`: stamp the `ActionLogEntry` row to `failed` via `ActionLogEntry::query()->where('id', $this->actionLogId)->update(['status' => 'failed', 'failed_at' => now()])`. Include `Log::error()` with `action_log_id`, `case_id`, and `$e->getMessage()`.
        - In `QuarantineMediaJob::failed()` and `PurgeModerationCacheJob::failed()`: same `ActionLogEntry` stamp + `report($e)` + structured log.
        - Do not attempt to re-run the enforcement action in `failed()` — that belongs in a staff recovery workflow, not automatic retry logic.
    - **Technical:** Laravel's queue worker invokes `failed()` after `$tries` are exhausted or when `$this->fail($e)` is called explicitly. All four jobs run `entry.update(['status' => 'dispatched', ...])` at the start of `handle()` but have no corresponding terminal-state write on permanent failure. Without `failed()`, the `ActionLogEntry` row remains in `dispatched` status forever — audit views show the enforcement as "in progress" when it may never complete. Additionally, without `report($e)`, Nightwatch receives no exception event; the only signal is the Horizon failed-jobs counter, which requires active polling. All four jobs already use `$this->queue = 'moderation_high'` (confirmed by grep), so they run on an isolated queue; the gap is purely in the terminal-failure path.
    - **Plain English:** These four jobs are the "lock the door" operations — they suspend accounts, hide sites, and quarantine flagged content. If any of them fails permanently (e.g., a database timeout that outlasts all three retries), the audit log still reads "in progress" and nobody gets paged. It's like a security guard receiving an alarm, failing to respond, and the dispatch board continuing to show the alarm as "being handled." Adding a `failed()` method marks the alarm as missed and pages the team.
    - **Evidence:**
        ```php
        // SuspendSiteJob.php — representative; SuspendUserJob, QuarantineMediaJob,
        // PurgeModerationCacheJob follow the identical structure.
        class SuspendSiteJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;

            public array $backoff = [10, 30, 60];

            public int $timeout = 60;

            public function __construct(
                public readonly string $actionLogId,
                public readonly string $caseId,
            ) {
                $this->queue = 'moderation_high';
            }

            public function handle(): void
            {
                DB::connection('pgsql')->transaction(function () {
                    // ... marks entry 'dispatched', applies enforcement, marks 'completed'
                });
            }
            // No failed() method — terminal failure is invisible to Nightwatch
            // and leaves ActionLogEntry.status stuck at 'dispatched'.
        }
        ```

---

## P2 — Should fix

- [ ] **#QUEUE-5** · P2 — `ProcessImageVariantsJob::failed()` and `ProcessVideoVariantsJob::failed()` do not call `report($e)`
    - **Where:** app/Jobs/ProcessImageVariantsJob.php (failed() method), app/Jobs/ProcessVideoVariantsJob.php (failed() method)
    - **Affects:** Media processing observability. A permanently failed image or video job correctly marks the `SiteMedia` row as `PROCESSING_STATE_FAILED` and cleans up R2 artifacts — but creates no Nightwatch exception event.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `report($e);` as the first line of `failed()` in both jobs.
        - No other changes needed — the existing `markFailed()` and `cleanupR2Artifacts()` calls are correct.
    - **Technical:** Both `failed()` handlers perform correct local recovery but are invisible to Nightwatch. Every other job in the codebase that has a `failed()` method also calls `report($e)` (confirmed: `WarmPublicSiteCacheJob`, `CloudflareCachePurgeJob`, `DeleteMediaArtifactsJob`, `SendEnquiryNotificationJob`, etc.). Without `report($e)`, a class-wide failure mode — a codec the variant service can't handle, a storage disk outage — won't surface as an aggregated exception trend. The structured logging in `handle()` covers per-attempt failures; `report($e)` in `failed()` covers the terminal signal.
    - **Plain English:** When an image or video fails to process after all retries, the database correctly says "failed" and the orphaned files are cleaned up. But the engineering team's error dashboard stays quiet — nobody is alerted, and a systemic problem (e.g., a codec your variant service can't handle) would only be discovered when users start complaining. Adding one line connects the "check engine" light to the team's monitoring system.
    - **Evidence:**
        ```php
        // ProcessImageVariantsJob.php
        public function failed(Throwable $e): void
        {
            $this->markFailed($e->getMessage());
            $this->cleanupR2Artifacts();
            // report($e) missing — no Nightwatch exception event on terminal failure
        }

        // ProcessVideoVariantsJob.php — identical omission
        public function failed(Throwable $e): void
        {
            $this->markFailed($e->getMessage());
            $this->cleanupR2Artifacts();
        }
        ```

- [ ] **#QUEUE-4** · P2 — `ExportUserDataJob::failed()` does not call `report($e)`
    - **Where:** app/Jobs/Gdpr/ExportUserDataJob.php (`failed()` method)
    - **Affects:** GDPR data export observability. A permanently failed export marks the `DataExportAudit` row as `failed` (visible in the GDPR audit UI) but generates no Nightwatch exception event — ops staff won't see it in their alert dashboard.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `report($e);` as the first line of the `failed()` method, before the `DataExportAudit::find()` call.
    - **Technical:** The `failed()` handler correctly transitions the audit row state, which satisfies the GDPR audit trail requirement. The gap is Nightwatch observability: without `report($e)`, a permanent failure is only discoverable by manually querying the `DataExportAudit` table or checking Horizon's failed-jobs list. Every peer job with a `failed()` method calls `report($e)` first — this job is the only outlier among the GDPR jobs. The GDPR right-of-access obligation (Privacy Act / GDPR Art. 15) makes silent permanent failure a compliance concern, not just an ops convenience.
    - **Plain English:** When a GDPR data export fails for good, the audit record correctly says "failed." But the operations team's dashboard never lights up — it's invisible unless someone manually checks the database. It's like a security camera that records a failure but never notifies the security guard. One line of code connects it to the alerting system.
    - **Evidence:**
        ```php
        public function failed(Throwable $e): void
        {
            $audit = DataExportAudit::find($this->auditId);
            if ($audit && $audit->status !== DataExportAudit::STATUS_COMPLETED) {
                $audit->markFailed('Job failed after retries: '.$e->getMessage());
            }
            // report($e) absent — no Nightwatch exception event fires on retry exhaustion
        }
        ```

- [ ] **#QUEUE-8** · P2 — `DispatchEnquiryNotificationsJob` runs on the `default` queue instead of `notifications`
    - **Where:** app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php (constructor)
    - **Affects:** Enquiry notification delivery latency. The contact-form POST returns quickly because this job is async, but the job itself competes with cache warming, Cloudflare purges, and other unrelated work on the `default` queue.
    - **Effort:** S (~0.5h)
    - **What to do:**
        - Add `$this->onQueue('notifications');` in the constructor. This matches the leaf jobs it fans out to (`SendEnquiryNotificationJob` and `SendEnquiryConfirmationJob`, both of which already use the `notifications` queue).
    - **Technical:** `DispatchEnquiryNotificationsJob` is the coordinator that calls `EnquiryNotificationDispatcher::dispatch()`, which fans out to the two leaf jobs. Those leaf jobs both use the `notifications` queue. If `default` is under load (e.g., a wave of site edits triggering `WarmPublicSiteCacheJob` and `CloudflareCachePurgeJob`), the coordinator job queues behind them, delaying the entire notification pipeline. The leaf jobs' idempotency guards (`lockForUpdate` + `email_sent_at`/`confirmation_sent_at` checks) mean retries of the coordinator are safe — there is no double-send risk.
    - **Plain English:** The coordinator job that kicks off enquiry notifications is stuck in the general checkout lane while every notification it triggers uses the express lane. During busy site-edit bursts, a visitor's contact form submission can sit waiting for unrelated cache operations to clear before anyone is notified. Moving the coordinator to the right lane is a one-line fix.
    - **Evidence:**
        ```php
        // DispatchEnquiryNotificationsJob.php — no queue assignment in constructor
        public function __construct(public readonly string $enquiryId) {}

        // Compare: SendEnquiryNotificationJob.php — explicit notifications queue
        public function __construct(
            public readonly string $enquiryId,
            public readonly string $blockId,
        ) {
            $this->onQueue('notifications');
        }
        ```

- [ ] **#QUEUE-7** · P2 — 3 moderation notification jobs run on the `default` queue instead of `notifications`
    - **Where:** app/Jobs/Moderation/NotifyReportedUserJob.php, app/Jobs/Moderation/NotifyReporterJob.php, app/Jobs/Moderation/NotifyStaffOfCaseUpdateJob.php (each constructor)
    - **Affects:** Moderation notification latency — reporter outcome emails and staff case-creation threshold alerts compete with cache warming and Cloudflare purges. A `default` queue backlog delays these notifications behind unrelated work.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `$this->onQueue('notifications');` to each job's constructor. Note: do NOT use `$this->queue = 'notifications'` — the untyped `Queueable::$queue` property approach used in enforcement jobs (to avoid a PHP 8.4 trait conflict) applies only when the trait is mixed with `HasCloudflareRetryPolicy`. Use `$this->onQueue()` here since these jobs do not use that trait.
    - **Technical:** `NotifyOnCallStaffJob` (the most critical moderation notification) already correctly uses `$this->queue = 'moderation_high'` (verified by grep). The three jobs flagged here default to the `default` queue because their constructors contain no queue assignment. The codebase standard for notification delivery is the `notifications` queue: every `SendEnquiry*`, `SendSubscriptionConfirmation*`, `SendFeedbackEmail*`, and `SendTransactionalNotification*` job uses `$this->onQueue('notifications')`. These three moderation notification jobs are the only outliers.
    - **Plain English:** These three notification jobs are using the "everyone" checkout lane while all other notification jobs use a dedicated "notifications" lane. During high-traffic periods — many site edits triggering cache and CDN operations — moderation emails to reporters and staff end up stuck behind unrelated work. Moving them to the right lane is a one-line fix per job.
    - **Evidence:**
        ```php
        // NotifyReportedUserJob.php — no queue assignment; defaults to 'default'
        public function __construct(
            public readonly string $actionLogId,
            public readonly string $caseId,
        ) {}

        // NotifyReporterJob.php — same
        public function __construct(
            public readonly string $actionLogId,
            public readonly string $caseId,
        ) {}

        // NotifyStaffOfCaseUpdateJob.php — same
        public function __construct(public readonly string $caseId) {}
        ```

- [ ] **#QUEUE-6** · P2 — Raw deletion token serialized into Redis job payload
    - **Where:** app/Jobs/Account/SendAccountDeletionRequestMailJob.php (constructor and `handle()`)
    - **Affects:** Account deletion token confidentiality. The raw bearer token — which, if held, allows confirming an account deletion — is stored in Redis for the duration of the job lifecycle plus any Horizon job snapshot retention.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - In `AccountDeletionService::request()` (where the job is dispatched), pre-compute both `$confirmationUrl` (the full `{frontend_url}/account/deletion/confirm?token={rawToken}`) and `$tokenHash` (`hash('sha256', $rawToken)`).
        - Change the job constructor to accept `string $confirmationUrl` and `string $tokenHash` instead of `string $rawToken`.
        - In `handle()`, use `$this->confirmationUrl` directly — no derivation needed.
        - In `failed()`, use `$this->tokenHash` directly in the `WHERE` clause — no `hash()` call on a raw token needed.
        - This eliminates the raw token from the Redis payload entirely. The `$confirmationUrl` still contains the token in the URL, but it cannot be used to perform any operation other than the one it was constructed for; the raw credential is no longer derivable from the payload alone.
    - **Technical:** The `rawToken` is a bearer credential — possession allows confirming an account deletion without the user's intent. Currently it is serialized into the Redis queue payload via `SerializesModels` / constructor property promotion and lives there for up to `$backoff[2]` (300s) after the third retry. The `failed()` handler uses it solely to compute `hash('sha256', $this->rawToken)` for the WHERE clause that clears the DB row. Because the DB stores only `deletion_token_hash` (never the raw token), reconstructing the URL in `handle()` from the DB is not possible — the fix must keep a pre-computed URL in the payload. The proposed restructuring removes the raw credential without changing the attack surface of the URL itself. Note: the existing token-rotation guard in `failed()` (matching the hash before clearing the row) is correct and must be preserved.
    - **Plain English:** The one-time link that confirms deleting an account currently sits in the Redis message queue while the email is being sent. If Redis data were ever leaked — through a snapshot backup misconfiguration, a monitoring tool that logs queue contents, or a compromised Redis instance — an attacker would have a working "delete my account" link for real users. The fix is to move URL construction into the service layer before the job is dispatched, so the queue only ever contains the final URL (which is no more useful than the email itself) rather than the raw cryptographic key the URL was derived from.
    - **Evidence:**
        ```php
        // app/Jobs/Account/SendAccountDeletionRequestMailJob.php
        public function __construct(
            public readonly string $userId,
            public readonly string $rawToken,  // raw bearer credential in Redis payload
        ) {
            $this->onQueue('notifications');
            $this->afterCommit = true;
        }

        public function handle(): void
        {
            // ...
            $confirmationUrl = rtrim((string) config('app.frontend_url'), '/')
                .'/account/deletion/confirm?token='.$this->rawToken;
            // ...
        }

        public function failed(\Throwable $e): void
        {
            report($e);
            $tokenHash = hash('sha256', $this->rawToken);  // rawToken needed only for this hash
            $rowsCleared = DB::connection('pgsql')
                ->table('core.users')
                ->where('id', $this->userId)
                ->where('deletion_token_hash', $tokenHash)
                ->update([...]);
        }
        ```

- [ ] **#QUEUE-3** · P2 — `RecordAnalyticsEventJob` and `DispatchEnquiryNotificationsJob` are missing `failed()` handlers
    - **Where:** app/Jobs/Analytics/RecordAnalyticsEventJob.php, app/Jobs/Notifications/DispatchEnquiryNotificationsJob.php
    - **Affects:** Analytics event durability and enquiry notification pipeline observability. A permanently failed `RecordAnalyticsEventJob` silently drops a page-view or subscription event with no Nightwatch alert; a permanently failed `DispatchEnquiryNotificationsJob` means no enquiry notification is ever dispatched for that submission.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `failed(Throwable $e): void` method to both jobs.
        - `RecordAnalyticsEventJob::failed()`: call `report($e)` and log structured context including `$this->payload['user_id']` (safe — UUIDs only, no PII) and `$this->payload['event_type']`.
        - `DispatchEnquiryNotificationsJob::failed()`: call `report($e)` and log `$this->enquiryId` and `$e->getMessage()`.
    - **Technical:** `RecordAnalyticsEventJob` uses `insertOrIgnore` on a minted PK for at-least-once idempotency — a retry never double-counts a page-view. However, a permanent write failure (DB schema mismatch, disk full on the analytics schema) is indistinguishable from success in the Horizon dashboard without `failed()`. `DispatchEnquiryNotificationsJob` fans out to `SendEnquiryNotificationJob` and `SendEnquiryConfirmationJob` which both have idempotency guards — a coordinator retry does not double-notify. But if the coordinator itself is permanently dropped (e.g., the `Block` query takes too long and times out on all retries), the professional never receives the enquiry email. Neither job uses a high-stakes queue (`RecordAnalyticsEventJob` is on `analytics`, `DispatchEnquiryNotificationsJob` defaults to `default`) but both affect user-visible outcomes when they fail.
    - **Plain English:** Two "fire and forget" jobs have no smoke detector. If the analytics recorder hits a permanent database error, the event vanishes without a trace. If the enquiry notification coordinator fails permanently, the professional never gets the contact email and nobody is paged. Adding failure handlers is like wiring these jobs to the operations dashboard so the team sees when they break.
    - **Evidence:**
        ```php
        // RecordAnalyticsEventJob.php — no failed() method
        class RecordAnalyticsEventJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;

            public int $backoff = 10;

            public int $timeout = 30;

            public function __construct(public readonly array $payload)
            {
                $this->onQueue((string) config('partna.analytics_queue.name', 'analytics'));
            }
            // handle() exists; failed() absent
        }

        // DispatchEnquiryNotificationsJob.php — no failed() method, no queue assignment
        class DispatchEnquiryNotificationsJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;

            public array $backoff = [30, 90, 180];

            public int $timeout = 30;

            public function __construct(public readonly string $enquiryId) {}
            // handle() exists; failed() absent
        }
        ```

- [ ] **#QUEUE-2** · P2 — 4 moderation notification jobs are missing `failed()` handlers
    - **Where:** app/Jobs/Moderation/NotifyOnCallStaffJob.php, app/Jobs/Moderation/NotifyReportedUserJob.php, app/Jobs/Moderation/NotifyReporterJob.php, app/Jobs/Moderation/NotifyStaffOfCaseUpdateJob.php
    - **Affects:** Moderation notification observability and audit trail consistency. A permanently failed notification job leaves `ActionLogEntry.status` stuck at `dispatched` and fires no Nightwatch exception — the moderation dispatch ledger shows "in progress" indefinitely for a notification that will never be delivered.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a `failed(Throwable $e): void` method to all 4 jobs that calls `report($e)` first.
        - In `NotifyOnCallStaffJob::failed()` and `NotifyReportedUserJob::failed()`: stamp the `ActionLogEntry` to `failed` — `ActionLogEntry::query()->where('id', $this->actionLogId)->update(['status' => 'failed', 'failed_at' => now()])`. Include a structured `Log::error()` with `action_log_id`, `case_id`, and `$e->getMessage()`.
        - In `NotifyReporterJob::failed()`: same audit stamp — it receives an `$actionLogId` constructor argument.
        - In `NotifyStaffOfCaseUpdateJob::failed()`: call `report($e)` and log `$this->caseId`. This job has no `actionLogId` (it is case-level, not action-level) so no audit row needs updating.
    - **Technical:** The enforcement actions (SuspendSite, SuspendUser, QuarantineMedia, PurgeModerationCache) are a separate job group that already runs first; these notification jobs are downstream and do not affect whether the enforcement succeeds. The consequence of permanent failure here is therefore not enforcement bypass — it is audit trail corruption (`ActionLogEntry` stuck at `dispatched`) and missing case-escalation alerts for staff. `NotifyOnCallStaffJob` already correctly uses `$this->queue = 'moderation_high'`; the other three default to `default` (addressed separately in QUEUE-7). Note: the CSAM auto-action path through `NotifyOnCallStaffJob` is currently dormant (CSAM pipeline removed 2026-05-29) — the active risk is case escalation alerts and the audit trail gap.
    - **Plain English:** After an account is suspended or content is quarantined, a second wave of notification jobs alerts the affected users and staff. If any of those notification jobs fails permanently today, the audit log still shows "in progress" forever — staff reviewing the moderation ledger have no way to know the alert was never sent. Adding failure handlers closes this gap: the ledger accurately shows "failed" and the team is paged to follow up manually.
    - **Evidence:**
        ```php
        // NotifyReportedUserJob.php — representative; all 4 jobs share this pattern
        class NotifyReportedUserJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;

            public array $backoff = [10, 30, 60];

            public int $timeout = 60;

            public function __construct(
                public readonly string $actionLogId,
                public readonly string $caseId,
            ) {}

            public function handle(): void
            {
                $entry = ActionLogEntry::query()->findOrFail($this->actionLogId);
                $entry->update(['status' => 'dispatched', 'dispatched_at' => now(), ...]);
                // ... notification delivery
                $entry->update(['status' => 'completed', 'completed_at' => now()]);
                // No failed() — if retries exhaust, entry stays at 'dispatched' forever
            }
        }
        ```

`★ Insight ─────────────────────────────────────`
The `$this->queue = 'moderation_high'` vs `$this->onQueue('notifications')` pattern difference is intentional, not inconsistent: PHP 8.4 introduced a conflict between typed properties and Queueable's untyped `$queue` property when certain traits are combined. The enforcement jobs work around this with direct property assignment; notification jobs use `onQueue()` since they don't mix the conflicting traits. QUEUE-7's fix should use `onQueue()` for the three notification jobs rather than copying the direct property pattern.
`─────────────────────────────────────────────────`
