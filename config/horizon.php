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
        'redis:scraping' => 300,
        'redis_gdpr:gdpr' => 600,
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

    'defaults' => [
        // High-priority lane for immediate-impact moderation jobs (suspend, notify on-call).
        // Kept isolated from the default queue so a moderation spike never starves notifications.
        'supervisor-moderation-high' => [
            'connection' => 'redis',
            'queue' => ['moderation_high'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 3,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 3,
            'timeout' => 60,
            'nice' => 0,
        ],
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
            'timeout' => 130,
            'nice' => 0,
        ],
        'supervisor-default' => [
            'connection' => 'redis',
            'queue' => ['default'],
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
        'supervisor-streaming' => [
            'connection' => 'redis',
            'queue' => ['streaming'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 120,
            'nice' => 0,
        ],
        // Capped to prevent analytics backlogs from starving critical queues.
        // nice=10 also deprioritises these at the OS scheduler level.
        // memory=256: images queue split into its own supervisor, so heap spikes
        // from image transformation no longer affect analytics workers.
        'supervisor-analytics' => [
            'connection' => 'redis',
            'queue' => ['analytics'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 300,
            'nice' => 10,
        ],
        // Cloudflare KV and cache-purge jobs run on their own supervisor so a burst of
        // platform-connection writes (SyncSubdomainToKvJob) or site mutations
        // (CloudflareCachePurgeJob) cannot compete with user-facing notifications or mail.
        // Low process count: Cloudflare's API is rate-limited, not CPU-bound.
        'supervisor-cloudflare' => [
            'connection' => 'redis',
            'queue' => ['cloudflare'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            // Non-urgent background work — de-prioritised at the OS scheduler like cache-warm.
            'nice' => 5,
        ],
        // Cache-warm jobs are background best-effort. Isolated so a post-publish
        // burst of WarmPublicSiteCacheJob dispatches doesn't crowd out notifications.
        'supervisor-cache-warm' => [
            'connection' => 'redis',
            'queue' => ['cache-warm'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 60,
            'nice' => 5,
        ],
        // Images queue is split from analytics to prevent slow image jobs from
        // blocking analytics ingest. Memory stays high (512) for PHP heap spikes
        // during image transformation.
        'supervisor-images' => [
            'connection' => 'redis',
            'queue' => ['images'],
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
        // Platform scraping jobs (InstagramConnectJob — billed Apify scrape + parallel
        // image mirroring, 150s timeout; DeleteMirroredMediaJob cleanup). Isolated on
        // its own supervisor so a burst of connects can't starve user-facing queues —
        // and, crucially, so the `scraping` queue is actually consumed (previously no
        // supervisor ran it, so InstagramConnectJob never executed in any env). External
        // APIs are rate-limited, not CPU-bound → low process count, nice'd. timeout 180
        // exceeds the job's 150s so Horizon doesn't SIGKILL mid-scrape (and stays under
        // the redis connection's retry_after=360 so the job can't be re-queued mid-run).
        'supervisor-scraping' => [
            'connection' => 'redis',
            'queue' => ['scraping'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 180,
            'nice' => 10,
        ],
        // Must use the redis_gdpr connection (retry_after=660), NOT the default redis
        // connection (retry_after=360). RedactShopJob has $timeout=600; with the default
        // connection the job would be re-queued mid-run, causing concurrent duplicate
        // execution of a destructive GDPR operation. Worker timeout must also exceed
        // 600s so Horizon does not SIGKILL mid-run.
        'supervisor-gdpr' => [
            'connection' => 'redis_gdpr',
            'queue' => ['gdpr'],
            'balance' => false,
            'maxProcesses' => 1,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 660,
            'nice' => 0,
        ],
        // Videos use a dedicated Redis connection with a 1-hour retry_after.
        // Run separately via: php artisan queue:work redis_video --queue=videos --timeout=3600
        'supervisor-videos' => [
            'connection' => 'redis_video',
            'queue' => ['videos'],
            'balance' => false,
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 512,
            'tries' => 1,
            'timeout' => 3600,
            'nice' => 5,
        ],
    ],

    'environments' => [

        'production' => [
            'supervisor-moderation-high' => ['minProcesses' => 1, 'maxProcesses' => 3],
            'supervisor-notifications' => ['minProcesses' => 1, 'maxProcesses' => 3],
            'supervisor-default' => ['minProcesses' => 1, 'maxProcesses' => 3],
            'supervisor-cloudflare' => ['minProcesses' => 1, 'maxProcesses' => 2],
            'supervisor-cache-warm' => ['minProcesses' => 1, 'maxProcesses' => 2],
            'supervisor-analytics' => ['minProcesses' => 1, 'maxProcesses' => 2],
            'supervisor-streaming' => ['minProcesses' => 1, 'maxProcesses' => 2],
            'supervisor-images' => ['minProcesses' => 1, 'maxProcesses' => 2],
            'supervisor-scraping' => ['minProcesses' => 1, 'maxProcesses' => 2],
            'supervisor-gdpr' => ['maxProcesses' => 1],
            'supervisor-videos' => ['maxProcesses' => 2],
        ],

        'development' => [
            'supervisor-1' => [
                'connection' => 'redis',
                'queue' => ['moderation_high', 'notifications', 'mail', 'default', 'cloudflare', 'cache-warm', 'analytics', 'images', 'streaming', 'scraping'],
                'balance' => 'simple',
                'maxProcesses' => 3,
                'tries' => 1,
                'timeout' => 300,
            ],
            'supervisor-gdpr' => [
                'connection' => 'redis_gdpr',
                'queue' => ['gdpr'],
                'balance' => false,
                'maxProcesses' => 1,
                'tries' => 1,
                'timeout' => 660,
            ],
            'supervisor-videos' => [
                'connection' => 'redis_video',
                'queue' => ['videos'],
                'balance' => false,
                'maxProcesses' => 1,
                'timeout' => 3600,
            ],
        ],

        'local' => [
            // Single supervisor for local dev — processes all queues in priority order.
            // `gdpr` is split out into its own supervisor because it requires the
            // redis_gdpr connection (see supervisor-gdpr note above).
            'supervisor-1' => [
                'connection' => 'redis',
                'queue' => ['moderation_high', 'notifications', 'mail', 'default', 'cloudflare', 'cache-warm', 'analytics', 'images', 'streaming', 'scraping'],
                'balance' => 'simple',
                'maxProcesses' => 3,
                'tries' => 1,
                'timeout' => 300,
            ],
            'supervisor-gdpr' => [
                'connection' => 'redis_gdpr',
                'queue' => ['gdpr'],
                'balance' => false,
                'maxProcesses' => 1,
                'tries' => 1,
                'timeout' => 660,
            ],
            'supervisor-videos' => [
                'connection' => 'redis_video',
                'queue' => ['videos'],
                'balance' => false,
                'maxProcesses' => 1,
                'timeout' => 3600,
            ],
        ],

    ],

];
