<?php

// Pins the two halves of the phpredis timeout invariant:
//
//   1. No redis connection is unbounded.
//   2. On any connection a queue worker BLPOPs, read_timeout > block_for.
//
// Why (1): phpredis defaults BOTH `timeout` (connect) and `read_timeout` to
// 0.0 = wait forever. Until 2026-07-31 none of the five connections in
// config/database.php set either, so a hung or packet-dropping Redis would
// hang the PHP process indefinitely. The Redis-down drill did not surface this
// because a *refused* socket fails in ~50 ms (the OS refuses immediately,
// measured 34-52 ms) — the unbounded case only bites when the server accepts
// the connection and then stops answering.
//
// Why (2) is the dangerous direction, and the reason this file exists: every
// redis queue connection in config/queue.php resolves to the `default` redis
// connection via REDIS_QUEUE_CONNECTION, and Horizon's `use` is 'default' too.
// Each queue sets block_for, so Laravel issues `BLPOP <key> <block_for>` and the
// server legitimately holds the socket for block_for seconds on EVERY idle
// worker poll. Set read_timeout at or below block_for and phpredis tears the
// socket down mid-command — verified directly on phpredis 6.3.0:
//
//   $r->connect('127.0.0.1', 6379, 2.0, null, 0, 2.0);
//   $r->blpop(['missing'], 5);
//   => RedisException: read error on connection to 127.0.0.1:6379, after 2.00s
//
// Laravel's Worker catches that, reports it, and sleeps — so it produces NO
// stdout. A 45-second `queue:work` run looks completely clean while every poll
// is failing. The signature is in the log, not the terminal. That is why this
// is a test and not a runbook step: the manual check reads as PASS when broken.
//
// block_for is env-tunable per queue (REDIS_QUEUE_BLOCK_FOR,
// PARTNA_VIDEO_QUEUE_BLOCK_FOR, PARTNA_GDPR_QUEUE_BLOCK_FOR,
// PARTNA_SCRAPING_QUEUE_BLOCK_FOR), so the coupling cannot be checked by
// eyeballing two literals — it has to be computed from resolved config.
//
// Source: docs/runbooks/drills/logs/2026-07-31-redis-down.md, Finding 3.

it('bounds connect and read timeouts on every redis connection', function () {
    $connections = collect(config('database.redis'))
        // 'client' is a string and 'options' is the shared option bag — neither
        // is a connection. Everything else in this array is.
        ->except(['client', 'options']);

    expect($connections)->not->toBeEmpty();

    foreach ($connections as $name => $config) {
        expect($config['timeout'] ?? 0.0)
            ->toBeGreaterThan(0.0, "redis connection [{$name}] has no connect timeout (phpredis default 0.0 = wait forever)");

        expect($config['read_timeout'] ?? 0.0)
            ->toBeGreaterThan(0.0, "redis connection [{$name}] has no read timeout (phpredis default 0.0 = wait forever)");
    }
});

it('keeps read_timeout above block_for on every connection a worker blocks on', function () {
    // Longest block_for per underlying redis connection. Several queue
    // connections share one redis connection, and the binding constraint is the
    // largest of them — a 15s read_timeout is safe against block_for=5 but not
    // against a block_for=20 added later on a sibling queue.
    $longestBlockFor = [];

    foreach (config('queue.connections') as $queueName => $queue) {
        if (($queue['driver'] ?? null) !== 'redis') {
            continue;
        }

        $redisConnection = $queue['connection'] ?? 'default';
        $blockFor = (float) ($queue['block_for'] ?? 0);

        $longestBlockFor[$redisConnection] = max(
            $longestBlockFor[$redisConnection] ?? 0.0,
            $blockFor,
        );

        expect(config("database.redis.{$redisConnection}"))
            ->not->toBeNull("queue [{$queueName}] points at redis connection [{$redisConnection}], which does not exist");
    }

    expect($longestBlockFor)->not->toBeEmpty();

    foreach ($longestBlockFor as $redisConnection => $blockFor) {
        $readTimeout = (float) (config("database.redis.{$redisConnection}.read_timeout") ?? 0.0);

        expect($readTimeout)->toBeGreaterThan(
            $blockFor,
            "redis connection [{$redisConnection}] has read_timeout {$readTimeout}s but a queue BLPOPs it for {$blockFor}s. "
                ."phpredis will throw 'read error on connection' on every idle worker poll, silently, in the log only."
        );
    }
});
