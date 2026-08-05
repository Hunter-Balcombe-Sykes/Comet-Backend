<?php

// The load-bearing test: proves "6 x 3s becomes 1 x 3s" without a real Redis.
//
// Uses a stub Redis client (CountingFailingRedisClient below) whose __call()
// counts invocations and throws. Connection::command() invokes
// `$this->client->{$method}(...$parameters)` — a dynamic method call, which
// PHP routes through the stub's __call() exactly the way it would route
// through phpredis's real methods. No Redis process involved.

use App\Services\Redis\Exceptions\RedisUnavailableException;
use App\Services\Redis\GuardedPhpRedisConnection;
use App\Services\Redis\RedisRequestBreaker;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

/**
 * Stand-in for the phpredis \Redis client. Every method call increments
 * $calls and throws the configured RedisException message — it never
 * "succeeds", so any invocation the guard fails to skip is directly visible
 * in the counter.
 */
class CountingFailingRedisClient
{
    public int $calls = 0;

    public function __construct(private readonly string $message) {}

    public function __call(string $method, array $args): mixed
    {
        $this->calls++;

        throw new RedisException($this->message);
    }
}

function makeGuardedConnection(
    CountingFailingRedisClient $stub,
    RedisRequestBreaker $breaker,
    ?callable $connector = null,
): GuardedPhpRedisConnection {
    // A resolver, mirroring how RedisBreakerServiceProvider wires it.
    $connection = new GuardedPhpRedisConnection($stub, $connector, [], fn () => $breaker);
    $connection->setName('cache');

    return $connection;
}

it('throws the stub exception on the first armed command, calls the stub exactly once, and opens the breaker', function () {
    $stub = new CountingFailingRedisClient('read error on connection');
    $breaker = new RedisRequestBreaker;
    $breaker->arm();
    $connection = makeGuardedConnection($stub, $breaker);

    expect(fn () => $connection->command('get', ['key']))->toThrow(RedisException::class);

    expect($stub->calls)->toBe(1);
    expect($breaker->isOpen())->toBeTrue();
});

it('skips commands 2 through 6 with RedisUnavailableException while the stub call count stays at 1', function () {
    // This is the whole fix in one assertion: the stub represents the real
    // Redis socket, and once the breaker has tripped on command 1, commands
    // 2-6 must never touch it again — that is the difference between paying
    // read_timeout six times and paying it once.
    $stub = new CountingFailingRedisClient('read error on connection');
    $breaker = new RedisRequestBreaker;
    $breaker->arm();
    $connection = makeGuardedConnection($stub, $breaker);

    try {
        $connection->command('get', ['key-1']);
    } catch (RedisException) {
        // expected — asserted in the previous test.
    }

    for ($i = 2; $i <= 6; $i++) {
        expect(fn () => $connection->command('get', ["key-{$i}"]))
            ->toThrow(RedisUnavailableException::class);
    }

    expect($stub->calls)->toBe(
        1,
        "expected the stub to have been called exactly once (the first failure), but it was called {$stub->calls} times — "
            .'commands 2-6 are re-hitting the transport instead of being skipped by the open breaker.'
    );
});

it('RedisUnavailableException is a RedisException but not a LockTimeoutException', function () {
    $stub = new CountingFailingRedisClient('read error on connection');
    $breaker = new RedisRequestBreaker;
    $breaker->arm();
    $connection = makeGuardedConnection($stub, $breaker);

    try {
        $connection->command('get', ['key-1']);
    } catch (RedisException) {
        // expected — the first call trips the breaker.
    }

    try {
        $connection->command('get', ['key-2']);
        $caught = null;
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->toBeInstanceOf(RedisUnavailableException::class);
    expect($caught)->toBeInstanceOf(RedisException::class);
    expect($caught)->not->toBeInstanceOf(LockTimeoutException::class);
});

it('reaches the stub for every command and never opens when the breaker is un-armed', function () {
    $stub = new CountingFailingRedisClient('read error on connection');
    $breaker = new RedisRequestBreaker; // never arm()'d — console/queue shape
    $connection = makeGuardedConnection($stub, $breaker);

    for ($i = 1; $i <= 4; $i++) {
        try {
            $connection->command('get', ["key-{$i}"]);
        } catch (RedisException) {
            // expected — vendor behaviour, unguarded.
        }
    }

    expect($stub->calls)->toBe(4);
    expect($breaker->isArmed())->toBeFalse();
    expect($breaker->isOpen())->toBeFalse();
});

it('does not trip the breaker on a logical error, and a second command still reaches the stub', function () {
    $stub = new CountingFailingRedisClient('WRONGTYPE Operation against a key holding the wrong kind of value');
    $breaker = new RedisRequestBreaker;
    $breaker->arm();
    $connection = makeGuardedConnection($stub, $breaker);

    try {
        $connection->command('get', ['key-1']);
    } catch (RedisException) {
        // expected — WRONGTYPE propagates untouched.
    }

    expect($breaker->isOpen())->toBeFalse();

    try {
        $connection->command('get', ['key-2']);
    } catch (RedisException) {
        // expected again — still not a transport failure.
    }

    expect($stub->calls)->toBe(2);
    expect($breaker->isOpen())->toBeFalse();
});

it('suppresses the vendor eager reconnect on the armed path but not on the un-armed path', function () {
    // Armed path: the connector must NOT fire after the first failing
    // command — GuardedPhpRedisConnection nulls $this->connector for the
    // duration of an armed call specifically to prevent this.
    $armedStub = new CountingFailingRedisClient('read error on connection');
    $armedConnectorCalls = 0;
    $armedConnector = function () use (&$armedConnectorCalls, $armedStub) {
        $armedConnectorCalls++;

        return $armedStub;
    };
    $armedBreaker = new RedisRequestBreaker;
    $armedBreaker->arm();
    $armedConnection = makeGuardedConnection($armedStub, $armedBreaker, $armedConnector);

    try {
        $armedConnection->command('get', ['key']);
    } catch (RedisException) {
        // expected.
    }

    expect($armedConnectorCalls)->toBe(
        0,
        "expected the vendor's eager reconnect to be suppressed on the armed path, but the connector was called {$armedConnectorCalls} time(s)."
    );

    // Un-armed path: vendor behaviour is untouched, so the SAME transport
    // failure message must trigger exactly the reconnect it does today.
    $unarmedStub = new CountingFailingRedisClient('read error on connection');
    $unarmedConnectorCalls = 0;
    $unarmedConnector = function () use (&$unarmedConnectorCalls, $unarmedStub) {
        $unarmedConnectorCalls++;

        return $unarmedStub;
    };
    $unarmedBreaker = new RedisRequestBreaker; // never armed
    $unarmedConnection = makeGuardedConnection($unarmedStub, $unarmedBreaker, $unarmedConnector);

    try {
        $unarmedConnection->command('get', ['key']);
    } catch (RedisException) {
        // expected.
    }

    expect($unarmedConnectorCalls)->toBe(
        1,
        'expected the vendor eager reconnect to fire on the un-armed path (scoped suppression only), '
            ."but the connector was called {$unarmedConnectorCalls} time(s)."
    );
});
