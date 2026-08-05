<?php

// Unit coverage for App\Services\Redis\RedisRequestBreaker in isolation — no
// Redis, no HTTP kernel. GuardedPhpRedisConnectionTest covers the seam that
// actually consumes isOpen()/trip(); RedisBreakerWiringTest covers
// installation. This file is the state machine on its own:
//   - inert until arm() (console/queue inertness — the single most important
//     property in the class, see the class docblock)
//   - arm() resets prior state
//   - trip() is armed-gated and idempotent on the reason
//   - recordSkip()/skippedCommands() and disarm()
//   - isTransportFailure()'s trip predicate, table-driven

use App\Services\Redis\RedisRequestBreaker;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

it('is not armed and not open when freshly constructed', function () {
    $breaker = new RedisRequestBreaker;

    expect($breaker->isArmed())->toBeFalse();
    expect($breaker->isOpen())->toBeFalse();
    expect($breaker->reason())->toBeNull();
});

it('is a no-op to trip an un-armed breaker — console/queue inertness', function () {
    $breaker = new RedisRequestBreaker;

    $breaker->trip(new RedisException('read error on connection'), 'cache', 'get');

    expect($breaker->isOpen())->toBeFalse();
    expect($breaker->reason())->toBeNull();
});

it('opens and records a reason naming the connection and command after arm() and a transport-failure trip', function () {
    $breaker = new RedisRequestBreaker;
    $breaker->arm();

    $breaker->trip(new RedisException('read error on connection'), 'cache', 'get');

    expect($breaker->isOpen())->toBeTrue();
    expect($breaker->reason())->toContain('cache');
    expect($breaker->reason())->toContain('get');
});

it('clears open, reason and skipped every time arm() runs — the per-request reset', function () {
    $breaker = new RedisRequestBreaker;
    $breaker->arm();
    $breaker->trip(new RedisException('read error on connection'), 'cache', 'get');
    $breaker->recordSkip();

    expect($breaker->isOpen())->toBeTrue();
    expect($breaker->skippedCommands())->toBe(1);

    $breaker->arm();

    expect($breaker->isOpen())->toBeFalse();
    expect($breaker->reason())->toBeNull();
    expect($breaker->skippedCommands())->toBe(0);
});

it('keeps the first trip reason on a second, different trip — idempotent for diagnostic value', function () {
    $breaker = new RedisRequestBreaker;
    $breaker->arm();

    $breaker->trip(new RedisException('read error on connection'), 'cache', 'get');
    $firstReason = $breaker->reason();

    $breaker->trip(new RedisException('connection refused'), 'app', 'set');

    expect($breaker->reason())->toBe($firstReason);
    expect($breaker->reason())->toContain('cache');
});

it('counts skipped commands via recordSkip()/skippedCommands()', function () {
    $breaker = new RedisRequestBreaker;

    expect($breaker->skippedCommands())->toBe(0);

    $breaker->recordSkip();
    $breaker->recordSkip();
    $breaker->recordSkip();

    expect($breaker->skippedCommands())->toBe(3);
});

it('disarm() clears everything including armed', function () {
    $breaker = new RedisRequestBreaker;
    $breaker->arm();
    $breaker->trip(new RedisException('read error on connection'), 'cache', 'get');
    $breaker->recordSkip();

    $breaker->disarm();

    expect($breaker->isArmed())->toBeFalse();
    expect($breaker->isOpen())->toBeFalse();
    expect($breaker->reason())->toBeNull();
    expect($breaker->skippedCommands())->toBe(0);
});

it('recognises a RedisException transport-failure message', function (string $message) {
    expect(RedisRequestBreaker::isTransportFailure(new RedisException($message)))->toBeTrue();
})->with([
    'read error on connection' => ['read error on connection to 127.0.0.1:6379, after 3.00s'],
    'Redis server went away' => ['Redis server went away'],
    'socket error on read socket' => ['socket error on read socket'],
    'Connection lost' => ['Connection lost'],
    'Connection refused' => ['Connection refused'],
    'Connection timed out' => ['Connection timed out'],
    "Can't connect to redis" => ["Can't connect to redis"],
    'Connection closed' => ['Connection closed'],
    'No connection' => ['No connection'],
    'Error while reading line from the server' => ['Error while reading line from the server'],
    // The PACKET-DROP shape, and the reason these three are not in Laravel's
    // own list. A TCP connect that never completes (security group changed,
    // node fenced, network partition) throws exactly 'Operation timed out' —
    // verified against real phpredis 6.3.0 on an unroutable address. The
    // `DEBUG SLEEP` drill CANNOT produce it: a sleeping server still completes
    // the handshake, so it fails later at SELECT with 'read error on
    // connection'. A green drill is not evidence these lines are redundant.
    'Operation timed out (phpredis connect timeout)' => ['Operation timed out'],
    'getaddrinfo (DNS failure)' => ['php_network_getaddresses: getaddrinfo for redis.invalid failed'],
    'Connection reset by peer' => ['Connection reset by peer'],
    // Casing varied deliberately — the predicate must be case-insensitive
    // because the message text comes from the phpredis extension, not us.
    'READ ERROR ON CONNECTION (uppercase)' => ['READ ERROR ON CONNECTION to 127.0.0.1:6379'],
    'OPERATION TIMED OUT (uppercase)' => ['OPERATION TIMED OUT'],
]);

it('does not trip on a logical/command-shape error or a wrong exception class', function (Throwable $e) {
    expect(RedisRequestBreaker::isTransportFailure($e))->toBeFalse();
})->with([
    'WRONGTYPE' => [new RedisException('WRONGTYPE Operation against a key holding the wrong kind of value')],
    'NOSCRIPT' => [new RedisException('NOSCRIPT No matching script')],
    'NOAUTH' => [new RedisException('NOAUTH Authentication required')],
    'OOM' => [new RedisException('OOM command not allowed when used memory > maxmemory')],
    'MISCONF' => [new RedisException('MISCONF Redis is configured to save RDB snapshots')],
    // Deliberately excluded per the TRANSPORT_FAILURE_FRAGMENTS docblock:
    // READONLY is a replica-topology error, not an unresponsive server.
    'READONLY (deliberately excluded)' => [new RedisException('READONLY You can t write against a read only replica')],
    // Right message text, wrong exception class — isTransportFailure() must
    // gate on `instanceof RedisException` first, not just the message.
    'plain RuntimeException with a transport-sounding message' => [new RuntimeException('read error on connection')],
]);
