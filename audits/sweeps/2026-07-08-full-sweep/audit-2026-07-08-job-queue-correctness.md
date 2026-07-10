# Job/Queue Correctness Audit — 2026-07-08

**Branch:** development
**Lens:** Job/Queue Correctness: idempotency, retry safety, ShouldBeUnique, missing `$this->fail()`, retry storms
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4.5`
**Source files audited:**
- app/Jobs/Moderation/*.php (NotifyStaffOfCaseUpdateJob, NotifyOnCallStaffJob, NotifyReportedUserJob, NotifyReporterJob, SuspendSiteJob, SuspendUserJob, QuarantineMediaJob, PurgeModerationCacheJob, Concerns/HasActionLogLifecycle)
- app/Jobs/Gdpr/ExportUserDataJob.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php, CloudflareCachePurgeJob.php
- app/Jobs/Notifications/*.php (DispatchEnquiryNotificationsJob, SendEnquiryNotificationJob, SendEnquiryConfirmationJob, SendTransactionalNotificationEmailJob, SendStaffBroadcastEmailsJob, SendStaffBroadcastEmailToSubscriberJob, SendSubscriptionConfirmationJob, SendFeedbackEmailJob, SyncCustomerMarketingOptInJob)
- app/Jobs/ProcessImageVariantsJob.php, ProcessVideoVariantsJob.php, ProcessLogoVariantsJob.php, DeleteMediaArtifactsJob.php
- app/Jobs/Cache/WarmPublicSiteCacheJob.php, AggregateCacheMetricsJob.php
- app/Jobs/Analytics/RecordAnalyticsEventJob.php
- app/Jobs/Account/SendAccountDeletionRequestMailJob.php
- app/Jobs/Streaming/CheckStreamingLiveStatusJob.php
- app/Jobs/Platforms/*.php (RefreshConnectionJob, EnrichLinkCardJob, DeleteMirroredMediaJob, InstagramConnectJob, GoogleBusinessEnrichJob, MenuFetchJob)
- app/Jobs/Design/*.php (AnalyzePreviousWebsiteJob, ResolveDesignPresetsJob, AnalyzeConnectionWebsitesJob)
- config/horizon.php, config/queue.php
- supabase/migrations/20260528000000_create_moderation_schema.sql
- tests/Feature/Moderation/NotifyStaffOfCaseUpdateJobTest.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 0 complete
- P3 Low: 0 of 0 complete

---

## P1 — Fix before pilot launch

- [ ] **#JOB-1** · P1 — `NotifyStaffOfCaseUpdateJob` re-sends the full staff alert on retry with no idempotency guard
    - **Where:** app/Jobs/Moderation/NotifyStaffOfCaseUpdateJob.php:58-81
    - **Affects:** Admin-role staff (`PartnaStaff::ROLE_ADMIN`) who receive moderation-case threshold alerts; noisy/confusing duplicate alerts during an active moderation incident (CSAM/report escalations are exactly when this job fires).
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a per-threshold idempotency check at the top of `handle()`, mirroring the pattern already used in `DispatchEnquiryNotificationsJob` (same file layout: no durable status column, cache-based check-then-set): `Cache::has("moderation:case-notified:{$this->caseId}:{$case->signal_count}")` → return early; after `Notification::send()` succeeds, `Cache::put(...)` the same key for ~24h.
        - Do NOT mirror `HasActionLogLifecycle`/`markDispatched`/`markCompleted` here — that trait requires an `actionLogId` tied to a `moderation.decisions` row (`action_log.decision_id` is `NOT NULL`, per `supabase/migrations/20260528000000_create_moderation_schema.sql:134,145`), but this job fires on a *signal_count threshold crossing*, before any decision exists. Retrofitting an action-log row here is the wrong shape; the cache-key approach used by `DispatchEnquiryNotificationsJob` is the correct sibling to copy.
        - Add a regression test to `tests/Feature/Moderation/NotifyStaffOfCaseUpdateJobTest.php` that calls `->handle()` twice for the same case and asserts `Notification::assertSentToTimes($staff, CaseCreatedStaffNotification::class, 1)` — the current test file only exercises single-invocation behavior.
    - **Technical:** `NotifyStaffOfCaseUpdateJob` implements `ShouldBeUnique` with `uniqueId()` scoped to `caseId:signalCount` (correctly fixing a prior concurrent-dispatch gap — see the already-resolved `audits/archive/codebase-full-sweep-2026-06-13` JOB-8). However, `ShouldBeUnique` only prevents a *second dispatch* with the same key while the first is in flight — it does not make the job's own retry safe. When `handle()` throws (e.g. a transient failure inside `Notification::send()` after reaching some recipients), Laravel's Worker releases the job for retry without going through the Bus dispatcher's uniqueness check at all, so the retry re-executes `handle()` end-to-end: it re-loads the case, re-confirms the threshold, re-fetches all admin staff, and calls `Notification::send()` again for every recipient — including ones already notified. Three sibling jobs in the same directory (`NotifyOnCallStaffJob`, `NotifyReportedUserJob`, `NotifyReporterJob`) all guard exactly this failure mode with `if ($entry->status === 'completed') { return; }` before sending, confirming this is a recognized, load-bearing invariant elsewhere in the codebase that was simply omitted here — likely because this job predates the others or was never retrofitted when `ActionLogEntry` tracking was added for enforcement jobs.
    - **Plain English:** When a moderation case crosses a report threshold (say, 3 reports), the system emails an alert to admin staff. If a small network hiccup happens partway through sending that alert, the system automatically tries again — but right now it has no way of remembering "I already told everyone about this," so the retry sends the exact same alert to everyone a second (or third) time. It's like a fire alarm that, after a brief power flicker, doesn't know it already sounded and rings again for the same fire — annoying and confusing during exactly the moment (an active moderation incident) when staff need clear, non-duplicated signal.
    - **Evidence:**
        ```php
        public function handle(): void
        {
            $thresholds = config('partna.moderation.reporting.staff_notify_thresholds', [1, 3, 5, 10]);

            $case = ModerationCase::query()->find($this->caseId);
            if ($case === null) {
                return;
            }

            if (! in_array($case->signal_count, $thresholds, strict: true)) {
                return;
            }

            // Route to all admin-role staff. No is_on_call filter (column doesn't exist).
            $oncall = PartnaStaff::query()
                ->where('role', PartnaStaff::ROLE_ADMIN)
                ->get();

            if ($oncall->isEmpty()) {
                return;
            }

            Notification::send($oncall, new CaseCreatedStaffNotification($case));
        }
        ```

## Suggested Bundled Sessions

None — the single surviving finding is a self-contained one-file fix.

## Standalone — do NOT bundle

- **JOB-1 — `NotifyStaffOfCaseUpdateJob` retry idempotency** · touches a moderation/trust-and-safety notification path; run alone with its own regression test rather than folded into an unrelated bundle.
