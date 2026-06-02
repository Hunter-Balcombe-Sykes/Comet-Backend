`★ Insight ─────────────────────────────────────`
JOBS-1 is invalidated by `CleanupStuckMediaProcessingCommand` — a fully-implemented hourly watchdog that was clearly written specifically to address the lock TTL trade-off documented in both processing jobs. The code comments in the jobs even say "A separate cleanup story ... is the right place to reconcile this" — and that story exists. DeepSeek missed it because it only looked at the job files, not the console commands or the schedule. This is a classic cross-file invariant miss.
`─────────────────────────────────────────────────`

# Jobs / Retry Correctness Audit — 2026-05-24

**Branch:** development
**Lens:** non-idempotent jobs, unsafe retries, missing failure handlers, wrong queue lane, unbounded backoff
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Jobs/ProcessImageVariantsJob.php
- app/Jobs/ProcessVideoVariantsJob.php
- app/Jobs/Gdpr/ExportProfessionalDataJob.php
- app/Jobs/DeleteMediaArtifactsJob.php
- app/Jobs/Streaming/CheckStreamingLiveStatusJob.php
- app/Jobs/Notifications/SendEnquiryNotificationJob.php
- app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php
- app/Jobs/Notifications/SendStaffBroadcastEmailToSubscriberJob.php
- app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php
- app/Jobs/Notifications/SyncCustomerMarketingOptInJob.php
- app/Jobs/Cache/WarmPublicSiteCacheJob.php
- app/Jobs/Cache/AggregateCacheMetricsJob.php
- app/Jobs/Cloudflare/CloudflareCachePurgeJob.php
- app/Jobs/Cloudflare/SyncSubdomainToKvJob.php
- app/Jobs/Cloudflare/RetireSubdomainFromKvJob.php
- app/Console/Commands/CleanupStuckMediaProcessingCommand.php
- routes/console.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 0 complete
- P2 Medium: 0 of 2 complete
- P3 Low: 0 of 2 complete

---

## P2 — Should fix

- [x] **#JOBS-1** · P2 — `DeleteMediaArtifactsJob.failed()` silently discards permanent failures — no Nightwatch alert
    - **Where:** app/Jobs/DeleteMediaArtifactsJob.php:93–100
    - **Affects:** On-call engineers. When video artifact cleanup exhausts all 3 retries, Nightwatch receives no exception event. Orphaned HLS segments on R2 go undetected until a manual audit.
    - **Effort:** S (~15 min)
    - **What to do:**
        - Add `report($e);` as the first line of `failed()` in `DeleteMediaArtifactsJob`, matching the pattern used by every other job in this codebase.
    - **Technical:** `DeleteMediaArtifactsJob::handle()` re-throws every caught exception, so `failed()` runs correctly after retries are exhausted. However, `failed()` contains only `Log::error()` — no `report($e)`. `Log::error` writes a structured log entry but does not forward to Laravel's exception handler, so Nightwatch never sees a rich exception event (stack trace, context, alerting). Every other job with a non-trivial `failed()` in this codebase — `CloudflareCachePurgeJob`, `SyncSubdomainToKvJob`, `RetireSubdomainFromKvJob`, `SendEnquiryNotificationJob`, `SyncCustomerMarketingOptInJob`, `SendTransactionalNotificationEmailJob`, `SendStaffBroadcastEmailsJob` — calls `report($e)` first. Note: `CheckStreamingLiveStatusJob` was also flagged in the draft, but its `handle()` catches and swallows all polling exceptions internally (never re-throws), so `failed()` is unreachable code for that job and the omission has no practical impact.
    - **Plain English:** When a video file's cleanup job gives up after three attempts, it writes a note in the server log — but doesn't ring the alarm. Every other job in the system rings the alarm; this one is missing its wire. Without the alarm, R2 storage accumulates orphaned video files from deleted accounts with no one noticing until a storage bill arrives.
    - **Evidence:**
        ```php
        // DeleteMediaArtifactsJob — failed()
        public function failed(Throwable $e): void
        {
            Log::error('DeleteMediaArtifactsJob: cleanup exhausted retries.', [
                'media_id' => $this->mediaId,
                'base_path' => $this->basePath,
                'pool' => $this->pool,
                'error' => $e->getMessage(),
                'exception' => get_class($e),
            ]);
            // ⚠ no report($e)
        }

        // vs. CloudflareCachePurgeJob — failed()
        public function failed(Throwable $e): void
        {
            report($e);  // ✅ present
            Log::error('cloudflare.cache_purge.failed', [
                'handle' => $this->handle,
                'error' => $e->getMessage(),
            ]);
        }
        ```

- [ ] **#JOBS-2** · P2 — `ExportProfessionalDataJob` sends a duplicate GDPR export email on crash-then-retry
    - **Where:** app/Jobs/Gdpr/ExportProfessionalDataJob.php:63–103
    - **Affects:** Users who request a GDPR data export. A worker crash between `Mail::send()` and `$audit->markCompleted()` causes the retry to rebuild the zip, re-upload, and re-send the email.
    - **Effort:** S (~1h)
    - **What to do:**
        - After a successful `$disk->put()`, immediately call a new `$audit->markUploaded($remotePath, $written['size'], $written['sha256'])` method (or store the remote path directly on the audit row) so a retry can detect "upload already complete" and skip straight to the email step.
        - Add a guard before `Mail::send()` that checks whether `$audit->file_path` is already set — if so, skip the upload and re-use the existing signed URL.
        - The `markCompleted()` call is already idempotent with respect to the file; make the email step idempotent too by adding an `email_sent_at` column to `data_export_audits` and checking it before `Mail::send()`, mirroring the pattern used in `SendEnquiryNotificationJob`.
    - **Technical:** The job's guard at the top exits early for `STATUS_COMPLETED` and `STATUS_FAILED`, but the crash window is specifically the `processing` state — which the guard explicitly allows through. After `markProcessing()`, the sequence is: build zip → upload to R2 → `Mail::send()` → `markCompleted()`. A crash anywhere after `Mail::send()` leaves the audit row in `processing`, and the retry re-runs the entire sequence including `Mail::send()`, producing a duplicate export email. Unlike `SendEnquiryNotificationJob` (which stamps `email_sent_at` and uses `lockForUpdate` to prevent concurrent sends) and `SendStaffBroadcastEmailToSubscriberJob` (which uses an `insertOrIgnore` receipt row for at-most-once delivery), `ExportProfessionalDataJob` has no send-idempotency guard. GDPR requests carry user expectations of correctness that routine notification emails don't.
    - **Plain English:** This job packs up a user's personal data, uploads it, and emails them a download link. If the server crashes right after sending the email but before marking the job "done", the job tries again from scratch — and the user gets a second email with a second download link. For a routine newsletter that's tolerable, but for someone formally requesting their legal data rights, getting two separate "here's your data" emails is confusing and looks like the system doesn't know what it's doing.
    - **Evidence:**
        ```php
        // ExportProfessionalDataJob — handle() (simplified)
        $disk->put($remotePath, $stream);          // upload succeeds
        // … stream closed, signed URL generated …
        Mail::to($audit->recipient_email)->send(…); // email sent
        // ← CRASH HERE before markCompleted() → row stays in 'processing'
        // → retry re-enters, re-uploads, re-sends email

        $audit->markCompleted(
            filePath: $remotePath,
            fileSizeBytes: $written['size'],
            // …
        );
        ```

---

## P3 — Nice to have

- [ ] **#JOBS-3** · P3 — `CheckStreamingLiveStatusJob` lands on the default queue despite a 90-second timeout
    - **Where:** app/Jobs/Streaming/CheckStreamingLiveStatusJob.php (entire class)
    - **Affects:** Default-queue workers. A job that can run up to 90 seconds on a 2-minute schedule can occupy a default worker for 75% of each duty cycle.
    - **Effort:** S (~15 min)
    - **What to do:**
        - Add `$this->onQueue('streaming');` in a constructor (the class currently has no constructor).
        - Ensure the Horizon/supervisor config provisions workers for the `streaming` queue with `timeout >= 90`.
    - **Technical:** The job has no `onQueue()` call and no constructor, so it inherits the framework default queue (`default`). With `$timeout = 90` and a 2-minute schedule, it can occupy a default-queue worker for up to 75% of each duty cycle. Other short-duration jobs on `default` — `WarmPublicSiteCacheJob` (10s), `CloudflareCachePurgeJob` (15s), `SyncSubdomainToKvJob` (30s) — are blocked during that window unless enough workers are provisioned. Dedicated queues by job class duration are the established pattern in this codebase (cf. `images`, `videos`, `notifications`, `redis_gdpr`, `mail`). `tries=1` limits the blast to one slot at a time, which keeps this at P3 rather than P2 — it's a queue hygiene issue, not a correctness gap.
    - **Plain English:** This streaming status poll runs for up to 90 seconds every 2 minutes, but it's sharing the same lane as quick jobs (like clearing a cache, which takes 15 seconds). During those 90 seconds it's occupying one worker, potentially making short jobs wait in line behind it. It should get its own dedicated lane so the fast jobs don't get stuck waiting on the slow one.
    - **Evidence:**
        ```php
        class CheckStreamingLiveStatusJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 1;
            public int $backoff = 0;
            public int $timeout = 90;
            // ← no constructor, no onQueue() call — lands on 'default'
        ```

- [ ] **#JOBS-4** · P3 — `CheckStreamingLiveStatusJob.failed()` missing `report($e)`, but is unreachable by design
    - **Where:** app/Jobs/Streaming/CheckStreamingLiveStatusJob.php:88–91
    - **Affects:** Code hygiene / future maintainers. The handler is dead code today; if handle() is ever refactored to re-throw, the missing `report()` becomes a silent failure.
    - **Effort:** S (~5 min)
    - **What to do:**
        - Add `report($e);` to `failed()` for consistency with the rest of the codebase.
        - Optionally add a comment noting that `handle()` currently swallows all polling exceptions, making this handler unreachable — or remove `failed()` entirely and rely on the framework default.
    - **Technical:** `handle()` wraps all polling work in a `try/catch(\Throwable $e)` that logs the error but does not re-throw. Since `tries=1`, the queue has no mechanism to call `failed()`. The method exists and is non-empty, which implies intent — but as written it can never fire. Adding `report($e)` is a defensive measure for when someone adds a re-throw path later. The more actionable observation is that poll errors for individual platforms are completely silent beyond `Log::error` — no Nightwatch exception event — but that's a consequence of the intentional swallow-and-continue design (so one bad platform doesn't abort all polling). If streaming poll failures should be visible in Nightwatch, `report($e)` should be added inside the per-platform `catch` in `handle()` as well.
    - **Plain English:** There's an "alarm wire" connected to this job that can never be triggered, because the job is designed to quietly ignore errors and always report success to the queue. The wire isn't harmful, but it's misleading. Either remove it, add a note explaining it's for future use, or move the alarm to where errors actually happen — inside the per-platform polling loop.
    - **Evidence:**
        ```php
        // CheckStreamingLiveStatusJob — handle() swallows all exceptions
        try {
            $poller->poll($platform, $handles);
        } catch (\Throwable $e) {
            Log::error('streaming.poll_error', [   // no report($e) here either
                'platform' => $platform,
                'message' => $e->getMessage(),
            ]);
        }

        // failed() — unreachable since handle() never throws
        public function failed(\Throwable $e): void
        {
            Log::error('streaming.job_failed', ['message' => $e->getMessage()]);
            // ⚠ no report($e)
        }
        ```
