`★ Insight ─────────────────────────────────────`
The verification confirms all three findings. Key observation on SAT-1: the streaming queue has a 90-second job timeout but the scheduler fires every 2 minutes — at zero workers, jobs accumulate at 720 per day. The `waits` config also has no `redis:streaming` threshold, so Horizon's long-wait notification system would never alert on the backlog even if a supervisor were added without the waits entry.
`─────────────────────────────────────────────────`

# Queue & Horizon Saturation Audit — 2026-05-31

**Branch:** development
**Lens:** Database and queue saturation, Supavisor connection-pool exhaustion, queue-lane saturation, Horizon throughput, synchronous work in request path, analytics-ingest backpressure at high visit volume
**Pipeline:** scan-tier draft by `deepseek-v4-pro`, adjudicated by `claude-sonnet-4-6`
**Source files audited:**
- config/horizon.php
- config/queue.php
- config/database.php
- app/Jobs/Streaming/CheckStreamingLiveStatusJob.php
- app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php
- app/Jobs/Analytics/RecordAnalyticsEventJob.php
- app/Jobs/ProcessImageVariantsJob.php
- app/Services/Analytics/Ingestors/QueuedIngestor.php

## Progress

- P0 Blockers: 0 of 0 complete
- P1 High: 0 of 2 complete
- P2 Medium: 0 of 1 complete

---

## P1 — Fix before pilot launch

- [ ] **#SAT-1** · P1 — Streaming queue has no Horizon supervisor; live-status polling never executes
    - **Where:** config/horizon.php (all three environment blocks); app/Jobs/Streaming/CheckStreamingLiveStatusJob.php:29
    - **Affects:** All professionals with streaming blocks (Twitch/Kick live badges). The `is_live` field never updates; live indicator stays permanently dark regardless of stream state. Jobs accumulate in Redis indefinitely — at one dispatch every 2 minutes, that is ~720 unreachable jobs per day.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Add `supervisor-streaming` to the `defaults` block in `config/horizon.php` with `connection: redis`, `queue: ['streaming']`, `balance: false`, `maxProcesses: 1`, `timeout: 120` (exceeds the job's 90s timeout; gives a 30s safety margin), `tries: 1`, `nice: 5`.
        - Add `'supervisor-streaming' => ['maxProcesses' => 1]` to the `production` environment block and `'supervisor-streaming' => ['maxProcesses' => 1]` to the `development` environment block so Horizon spawns the supervisor in both environments.
        - Add `'redis:streaming' => 300` to the `waits` array so Horizon's long-wait notification fires if the queue backs up — without this entry, even after the supervisor is added, an unexpected worker crash produces no alert.
    - **Technical:** Laravel Horizon only dispatches workers to queues listed in active supervisor definitions. `CheckStreamingLiveStatusJob` calls `$this->onQueue('streaming')` in its constructor, but no supervisor in `production`, `development`, or `local` references that queue. The `defaults` block defines the base shape for supervisors but does not make them active — each environment block must explicitly include the supervisor name. Additionally, the `waits` config has no `redis:streaming` entry, meaning Horizon's built-in long-wait alert (configured via `notifications.slack_webhook`) will never fire even after the supervisor is added.
    - **Plain English:** Imagine a postal sorting office where every streaming status check gets written on an envelope and dropped into a bin labelled "streaming." The problem is that bin has no postal worker assigned to it — ever. The envelopes pile up, and every user profile that should show a "🔴 LIVE" badge stays dark forever. The fix is to assign one postal worker to that bin.
    - **Evidence:**
        ```php
        // app/Jobs/Streaming/CheckStreamingLiveStatusJob.php:29
        $this->onQueue('streaming');

        // app/Jobs/Streaming/CheckStreamingLiveStatusJob.php:32
        public int $timeout = 90;
        ```
        ```php
        // config/horizon.php — production environment (no streaming entry)
        'production' => [
            'supervisor-moderation-high' => ['minProcesses' => 1, 'maxProcesses' => 3],
            'supervisor-notifications' => ['minProcesses' => 1, 'maxProcesses' => 3],
            'supervisor-default' => ['minProcesses' => 1, 'maxProcesses' => 3],
            'supervisor-analytics' => ['minProcesses' => 1, 'maxProcesses' => 2],
            'supervisor-gdpr' => ['maxProcesses' => 1],
            'supervisor-videos' => ['maxProcesses' => 2],
        ],
        ```
        ```php
        // config/horizon.php — waits (no redis:streaming entry)
        'waits' => [
            'redis:moderation_high' => 30,
            'redis:notifications' => 60,
            'redis:default' => 60,
            'redis:analytics' => 300,
            'redis:images' => 300,
            'redis:mail' => 120,
            'redis_gdpr:gdpr' => 600,
            'redis_video:videos' => 300,
        ],
        ```

- [ ] **#SAT-2** · P1 — Staff-broadcast coordinator timeout (120s) exceeds supervisor timeout (60s); large broadcasts never complete
    - **Where:** app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php (line: `public int $timeout = 120`); config/horizon.php (`supervisor-notifications timeout: 60`)
    - **Affects:** Staff-initiated email broadcasts to marketing-list subscribers. Any broadcast with a subscriber list large enough to require more than 60 seconds of `chunkById` iteration is killed by the supervisor, consumes a retry attempt, and fails permanently after two exceptions — leaving the entire broadcast undelivered. The bug is invisible in development (where `supervisor-1` has `timeout: 300`) and only manifests in production.
    - **Effort:** S (~0.5–1h)
    - **What to do:**
        - Raise `supervisor-notifications` `timeout` in the `defaults` block from `60` to at least `130` (10s above the job's declared `$timeout = 120`). Update the production environment override if it sets a different timeout.
        - Verify the `redis` queue connection's `retry_after` (currently 360s in config/queue.php) still exceeds the new supervisor timeout — it does, so no change needed there.
        - Add a comment explaining the constraint: the supervisor timeout must always exceed the maximum `$timeout` of any job dispatched to its queues.
        - Note: `SendStaffBroadcastEmailsJob` implements `ShouldBeUnique` with `uniqueFor = 600`. When a worker is SIGKILL'd at 60s, the uniqueness lock remains held for up to 540 more seconds, blocking any re-dispatch during that window. Raising the supervisor timeout above 120s eliminates this class of lockout.
    - **Technical:** Horizon kills a worker process via SIGKILL when the job runtime exceeds the supervisor's `timeout`. The job's `$timeout` property is advisory only — it sets the `SIGALRM`-based PHP timeout, which fires first if the job exceeds 120s. However, Horizon's supervisor-level `timeout: 60` triggers SIGKILL at 60s, before PHP's own alarm. The job gets retried up to `$maxExceptions = 2` times, each time hitting the same 60-second wall. The development `supervisor-1` sets `timeout: 300`, so this mismatch never appears locally. The production supervisor-notifications also handles the `mail` queue (per-subscriber leaf jobs at 30s timeout), but those are unaffected; only the coordinator job triggers the ceiling.
    - **Plain English:** Your broadcast coordinator is like a manager who needs two hours to hand out tasks to 500 employees. But management has told the office security to throw the manager out after one hour, every time, no exceptions. So the manager gets partway through the list, gets evicted, tries again, gets evicted again — and the task list never gets finished. The fix is to tell security the broadcast manager needs at least two hours, not one.
    - **Evidence:**
        ```php
        // app/Jobs/Notifications/SendStaffBroadcastEmailsJob.php
        public int $timeout = 120;

        public function __construct(
            public string $notificationId,
            public string $listKey = 'sidest_updates'
        ) {
            $this->onQueue('notifications');
        }
        ```
        ```php
        // config/horizon.php — defaults block
        'supervisor-notifications' => [
            'connection' => 'redis',
            'queue' => ['notifications', 'mail'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 0,
        ],
        ```

---

## P2 — Should fix

- [ ] **#SAT-3** · P2 — Analytics and image processing share one supervisor lane; image burst can delay visit recording
    - **Where:** config/horizon.php (`supervisor-analytics`: `queue: ['analytics', 'images']`, `maxProcesses: 2`)
    - **Affects:** Timeliness of analytics data in professional dashboards. During a burst of image uploads — e.g., a professional uploads their full 5-image gallery at once — both worker slots can be occupied by 120-second image-processing jobs, leaving analytics events queued and delaying summary updates for that professional (and any others being visited simultaneously).
    - **Effort:** S (configuration change)
    - **What to do:**
        - Monitor the `redis:analytics` Horizon wait metric in production. The `waits` threshold is already set to 300s — if it regularly fires, split the queues.
        - To split: add a `supervisor-images` definition to `defaults` with `connection: redis`, `queue: ['images']`, `timeout: 300`, `maxProcesses: 1`, `nice: 15`; then remove `images` from `supervisor-analytics`'s queue list and reduce its `maxProcesses` to 1 (analytics events are individually fast at 30s; 1 worker per slot is sufficient at pilot scale).
        - Update both `production` and `development` environment blocks and add `redis:images` separately to `waits` if splitting.
    - **Technical:** `supervisor-analytics` is intentionally capped (the comment notes "Capped to prevent analytics/image backlogs from starving critical queues"). However, the two queue types have very different cost profiles: `RecordAnalyticsEventJob` completes in well under its 30s timeout (a single PostgreSQL `insertOrIgnore`), while `ProcessImageVariantsJob` can run up to its 120s timeout (multi-variant WebP conversion). When both worker slots are occupied by image jobs, analytics events wait for up to 120s before being picked up — at a visit spike (e.g., a profile shared on social media), this degrades dashboard freshness visibly. The `balance: auto` setting does help Horizon allocate workers toward the busier queue, but with only 2 total processes and images consuming full 120s windows, the balancer has limited room to manoeuvre.
    - **Plain English:** Imagine a small post office with two clerks, where one window handles quick "drop-off a parcel" tasks (analytics) and the other handles hour-long passport applications (image processing). If both clerks get tied up in passport appointments, even quick drop-offs have to wait. The fix is to give the passport window its own dedicated clerk so the drop-off queue never stalls.
    - **Evidence:**
        ```php
        // config/horizon.php — defaults block
        // Capped to prevent analytics/image backlogs from starving critical queues.
        // nice=10 also deprioritises these at the OS scheduler level.
        // memory=512: raised from 256 — images queue can spike PHP heap during
        // transformation and rebuild aggregates scan large date windows at Stage 2.
        'supervisor-analytics' => [
            'connection' => 'redis',
            'queue' => ['analytics', 'images'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 512,
            'tries' => 1,
            'timeout' => 300,
            'nice' => 10,
        ],
        ```
