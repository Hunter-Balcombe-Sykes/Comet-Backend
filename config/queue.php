<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            // Must exceed the longest job $timeout on this connection (ProcessLogoVariantsJob
            // has $timeout = 280); use 360 to give a safety margin.
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 360),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            // Must exceed the longest job $timeout (300 s). See database connection above.
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 360),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            // Must exceed the longest job $timeout on this connection (ProcessLogoVariantsJob
            // has $timeout = 280); use 360 for a safety margin so a slow job is never
            // re-queued while still running.
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 360),
            // BLPOP for ~5s beats null (userland polling latency+Redis round trips) without
            // risking 0, which per the Laravel docs stalls SIGTERM handling and breaks deploys.
            'block_for' => max(1, (int) env('REDIS_QUEUE_BLOCK_FOR', 5)),
            'after_commit' => false,
        ],

        // DISPATCH-ONLY. Nothing ever consumes this connection — no worker, no Horizon
        // supervisor points here. It exists so the request path can PUSH a job without
        // inheriting `redis`'s `default` Redis connection and its 15.0s read_timeout,
        // sized for queue workers' BLPOP (see config/database.php). Drill 03 (2026-08-05)
        // measured POST /api/public/analytics/pageviews at 15.06s against a hung Redis —
        // ~one 15s op: QueuedIngestor dispatching RecordAnalyticsEventJob through `redis`,
        // whose `connection` resolves to `default`.
        //
        // `connection` is hardcoded to `app`, not env-driven — the whole point is that
        // this is the request-path view, not a configurable choice. `app` and `default`
        // are two views of the SAME Redis DB 0 with the same `laravel_database_` prefix
        // (see config/database.php's `app` connection comment), so a job pushed here
        // lands on byte-identical queue keys to one pushed via `redis`, and Horizon's
        // existing `redis` supervisors consume it unchanged — nothing about the queue
        // NAME or keyspace changes, only which Redis connection performs the push.
        //
        // `block_for` is null, not omitted or copied from `redis`: a consumer would
        // BLPOP and busy-poll against a connection with no read_timeout headroom for
        // blocking reads, which is a signal that something started consuming here by
        // mistake, not a config value to tune. `queue`, `retry_after` and `after_commit`
        // are mirrored byte-for-byte from `redis`, reading the SAME env vars with the
        // SAME defaults, purely so the two blocks can never drift apart and stay
        // comparable — the CONSUMING worker's `redis` block is what actually governs
        // reservation and re-queue timing; this connection is never reserved from.
        'redis_request' => [
            'driver' => 'redis',
            'connection' => 'app',
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 360),
            'block_for' => null,
            'after_commit' => false,
        ],

        // Dedicated connection for video transcoding jobs.
        // Higher retry_after to accommodate long-running ffmpeg encodes.
        // Run workers with: php artisan queue:work redis_video --queue=videos --timeout=3600
        'redis_video' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('PARTNA_VIDEO_QUEUE_NAME', env('SIDEST_VIDEO_QUEUE_NAME', 'videos')),
            'retry_after' => (int) env('PARTNA_VIDEO_QUEUE_RETRY_AFTER', env('SIDEST_VIDEO_QUEUE_RETRY_AFTER', 3600)),
            // BLPOP for ~5s beats null (userland polling latency+Redis round trips) without
            // risking 0, which per the Laravel docs stalls SIGTERM handling and breaks deploys.
            'block_for' => max(1, (int) env('PARTNA_VIDEO_QUEUE_BLOCK_FOR', 5)),
            'after_commit' => false,
        ],

        // Dedicated connection for GDPR jobs. retry_after must exceed ExportUserDataJob::$timeout
        // (600s) so Redis does not re-queue the job while it is still chunking through customers.
        // Run workers with: php artisan queue:work redis_gdpr --queue=gdpr --timeout=660
        'redis_gdpr' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('PARTNA_GDPR_QUEUE', env('GDPR_QUEUE', 'gdpr')),
            'retry_after' => (int) env('PARTNA_GDPR_QUEUE_RETRY_AFTER', env('GDPR_QUEUE_RETRY_AFTER', 660)),
            // BLPOP for ~5s beats null (userland polling latency+Redis round trips) without
            // risking 0, which per the Laravel docs stalls SIGTERM handling and breaks deploys.
            'block_for' => max(1, (int) env('PARTNA_GDPR_QUEUE_BLOCK_FOR', 5)),
            'after_commit' => false,
        ],

        // Dedicated connection for platform scraping jobs. retry_after must exceed
        // MenuFetchJob::$timeout (600s) so Redis never re-queues a still-running
        // scrape to a second worker (concurrent double-scrape + duplicate menu rows).
        // Run workers with: php artisan queue:work redis_scraping --queue=scraping --timeout=660
        'redis_scraping' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('PARTNA_QUEUE_SCRAPING', 'scraping'),
            'retry_after' => (int) env('PARTNA_SCRAPING_QUEUE_RETRY_AFTER', 660),
            // BLPOP for ~5s beats null (userland polling latency+Redis round trips) without
            // risking 0, which per the Laravel docs stalls SIGTERM handling and breaks deploys.
            'block_for' => max(1, (int) env('PARTNA_SCRAPING_QUEUE_BLOCK_FOR', 5)),
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'pgsql'),
        'table' => 'failed_jobs',
    ],

];
