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
        'redis:stripe' => 30,
        'redis:integrations' => 60,
        'redis:notifications' => 60,
        'redis:default' => 60,
        // Exports are async by design — a 5-min queue wait is acceptable.
        'redis:exports' => 300,
        'redis:analytics' => 300,
        'redis:images' => 300,
        'redis:mail' => 120,
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
        'supervisor-stripe' => [
            'connection' => 'redis',
            'queue' => ['stripe'],
            'balance' => false,
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 180,
            'nice' => 0,
        ],
        'supervisor-integrations' => [
            'connection' => 'redis',
            'queue' => ['integrations'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 4,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 128,
            'tries' => 1,
            'timeout' => 90,
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
            'timeout' => 60,
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
        // Async commission exports. ExportChunkJob + ExportFinalizerJob run on this
        // queue. memory=256 to handle JSONL parts for large payout windows; timeout=300
        // matches the per-chunk Stripe page-fetch budget. Capped at 2 processes to stay
        // within Stripe rate limits across concurrent exports.
        'supervisor-exports' => [
            'connection' => 'redis',
            'queue' => ['exports'],
            'balance' => false,
            'maxProcesses' => 2,
            'maxTime' => 0,
            'maxJobs' => 0,
            'memory' => 256,
            'tries' => 1,
            'timeout' => 300,
            'nice' => 0,
        ],
    ],

    'environments' => [

        'production' => [
            'supervisor-stripe' => ['maxProcesses' => 2],
            'supervisor-integrations' => ['minProcesses' => 1, 'maxProcesses' => 4],
            'supervisor-notifications' => ['minProcesses' => 1, 'maxProcesses' => 3],
            'supervisor-default' => ['minProcesses' => 1, 'maxProcesses' => 3],
            'supervisor-analytics' => ['minProcesses' => 1, 'maxProcesses' => 2],
            'supervisor-gdpr' => ['maxProcesses' => 1],
            'supervisor-videos' => ['maxProcesses' => 2],
            'supervisor-exports' => ['maxProcesses' => 2],
        ],

        'development' => [
            'supervisor-1' => [
                'connection' => 'redis',
                'queue' => ['stripe', 'integrations', 'notifications', 'mail', 'default', 'exports', 'analytics', 'images'],
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
                'queue' => ['stripe', 'integrations', 'notifications', 'mail', 'default', 'exports', 'analytics', 'images'],
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
