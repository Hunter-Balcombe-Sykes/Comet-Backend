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
    | 'simple'/'auto' floor at one worker PER QUEUE (Supervisor::scale raises
    | maxProcesses to the pool count). The pre-2026-07-22 one-supervisor-per-
    | queue layout (~4 GiB box) is in git history at 9f45c291 if real load
    | ever justifies resurrecting it.
    |
    */

    'defaults' => [
        // Short-job lane: everything with $timeout ≤ 300s, priority-ordered.
        // redis retry_after=360 > timeout 300. memory=256 tolerates
        // ProcessImageVariantsJob heap spikes without restart churn.
        'supervisor-1' => [
            'connection' => 'redis',
            'queue' => ['moderation_high', 'notifications', 'mail', 'default', 'cloudflare', 'cache-warm', 'analytics', 'images', 'streaming', 'platform_refresh', 'platform_connect'],
            'balance' => false,
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 300,
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
    | middleman process per lane + workers, ~90 MiB each: idle deployed
    | footprint is 7 procs (~630 MiB); a busy main lane autoscales 1→2
    | (AutoScaler splits comma-joined pools correctly under balance=false).
    | Every environment must name all three lanes explicitly — that is what
    | HorizonQueueCoverageTest walks to prove every dispatchable queue has a
    | consumer in every env.
    |
    */

    'environments' => [

        'production' => [
            'supervisor-1' => ['maxProcesses' => 2],
            'supervisor-long' => ['maxProcesses' => 1],
            'supervisor-videos' => ['maxProcesses' => 1],
        ],

        'development' => [
            'supervisor-1' => ['maxProcesses' => 2],
            'supervisor-long' => ['maxProcesses' => 1],
            'supervisor-videos' => ['maxProcesses' => 1],
        ],

        'local' => [
            'supervisor-1' => ['maxProcesses' => 3],
            'supervisor-long' => ['maxProcesses' => 2],
            'supervisor-videos' => ['maxProcesses' => 1],
        ],

    ],

];
