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

it('records a buffered miss on flush() for a CacheMissed event', function () {
    Redis::shouldReceive('hIncrBy')
        ->once()
        ->withArgs(fn ($key, $field) => str_starts_with($field, 'site:misses'))
        ->andReturn(2);

    // handle() only buffers a primary-key miss now (#CACHE-2 SWR lookahead) —
    // it never touches Redis itself. flush() is what resolves the buffer and
    // writes through; calling it explicitly keeps this deterministic instead
    // of relying on __destruct() firing when $listener goes out of scope.
    $listener = new RecordCacheMetrics;
    $listener->handle(new CacheMissed('redis', 'site:payload:abc'));
    $listener->flush();
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

// #CACHE-2: stale-while-revalidate fold. CacheLockService::rememberLocked() and
// SiteCacheService::getPublicSitePayload() always probe the primary key then,
// on miss, the adjacent ":stale" companion — one logical read, two Redis ops.
// A one-event lookahead buffer folds the pair into a single hit/miss so the
// per-prefix hit rate measures "served without recompute", not "primary warm"
// (see class docblock — the un-folded rate has a ~52.6% ceiling on an
// SWR-served prefix, which a >=90% SLO can never pass).
describe('SWR stale-probe fold (#CACHE-2)', function () {
    it('folds a primary miss + stale hit into ONE hit on the write-through (console) path', function () {
        Redis::shouldReceive('hIncrBy')
            ->once()
            ->withArgs(fn ($key, $field, $by) => $field === 'site:hits' && $by === 1)
            ->andReturn(1);
        Redis::shouldReceive('expire')->once()->andReturn(true);

        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheMissed('redis', 'site:payload:x'));
        $listener->handle(new CacheHit('redis', 'site:payload:x:stale', []));
    });

    it('folds a genuine cold miss (primary miss + stale miss) into ONE miss on the write-through path', function () {
        Redis::shouldReceive('hIncrBy')
            ->once()
            ->withArgs(fn ($key, $field, $by) => $field === 'site:misses' && $by === 1)
            ->andReturn(1);
        Redis::shouldReceive('expire')->once()->andReturn(true);

        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheMissed('redis', 'site:payload:y'));
        $listener->handle(new CacheMissed('redis', 'site:payload:y:stale'));
    });

    it('ignores the :stale half of a write-through pair (one logical write, not two)', function () {
        Redis::shouldReceive('hIncrBy')
            ->once()
            ->withArgs(fn ($key, $field, $by) => $field === 'site:writes' && $by === 1)
            ->andReturn(1);
        Redis::shouldReceive('expire')->once()->andReturn(true);

        $listener = new RecordCacheMetrics;
        $listener->handle(new KeyWritten('redis', 'site:payload:z', 'v', 60));
        $listener->handle(new KeyWritten('redis', 'site:payload:z:stale', 'v', 600));
    });

    it('folds a primary miss + stale hit into ONE hit on the deferred (HTTP) path', function () {
        Carbon::setTestNow('2026-07-23 10:30:00');

        $listener = deferringRecordCacheMetrics();
        $listener->handle(new CacheMissed('redis', 'site:payload:def'));
        $listener->handle(new CacheHit('redis', 'site:payload:def:stale', []));
        // Nothing written yet — still batched until flush(), same as any other deferred record.

        Redis::shouldReceive('hIncrBy')
            ->once()
            ->withArgs(fn ($key, $field, $by) => str_starts_with($key, 'cache_metrics:') && $field === 'site:hits' && $by === 1)
            ->andReturn(1);
        Redis::shouldReceive('expire')->once()->andReturn(true);

        $listener->flush();

        Carbon::setTestNow();
    });

    it('ignores the :stale half of a write-through pair on the deferred (HTTP) path', function () {
        Carbon::setTestNow('2026-07-23 10:30:00');

        $listener = deferringRecordCacheMetrics();
        $listener->handle(new KeyWritten('redis', 'site:payload:ghi', 'v', 60));
        $listener->handle(new KeyWritten('redis', 'site:payload:ghi:stale', 'v', 600));

        Redis::shouldReceive('hIncrBy')
            ->once()
            ->withArgs(fn ($key, $field, $by) => $field === 'site:writes' && $by === 1)
            ->andReturn(1);
        Redis::shouldReceive('expire')->once()->andReturn(true);

        $listener->flush();

        Carbon::setTestNow();
    });

    it('does not touch Redis for a lone buffered miss until it is resolved (deferred path)', function () {
        Redis::shouldReceive('hIncrBy')->never();
        Redis::shouldReceive('expire')->never();

        $listener = deferringRecordCacheMetrics();
        $listener->handle(new CacheMissed('redis', 'site:payload:lonely'));
        // No pairing event, no flush() — the miss sits in the buffer, not Redis.
    });

    it('flush() records a still-buffered miss (deferred path) even with no pairing event', function () {
        Carbon::setTestNow('2026-07-23 10:30:00');

        $listener = deferringRecordCacheMetrics();
        $listener->handle(new CacheMissed('redis', 'site:payload:lonely'));

        Redis::shouldReceive('hIncrBy')
            ->once()
            ->withArgs(fn ($key, $field, $by) => $field === 'site:misses' && $by === 1)
            ->andReturn(1);
        Redis::shouldReceive('expire')->once()->andReturn(true);

        $listener->flush();

        Carbon::setTestNow();
    });

    it('flushes a lingering buffered miss via __destruct() on the write-through path (no pairing event before job end)', function () {
        // Simulates a queue job whose LAST cache op is an unpaired miss: no
        // further event arrives to trigger the rule-2 flush, and there is no
        // request-termination hook on this path (see class docblock on
        // __destruct()). forgetScopedInstances() drops the container's only
        // reference between jobs — unset() here stands in for that.
        Redis::shouldReceive('hIncrBy')
            ->once()
            ->withArgs(fn ($key, $field, $by) => $field === 'site:misses' && $by === 1)
            ->andReturn(1);
        Redis::shouldReceive('expire')->once()->andReturn(true);

        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheMissed('redis', 'site:payload:queued'));

        unset($listener);
    });

    it('__destruct() is a no-op when nothing was ever buffered', function () {
        Redis::shouldReceive('hIncrBy')->never();

        // SKIP_PREFIXES key — dropped before any buffering/write decision, so
        // there is nothing for __destruct() to flush.
        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheHit('redis', 'lock:site:payload:warm', []));

        unset($listener);
    });

    it('records the SWR-folded event under the bucket captured at buffer time, not pairing time', function () {
        Carbon::setTestNow('2026-07-23 10:59:59');

        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheMissed('redis', 'site:payload:boundary')); // buffered in the 10:00 hour

        Carbon::setTestNow('2026-07-23 11:00:01'); // crossed the hour boundary before the pair arrives

        Redis::shouldReceive('hIncrBy')
            ->once()
            ->withArgs(fn ($key, $field, $by) => $key === 'cache_metrics:2026-07-23-10' && $field === 'site:hits' && $by === 1)
            ->andReturn(1);
        Redis::shouldReceive('expire')
            ->once()
            ->with('cache_metrics:2026-07-23-10', RecordCacheMetrics::BUCKET_TTL_SECONDS)
            ->andReturn(true);

        $listener->handle(new CacheHit('redis', 'site:payload:boundary:stale', []));

        Carbon::setTestNow();
    });

    it('flushes a buffered miss under its own bucket when a non-pairing event lands in a later hour', function () {
        Carbon::setTestNow('2026-07-23 10:59:59');

        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheMissed('redis', 'site:payload:orphan')); // buffered in the 10:00 hour

        Carbon::setTestNow('2026-07-23 11:00:01');

        Redis::shouldReceive('hIncrBy')
            ->twice() // 1: the flushed 10:00 miss, 2: the unrelated 11:00 hit
            ->andReturnUsing(function ($key, $field, $by) {
                if ($field === 'site:misses') {
                    expect($key)->toBe('cache_metrics:2026-07-23-10');
                } elseif ($field === 'pro:hits') {
                    expect($key)->toBe('cache_metrics:2026-07-23-11');
                }

                return $by;
            });
        Redis::shouldReceive('expire')->twice()->andReturn(true);

        // Unrelated pro: key in the new hour — doesn't pair with the buffered
        // site: miss, so it flushes the buffered miss under ITS OWN (old) bucket
        // and then records itself under the current (new) bucket.
        $listener->handle(new CacheHit('redis', 'pro:model:abc', []));

        Carbon::setTestNow();
    });

    it('does not pair a stale-key event with an unrelated buffered miss (different key)', function () {
        Redis::shouldReceive('hIncrBy')
            ->once() // only the flushed buffered miss — the unrelated :stale event is dropped
            ->withArgs(fn ($key, $field, $by) => $field === 'site:misses' && $by === 1)
            ->andReturn(1);
        Redis::shouldReceive('expire')->once()->andReturn(true);

        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheMissed('redis', 'site:payload:a'));
        $listener->handle(new CacheHit('redis', 'site:payload:b:stale', []));
    });

    it('drops an unpaired :stale event with no buffered miss at all (SWR healing housekeeping)', function () {
        Redis::shouldReceive('hIncrBy')->never();

        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheMissed('redis', 'site:payload:c:stale'));
    });

    it('flushes a buffered miss (and still skips the event) when a lock: probe intervenes', function () {
        Redis::shouldReceive('hIncrBy')
            ->once()
            ->withArgs(fn ($key, $field, $by) => $field === 'site:misses' && $by === 1)
            ->andReturn(1);
        Redis::shouldReceive('expire')->once()->andReturn(true);

        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheMissed('redis', 'site:payload:abc'));
        $listener->handle(new CacheHit('redis', 'lock:site:payload:abc', []));
    });
});
