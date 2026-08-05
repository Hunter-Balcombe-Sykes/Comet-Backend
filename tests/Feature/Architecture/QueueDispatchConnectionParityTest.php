<?php

// Pins the `redis_request` dispatch-only queue connection introduced alongside the per-request
// Redis breaker (docs/superpowers/specs/2026-08-05-redis-request-breaker-design.md §2.9).
//
// The problem it answers: drill 03 (2026-08-05) measured POST /api/public/analytics/pageviews at
// 15.06s against a hung Redis — one 15s op. QueuedIngestor dispatched RecordAnalyticsEventJob
// through `queue.connections.redis`, whose `connection` is `default` — a 15.0s read_timeout sized
// for queue workers' BLPOP, not a user-facing request (see config/database.php). `default` must
// NOT be lowered; RedisTimeoutBoundsTest pins it above the largest block_for.
//
// The fix is `redis_request`: a copy of `redis` with `connection` hardcoded to `app` (the
// request-path Redis view, read_timeout 3.0s) and `block_for` null (dispatch-only — nothing
// consumes it). This is only safe because `app` and `default` are two views of the SAME Redis DB 0
// with the SAME `laravel_database_` prefix, so a job pushed via `redis_request` lands on
// byte-identical queue keys to one pushed via `redis`, and Horizon's existing `redis` supervisors
// consume it unchanged. If that keyspace parity ever drifted — different `database` index, or a
// different `prefix` — a job pushed to `redis_request` would silently vanish into a queue no
// worker ever drains. That is the load-bearing assertion in this file.

it('defines redis_request as a redis-driver queue connection', function () {
    $connection = config('queue.connections.redis_request');

    expect($connection)->not->toBeNull('queue.connections.redis_request is not defined');
    expect($connection['driver'])->toBe('redis', 'redis_request must use the redis driver — it is a copy of the redis connection');
});

it('mirrors queue, retry_after and after_commit between redis_request and redis', function () {
    $redis = config('queue.connections.redis');
    $redisRequest = config('queue.connections.redis_request');

    // Compare RESOLVED config values, not source text — both read the same env vars with the
    // same defaults, so if either default or env value ever drifts, this catches it live.
    expect($redisRequest['queue'])->toBe(
        $redis['queue'],
        'redis_request must dispatch to the SAME queue name as redis, or Horizon\'s redis supervisors will never see the job'
    );

    expect($redisRequest['retry_after'])->toBe(
        $redis['retry_after'],
        'redis_request.retry_after has drifted from redis.retry_after — the two blocks are meant to read identical env vars'
    );

    expect($redisRequest['after_commit'])->toBe(
        $redis['after_commit'],
        'redis_request.after_commit has drifted from redis.after_commit — the two blocks are meant to read identical env vars'
    );
});

it('sets redis_request block_for to null because nothing ever consumes it', function () {
    expect(config('queue.connections.redis_request.block_for'))->toBeNull(
        'redis_request is dispatch-only — a non-null block_for would signal a worker is consuming '
            .'from it, which must never happen (it would busy-poll against the app connection)'
    );
});

it('keeps redis_request and redis pushing to the identical Redis keyspace', function () {
    // The load-bearing assertion: redis_request hardcodes 'connection' => 'app'; `redis` resolves
    // its connection from env('REDIS_QUEUE_CONNECTION', 'default'). Those must be two views of the
    // SAME underlying Redis DB, or a job dispatched via redis_request lands on keys no worker
    // ever drains.
    //
    // RESOLVE THE COMPARISON TARGET, NEVER HARDCODE 'default'. config/database.php documents
    // `queue` (DB 3) as exactly the slot REDIS_QUEUE_CONNECTION would point at. Comparing `app`
    // against a literal 'default' would keep this test green while REDIS_QUEUE_CONNECTION=queue
    // silently split dispatch (DB 0) from consumption (DB 3) — every analytics beacon lost
    // forever, with no error anywhere. Reading the name back from config is what makes flipping
    // that env var fail CI instead of failing production.
    $dispatchName = config('queue.connections.redis_request.connection');
    $consumeName = config('queue.connections.redis.connection');

    $dispatch = config("database.redis.{$dispatchName}");
    $consume = config("database.redis.{$consumeName}");

    expect($dispatch)->not->toBeNull("database.redis.{$dispatchName} is not defined");
    expect($consume)->not->toBeNull("database.redis.{$consumeName} is not defined");

    foreach (['host', 'port', 'database'] as $key) {
        expect($dispatch[$key] ?? null)->toBe(
            $consume[$key] ?? null,
            "database.redis.{$dispatchName} and database.redis.{$consumeName} disagree on [{$key}] — "
                .'jobs dispatched on `redis_request` would land on keys no `redis` worker ever drains'
        );
    }

    // Prefix parity matters as much as the DB index: a per-connection `options.prefix` on either
    // side would namespace the queue keys apart even on the same DB. Neither carries one today —
    // both inherit database.redis.options.prefix — and this pins that.
    expect($dispatch['options']['prefix'] ?? null)->toBe(
        $consume['options']['prefix'] ?? null,
        "database.redis.{$dispatchName} and database.redis.{$consumeName} carry different key prefixes — "
            .'the queue keys would be namespaced apart despite sharing a DB'
    );
});

it('bounds app.read_timeout at or under 3.0s and default.read_timeout above every redis queue block_for', function () {
    $appReadTimeout = (float) config('database.redis.app.read_timeout');
    $defaultReadTimeout = (float) config('database.redis.default.read_timeout');

    expect($appReadTimeout)->toBeLessThanOrEqual(
        3.0,
        "database.redis.app.read_timeout is {$appReadTimeout}s — redis_request dispatch would inherit a bound wider than the request-path ceiling"
    );

    $largestBlockFor = 0.0;
    $consumedRedisQueuesFound = 0;

    foreach (config('queue.connections') as $queueName => $queue) {
        if (($queue['driver'] ?? null) !== 'redis') {
            continue;
        }

        // redis_request itself is dispatch-only (block_for null) and never consumed — it is not
        // part of the worker-path bound this assertion protects.
        if ($queueName === 'redis_request') {
            continue;
        }

        $consumedRedisQueuesFound++;
        $largestBlockFor = max($largestBlockFor, (float) ($queue['block_for'] ?? 0));
    }

    expect($consumedRedisQueuesFound)->toBeGreaterThan(0, 'no consumed redis-driver queue connections were found to compute the largest block_for from');

    expect($defaultReadTimeout)->toBeGreaterThan(
        $largestBlockFor,
        "database.redis.default.read_timeout is {$defaultReadTimeout}s but a queue worker BLPOPs for up to {$largestBlockFor}s — "
            .'adding redis_request must not have quietly narrowed the worker-path bound'
    );
});
