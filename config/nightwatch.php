<?php

/*
 * Laravel Nightwatch — observability config.
 *
 * Slow-route / slow-query / slow-job alert thresholds are configured in the
 * Nightwatch dashboard, not here. This file controls what events are
 * captured and shipped from this app.
 *
 * Defaults below mirror vendor/laravel/nightwatch/config/nightwatch.php
 * verbatim — publishing this file does not change runtime behavior.
 */

return [
    'enabled' => env('NIGHTWATCH_ENABLED', true),
    'token' => env('NIGHTWATCH_TOKEN'),

    // Identifies the deploy in the dashboard. Laravel Cloud sets LARAVEL_CLOUD_COMMIT automatically.
    'deployment' => env('NIGHTWATCH_DEPLOY', env('LARAVEL_CLOUD_COMMIT', env('FORGE_DEPLOY_COMMIT', env('VAPOR_COMMIT_HASH')))),
    'server' => env('NIGHTWATCH_SERVER', (string) gethostname()),

    // Capture surrounding lines around exception sites — opt-in; set NIGHTWATCH_CAPTURE_EXCEPTION_SOURCE_CODE=true when actively debugging.
    'capture_exception_source_code' => env('NIGHTWATCH_CAPTURE_EXCEPTION_SOURCE_CODE', false),

    // Off by default: request bodies often contain PII (Supabase JWTs, Shopify tokens, customer data).
    'capture_request_payload' => env('NIGHTWATCH_CAPTURE_REQUEST_PAYLOAD', false),

    // Field/header names scrubbed before send. Comma-separated.
    'redact_payload_fields' => explode(',', env('NIGHTWATCH_REDACT_PAYLOAD_FIELDS', '_token,password,password_confirmation')),
    'redact_headers' => explode(',', env('NIGHTWATCH_REDACT_HEADERS', 'Authorization,Cookie,Proxy-Authorization,X-XSRF-TOKEN')),

    // Per-event-type sampling. 1.0 = capture all; 0.1 = capture 10%.
    // Pre-beta we keep everything at 1.0; drop request rate first when traffic grows.
    'sampling' => [
        'requests' => env('NIGHTWATCH_REQUEST_SAMPLE_RATE', 1.0),
        'commands' => env('NIGHTWATCH_COMMAND_SAMPLE_RATE', 1.0),
        'exceptions' => env('NIGHTWATCH_EXCEPTION_SAMPLE_RATE', 1.0),
        'scheduled_tasks' => env('NIGHTWATCH_SCHEDULED_TASK_SAMPLE_RATE', 1.0),
    ],

    // Drop noisy event categories. Useful once volume is high; leave false during pre-beta.
    'filtering' => [
        'ignore_cache_events' => env('NIGHTWATCH_IGNORE_CACHE_EVENTS', false),
        'ignore_mail' => env('NIGHTWATCH_IGNORE_MAIL', false),
        'ignore_notifications' => env('NIGHTWATCH_IGNORE_NOTIFICATIONS', false),
        'ignore_outgoing_requests' => env('NIGHTWATCH_IGNORE_OUTGOING_REQUESTS', false),
        'ignore_queries' => env('NIGHTWATCH_IGNORE_QUERIES', false),
        'log_level' => env('NIGHTWATCH_LOG_LEVEL', env('LOG_LEVEL', 'warning')),
    ],

    // Local ingest agent — Laravel Cloud runs the daemon on 127.0.0.1:2407.
    'ingest' => [
        'uri' => env('NIGHTWATCH_INGEST_URI', '127.0.0.1:2407'),
        'timeout' => env('NIGHTWATCH_INGEST_TIMEOUT', 0.5),
        'connection_timeout' => env('NIGHTWATCH_INGEST_CONNECTION_TIMEOUT', 0.5),
        'event_buffer' => env('NIGHTWATCH_INGEST_EVENT_BUFFER', 500),
    ],
];
