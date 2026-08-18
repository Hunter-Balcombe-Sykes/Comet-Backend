<?php

use Illuminate\Support\Str;

return [

    'domain' => env('HORIZON_DOMAIN'),

    'path' => env('HORIZON_PATH', 'horizon'),

    // Uses the 'default' Redis connection key from config/database.php.
    'use' => 'default',

    'prefix' => env('HORIZON_PREFIX', Str::slug(env('APP_NAME', 'laravel'), '_').'_horizon:'),

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Dashboard Access
    |--------------------------------------------------------------------------
    |
    | Optional HTTP Basic auth credentials for the Horizon dashboard. Used by
    | the Horizon::auth gate in AppServiceProvider — when both are set, the
    | dashboard accepts these credentials in non-local environments. When
    | either is empty, prod stays sealed (Nightwatch is the prod story).
    |
    */

    'dashboard' => [
        'username' => env('HORIZON_DASHBOARD_USERNAME'),
        'password' => env('HORIZON_DASHBOARD_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Failure / Long-Wait Notifications
    |--------------------------------------------------------------------------
    |
    | Routes for Horizon's built-in long-wait notifications. Thresholds are
    | the per-queue 'waits' values below. Mail and Slack are independent —
    | configure either, both, or neither. Nightwatch already covers job
    | exception alerts, so these primarily add queue-backlog visibility.
    |
    */

    'notifications' => [
        'mail' => env('HORIZON_NOTIFICATION_EMAIL'),
        'slack_webhook' => env('HORIZON_NOTIFICATION_SLACK_WEBHOOK'),
        'slack_channel' => env('HORIZON_NOTIFICATION_SLACK_CHANNEL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | OBS-6 / SCALE-10 — the keys below are the ones Horizon ACTUALLY emits
    |--------------------------------------------------------------------------
    |
    | Verified against laravel/horizon in vendor/: under `balance => false`
    | (every lane here except supervisor-ingest), Supervisor::createSingleProcessPool()
    | registers ONE wait-time key per SUPERVISOR — the connection plus the
    | comma-joined queue list — not one key per queue. MonitorWaitTimes
    | compares `config("horizon.waits.{$key}")`, and a key with no entry
    | defaults to 60s (Listeners/MonitorWaitTimes.php:52-55), so an
    | un-keyed lane isn't "unmonitored", it's silently thresholded at 60s —
    | a single long purge job blows through that on any ordinary afternoon.
    |
    | The entries below are the composite keys the five supervisors in
    | `defaults` (above) actually emit today. Do NOT add a per-queue key for
    | any queue inside supervisor-1/-mail/-long/-videos — it can never match.
    | Guarded by HorizonQueueCoverageTest's "every wait-time key Horizon will
    | actually emit has an explicit waits entry" case, which derives the live
    | key set from this file's own supervisor definitions. A composite key is
    | the comma-joined queue array verbatim, so appending a queue to any
    | `defaults.*.queue` array silently changes the key that test derives —
    | that is deliberate: the test fails loudly instead of this threshold
    | quietly going dead.
    |
    | Thresholds are sized against ROUTINE projected drain time for each
    | lane (sum(readyNow(q) * avgRuntime(q)) / processes — WaitTimeCalculator,
    | not oldest-job-age), not round numbers, so ordinary load never fires
    | them (Unit F, OBS-6/SCALE-10, plan §3).
    |
    | Nothing is DELIVERED from any of this until HORIZON_NOTIFICATION_EMAIL
    | or HORIZON_NOTIFICATION_SLACK_WEBHOOK is set (see AppServiceProvider,
    | ~:317-323) — both are unset here and in .env.example on purpose, so
    | merging this block has zero blast radius until an operator turns the
    | channel on deliberately after reading a week of Horizon's Metrics tab.
    |
    */
    'waits' => [
        // Lane 1 — supervisor-1 (balance=>false, 2 procs in prod/dev): covers
        // platform_refresh/platform_connect/analytics/cloudflare_bulk (OBS-6's
        // named queues), which sit at the bottom of this strict-priority list
        // and are the first to starve. 900s = 15min: a 180s Cloudflare purge plus
        // a batch of image jobs projects well under this on routine load
        // (integrations:refresh's staggered dispatch keeps delayed jobs out
        // of readyNow entirely); a lane that can't clear in a quarter of
        // integrations:refresh's hourly cadence has already failed a user-
        // visible connect spinner.
        //
        // ⚠️ This key is the comma-joined queue array VERBATIM — MonitorWaitTimes
        // reads back exactly the composite key the supervisor registers. Reorder
        // `defaults.supervisor-1.queue` and this string MUST move with it, or the
        // lane silently falls back to the accidental 60s ceiling.
        'redis:moderation_high,default,cloudflare,cache-warm,images,media-mirror,streaming,platform_refresh,platform_connect,analytics,cloudflare_bulk' => 900,

        // Lane 2 — supervisor-ingest (balance=>'auto', 1 proc): the one lane
        // where a per-queue key is legitimate. 1800s = 2x ingest:dispatch's
        // 15min cadence — one tick's work must clear before the tick-after-
        // next or the lane is genuinely compounding. Below
        // SourceScheduler::STRANDED_AFTER_SECONDS (7200) so this fires before
        // ingest:stranded starts filing anomalies; the two signals nest
        // instead of duplicating.
        'redis:ingest' => 1800,

        // Lane 3 — supervisor-mail (balance=>false, 2 procs in prod/dev),
        // RV-12's latency-sensitive transactional split. 300s = 5min: past
        // this a magic-link or confirmation email is late enough to already
        // be a support ticket. Was previously unkeyed (accidental 60s
        // default) — 60s would page on every SendStaffBroadcastEmailsJob
        // coordinator run (a legitimate ~2min across 2 procs).
        'redis:notifications,mail' => 300,

        // Lane 4 — supervisor-long (balance=>false, 1 proc): scraping/gdpr,
        // 600s-job tier. Was previously unkeyed (accidental 60s default),
        // close to absurd for this lane — two queued 600s scrapes alone
        // project 1200s and would have paged every 5 minutes forever on an
        // ordinary Monday. 3600s = 60min is the point one worker genuinely
        // can't absorb its own dispatch rate, clear of the 660s retry_after
        // tier this lane is built around.
        'redis_scraping:scraping,gdpr' => 3600,

        // Lane 5 — supervisor-videos: single-queue supervisor, so the
        // comma-join is a no-op and this key already matched what Horizon
        // emits. Unchanged.
        'redis_video:videos' => 300,

        // ── Inert while every supervisor above stays `balance => false` ──
        // These per-queue keys can NEVER match today: MonitorWaitTimes reads
        // back exactly the composite key each supervisor registers (see the
        // block comment above), and none of supervisor-1/-mail/-long emits
        // a key this granular. Retained (not deleted) only so a future
        // `balance` change doesn't have to re-derive this from scratch — and
        // deliberately NOT fixed by switching supervisor-1 to simple/auto:
        // that raises maxProcesses to one-per-queue (ten workers on
        // supervisor-1 alone), which is the exact 2026-07-22 dev OOM loop
        // this file's "Worker Lanes" docblock exists to prevent.
        'redis:moderation_high' => 30,
        'redis:notifications' => 60,
        'redis:default' => 60,
        'redis:cloudflare' => 120,
        'redis:cache-warm' => 300,
        'redis:analytics' => 300,
        'redis:images' => 300,
        'redis:streaming' => 120,
        'redis:mail' => 120,
        'redis_scraping:scraping' => 300,
        'redis_scraping:gdpr' => 600,
    ],

    'trim' => [
        'recent' => 60,
        'pending' => 60,
        'completed' => 60,
        'recent_failed' => 10080,
        'failed' => 10080,
        'monitored' => 10080,
    ],

    'silenced' => [],

    'metrics' => [
        'trim_snapshots' => [
            'job' => 24,
            'queue' => 24,
        ],
    ],

    'fast_termination' => false,

    'memory_limit' => 64,

    /*
    |--------------------------------------------------------------------------
    | Worker Lanes
    |--------------------------------------------------------------------------
    |
    | ⚠ Horizon UNIONS this array into every environment block below
    | (ProvisioningPlan::applyDefaultOptions → array_replace_recursive):
    | every supervisor defined here runs in EVERY environment whether or not
    | the environment names it — env entries only override same-named keys.
    | The 2026-07-22 dev OOM loop was 13 supervisors defined here all
    | spawning beside the "trimmed" env blocks (~30 procs ≈ 90 MiB each).
    | Never add a supervisor here unless every deployed box can afford its
    | middleman process + workers.
    |
    | Lanes map 1:1 to retry_after tiers, not to queues. Every queue
    | connection points at the same Redis DB, so any worker can drain any
    | queue name — a lane exists only because its connection's retry_after
    | must exceed the longest job $timeout it consumes (JOB-103), else Redis
    | re-hands a still-running job to a second worker. balance MUST stay
    | false: one queue:work drains the comma-joined list in listed (priority)
    | order, and it is the only strategy that respects maxProcesses —
    | 'simple'/'auto' floor at one worker PER QUEUE — Supervisor::createProcessPools()
    | builds one pool per comma-separated queue under a balancing strategy, and
    | Supervisor::scale() raises maxProcesses to max(maxProcesses, $processes,
    | count($processPools)). Verified against laravel/horizon v5.48.1:
    | src/Supervisor.php:88-93 and :135-139; reached on ordinary startup via
    | Console/SupervisorCommand::start():100, not only via horizon:scale.
    | The pre-2026-07-22 one-supervisor-per-queue layout (~4 GiB box) is in
    | git history at 9f45c291 if real load ever justifies resurrecting it.
    |
    */

    'defaults' => [
        // Short-job lane: everything with $timeout ≤ 300s, priority-ordered.
        // redis retry_after=360 > timeout 300. memory=256 tolerates
        // ProcessImageVariantsJob heap spikes without restart churn.
        'supervisor-1' => [
            'connection' => 'redis',
            // R3-CACHE-1: 'cloudflare_bulk' appended LAST — balance=>false means this
            // supervisor drains the list in strict priority order, so a takedown's
            // bulk-fanout purges are only ever served once every lane above them
            // (including real-time 'cloudflare') is empty. Adds a queue NAME only,
            // no new supervisor/process/memory.
            //
            // 2026-08-07: 'analytics' moved from position 5 to position 9 (k6 phase 3b).
            // It was ranked ABOVE 'images' — a priority inversion. analytics is the
            // highest-volume, LEAST urgent queue (fire-and-forget visitor beacons, no
            // one waits on one); images is low-volume and HIGHLY urgent (a user is
            // watching for their upload to appear). Measured on dev: a 20,000-job
            // analytics backlog held an 'images' job unstarted for the full 4.5-minute
            // drain, while a 'default' job one rank higher ran immediately. At the
            // measured ~58 jobs/s that means an hour-long visitor spike stalls image
            // processing for ~an hour. Nothing is lost — this is latency, not
            // correctness — but it is invisible to the user whose photo never appears.
            // Guarded by 'analytics is listed AFTER images' in HorizonQueueCoverageTest.
            //
            // 2026-08-18: 'media-mirror' SPLIT OUT of 'images' and inserted directly
            // below it (R8 follow-up). 'images' was a MIXED queue — ProcessImageVariantsJob
            // has a user watching an upload spinner; MirrorMediaAssetJob is background work
            // on bytes that already render from their source_url. balance=>false declares
            // two jobs on one queue name equally urgent, so a pre-account build wave's ~300
            // mirrors queued in front of every real upload. No reordering of THIS list could
            // fix that — the contention was inside the queue, not between queues.
            // Adds a queue NAME only: no new supervisor, process or memory (same shape as
            // 'cloudflare_bulk'). Placement is deliberate on both sides — below 'images' so
            // uploads always win, and ABOVE 'analytics' because that 20k-job firehose would
            // otherwise strand a mirror for a whole visitor spike, long enough for an
            // Instagram signed url to expire and turn latency into a real failure.
            // Rejected alternative: promoting 'images' above 'cloudflare'. That queue carries
            // SyncSubdomainToKvJob — the ONLY KV writer — so it would have traded "photo
            // appears late" for "the site does not resolve".
            // Guarded by two ordering tests in HorizonQueueCoverageTest.
            'queue' => ['moderation_high', 'default', 'cloudflare', 'cache-warm', 'images', 'media-mirror', 'streaming', 'platform_refresh', 'platform_connect', 'analytics', 'cloudflare_bulk'],
            'balance' => false,
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 300,
            'nice' => 0,
        ],
        // RV-12: transactional mail lane. Split out of supervisor-1 because that
        // pool drains ten queues with two processes under strict priority — two
        // concurrent long jobs (a ~180s Cloudflare purge, a ~300s logo job) stalled
        // every outbound email until one finished. Queue order ['notifications',
        // 'mail'] is NOT "confirmations vs bulk" — both queues carry a mix.
        // SendStaffBroadcastEmailsJob, the 120s broadcast coordinator
        // (chunkById(500) walk) and the LONGEST job on this lane, is itself
        // dispatched to 'notifications' (see its constructor); the 200-job BULK
        // batches — NotificationPublisher::publishMany()'s Bus::batch() at :297
        // and the broadcast leaf batches SendStaffBroadcastEmailsJob dispatches
        // at :94 — land on 'mail'. So the ordering keeps bulk fan-out behind
        // transactional traffic; it is maxProcesses => 2, not the ordering, that
        // keeps the coordinator from blocking confirmations — a second worker
        // can still drain the front of the priority list while one worker is
        // tied up running it. redis retry_after=360 clears the 120s coordinator
        // comfortably. memory=128: these jobs render one Blade view and make one
        // mail-API call each, so 128 is ample — memory is a restart-after-
        // exceeded threshold, not a reservation. nice=0: this lane is
        // latency-sensitive and must not be deprioritised.
        'supervisor-mail' => [
            'connection' => 'redis',
            'queue' => ['notifications', 'mail'],
            'balance' => false,
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 180,
            'nice' => 0,
        ],
        // 600s-job lane: MenuFetchJob/InstagramConnectJob scrapes + gdpr
        // export/redaction. Consumed via redis_scraping (retry_after=660);
        // gdpr jobs dispatched on redis_gdpr land in the same Redis DB and
        // queue name — connection retry_after only matters to the consumer,
        // and both are 660. retry_after must exceed the 600s job timeouts or
        // Redis re-queues a still-running scrape (double-billed Apify,
        // duplicate menu rows) or a destructive GDPR run. timeout 660 keeps
        // Horizon's own SIGKILL ahead of any Redis re-queue. maxProcesses
        // stays 1 on deployed envs — external APIs are rate-limited, not
        // CPU-bound.
        'supervisor-long' => [
            'connection' => 'redis_scraping',
            'queue' => ['scraping', 'gdpr'],
            'balance' => false,
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 660,
            'nice' => 5,
        ],
        // ffmpeg lane: ProcessVideoVariantsJob encodes for up to an hour
        // (redis_video retry_after=3600), so it can never share a retry_after
        // tier with anything shorter. memory=512 for PHP heap spikes around
        // the encode.
        'supervisor-videos' => [
            'connection' => 'redis_video',
            'queue' => ['videos'],
            'balance' => false,
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 512,
            'tries' => 1,
            'timeout' => 3600,
            'nice' => 5,
        ],
        // Ingest lane (plan §4/P3): ingest:dispatch fans out one RunSourceJob per
        // due ingest.sources row every 15 minutes. Split into its OWN supervisor
        // rather than folded into supervisor-1's ten-queue priority list — that
        // lane's balance=>false strict ordering means a busy day on any
        // higher-priority queue could starve a 15-min-cadence dispatcher
        // indefinitely, the same head-of-line risk RV-12 split 'mail'/
        // 'notifications' out for. balance=>'auto' (not false, unlike every
        // sibling lane) is deliberate: this lane carries exactly ONE queue name,
        // so there is no cross-queue priority order to protect, and 'auto' lets
        // Horizon scale workers down to idle between dispatch bursts instead of
        // holding maxProcesses workers alive around the clock. 'redis'
        // connection's retry_after=360 still exceeds RunSourceJob's own
        // $timeout=300 (JOB-103) — margin narrowed by the Phase 5 menu retry
        // budget, so a further raise on either needs retry_after raised first.
        // memory=256 matches supervisor-1's tier: a
        // connector run is an HTTP fetch plus regex/JSON parsing and a handful of
        // DB writes — the same rough weight class as that lane's mixed workload,
        // not the video tier.
        'supervisor-ingest' => [
            'connection' => 'redis',
            'queue' => ['ingest'],
            'balance' => 'auto',
            // 1, not 2: a 15-minute dispatcher with 300s jobs has no need of
            // two concurrent workers at pilot scale, and every extra permitted
            // worker is heap this box does not have (see the ceiling note
            // below). Raise it WITH a resize, not before one.
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            // 192, not 256: a connector run is an HTTP fetch plus JSON/HTML
            // parsing and a few writes — nothing like the video tier.
            'memory' => 192,
            'tries' => 1,
            // 300, raised from 120 alongside RunSourceJob::$timeout for the menu
            // actor's retry budget (convergence Phase 5). A worker timeout below
            // the job's own $timeout would kill the job first, mid-billed-run —
            // so these two move together. Still under the connection's
            // retry_after=360, which is the JOB-103 invariant.
            'timeout' => 300,
            'nice' => 5,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Environments
    |--------------------------------------------------------------------------
    |
    | Process-count overrides per environment. Footprint = 1 master + one
    | middleman process per lane + workers, ~90 MiB each (an estimate, not a
    | measurement): idle deployed footprint is 11 procs (~990 MiB) — under
    | balance=>false, AutoScaler::numberOfWorkersPerQueue() floors every lane
    | at minProcesses=1 when idle (see the Worker Lanes docblock above), so
    | that is 1 master + 5 middlemen + 5 workers (one per lane; supervisor-ingest
    | runs balance=>'auto' instead, but still floors at 1 idle worker like every
    | other lane). A busy lane autoscales 1→maxProcesses; when supervisor-1 and
    | supervisor-mail both scale to 2 (production/development), the busy ceiling
    | is 13 procs. Permitted worker heap sums to 2×256 (supervisor-1) +
    | 2×128 (supervisor-mail) + 256 (supervisor-long) + 512 (supervisor-videos)
    | + 192 (supervisor-ingest, capped at ONE worker) = 1728 MiB; plus
    | non-worker processes (master at horizon.memory_limit=64, 5 middlemen at
    | ~90 MiB each) ≈ 514 MiB, for a permitted ceiling of ~2242 MiB against the
    | 2048 MiB flex-2gb Worker box (RV-4 resize, 2026-07-24).
    | ⚠ KNOWN OVER-COMMIT (2026-07-27): that ceiling is ~194 MiB over the box.
    | Two things make it tolerable rather than reckless. First, `memory` is a
    | per-worker RESTART THRESHOLD, not a reservation — the sum is what every
    | worker would use if all of them simultaneously sat at the point where
    | Horizon kills and respawns them, which is not a steady state. Second, the
    | box had only ~88 MiB of headroom on this same pessimistic measure BEFORE
    | ingest existed, so any fifth lane crosses it; supervisor-ingest is
    | deliberately the smallest lane on the box (1 worker, 192 MiB) to keep the
    | overshoot as small as a fifth lane can be. The rebuild plan's §19 already
    | carries a Horizon-lane infra line for exactly this: the real fix is a box
    | resize, which is an owner cost decision, not something to paper over by
    | pretending the arithmetic works. Do NOT raise supervisor-ingest's
    | maxProcesses before that resize. Every environment must name all five
    | lanes explicitly — that is what HorizonQueueCoverageTest walks to prove
    | every dispatchable queue has a consumer in every env.
    |
    */

    'environments' => [

        'production' => [
            'supervisor-1' => ['maxProcesses' => 2],
            'supervisor-mail' => ['maxProcesses' => 2],
            'supervisor-long' => ['maxProcesses' => 1],
            'supervisor-videos' => ['maxProcesses' => 1],
            'supervisor-ingest' => ['maxProcesses' => 1],
        ],

        'development' => [
            'supervisor-1' => ['maxProcesses' => 2],
            'supervisor-mail' => ['maxProcesses' => 2],
            'supervisor-long' => ['maxProcesses' => 1],
            'supervisor-videos' => ['maxProcesses' => 1],
            'supervisor-ingest' => ['maxProcesses' => 1],
        ],

        'local' => [
            'supervisor-1' => ['maxProcesses' => 3],
            'supervisor-mail' => ['maxProcesses' => 1],
            'supervisor-long' => ['maxProcesses' => 2],
            'supervisor-videos' => ['maxProcesses' => 1],
            'supervisor-ingest' => ['maxProcesses' => 1],
        ],

    ],

];
