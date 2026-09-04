<?php

use App\Listeners\RecordCacheMetrics;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Mockery\MockInterface;

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

/**
 * RecordCacheMetrics::write() reads through Redis::connection('app'), not the
 * bare facade (see drill 03, 2026-08-05 / RedisConnectionPinningTest) — the
 * request path takes the tight 3.0s read_timeout bound, not `default`'s
 * 15.0s. Mocking `Redis::shouldReceive('hIncrBy')` directly no longer
 * intercepts anything; this stubs `connection('app')` to return a Connection
 * double and hands that double back so each test sets its hIncrBy/expire
 * expectations on it instead.
 */
function mockAppRedisConnection(): MockInterface
{
    $connection = Mockery::mock(Connection::class);
    Redis::shouldReceive('connection')->with('app')->andReturn($connection);

    return $connection;
}

it('increments hits counter for a CacheHit event', function () {
    $redis = mockAppRedisConnection();
    $redis->shouldReceive('hIncrBy')
        ->once()
        ->withArgs(fn ($key, $field) => str_ends_with($key, ':') === false
            && str_starts_with($field, 'site:hits'))
        ->andReturn(5);

    $listener = new RecordCacheMetrics;
    $listener->handle(new CacheHit('redis', 'site:abc:blocks:links', []));
});

it('records a buffered miss on flush() for a CacheMissed event', function () {
    $redis = mockAppRedisConnection();
    $redis->shouldReceive('hIncrBy')
        ->once()
        ->withArgs(fn ($key, $field) => str_starts_with($field, 'site:misses'))
        ->andReturn(2);

    // handle() only buffers a primary-key miss now (#CACHE-2 SWR lookahead) —
    // it never touches Redis itself. flush() is what resolves the buffer and
    // writes through; calling it explicitly keeps this deterministic instead
    // of relying on __destruct() firing when $listener goes out of scope.
    $listener = new RecordCacheMetrics;
    $listener->handle(new CacheMissed('redis', 'site:abc:blocks:links'));
    $listener->flush();
});

it('increments writes counter for a KeyWritten event', function () {
    $redis = mockAppRedisConnection();
    $redis->shouldReceive('hIncrBy')
        ->once()
        ->withArgs(fn ($key, $field) => str_starts_with($field, 'pro:writes'))
        ->andReturn(1);

    $redis->shouldReceive('expire')->once()->andReturn(true);

    $listener = new RecordCacheMetrics;
    $listener->handle(new KeyWritten('redis', 'pro:model:xyz', 'value', 60));
});

it('sets TTL on the bucket hash when a field is first created', function () {
    $redis = mockAppRedisConnection();
    $redis->shouldReceive('hIncrBy')->andReturn(1); // new field
    $redis->shouldReceive('expire')
        ->once()
        ->with(Mockery::pattern('/^cache_metrics:/'), RecordCacheMetrics::BUCKET_TTL_SECONDS);

    $listener = new RecordCacheMetrics;
    $listener->handle(new CacheHit('redis', 'site:abc:blocks:links', []));
});

it('does not set TTL when a field already existed', function () {
    $redis = mockAppRedisConnection();
    $redis->shouldReceive('hIncrBy')->andReturn(42); // field already existed
    $redis->shouldReceive('expire')->never();

    $listener = new RecordCacheMetrics;
    $listener->handle(new CacheHit('redis', 'site:abc:blocks:links', []));
});

it('skips lock: prefix keys', function () {
    $redis = mockAppRedisConnection();
    $redis->shouldReceive('hIncrBy')->never();

    $listener = new RecordCacheMetrics;
    $listener->handle(new CacheHit('redis', 'lock:site:abc:blocks:links', []));
});

it('skips scheduler: prefix keys', function () {
    $redis = mockAppRedisConnection();
    $redis->shouldReceive('hIncrBy')->never();

    $listener = new RecordCacheMetrics;
    $listener->handle(new CacheHit('redis', 'scheduler:last_run:task', []));
});

it('buckets multi-segment keys by first prefix segment', function () {
    $redis = mockAppRedisConnection();
    $redis->shouldReceive('hIncrBy')
        ->once()
        ->withArgs(fn ($key, $field) => str_starts_with($field, 'commerce:hits'))
        ->andReturn(1);

    $redis->shouldReceive('expire')->once()->andReturn(true);

    $listener = new RecordCacheMetrics;
    $listener->handle(new CacheHit('redis', 'commerce:orders:brand:uuid', []));
});

it('swallows redis errors so cache operations are not disrupted', function () {
    $redis = mockAppRedisConnection();
    $redis->shouldReceive('hIncrBy')->andThrow(new RuntimeException('Redis connection failed'));
    Log::spy();

    $listener = new RecordCacheMetrics;

    expect(fn () => $listener->handle(new CacheHit('redis', 'site:x:blocks:links', [])))->not->toThrow(Throwable::class);

    Log::shouldHaveReceived('warning')->with('cache.metrics.record_failed', Mockery::type('array'))->once();
});

it('does not touch Redis during handle() on the deferred path', function () {
    Carbon::setTestNow('2026-07-23 10:30:00');
    $redis = mockAppRedisConnection();
    $redis->shouldReceive('hIncrBy')->never();
    $redis->shouldReceive('expire')->never();

    $listener = deferringRecordCacheMetrics();
    $listener->handle(new CacheHit('redis', 'site:a:blocks:links', []));
    $listener->handle(new CacheHit('redis', 'site:b:blocks:links', []));
    // No flush() → nothing is written yet; the batch lives in-process until termination.

    Carbon::setTestNow();
});

it('flushes repeated events for a field as one batched HINCRBY', function () {
    Carbon::setTestNow('2026-07-23 10:30:00');

    $listener = deferringRecordCacheMetrics();
    $listener->handle(new CacheHit('redis', 'site:a:blocks:links', []));
    $listener->handle(new CacheHit('redis', 'site:b:blocks:links', []));
    $listener->handle(new CacheHit('redis', 'site:c:blocks:links', []));

    // Three hits collapse into a single HINCRBY of +3; a brand-new field returns
    // its own increment, so TTL is set exactly once.
    $redis = mockAppRedisConnection();
    $redis->shouldReceive('hIncrBy')
        ->once()
        ->withArgs(fn ($key, $field, $by) => str_starts_with($key, 'cache_metrics:')
            && $field === 'site:hits'
            && $by === 3)
        ->andReturn(3);
    $redis->shouldReceive('expire')
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
    $redis = mockAppRedisConnection();
    $redis->shouldReceive('hIncrBy')->times(3)->andReturnUsing(function ($key, $field, $by) use (&$seen) {
        $seen[$field] = $by;

        return $by; // pretend every field is newly created
    });
    $redis->shouldReceive('expire')->times(3)->andReturn(true);

    $listener->flush();

    expect($seen)->toEqual(['site:hits' => 2, 'site:misses' => 1, 'pro:writes' => 1]);

    Carbon::setTestNow();
});

it('flush() is a no-op when nothing was accumulated', function () {
    $redis = mockAppRedisConnection();
    $redis->shouldReceive('hIncrBy')->never();

    deferringRecordCacheMetrics()->flush();
});

it('still swallows redis errors on the batched flush path', function () {
    Carbon::setTestNow('2026-07-23 10:30:00');
    $redis = mockAppRedisConnection();
    $redis->shouldReceive('hIncrBy')->andThrow(new RuntimeException('Redis connection failed'));
    Log::spy();

    $listener = deferringRecordCacheMetrics();
    $listener->handle(new CacheHit('redis', 'site:a:blocks:links', []));

    expect(fn () => $listener->flush())->not->toThrow(Throwable::class);
    Log::shouldHaveReceived('warning')->with('cache.metrics.record_failed', Mockery::type('array'))->once();

    Carbon::setTestNow();
});

// #CACHE-2: stale-while-revalidate fold. CacheLockService::rememberLocked()
// always probes the primary key then, on miss, the adjacent ":stale"
// companion — one logical read, two Redis ops.
// A one-event lookahead buffer folds the pair into a single hit/miss so the
// per-prefix hit rate measures "served without recompute", not "primary warm"
// (see class docblock — the fold takes a stale-serving recompute from 33% to
// 50%; it does not lift `site`/`pro` past the >=90% SLO, which is a separate
// calibration decision).
describe('SWR stale-probe fold (#CACHE-2)', function () {
    it('folds a primary miss + stale hit into ONE hit on the write-through (console) path', function () {
        $redis = mockAppRedisConnection();
        $redis->shouldReceive('hIncrBy')
            ->once()
            ->withArgs(fn ($key, $field, $by) => $field === 'site:hits' && $by === 1)
            ->andReturn(1);
        $redis->shouldReceive('expire')->once()->andReturn(true);

        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheMissed('redis', 'site:x:blocks:links'));
        $listener->handle(new CacheHit('redis', 'site:x:blocks:links:stale', []));
    });

    it('folds a genuine cold miss (primary miss + stale miss) into ONE miss on the write-through path', function () {
        $redis = mockAppRedisConnection();
        $redis->shouldReceive('hIncrBy')
            ->once()
            ->withArgs(fn ($key, $field, $by) => $field === 'site:misses' && $by === 1)
            ->andReturn(1);
        $redis->shouldReceive('expire')->once()->andReturn(true);

        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheMissed('redis', 'site:y:blocks:links'));
        $listener->handle(new CacheMissed('redis', 'site:y:blocks:links:stale'));
    });

    it('ignores the :stale half of a write-through pair (one logical write, not two)', function () {
        $redis = mockAppRedisConnection();
        $redis->shouldReceive('hIncrBy')
            ->once()
            ->withArgs(fn ($key, $field, $by) => $field === 'site:writes' && $by === 1)
            ->andReturn(1);
        $redis->shouldReceive('expire')->once()->andReturn(true);

        $listener = new RecordCacheMetrics;
        $listener->handle(new KeyWritten('redis', 'site:z:blocks:links', 'v', 60));
        $listener->handle(new KeyWritten('redis', 'site:z:blocks:links:stale', 'v', 600));
    });

    it('folds a primary miss + stale hit into ONE hit on the deferred (HTTP) path', function () {
        Carbon::setTestNow('2026-07-23 10:30:00');

        $listener = deferringRecordCacheMetrics();
        $listener->handle(new CacheMissed('redis', 'site:def:blocks:links'));
        $listener->handle(new CacheHit('redis', 'site:def:blocks:links:stale', []));
        // Nothing written yet — still batched until flush(), same as any other deferred record.

        $redis = mockAppRedisConnection();
        $redis->shouldReceive('hIncrBy')
            ->once()
            ->withArgs(fn ($key, $field, $by) => str_starts_with($key, 'cache_metrics:') && $field === 'site:hits' && $by === 1)
            ->andReturn(1);
        $redis->shouldReceive('expire')->once()->andReturn(true);

        $listener->flush();

        Carbon::setTestNow();
    });

    it('ignores the :stale half of a write-through pair on the deferred (HTTP) path', function () {
        Carbon::setTestNow('2026-07-23 10:30:00');

        $listener = deferringRecordCacheMetrics();
        $listener->handle(new KeyWritten('redis', 'site:ghi:blocks:links', 'v', 60));
        $listener->handle(new KeyWritten('redis', 'site:ghi:blocks:links:stale', 'v', 600));

        $redis = mockAppRedisConnection();
        $redis->shouldReceive('hIncrBy')
            ->once()
            ->withArgs(fn ($key, $field, $by) => $field === 'site:writes' && $by === 1)
            ->andReturn(1);
        $redis->shouldReceive('expire')->once()->andReturn(true);

        $listener->flush();

        Carbon::setTestNow();
    });

    it('does not touch Redis for a lone buffered miss until it is resolved (deferred path)', function () {
        $redis = mockAppRedisConnection();
        $redis->shouldReceive('hIncrBy')->never();
        $redis->shouldReceive('expire')->never();

        $listener = deferringRecordCacheMetrics();
        $listener->handle(new CacheMissed('redis', 'site:lonely:blocks:links'));
        // No pairing event, no flush() — the miss sits in the buffer, not Redis.
    });

    it('flush() records a still-buffered miss (deferred path) even with no pairing event', function () {
        Carbon::setTestNow('2026-07-23 10:30:00');

        $listener = deferringRecordCacheMetrics();
        $listener->handle(new CacheMissed('redis', 'site:lonely:blocks:links'));

        $redis = mockAppRedisConnection();
        $redis->shouldReceive('hIncrBy')
            ->once()
            ->withArgs(fn ($key, $field, $by) => $field === 'site:misses' && $by === 1)
            ->andReturn(1);
        $redis->shouldReceive('expire')->once()->andReturn(true);

        $listener->flush();

        Carbon::setTestNow();
    });

    it('flushes a lingering buffered miss via __destruct() on the write-through path (no pairing event before job end)', function () {
        // Simulates a queue job whose LAST cache op is an unpaired miss: no
        // further event arrives to trigger the rule-2 flush, and there is no
        // request-termination hook on this path (see class docblock on
        // __destruct()). forgetScopedInstances() drops the container's only
        // reference between jobs — unset() here stands in for that.
        $redis = mockAppRedisConnection();
        $redis->shouldReceive('hIncrBy')
            ->once()
            ->withArgs(fn ($key, $field, $by) => $field === 'site:misses' && $by === 1)
            ->andReturn(1);
        $redis->shouldReceive('expire')->once()->andReturn(true);

        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheMissed('redis', 'site:queued:blocks:links'));

        unset($listener);
    });

    it('__destruct() is a no-op when nothing was ever buffered', function () {
        $redis = mockAppRedisConnection();
        $redis->shouldReceive('hIncrBy')->never();

        // SKIP_PREFIXES key — dropped before any buffering/write decision, so
        // there is nothing for __destruct() to flush.
        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheHit('redis', 'lock:site:warm:blocks:links', []));

        unset($listener);
    });

    it('records the SWR-folded event under the bucket captured at buffer time, not pairing time', function () {
        Carbon::setTestNow('2026-07-23 10:59:59');

        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheMissed('redis', 'site:boundary:blocks:links')); // buffered in the 10:00 hour

        Carbon::setTestNow('2026-07-23 11:00:01'); // crossed the hour boundary before the pair arrives

        $redis = mockAppRedisConnection();
        $redis->shouldReceive('hIncrBy')
            ->once()
            ->withArgs(fn ($key, $field, $by) => $key === 'cache_metrics:2026-07-23-10' && $field === 'site:hits' && $by === 1)
            ->andReturn(1);
        $redis->shouldReceive('expire')
            ->once()
            ->with('cache_metrics:2026-07-23-10', RecordCacheMetrics::BUCKET_TTL_SECONDS)
            ->andReturn(true);

        $listener->handle(new CacheHit('redis', 'site:boundary:blocks:links:stale', []));

        Carbon::setTestNow();
    });

    it('flushes a buffered miss under its own bucket when a non-pairing event lands in a later hour', function () {
        Carbon::setTestNow('2026-07-23 10:59:59');

        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheMissed('redis', 'site:orphan:blocks:links')); // buffered in the 10:00 hour

        Carbon::setTestNow('2026-07-23 11:00:01');

        $redis = mockAppRedisConnection();
        $redis->shouldReceive('hIncrBy')
            ->twice() // 1: the flushed 10:00 miss, 2: the unrelated 11:00 hit
            ->andReturnUsing(function ($key, $field, $by) {
                if ($field === 'site:misses') {
                    expect($key)->toBe('cache_metrics:2026-07-23-10');
                } elseif ($field === 'pro:hits') {
                    expect($key)->toBe('cache_metrics:2026-07-23-11');
                }

                return $by;
            });
        $redis->shouldReceive('expire')->twice()->andReturn(true);

        // Unrelated pro: key in the new hour — doesn't pair with the buffered
        // site: miss, so it flushes the buffered miss under ITS OWN (old) bucket
        // and then records itself under the current (new) bucket.
        $listener->handle(new CacheHit('redis', 'pro:model:abc', []));

        Carbon::setTestNow();
    });

    it('does not pair a stale-key event with an unrelated buffered miss (different key)', function () {
        $redis = mockAppRedisConnection();
        $redis->shouldReceive('hIncrBy')
            ->once() // only the flushed buffered miss — the unrelated :stale event is dropped
            ->withArgs(fn ($key, $field, $by) => $field === 'site:misses' && $by === 1)
            ->andReturn(1);
        $redis->shouldReceive('expire')->once()->andReturn(true);

        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheMissed('redis', 'site:a:blocks:links'));
        $listener->handle(new CacheHit('redis', 'site:b:blocks:links:stale', []));
    });

    it('drops an unpaired :stale event with no buffered miss at all (SWR healing housekeeping)', function () {
        $redis = mockAppRedisConnection();
        $redis->shouldReceive('hIncrBy')->never();

        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheMissed('redis', 'site:c:blocks:links:stale'));
    });

    it('flushes a buffered miss (and still skips the event) when a lock: probe intervenes', function () {
        $redis = mockAppRedisConnection();
        $redis->shouldReceive('hIncrBy')
            ->once()
            ->withArgs(fn ($key, $field, $by) => $field === 'site:misses' && $by === 1)
            ->andReturn(1);
        $redis->shouldReceive('expire')->once()->andReturn(true);

        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheMissed('redis', 'site:abc:blocks:links'));
        $listener->handle(new CacheHit('redis', 'lock:site:abc:blocks:links', []));
    });
});

// CACHE-3: the other half of the over-counting #CACHE-2 left behind. Both
// CacheLockService::rememberLocked() (:102/:161) and rememberLockedNullable()
// (:245/:257) re-read the primary key AFTER winning the single-flight lock —
// a third Redis probe for the same logical read, which the one-event lookahead
// buffer never saw. rememberLockedNullable() keeps no ":stale" copy at all, so
// for most of the `pro` prefix the SWR fold changed nothing and every cold read
// still booked two misses.
//
// The rule: once a read of key K has been scored, further READ events on K
// belong to the same logical read until K is written. A hit that was NOT
// preceded by a miss on the same key is never folded — collapsing repeated
// hot-path hits would deflate the hit rate rather than correct it.
describe('post-lock re-check fold (CACHE-3)', function () {
    // Capture EVERY hIncrBy rather than asserting `->once()->withArgs(...)`:
    // write() swallows Throwable by design, so an unmatched Mockery expectation
    // is caught and logged instead of failing the test. A surplus increment
    // would be invisible — exactly the bug these tests exist to catch.
    beforeEach(function () {
        $this->fields = [];
        $redis = mockAppRedisConnection();
        $redis->shouldReceive('hIncrBy')->andReturnUsing(function ($key, $field, $by) {
            $this->fields[] = $field;

            return $by;
        });
        $redis->shouldReceive('expire')->andReturn(true);
    });

    it('folds the post-lock re-check that follows an SWR stale probe', function () {
        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheMissed('redis', 'site:x:blocks:links'));          // primary probe
        $listener->handle(new CacheMissed('redis', 'site:x:blocks:links:stale'));    // SWR companion
        $listener->handle(new CacheMissed('redis', 'site:x:blocks:links'));          // post-lock re-check

        $listener->flush();

        expect($this->fields)->toBe(['site:misses']);
    });

    it('folds a repeated primary miss with no :stale companion (rememberLockedNullable)', function () {
        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheMissed('redis', 'pro:model:abc'));   // primary probe
        $listener->handle(new CacheMissed('redis', 'pro:model:abc'));   // post-lock re-check
        $listener->flush();

        $listener->flush();

        expect($this->fields)->toBe(['pro:misses']);
    });

    it('folds a post-lock re-check that finds the key warm into an already-scored read', function () {
        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheMissed('redis', 'site:y:blocks:links'));
        $listener->handle(new CacheMissed('redis', 'site:y:blocks:links:stale'));
        $listener->handle(new CacheHit('redis', 'site:y:blocks:links', []));  // another worker filled it

        $listener->flush();

        expect($this->fields)->toBe(['site:misses']);
    });

    it('scores an unpaired read as a hit when the post-lock re-check finds it warm', function () {
        // rememberLockedNullable(): no ":stale" copy, so the buffered miss is
        // still unscored when the re-check lands. Another process filled the key
        // while we queued for the lock — we served without recomputing, so the
        // logical read is a HIT, not a miss.
        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheMissed('redis', 'pro:model:def'));
        $listener->handle(new CacheHit('redis', 'pro:model:def', []));

        $listener->flush();

        expect($this->fields)->toBe(['pro:hits']);
    });

    it('records the miss that drove a recompute exactly once alongside its write', function () {
        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheMissed('redis', 'pro:model:ghi'));
        $listener->handle(new CacheMissed('redis', 'pro:model:ghi'));
        $listener->handle(new KeyWritten('redis', 'pro:model:ghi', 'v', 60));

        $listener->flush();

        expect($this->fields)->toBe(['pro:misses', 'pro:writes']);
    });

    it('starts a new logical read for the same key once it has been written', function () {
        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheMissed('redis', 'pro:model:jkl'));
        $listener->handle(new KeyWritten('redis', 'pro:model:jkl', 'v', 60));
        $listener->handle(new CacheHit('redis', 'pro:model:jkl', []));  // genuinely a second read

        $listener->flush();

        expect($this->fields)->toBe(['pro:misses', 'pro:writes', 'pro:hits']);
    });

    it('never folds repeated hits on a key that did not miss first', function () {
        // Guard against over-folding: a hot request reading the same warm key
        // several times is several hits. Collapsing them would shrink the
        // numerator while other keys' misses stayed in the denominator.
        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheHit('redis', 'pro:model:warm', []));
        $listener->handle(new CacheHit('redis', 'pro:model:warm', []));
        $listener->handle(new CacheHit('redis', 'pro:model:warm', []));

        $listener->flush();

        expect($this->fields)->toBe(['pro:hits', 'pro:hits', 'pro:hits']);
    });

    it('does not fold a repeated miss across two different keys', function () {
        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheMissed('redis', 'pro:model:one'));
        $listener->handle(new CacheMissed('redis', 'pro:model:two'));
        $listener->flush();

        $listener->flush();

        expect($this->fields)->toBe(['pro:misses', 'pro:misses']);
    });
});

// CACHE-3: two sources dominate the metrics hash without carrying any signal.
describe('noise prefixes are not recorded (CACHE-3)', function () {
    it('skips illuminate: framework-internal keys', function () {
        // Queue workers poll illuminate:queue:restart on every loop; the key is
        // only ever set by `queue:restart`, so it is a permanent 100% miss —
        // ~9.7k events/hour on dev, about 90% of everything recorded.
        $redis = mockAppRedisConnection();
        $redis->shouldReceive('hIncrBy')->never();

        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheMissed('redis', 'illuminate:queue:restart'));
        $listener->flush();
    });

    it('skips hash-shaped rate limiter keys', function () {
        // ThrottleRequests hashes its request signature, so each limited caller
        // mints its own single-use "prefix" field in the bucket hash. They can
        // never aggregate into a meaningful rate and the hash lives 48h.
        $redis = mockAppRedisConnection();
        $redis->shouldReceive('hIncrBy')->never();

        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheHit('redis', 'a7106324236cf58f7c9a5bc67c05877c', []));
        $listener->handle(new CacheMissed('redis', 'a7106324236cf58f7c9a5bc67c05877c:timer'));
        $listener->flush();
    });

    it('still records ordinary named prefixes', function () {
        $redis = mockAppRedisConnection();
        $redis->shouldReceive('hIncrBy')
            ->once()
            ->withArgs(fn ($key, $field, $by) => $field === 'analytics:hits')
            ->andReturn(1);
        $redis->shouldReceive('expire')->andReturn(true);

        $listener = new RecordCacheMetrics;
        $listener->handle(new CacheHit('redis', 'analytics:summary:abc', []));
    });
});
