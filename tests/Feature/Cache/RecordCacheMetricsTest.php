<?php

use App\Listeners\RecordCacheMetrics;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

it('increments hits counter for a CacheHit event', function () {
    Redis::shouldReceive('hIncrBy')
        ->once()
        ->withArgs(fn ($key, $field) => str_ends_with($key, ':') === false
            && str_starts_with($field, 'site:hits'))
        ->andReturn(5);

    $listener = new RecordCacheMetrics;
    $listener->handle(new CacheHit('redis', 'site:payload:abc', []));
});

it('increments misses counter for a CacheMissed event', function () {
    Redis::shouldReceive('hIncrBy')
        ->once()
        ->withArgs(fn ($key, $field) => str_starts_with($field, 'site:misses'))
        ->andReturn(2);

    $listener = new RecordCacheMetrics;
    $listener->handle(new CacheMissed('redis', 'site:payload:abc'));
});

it('increments writes counter for a KeyWritten event', function () {
    Redis::shouldReceive('hIncrBy')
        ->once()
        ->withArgs(fn ($key, $field) => str_starts_with($field, 'pro:writes'))
        ->andReturn(1);

    Redis::shouldReceive('expire')->once()->andReturn(true);

    $listener = new RecordCacheMetrics;
    $listener->handle(new KeyWritten('redis', 'pro:model:xyz', 'value', 60));
});

it('sets TTL on the bucket hash when a field is first created', function () {
    Redis::shouldReceive('hIncrBy')->andReturn(1); // new field
    Redis::shouldReceive('expire')
        ->once()
        ->with(Mockery::pattern('/^cache_metrics:/'), RecordCacheMetrics::BUCKET_TTL_SECONDS);

    $listener = new RecordCacheMetrics;
    $listener->handle(new CacheHit('redis', 'site:payload:abc', []));
});

it('does not set TTL when a field already existed', function () {
    Redis::shouldReceive('hIncrBy')->andReturn(42); // field already existed
    Redis::shouldReceive('expire')->never();

    $listener = new RecordCacheMetrics;
    $listener->handle(new CacheHit('redis', 'site:payload:abc', []));
});

it('skips lock: prefix keys', function () {
    Redis::shouldReceive('hIncrBy')->never();

    $listener = new RecordCacheMetrics;
    $listener->handle(new CacheHit('redis', 'lock:site:payload:abc', []));
});

it('skips scheduler: prefix keys', function () {
    Redis::shouldReceive('hIncrBy')->never();

    $listener = new RecordCacheMetrics;
    $listener->handle(new CacheHit('redis', 'scheduler:last_run:task', []));
});

it('buckets multi-segment keys by first prefix segment', function () {
    Redis::shouldReceive('hIncrBy')
        ->once()
        ->withArgs(fn ($key, $field) => str_starts_with($field, 'commerce:hits'))
        ->andReturn(1);

    Redis::shouldReceive('expire')->once()->andReturn(true);

    $listener = new RecordCacheMetrics;
    $listener->handle(new CacheHit('redis', 'commerce:orders:brand:uuid', []));
});

it('swallows redis errors so cache operations are not disrupted', function () {
    Redis::shouldReceive('hIncrBy')->andThrow(new RuntimeException('Redis connection failed'));
    Log::spy();

    $listener = new RecordCacheMetrics;

    expect(fn () => $listener->handle(new CacheHit('redis', 'site:payload:x', [])))->not->toThrow(Throwable::class);

    Log::shouldHaveReceived('warning')->with('cache.metrics.record_failed', Mockery::type('array'))->once();
});
