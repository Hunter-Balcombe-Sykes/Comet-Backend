<?php

// Guards the thing most likely to silently break: the breaker classes being
// correct in isolation (see tests/Unit/Redis/*) but not actually installed —
// a connection resolving to the vendor PhpRedisConnection, or the arming
// middleware quietly falling out of the global stack.

use App\Services\Redis\Exceptions\RedisUnavailableException;
use App\Services\Redis\GuardedPhpRedisConnection;
use App\Services\Redis\RedisRequestBreaker;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

it('resolves every configured redis connection to GuardedPhpRedisConnection', function () {
    // Derived from config, not hardcoded — a new connection added to
    // config/database.php later is covered automatically. 'client' is a
    // string and 'options' is the shared option bag; neither is a connection.
    $connections = collect(config('database.redis'))->except(['client', 'options']);

    expect($connections)->not->toBeEmpty();

    foreach ($connections as $name => $config) {
        expect(Redis::connection($name))->toBeInstanceOf(
            GuardedPhpRedisConnection::class,
            "redis connection [{$name}] did not resolve to GuardedPhpRedisConnection — "
                .'RedisBreakerServiceProvider is not installing the guarded phpredis connector for it.'
        );
    }
});

it('arms RedisRequestBreaker for every HTTP request via the global middleware stack', function () {
    // A real request through the HTTP kernel is a stronger proof than
    // inspecting the configured middleware list: it exercises the exact path
    // a production request takes, including SortedMiddleware ordering.
    Route::get('api/__test/redis-breaker-armed', function () {
        return response()->json(['armed' => app(RedisRequestBreaker::class)->isArmed()]);
    });

    $response = $this->getJson('api/__test/redis-breaker-armed');

    $response->assertOk();
    expect($response->json('armed'))->toBeTrue();
});

it('binds RedisRequestBreaker as a container singleton', function () {
    expect(app(RedisRequestBreaker::class))->toBe(app(RedisRequestBreaker::class));
});

it('makes RedisUnavailableException a RedisException', function () {
    expect(is_subclass_of(RedisUnavailableException::class, RedisException::class))->toBeTrue(
        'RedisUnavailableException must extend RedisException — every existing fail-open catch '
            .'(CacheLockService, ResilientRateLimiter, QueuedIngestor, VerifySupabaseJwt) is written '
            .'as catch (Throwable)/catch (RedisException), and a plain RuntimeException would miss the latter.'
    );
});
