`streaming_platforms: ['twitch', 'kick']` is active in config but there's zero `streaming` supervisor in Horizon — confirmed miss. Now I have everything I need.

`★ Insight ─────────────────────────────────────`
Three patterns to watch in this codebase: (1) `markFailed()` inside a catch-then-rethrow creates a state gate that silently kills all configured retries; (2) Horizon `waits:` entries are the fastest signal for whether a queue is intended to be consumed — `redis:mail` is there but `streaming` is absent; (3) `$maxExceptions` counts *all* exceptions, not deterministic ones — comments saying "surface deterministic failures fast" are misleading when the actual implementation catches everything.
`─────────────────────────────────────────────────`

---

# Jobs / Queue Safety Audit — 2026-05-25

**Branch:** development
**Lens:** non-idempotent jobs, unsafe retries, missing failure handlers, wrong queue lane, unbounded backoff
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- app/Jobs/Streaming/CheckStreamingLiveStatusJob.php
- app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php
- app/Jobs/Notifications/SendStaffBroadcastEmailToSubscriberJob.php
- app/Jobs/Notifications/SendEnquiryNotificationJob.php
- app/Jobs/Notifications/SendFeedbackEmailJob.php
- app/Jobs/Notifications/SendTransactionalNotificationEmailJob.php
- app/Jobs/Notifications/SyncCustomerMarketingOptInJob.php
- app/Jobs/Gdpr/ExportProfessionalDataJob.php
- app/Jobs/ProcessVideoVariantsJob.php
- app/Jobs/ProcessImageVariantsJob.php
- app/Jobs/Cache/WarmPublicSiteCacheJob.php
- app/Models/Core/Gdpr/DataExportAudit.php
- config/horizon.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 1 complete
- P2 Medium: 0 of 4 complete
- P3 Low: 0 of 2 complete

---

## P1 — Fix before pilot launch

- [ ] **#JOB-1** · P1 — `CheckStreamingLiveStatusJob` dispatches to a `streaming` queue that no Horizon supervisor consumes
    - **Where:** app/Jobs/Streaming/CheckStreamingLiveStatusJob.php (constructor `onQueue('streaming')`) + config/horizon.php (no streaming supervisor defined)
    - **Affects:** Live streaming status indicators on all user site pages — Twitch/Kick live-status is never polled, so all streaming blocks permanently show stale or default-offline status. Jobs accumulate in Redis DB 2 without being consumed or failing visibly.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add a `supervisor-streaming` entry to `config/horizon.php` `defaults` (connection: `redis`, queue: `['streaming']`, balance: `simple`, maxProcesses: 1, timeout: 120).
        - Add the env override in `environments.production` and both dev environments.
        - Add `'redis:streaming' => 120` to the `waits` array so Horizon alerts on backlog accumulation.
    - **Technical:** `config('partna.streaming_platforms', [])` returns `['twitch', 'kick']` (verified in `config/partna.php:227`). The job is scheduled every 2 minutes, dispatched to the `'streaming'` queue, and has a 90-second timeout. The Horizon `defaults` block defines supervisors for `notifications/mail`, `default`, `analytics/images`, `gdpr`, and `videos` — no entry for `streaming`. The `waits` map also has no `redis:streaming` key. Jobs accumulate silently because `ShouldQueue` puts them into Redis with no consumer; `failed()` never fires because the jobs never execute. The streaming feature has been broken since `onQueue('streaming')` was added.
    - **Plain English:** Imagine a receptionist whose job is to call Twitch every 2 minutes to check if a streamer is live. The receptionist keeps writing notes to call Twitch, dropping them in an "outbound" tray — but nobody is assigned to pick up that tray. Every 2 minutes a new note piles up. All streaming blocks on every site show stale status forever, and nobody gets an alert because the notes never actually failed — they're just sitting there unread.
    - **Evidence:**
        ```php
        // CheckStreamingLiveStatusJob constructor:
        $this->onQueue('streaming');
        ```
        ```php
        // config/horizon.php — all defined supervisors, no streaming entry:
        'defaults' => [
            'supervisor-notifications' => ['queue' => ['notifications', 'mail'], ...],
            'supervisor-default'       => ['queue' => ['default'], ...],
            'supervisor-analytics'     => ['queue' => ['analytics', 'images'], ...],
            'supervisor-gdpr'          => ['queue' => ['gdpr'], ...],
            'supervisor-videos'        => ['queue' => ['videos'], ...],
            // streaming supervisor absent
        ],
        'waits' => [
            'redis:notifications' => 60,
            'redis:default' => 60,
            'redis:analytics' => 300,
            'redis:images' => 300,
            'redis:mail' => 120,
            'redis_gdpr:gdpr' => 600,
            'redis_video:videos' => 300,
            // 'redis:streaming' absent
        ],
        ```
        ```php
        // config/partna.php:227 — streaming is active:
        'streaming_platforms' => ['twitch', 'kick'],
        ```

---

## P2 — Should fix

- [ ] **#JOB-2** · P2 — `ProcessVideoVariantsJob` only gets 2 attempts for 12-minute transcoding work
    - **Where:** app/Jobs/ProcessVideoVariantsJob.php:31–33
    - **Affects:** Video uploaders — a single transient FFmpeg OOM, disk pressure spike, or R2 upload timeout permanently fails the video with no second meaningful retry; user must re-upload.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Raise `$tries` from 2 to 3.
        - Change `$backoff` from the scalar `60` to `[120, 600, 1800]` — the first retry gives the transcoding pool 2 minutes to breathe; the second gives it 10 minutes; only then is the job permanently failed.
    - **Technical:** `$timeout = 720` (12 minutes) makes this the most resource-intensive job in the system, and therefore the most susceptible to transient contention. With `$tries = 2` and `$backoff = 60`, the single retry fires 60 seconds after the first failure — too soon to recover from FFmpeg OOM or a competing transcode on the same worker. `ExportProfessionalDataJob` (far less compute-intensive) gets 3 attempts with exponential backoff `[60, 300, 900]`. The in-flight Redis lock (`video:processing-lock:{mediaId}`) already prevents parallel execution on the same media ID, so raising tries is safe — retry 2 will see the lock expired (TTL = timeout + 60 = 780s) and re-acquire cleanly.
    - **Plain English:** Rendering video is like baking a complex cake with one oven. If the oven trips mid-bake, this job tries once more after one minute — not long enough for the oven to cool and reset. Three attempts with longer waits between them would catch most temporary equipment problems without requiring the user to start over from scratch.
    - **Evidence:**
        ```php
        class ProcessVideoVariantsJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 2;

            public int $backoff = 60;

            public int $timeout = 720;
        ```

- [ ] **#JOB-3** · P2 — `ExportProfessionalDataJob` has no concurrency lock; PROCESSING status is not in the early-exit guard
    - **Where:** app/Jobs/Gdpr/ExportProfessionalDataJob.php:52–62 (status check + markProcessing)
    - **Affects:** GDPR data exports — a duplicate dispatch (staff retry, Horizon scale-out) where the second worker arrives while the first is mid-flight: both workers pass the status gate (`STATUS_PROCESSING` is not in the exit list), both execute the full streaming zip build, and both race on the R2 upload and signed-URL generation. The `lockForUpdate` email guard prevents double-sending, but both workers build and upload a full export zip.
    - **Effort:** M (~2–4h)
    - **What to do:**
        - Add a Redis `NX` lock keyed on `auditId` acquired immediately after the status check, TTL = `$timeout + 60` (660 seconds), matching the pattern in `ProcessImageVariantsJob` and `ProcessVideoVariantsJob`.
        - Expand the early-exit guard to include `STATUS_PROCESSING` — a job that finds `processing` and cannot acquire the lock should return silently (same semantics as the media jobs).
    - **Technical:** The early-exit check is `in_array($audit->status, [STATUS_COMPLETED, STATUS_FAILED])`. This correctly short-circuits completed/failed re-runs but lets a second worker sail through when the first is mid-flight in `STATUS_PROCESSING`. Unlike the media jobs — which acquire `Redis::set($lockKey, '1', 'EX', $timeout+60, 'NX')` before any state mutation — the export job calls `$audit->markProcessing()` with no lock. Two concurrent workers each call `writeStreaming()` (streaming the full dataset to a temp zip), `$disk->put()` (uploading to R2), and `$disk->temporaryUrl()` (generating a signed URL). The last `markCompleted()` wins, but the wasted I/O (potentially gigabytes for large accounts) is avoidable and the signed URL race is a correctness concern.
    - **Plain English:** Two employees get assigned the same filing job simultaneously. There's a sign-in sheet that says "check if it's done before starting," but it only says "stop if it's finished or failed" — not "stop if someone else is already doing it." So both employees pull out the same files, build the same package, and race to drop it in the mailbox. Only one package gets acknowledged, but both burned the same effort.
    - **Evidence:**
        ```php
        if (in_array($audit->status, [DataExportAudit::STATUS_COMPLETED, DataExportAudit::STATUS_FAILED], true)) {
            return;
        }
        // STATUS_PROCESSING is not in the exit list — a second worker passes through here.

        $audit->markProcessing();  // No lock acquired before this
        ```
        Compare with `ProcessImageVariantsJob`:
        ```php
        $lockKey = "image:processing-lock:{$this->imageId}";
        $acquired = Redis::set($lockKey, '1', 'EX', $this->timeout + 60, 'NX');
        if (! $acquired) {
            Log::info('ProcessImageVariantsJob: another worker is processing this image, skipping.');
            return;
        }
        ```

- [ ] **#JOB-4** · P2 — `ExportProfessionalDataJob` calls `markFailed()` inside the catch block, permanently killing all configured retries
    - **Where:** app/Jobs/Gdpr/ExportProfessionalDataJob.php (catch block ~line 87), app/Models/Core/Gdpr/DataExportAudit.php:121–128
    - **Affects:** GDPR data exports — any transient failure (R2 network timeout, SMTP hiccup, temporary disk pressure) immediately marks the audit row `failed` in the database. The queue schedules retries per `$tries = 3` and `backoff: [60, 300, 900]`, but every retry hits the early-exit guard (`STATUS_FAILED` → return) and does nothing. The three-attempt retry window and exponential backoff are dead code.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Remove `$audit->markFailed($e->getMessage())` from the catch block. Let the exception propagate unmodified so the queue framework handles retry scheduling.
        - Keep `markFailed()` exclusively in the `failed()` callback (which already calls it), which only fires after `$tries` is fully exhausted.
        - Update the catch-block comment to remove the misleading "let queue retry per $tries/$backoff" note (that is now actually true once this change is made).
    - **Technical:** `DataExportAudit::markFailed()` calls `$this->update(['status' => STATUS_FAILED, ...])`, persisting to the database immediately. On the next retry, `DataExportAudit::find($auditId)->status` reads `'failed'`, so `in_array($audit->status, [STATUS_COMPLETED, STATUS_FAILED])` is true and the job returns without doing any work. The `failed()` callback *also* calls `markFailed()` but correctly guards against overwriting a `STATUS_COMPLETED` row. Removing the catch-block call restores the intended at-most-3-attempts semantics. Note: this fix interacts with JOB-3 — if the concurrency lock is added first, the catch-block `markFailed` also prevents lock-holder recovery after a crash (since the retry no-ops), so both fixes should ship together.
    - **Plain English:** The job has a "try 3 times" label on the outside, but the first time something goes wrong, the code writes "FAILED" in permanent ink on the job ticket before throwing it back in the retry pile. The next worker picks it up, sees "FAILED" written on it, and puts it straight in the bin without trying. The retry machinery is real, but the permanent-ink pen makes it useless. Remove the pen from the catch block — only write "FAILED" after all three attempts are truly exhausted.
    - **Evidence:**
        ```php
        // In the catch block — markFailed() persists STATUS_FAILED to the DB,
        // then throw $e queues a retry that will immediately no-op:
        } catch (Throwable $e) {
            $audit->markFailed($e->getMessage());
            Log::error('ExportProfessionalDataJob failed', [
                'audit_id' => $audit->id,
                'error' => $e->getMessage(),
            ]);
            throw $e; // let queue retry per $tries/$backoff
        }
        ```
        ```php
        // DataExportAudit::markFailed() — persists to DB immediately:
        public function markFailed(string $error): void
        {
            $this->completed_at = now();
            $this->update([
                'status' => self::STATUS_FAILED,
                'error_message' => mb_substr($error, 0, 2000),
            ]);
        }
        ```
        ```php
        // Early-exit guard on every retry — will always return after attempt 1 fails:
        if (in_array($audit->status, [DataExportAudit::STATUS_COMPLETED, DataExportAudit::STATUS_FAILED], true)) {
            return;
        }
        ```

- [ ] **#JOB-5** · P2 — `$maxExceptions = 2` silently caps effective attempts to 2 across 6 notification jobs despite `$tries = 3`
    - **Where:** app/Jobs/Notifications/SendEnquiryNotificationJob.php:34, SendFeedbackEmailJob.php:36, SendStaffBroadcastEmailsJob.php:40, SendStaffBroadcastEmailToSubscriberJob.php:34, SendTransactionalNotificationEmailJob.php:68, SyncCustomerMarketingOptInJob.php:35
    - **Affects:** All notification/transactional email delivery paths — two consecutive transient failures (SMTP server down for 60 seconds, mail provider rate-limit) exhaust the exception budget, permanently failing the job before the third `$tries` attempt is ever attempted. `SendTransactionalNotificationEmailJob` explicitly documents at-least-once semantics, directly contradicting this behaviour.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Raise `$maxExceptions` from 2 to 3 in all six jobs, matching `$tries`. This makes the comment "Surface deterministic failures fast" false — remove or rewrite it.
        - **Alternatively:** if truly fail-fast on two exceptions is the intent, lower `$tries` to 2 and update comments to match. Do not leave `$tries` and `$maxExceptions` disagreeing silently.
    - **Technical:** Laravel's `$maxExceptions` counts *any* unhandled exception, not deterministic ones. The comment "Surface deterministic failures fast" implies it only activates on configuration errors (missing mailable class, missing email address), but `Mail::to()->send()` throws on a transient SMTP timeout just as readily as on a misconfiguration. The effective sequence with current values: attempt 1 throws (SMTP timeout) → exception #1 → retry; attempt 2 throws (server still recovering) → exception #2 → `maxExceptions` limit reached → `failed()` called permanently. The third attempt specified by `$tries = 3` never runs. For `SendTransactionalNotificationEmailJob` this directly contradicts the in-code comment: "At-least-once semantics: stamp happens after send, so a crash between send and stamp will cause a retry to re-send. For financially-sensitive emails this is preferable to never sending."
    - **Plain English:** Each of these jobs says "try three times" on the box, but there's a hidden clause that says "stop after two failures, no matter what kind." If the email server is overloaded and rejects two sends in a row, the job gives up before the third try — even though the server might be fine 90 seconds later. For important emails (enquiries from potential clients, financial notifications), that missed third attempt could be the one that gets through.
    - **Evidence:**
        ```php
        // SendTransactionalNotificationEmailJob — declares at-least-once but caps at 2:
        public int $tries = 3;

        // Surface deterministic failures fast — fail after 2 consecutive throws
        // instead of burning the full backoff window before Horizon alerts.
        public int $maxExceptions = 2;

        // ...later in handle():
        // At-least-once semantics: stamp happens after send, so a crash between send and
        // stamp will cause a retry to re-send. For financially-sensitive emails this is
        // preferable to never sending.
        ```
        ```php
        // Same pattern (same $tries / $maxExceptions values) in all six jobs:
        // SendEnquiryNotificationJob, SendFeedbackEmailJob, SendStaffBroadcastEmailsJob,
        // SendStaffBroadcastEmailToSubscriberJob, SyncCustomerMarketingOptInJob
        public int $tries = 3;
        public int $maxExceptions = 2;
        ```

---

## P3 — Nice to have

- [ ] **#JOB-6** · P3 — `ProcessImageVariantsJob` uses flat 30-second backoff instead of exponential
    - **Where:** app/Jobs/ProcessImageVariantsJob.php:33
    - **Affects:** Image uploaders — with flat 30-second spacing, all three retry attempts may land inside the same transient pressure window (disk I/O contention, R2 rate-limit burst), failing all three before the system recovers.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Change `public int $backoff = 30;` to `public array $backoff = [30, 120, 300];` — the second retry at 2 minutes and third at 5 minutes give the system meaningful breathing room between attempts.
    - **Technical:** `ProcessImageVariantsJob` has `$tries = 3` and `$backoff = 30` (scalar). All three attempts fire at 30-second intervals. If a transient failure resolves in 90 seconds, attempt 1 (30s) and attempt 2 (60s) both fail and attempt 3 (90s) succeeds — but with exponential spacing only attempt 2 is needed. More critically, if a pressure window lasts 90 seconds, flat spacing can exhaust all three attempts inside it. The video job has the same pattern (`$backoff = 60`), but with `$tries = 2` there's only one retry anyway; the image job has three attempts where spacing matters.
    - **Plain English:** When the image-processing pipeline gets briefly overloaded, this job knocks on the door at 30, 60, and 90 seconds — like pressing a doorbell every half-minute. Exponential spacing would knock at 30 seconds, then wait 2 minutes, then wait 5 minutes, giving the system much more time to clear its backlog between each attempt.
    - **Evidence:**
        ```php
        class ProcessImageVariantsJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;

            public int $backoff = 30;
        ```

- [ ] **#JOB-7** · P3 — `WarmPublicSiteCacheJob` has a 10-second timeout that may be too tight for full payload assembly
    - **Where:** app/Jobs/Cache/WarmPublicSiteCacheJob.php:23
    - **Affects:** First visitors after a site publish — if `warmSiteCache()` or payload assembly consistently exceeds 10 seconds, all three retry attempts hit the same wall, the cache stays cold, and the first visitor bears the full payload latency on every publish.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Raise `$timeout` to at least 30 seconds to give `$siteCache->warmSiteCache($subdomain)` plus the two Eloquent lookups room to complete under normal load.
        - If `warmSiteCache()` is known to be fast (Redis + small query), add a comment documenting that assumption so the timeout is not accidentally raised again without justification.
    - **Technical:** The `handle()` method calls `$siteCache->warmSiteCache($subdomain)` (outside the try-catch — a timeout here aborts the job and triggers a retry) followed by a try-catch block for the `§28.8 warm` path, which swallows exceptions and does not consume `$tries`. The 10-second wall applies to the uncaught `warmSiteCache()` path. The job declares `$tries = 3` with `$backoff = [5, 15, 30]` — if the timeout is consistently tight, the first visitor after a publish sees a cold cache regardless of the retry policy. `CloudflareCachePurgeJob` (a lighter HTTP call) already uses 15 seconds; this job does more work.
    - **Plain English:** This job pre-warms the cache so visitors don't have to wait while their page is assembled. It has 10 seconds to do this. If the assembly takes 12 seconds, the job gets cut off every time, retries three times, fails each time, and the visitor still gets a slow page — even though retrying actually worked, it just ran out of time.
    - **Evidence:**
        ```php
        class WarmPublicSiteCacheJob implements ShouldQueue
        {
            use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

            public int $tries = 3;

            public array $backoff = [5, 15, 30];

            public int $timeout = 10;
        ```
