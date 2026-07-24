<?php

namespace App\Listeners;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

// Counts cache hits, misses, and writes per key prefix into an hourly Redis hash
// so AggregateCacheMetricsJob can surface hit-rate trends and SLO violations to
// Nightwatch.
//
// Within an HTTP request the counts are accumulated in-process and flushed once,
// on app termination, as one HINCRBY per (bucket, field) — a hot page that hits
// dozens of cache keys pays a single batched round-trip at request end instead
// of one synchronous HINCRBY per cache op (#CACHE-1). Outside an HTTP request
// (console commands, queue workers) we write through immediately: there,
// app()->terminating() fires only at process shutdown, so batching would either
// accumulate unbounded across thousands of jobs or be discarded between jobs
// (the binding is `scoped`, reset per job) — both worse than a direct write.
//
// #CACHE-2: stale-while-revalidate (CacheLockService::rememberLocked(),
// SiteCacheService::getPublicSitePayload()) always probes the primary key,
// then — on miss — its ":stale" companion, immediately adjacent, in the same
// call: two Redis reads for what is logically ONE read. Counting both makes the
// metric measure "primary key was warm" instead of "served without recompute".
// Serving from stale also recomputes and rewrites both keys, so once reads are
// spaced further apart than the primary TTL every single one scores exactly one
// miss + one hit — a hard 50% ceiling, and a >=90% SLO on it can never pass.
// (Measured on development 2026-07-24: dozens of prefixes sat at precisely
// 0.5000 with hits == misses.) A one-event lookahead buffer folds the pair into a
// single hit/miss under the PRIMARY key's prefix/bucket, and drops the ":stale"
// half of write-through pairs (writeWithJitter() writes both keys for one
// logical write).
class RecordCacheMetrics
{
    // Internal prefixes that add noise without insight (lock acquisition, heartbeat keys).
    public const SKIP_PREFIXES = ['lock', 'scheduler'];

    // Hot-path prefixes whose hit rate is tracked against the SLO.
    public const SLO_PREFIXES = ['site', 'pro'];

    public const SLO_MIN_HIT_RATE = 0.9;

    // Redis hash key pattern. One key per UTC hour, expired after 48 h so yesterday's
    // data is still queryable when the next day's aggregation job runs.
    public const BUCKET_TTL_SECONDS = 172800; // 48 h

    /**
     * Accumulated counts for the current request: bucketKey => [field => count].
     * Only populated on the deferred (HTTP) path; flushed by flush().
     *
     * @var array<string, array<string, int>>
     */
    private array $pending = [];

    // Guards against registering more than one terminating() flush per request.
    private bool $flushRegistered = false;

    /**
     * A CacheMissed on a primary key, held back one event on the chance the very
     * next event is its SWR ":stale" companion (#CACHE-2). bucketKey/prefix are
     * captured HERE, at buffer time — not when the buffer is later resolved —
     * so a miss buffered right before an hour boundary is still credited to the
     * hour it actually happened in.
     *
     * @var array{key: string, bucketKey: string, prefix: string}|null
     */
    private ?array $bufferedMiss = null;

    public function handle(CacheHit|CacheMissed|KeyWritten $event): void
    {
        $key = $event->key;
        $isStaleKey = str_ends_with($key, ':stale');

        // Paired SWR probe: the buffered primary miss's own ":stale" companion,
        // arriving as the very next event. Fold into ONE hit/miss under the
        // primary's prefix/bucket instead of counting both Redis reads.
        if ($isStaleKey && $this->bufferedMiss !== null && $key === $this->bufferedMiss['key'].':stale') {
            $buffered = $this->bufferedMiss;
            $this->bufferedMiss = null;

            $type = $event instanceof CacheMissed ? 'misses' : 'hits';
            $this->recordOrDefer($buffered['bucketKey'], "{$buffered['prefix']}:{$type}");

            return;
        }

        // Anything else arriving while a miss is buffered means it didn't pair —
        // a genuine cold read with no live stale sibling. Flush it as a miss
        // using the bucket/prefix captured when it was buffered, then fall
        // through to handle the current event on its own merits.
        if ($this->bufferedMiss !== null) {
            $buffered = $this->bufferedMiss;
            $this->bufferedMiss = null;
            $this->recordOrDefer($buffered['bucketKey'], "{$buffered['prefix']}:misses");
        }

        if ($isStaleKey) {
            // writeWithJitter() / writePayloadWithStale() always write the
            // primary key then the ":stale" copy for one logical write — the
            // KeyWritten on ":stale" is the same write, not a second one.
            //
            // Any other unpaired ":stale" event (didn't match the pairing check
            // above) is SWR housekeeping — e.g. the forget()+reget healing path
            // in SiteCacheService — never a metric subject in its own right.
            return;
        }

        $prefix = $this->extractPrefix($key);

        if ($prefix === null) {
            return;
        }

        // Hold a primary miss back — it may be the first half of an SWR pair,
        // resolved above on the next event, or by flush() at request/job end.
        if ($event instanceof CacheMissed) {
            $this->bufferedMiss = [
                'key' => $key,
                'bucketKey' => $this->currentBucketKey(),
                'prefix' => $prefix,
            ];

            // Buffering alone doesn't touch `pending`, so without this a lone
            // buffered miss that's never paired would have no app()->terminating()
            // callback registered to flush it — it would only surface via the
            // __destruct() safety net, at whatever moment GC happens to run
            // rather than deterministically at request end.
            if ($this->shouldDefer()) {
                $this->registerFlush();
            }

            return;
        }

        $type = $event instanceof CacheHit ? 'hits' : 'writes'; // KeyWritten
        $this->recordOrDefer($this->currentBucketKey(), "{$prefix}:{$type}");
    }

    /**
     * Flush all counts accumulated during this request as one HINCRBY per field.
     * Registered on app()->terminating() so it runs after the response is sent.
     * Also folds in a still-buffered miss that never got a pairing SWR event, so
     * it isn't silently dropped when the request ends.
     */
    public function flush(): void
    {
        if ($this->bufferedMiss !== null) {
            $buffered = $this->bufferedMiss;
            $this->bufferedMiss = null;
            $field = "{$buffered['prefix']}:misses";
            $this->pending[$buffered['bucketKey']][$field] = ($this->pending[$buffered['bucketKey']][$field] ?? 0) + 1;
        }

        if ($this->pending === []) {
            return;
        }

        $pending = $this->pending;
        $this->pending = [];
        $this->flushRegistered = false;

        foreach ($pending as $bucketKey => $fields) {
            $this->write($bucketKey, $fields);
        }
    }

    /**
     * Safety net for the write-through (console/queue) path, which has no
     * request-termination hook to rely on: a buffered miss with no pairing SWR
     * event and no explicit flush() call would otherwise vanish when the scoped
     * instance is torn down between jobs. forgetScopedInstances() drops the
     * container's only reference, so PHP's refcounting destructs the object
     * deterministically right there — this is the last chance to write it.
     * Deliberately does NOT unconditionally call flush(): the HTTP path already
     * has its own dedicated app()->terminating() flush, and calling flush()
     * here regardless would drain still-batching `pending` counts early on an
     * object that's simply going out of scope mid-request (e.g. in tests).
     */
    public function __destruct()
    {
        if ($this->bufferedMiss !== null) {
            // Destructors can run during PHP shutdown, after the container and
            // facade roots are torn down (see class docblock above) — Redis::
            // and Log:: may both be unusable at that point. A dropped metric
            // counter is strictly preferable to an exception escaping
            // __destruct(), which PHP treats as a fatal error.
            try {
                $this->flush();
            } catch (\Throwable) {
            }
        }
    }

    /**
     * Apply a batch of field => increment counts to one bucket hash. A metrics
     * write must never break the cache operation that triggered it, so the whole
     * batch is wrapped in a single try/catch (matching the prior behaviour).
     *
     * @param  array<string, int>  $fields
     */
    private function write(string $bucketKey, array $fields): void
    {
        try {
            foreach ($fields as $field => $count) {
                $newValue = Redis::hIncrBy($bucketKey, $field, $count);

                // HINCRBY on a fresh field (base 0) returns exactly $count, so an
                // equal result means this field/hash was just created and needs its
                // TTL set. A pre-existing field returns prior+count > count, and its
                // hash TTL is already set — skip the redundant EXPIRE. Two concurrent
                // first-writes may both EXPIRE; that's idempotent for the same TTL.
                if ($newValue === $count) {
                    Redis::expire($bucketKey, self::BUCKET_TTL_SECONDS);
                }
            }
        } catch (\Throwable $e) {
            // Never let a metrics write fail a cache operation. The log call
            // itself resolves through the same facade mechanism that just
            // failed (e.g. mid-shutdown from __destruct()), so it must not be
            // able to turn a handled failure into an unhandled one.
            try {
                Log::warning('cache.metrics.record_failed', ['error' => $e->getMessage()]);
            } catch (\Throwable) {
            }
        }
    }

    // Record now (write-through) or batch for the end-of-request flush,
    // depending on context. Shared by the normal path and by buffer
    // resolution (pairing / flush-on-non-pair), so both go through the same
    // defer/write-through decision.
    private function recordOrDefer(string $bucketKey, string $field): void
    {
        if ($this->shouldDefer()) {
            $this->pending[$bucketKey][$field] = ($this->pending[$bucketKey][$field] ?? 0) + 1;
            $this->registerFlush();

            return;
        }

        // Console / queue context: write through immediately (see class docblock).
        $this->write($bucketKey, [$field => 1]);
    }

    // Defer + batch only inside an HTTP request, where app()->terminate() reliably
    // flushes. runningInConsole() is true for tests, artisan commands, and queue
    // workers alike — all write-through contexts.
    protected function shouldDefer(): bool
    {
        return ! app()->runningInConsole();
    }

    private function registerFlush(): void
    {
        if ($this->flushRegistered) {
            return;
        }

        $this->flushRegistered = true;
        app()->terminating(fn () => $this->flush());
    }

    private function currentBucketKey(): string
    {
        return 'cache_metrics:'.now('UTC')->format('Y-m-d-H');
    }

    private function extractPrefix(string $key): ?string
    {
        $prefix = explode(':', $key)[0];

        if (in_array($prefix, self::SKIP_PREFIXES, true)) {
            return null;
        }

        return $prefix;
    }
}
