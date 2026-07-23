<?php

use App\Listeners\RecordCacheMetrics;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

// A listener forced onto the deferred (HTTP-request) path, so the batching
// behaviour can be exercised without spoofing runningInConsole(). Real requests
// reach this path because AppServiceProvider binds RecordCacheMetrics as scoped
// and shouldDefer() returns true when not running in console.
function deferringRecordCacheMetrics(): RecordCacheMetrics
{
    return new class extends RecordCacheMetrics
    {
        protected function shouldDefer(): bool
        {
            return true;
        }
    };
}

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

it('does not touch Redis during handle() on the deferred path', function () {
    Carbon::setTestNow('2026-07-23 10:30:00');
    Redis::shouldReceive('hIncrBy')->never();
    Redis::shouldReceive('expire')->never();

    $listener = deferringRecordCacheMetrics();
    $listener->handle(new CacheHit('redis', 'site:payload:a', []));
    $listener->handle(new CacheHit('redis', 'site:payload:b', []));
    // No flush() → nothing is written yet; the batch lives in-process until termination.

    Carbon::setTestNow();
});

it('flushes repeated events for a field as one batched HINCRBY', function () {
    Carbon::setTestNow('2026-07-23 10:30:00');

    $listener = deferringRecordCacheMetrics();
    $listener->handle(new CacheHit('redis', 'site:payload:a', []));
    $listener->handle(new CacheHit('redis', 'site:payload:b', []));
    $listener->handle(new CacheHit('redis', 'site:payload:c', []));

    // Three hits collapse into a single HINCRBY of +3; a brand-new field returns
    // its own increment, so TTL is set exactly once.
    Redis::shouldReceive('hIncrBy')
        ->once()
        ->withArgs(fn ($key, $field, $by) => str_starts_with($key, 'cache_metrics:')
            && $field === 'site:hits'
            && $by === 3)
        ->andReturn(3);
    Redis::shouldReceive('expire')
        ->once()
        ->with(Mockery::pattern('/^cache_metrics:/'), RecordCacheMetrics::BUCKET_TTL_SECONDS)
        ->andReturn(true);

    $listener->flush();

    Carbon::setTestNow();
});

it('flushes distinct prefixes and types as separate batched increments', function () {
    Carbon::setTestNow('2026-07-23 10:30:00');

    $listener = deferringRecordCacheMetrics();
    $listener->handle(new CacheHit('redis', 'site:a', []));        // site:hits +1
    $listener->handle(new CacheMissed('redis', 'site:b'));         // site:misses +1
    $listener->handle(new KeyWritten('redis', 'pro:x', 'v', 60));  // pro:writes +1
    $listener->handle(new CacheHit('redis', 'site:c', []));        // site:hits → 2

    $seen = [];
    Redis::shouldReceive('hIncrBy')->times(3)->andReturnUsing(function ($key, $field, $by) use (&$seen) {
        $seen[$field] = $by;

        return $by; // pretend every field is newly created
    });
    Redis::shouldReceive('expire')->times(3)->andReturn(true);

    $listener->flush();

    expect($seen)->toEqual(['site:hits' => 2, 'site:misses' => 1, 'pro:writes' => 1]);

    Carbon::setTestNow();
});

it('flush() is a no-op when nothing was accumulated', function () {
    Redis::shouldReceive('hIncrBy')->never();

    deferringRecordCacheMetrics()->flush();
});

it('still swallows redis errors on the batched flush path', function () {
    Carbon::setTestNow('2026-07-23 10:30:00');
    Redis::shouldReceive('hIncrBy')->andThrow(new RuntimeException('Redis connection failed'));
    Log::spy();

    $listener = deferringRecordCacheMetrics();
    $listener->handle(new CacheHit('redis', 'site:payload:a', []));

    expect(fn () => $listener->flush())->not->toThrow(Throwable::class);
    Log::shouldHaveReceived('warning')->with('cache.metrics.record_failed', Mockery::type('array'))->once();

    Carbon::setTestNow();
});
