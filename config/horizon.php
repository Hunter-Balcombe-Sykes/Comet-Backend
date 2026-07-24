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

    'waits' => [
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
        // gdpr is consumed via the redis_scraping connection (supervisor-long).
        'redis_scraping:gdpr' => 600,
        'redis_video:videos' => 300,
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
            'queue' => ['moderation_high', 'default', 'cloudflare', 'cache-warm', 'analytics', 'images', 'streaming', 'platform_refresh', 'platform_connect', 'cloudflare_bulk'],
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
    ],

    /*
    |--------------------------------------------------------------------------
    | Environments
    |--------------------------------------------------------------------------
    |
    | Process-count overrides per environment. Footprint = 1 master + one
    | middleman process per lane + workers, ~90 MiB each (an estimate, not a
    | measurement): idle deployed footprint is 9 procs (~810 MiB) — under
    | balance=>false, AutoScaler::numberOfWorkersPerQueue() floors every lane
    | at minProcesses=1 when idle (see the Worker Lanes docblock above), so
    | that is 1 master + 4 middlemen + 4 workers (one per lane). A busy lane
    | autoscales 1→maxProcesses; when supervisor-1 AND supervisor-mail both
    | scale to 2 (production/development), the busy ceiling is 11 procs.
    | Permitted worker heap sums to 2×256 (supervisor-1) + 2×128
    | (supervisor-mail) + 256 (supervisor-long) + 512 (supervisor-videos) =
    | 1536 MiB; plus non-worker processes (master at horizon.memory_limit=64,
    | 4 middlemen at ~90 MiB each) ≈ 424 MiB, for a permitted ceiling of
    | ~1960 MiB against the 2048 MiB flex-2gb Worker box (RV-4 resize,
    | 2026-07-24). Every environment must name all four lanes explicitly —
    | that is what HorizonQueueCoverageTest walks to prove every dispatchable
    | queue has a consumer in every env.
    |
    */

    'environments' => [

        'production' => [
            'supervisor-1' => ['maxProcesses' => 2],
            'supervisor-mail' => ['maxProcesses' => 2],
            'supervisor-long' => ['maxProcesses' => 1],
            'supervisor-videos' => ['maxProcesses' => 1],
        ],

        'development' => [
            'supervisor-1' => ['maxProcesses' => 2],
            'supervisor-mail' => ['maxProcesses' => 2],
            'supervisor-long' => ['maxProcesses' => 1],
            'supervisor-videos' => ['maxProcesses' => 1],
        ],

        'local' => [
            'supervisor-1' => ['maxProcesses' => 3],
            'supervisor-mail' => ['maxProcesses' => 1],
            'supervisor-long' => ['maxProcesses' => 2],
            'supervisor-videos' => ['maxProcesses' => 1],
        ],

    ],

];
