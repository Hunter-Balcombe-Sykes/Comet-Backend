<?php

use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// RV-9: block_for was null on every redis connection, so workers polled in
// userland instead of blocking on BLPOP. 0 is forbidden (Laravel docs: it
// prevents SIGTERM being handled until the next job is processed, breaking
// zero-downtime deploys), so every connection must resolve to a positive int
// no matter what the env override says.
const QUEUE_BLOCK_FOR_CONNECTIONS = [
    'redis' => 'REDIS_QUEUE_BLOCK_FOR',
    'redis_video' => 'PARTNA_VIDEO_QUEUE_BLOCK_FOR',
    'redis_gdpr' => 'PARTNA_GDPR_QUEUE_BLOCK_FOR',
    'redis_scraping' => 'PARTNA_SCRAPING_QUEUE_BLOCK_FOR',
];

it('every redis queue connection has a positive int block_for, never 0 or null', function () {
    foreach (array_keys(QUEUE_BLOCK_FOR_CONNECTIONS) as $connection) {
        $blockFor = config("queue.connections.{$connection}.block_for");

        expect($blockFor)
            ->toBeInt("{$connection}.block_for must be an int, got ".var_export($blockFor, true))
            ->and($blockFor)->toBeGreaterThan(0, "{$connection}.block_for must be > 0 (0 blocks SIGTERM handling)");
    }
});

it('every connection defaults block_for to 5 when its env var is unset', function () {
    foreach (QUEUE_BLOCK_FOR_CONNECTIONS as $connection => $envKey) {
        putenv($envKey);

        $config = require base_path('config/queue.php');

        expect($config['connections'][$connection]['block_for'])->toBe(5);
    }
});

it('an env override of 0 cannot silently produce a 0 block_for', function () {
    foreach (QUEUE_BLOCK_FOR_CONNECTIONS as $connection => $envKey) {
        try {
            putenv("{$envKey}=0");

            $config = require base_path('config/queue.php');

            expect($config['connections'][$connection]['block_for'])
                ->toBeGreaterThan(0, "{$connection} block_for became 0 when {$envKey}=0");
        } finally {
            putenv($envKey);
        }
    }
});

it('an empty-string env override cannot silently produce a 0 block_for', function () {
    foreach (QUEUE_BLOCK_FOR_CONNECTIONS as $connection => $envKey) {
        try {
            putenv("{$envKey}=");

            $config = require base_path('config/queue.php');

            expect($config['connections'][$connection]['block_for'])
                ->toBeGreaterThan(0, "{$connection} block_for became 0 when {$envKey}=''");
        } finally {
            putenv($envKey);
        }
    }
});
