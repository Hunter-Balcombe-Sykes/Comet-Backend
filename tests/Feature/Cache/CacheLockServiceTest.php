<?php

use App\Services\Cache\CacheLockService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Mockery as M;

beforeEach(function () {
    Cache::flush();
    $this->service = new CacheLockService;
});

afterEach(function () {
    M::close();
});

it('returns cached value without acquiring lock on cache hit', function () {
    Cache::shouldReceive('get')->with('test:key')->andReturn(['cached' => true])->once();
    Cache::shouldReceive('lock')->never();

    $result = $this->service->rememberLocked(
        'test:key',
        60,
        fn () => throw new RuntimeException('closure should not run'),
    );

    expect($result)->toBe(['cached' => true]);
});

it('runs closure and stores result on cache miss', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once();
    $lock->shouldReceive('release')->once()->andReturn(true);

    // Cold miss: primary null, stale null, double-check null.
    Cache::shouldReceive('get')->with('test:miss')->twice()->andReturn(null, null);
    Cache::shouldReceive('get')->with('test:miss:stale')->once()->andReturn(null);
    Cache::shouldReceive('lock')->with('lock:test:miss', 10)->once()->andReturn($lock);
    // Primary and stale both get independently jittered int TTLs (±20% of 60 and 600 respectively).
    Cache::shouldReceive('put')
        ->with('test:miss', ['fresh' => 'value'], M::type('int'))
        ->once();
    Cache::shouldReceive('put')
        ->with('test:miss:stale', ['fresh' => 'value'], M::type('int'))
        ->once();

    $result = $this->service->rememberLocked(
        'test:miss',
        60,
        fn () => ['fresh' => 'value'],
    );

    expect($result)->toBe(['fresh' => 'value']);
});

it('skips closure when cache fills during lock wait (double-check)', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once();
    $lock->shouldReceive('release')->once()->andReturn(true);

    // First Cache::get returns null (initial miss); second returns filled value
    // (another process filled it while we were waiting on the lock).
    Cache::shouldReceive('get')
        ->with('test:double')
        ->twice()
        ->andReturn(null, ['filled' => 'by other']);
    Cache::shouldReceive('get')->with('test:double:stale')->once()->andReturn(null);
    Cache::shouldReceive('lock')->with('lock:test:double', 10)->once()->andReturn($lock);
    Cache::shouldReceive('put')->never();

    $closureRan = false;
    $result = $this->service->rememberLocked(
        'test:double',
        60,
        function () use (&$closureRan) {
            $closureRan = true;

            return ['fresh' => 'should not run'];
        },
    );

    expect($result)->toBe(['filled' => 'by other']);
    expect($closureRan)->toBeFalse();
});

it('falls through to closure when lock acquisition times out and cache is still empty', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once()->andThrow(new LockTimeoutException);

    // Initial miss, stale miss, then re-check after timeout still returns null.
    Cache::shouldReceive('get')->with('test:timeout')->twice()->andReturn(null, null);
    Cache::shouldReceive('get')->with('test:timeout:stale')->once()->andReturn(null);
    Cache::shouldReceive('lock')->with('lock:test:timeout', 10)->once()->andReturn($lock);
    Cache::shouldReceive('put')->never();

    $result = $this->service->rememberLocked(
        'test:timeout',
        60,
        fn () => ['last' => 'resort'],
    );

    expect($result)->toBe(['last' => 'resort']);
});

it('returns cached value on lock timeout if cache filled in the meantime', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once()->andThrow(new LockTimeoutException);

    Cache::shouldReceive('get')
        ->with('test:timeout-filled')
        ->twice()
        ->andReturn(null, ['filled' => 'while waiting']);
    Cache::shouldReceive('get')->with('test:timeout-filled:stale')->once()->andReturn(null);
    Cache::shouldReceive('lock')->with('lock:test:timeout-filled', 10)->once()->andReturn($lock);

    $closureRan = false;
    $result = $this->service->rememberLocked(
        'test:timeout-filled',
        60,
        function () use (&$closureRan) {
            $closureRan = true;

            return ['should' => 'not run'];
        },
    );

    expect($result)->toBe(['filled' => 'while waiting']);
    expect($closureRan)->toBeFalse();
});

it('releases lock when closure throws', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once();
    $lock->shouldReceive('release')->once()->andReturn(true);

    Cache::shouldReceive('get')->with('test:throw')->twice()->andReturn(null, null);
    Cache::shouldReceive('get')->with('test:throw:stale')->once()->andReturn(null);
    Cache::shouldReceive('lock')->with('lock:test:throw', 10)->once()->andReturn($lock);
    Cache::shouldReceive('put')->never();

    $call = fn () => $this->service->rememberLocked(
        'test:throw',
        60,
        fn () => throw new RuntimeException('boom'),
    );

    expect($call)->toThrow(RuntimeException::class, 'boom');
});

it('honours custom lockSeconds and blockSeconds', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(2)->once();
    $lock->shouldReceive('release')->once()->andReturn(true);

    Cache::shouldReceive('get')->with('test:custom')->twice()->andReturn(null, null);
    Cache::shouldReceive('get')->with('test:custom:stale')->once()->andReturn(null);
    Cache::shouldReceive('lock')->with('lock:test:custom', 30)->once()->andReturn($lock);
    Cache::shouldReceive('put')
        ->with('test:custom', 'v', M::type('int'))
        ->once();
    Cache::shouldReceive('put')
        ->with('test:custom:stale', 'v', M::type('int'))
        ->once();

    $result = $this->service->rememberLocked(
        'test:custom',
        60,
        fn () => 'v',
        lockSeconds: 30,
        blockSeconds: 2,
    );

    expect($result)->toBe('v');
});

// rememberLockedNullable

it('nullable: returns cached non-null value without acquiring lock', function () {
    Cache::shouldReceive('get')->with('test:n:hit')->andReturn(['v' => 1])->once();
    Cache::shouldReceive('lock')->never();

    $result = $this->service->rememberLockedNullable(
        'test:n:hit',
        60,
        fn () => throw new RuntimeException('closure should not run'),
    );

    expect($result)->toBe(['v' => 1]);
});

it('nullable: returns null without acquiring lock when sentinel is cached', function () {
    Cache::shouldReceive('get')->with('test:n:sentinel')->andReturn('__cache_lock_null_sentinel__')->once();
    Cache::shouldReceive('lock')->never();

    $result = $this->service->rememberLockedNullable(
        'test:n:sentinel',
        60,
        fn () => throw new RuntimeException('closure should not run'),
    );

    expect($result)->toBeNull();
});

it('nullable: caches non-null callback result with ttl', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once();
    $lock->shouldReceive('release')->once()->andReturn(true);

    Cache::shouldReceive('get')->with('test:n:miss')->twice()->andReturn(null, null);
    Cache::shouldReceive('lock')->with('lock:test:n:miss', 10)->once()->andReturn($lock);
    Cache::shouldReceive('put')->with('test:n:miss', 'value', 60)->once();

    $result = $this->service->rememberLockedNullable(
        'test:n:miss',
        60,
        fn () => 'value',
    );

    expect($result)->toBe('value');
});

it('nullable: caches sentinel when callback returns null, using ttl by default', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once();
    $lock->shouldReceive('release')->once()->andReturn(true);

    Cache::shouldReceive('get')->with('test:n:null')->twice()->andReturn(null, null);
    Cache::shouldReceive('lock')->with('lock:test:n:null', 10)->once()->andReturn($lock);
    Cache::shouldReceive('put')->with('test:n:null', '__cache_lock_null_sentinel__', 60)->once();

    $result = $this->service->rememberLockedNullable(
        'test:n:null',
        60,
        fn () => null,
    );

    expect($result)->toBeNull();
});

it('nullable: uses nullTtl when caching the sentinel', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once();
    $lock->shouldReceive('release')->once()->andReturn(true);

    Cache::shouldReceive('get')->with('test:n:nullttl')->twice()->andReturn(null, null);
    Cache::shouldReceive('lock')->with('lock:test:n:nullttl', 10)->once()->andReturn($lock);
    // Sentinel uses 30s, NOT the 600s positive ttl.
    Cache::shouldReceive('put')->with('test:n:nullttl', '__cache_lock_null_sentinel__', 30)->once();

    $result = $this->service->rememberLockedNullable(
        'test:n:nullttl',
        600,
        fn () => null,
        nullTtl: 30,
    );

    expect($result)->toBeNull();
});

it('nullable: uses positive ttl for non-null even when nullTtl is set', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once();
    $lock->shouldReceive('release')->once()->andReturn(true);

    Cache::shouldReceive('get')->with('test:n:posttl')->twice()->andReturn(null, null);
    Cache::shouldReceive('lock')->with('lock:test:n:posttl', 10)->once()->andReturn($lock);
    Cache::shouldReceive('put')->with('test:n:posttl', 'fresh', 600)->once();

    $result = $this->service->rememberLockedNullable(
        'test:n:posttl',
        600,
        fn () => 'fresh',
        nullTtl: 30,
    );

    expect($result)->toBe('fresh');
});

it('nullable: skips closure when sentinel was cached during lock wait', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once();
    $lock->shouldReceive('release')->once()->andReturn(true);

    // Initial miss; second read inside the lock returns sentinel (filled by another process).
    Cache::shouldReceive('get')
        ->with('test:n:double-null')
        ->twice()
        ->andReturn(null, '__cache_lock_null_sentinel__');
    Cache::shouldReceive('lock')->with('lock:test:n:double-null', 10)->once()->andReturn($lock);
    Cache::shouldReceive('put')->never();

    $closureRan = false;
    $result = $this->service->rememberLockedNullable(
        'test:n:double-null',
        60,
        function () use (&$closureRan) {
            $closureRan = true;

            return 'should not run';
        },
    );

    expect($result)->toBeNull();
    expect($closureRan)->toBeFalse();
});

it('nullable: lock-timeout returns null when sentinel cached in the meantime', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once()->andThrow(new LockTimeoutException);

    Cache::shouldReceive('get')
        ->with('test:n:timeout-sentinel')
        ->twice()
        ->andReturn(null, '__cache_lock_null_sentinel__');
    Cache::shouldReceive('lock')->with('lock:test:n:timeout-sentinel', 10)->once()->andReturn($lock);

    $closureRan = false;
    $result = $this->service->rememberLockedNullable(
        'test:n:timeout-sentinel',
        60,
        function () use (&$closureRan) {
            $closureRan = true;

            return 'should not run';
        },
    );

    expect($result)->toBeNull();
    expect($closureRan)->toBeFalse();
});

it('nullable: throws if closure returns the reserved sentinel string', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once();
    $lock->shouldReceive('release')->once()->andReturn(true);

    Cache::shouldReceive('get')->with('test:n:reserved')->twice()->andReturn(null, null);
    Cache::shouldReceive('lock')->with('lock:test:n:reserved', 10)->once()->andReturn($lock);
    Cache::shouldReceive('put')->never();

    $call = fn () => $this->service->rememberLockedNullable(
        'test:n:reserved',
        60,
        fn () => '__cache_lock_null_sentinel__',
    );

    expect($call)->toThrow(LogicException::class, 'reserved');
});

it('stale key receives jittered TTL — not a fixed STALE_MULTIPLIER × base', function () {
    $staleTtls = [];

    for ($i = 0; $i < 20; $i++) {
        $lock = M::mock(Lock::class);
        $lock->shouldReceive('block')->with(5)->once();
        $lock->shouldReceive('release')->once()->andReturn(true);

        Cache::shouldReceive('get')->with("jitter:stale:$i")->twice()->andReturn(null, null);
        Cache::shouldReceive('get')->with("jitter:stale:$i:stale")->once()->andReturn(null);
        Cache::shouldReceive('lock')->with("lock:jitter:stale:$i", 10)->once()->andReturn($lock);
        Cache::shouldReceive('put')
            ->with("jitter:stale:$i", 'v', M::type('int'))
            ->once();
        Cache::shouldReceive('put')
            ->with("jitter:stale:$i:stale", 'v', M::on(function ($ttl) use (&$staleTtls) {
                $staleTtls[] = $ttl;

                return true;
            }))
            ->once();

        $this->service->rememberLocked("jitter:stale:$i", 60, fn () => 'v');
    }

    // Fixed stale (no jitter): all values identical → array_unique returns 1 element → fails.
    // Jittered stale: spreads across [480, 720]; 20 samples will contain >1 distinct value.
    expect(count(array_unique($staleTtls)))->toBeGreaterThan(1);

    foreach ($staleTtls as $ttl) {
        expect($ttl)->toBeGreaterThanOrEqual(480)->and($ttl)->toBeLessThanOrEqual(720);
    }
});

it('nullable: releases lock when closure throws', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once();
    $lock->shouldReceive('release')->once()->andReturn(true);

    Cache::shouldReceive('get')->with('test:n:throw')->twice()->andReturn(null, null);
    Cache::shouldReceive('lock')->with('lock:test:n:throw', 10)->once()->andReturn($lock);
    Cache::shouldReceive('put')->never();

    $call = fn () => $this->service->rememberLockedNullable(
        'test:n:throw',
        60,
        fn () => throw new RuntimeException('boom'),
    );

    expect($call)->toThrow(RuntimeException::class, 'boom');
});

// Lock-release failure counter

it('gives the lock-release failure counter a 7-day rolling TTL', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once();
    $lock->shouldReceive('release')->once()->andThrow(new RuntimeException('release failed'));

    Cache::shouldReceive('get')->with('test:ttl')->twice()->andReturn(null, null);
    Cache::shouldReceive('get')->with('test:ttl:stale')->once()->andReturn(null);
    Cache::shouldReceive('lock')->with('lock:test:ttl', 10)->once()->andReturn($lock);
    Cache::shouldReceive('put')->andReturn(true);

    // The counter lives on Redis DB 0 alongside Horizon. Without a TTL it is
    // inevictable under volatile-lru, which is the policy this instance needs.
    // recordLockReleaseFailure() reads through Redis::connection('app'), not
    // the bare facade (see drill 03, 2026-08-05 / RedisConnectionPinningTest).
    $redis = M::mock(Connection::class);
    Redis::shouldReceive('connection')->with('app')->andReturn($redis);
    $redis->shouldReceive('incr')->with('cache:lock_release_failures')->once();
    $redis->shouldReceive('expire')->with('cache:lock_release_failures', 604800)->once();

    $result = $this->service->rememberLocked('test:ttl', 60, fn () => 'value');

    expect($result)->toBe('value');
});

it('swallows a driver error from the counter rather than failing the request', function () {
    $lock = M::mock(Lock::class);
    $lock->shouldReceive('block')->with(5)->once();
    $lock->shouldReceive('release')->once()->andThrow(new RuntimeException('release failed'));

    Cache::shouldReceive('get')->with('test:swallow')->twice()->andReturn(null, null);
    Cache::shouldReceive('get')->with('test:swallow:stale')->once()->andReturn(null);
    Cache::shouldReceive('lock')->with('lock:test:swallow', 10)->once()->andReturn($lock);
    Cache::shouldReceive('put')->andReturn(true);

    // A failure to COUNT a failure must never cascade into the caller's request.
    $redis = M::mock(Connection::class);
    Redis::shouldReceive('connection')->with('app')->andReturn($redis);
    $redis->shouldReceive('incr')->andThrow(new RuntimeException('redis down'));
    Log::shouldReceive('warning')->with('cache.lock_release_failure_counter_failed', M::type('array'))->once();

    $result = $this->service->rememberLocked('test:swallow', 60, fn () => 'value');

    expect($result)->toBe('value');
});

it('rememberLockedNullable with nullTtl 0 remembers nothing', function () {
    // Guards the framework behaviour AppleSearch's CCH-1 fix rests on:
    // Illuminate\Cache\Repository::put() forwards $seconds <= 0 to forget(),
    // so nullTtl: 0 writes NOTHING at all — no key, no TTL-rule breach — not
    // a zero-TTL key that happens to expire instantly. Runs against the REAL
    // array store (not Cache::shouldReceive) so it exercises Repository::put
    // for real, guarding this across a Laravel upgrade.
    $calls = 0;
    $callback = function () use (&$calls) {
        $calls++;

        return null;
    };

    $first = $this->service->rememberLockedNullable('test:n:realzero', 60, $callback, nullTtl: 0);
    $second = $this->service->rememberLockedNullable('test:n:realzero', 60, $callback, nullTtl: 0);

    expect($first)->toBeNull();
    expect($second)->toBeNull();
    expect($calls)->toBe(2); // nothing was remembered, so the closure ran twice
    expect(Cache::has('test:n:realzero'))->toBeFalse();
});
