<?php

namespace App\Listeners;

use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Redis\Connections\Connection;
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
// #CACHE-2 / CACHE-3: one logical cache read fires up to three Redis probes,
// and counting each of them made the hit rate measure "this exact key was warm"
// rather than "we served without recomputing". The single-flight read paths
// (CacheLockService::rememberLocked()/rememberLockedNullable(), which serve
// the IndividualProfileController payload among others) do, in order:
//
//   1. probe the primary key
//   2. on miss, probe its ":stale" companion (rememberLocked only)
//   3. after winning the lock, re-probe the primary in case a racing worker
//      already filled it (CacheLockService:102/161/245/257)
//
// So a cold read used to book 2-3 misses. rememberLockedNullable() keeps no
// ":stale" copy at all and serves most of the `pro` prefix, so #CACHE-2's
// ":stale" fold alone left it counting two misses per read.
//
// The listener therefore tracks ONE logical read at a time: a primary-key miss
// opens it, the ":stale" probe or a post-lock re-check resolves it, and a write
// to that key closes it. Once a read is scored, further read events on the same
// key are suppressed until it is written. A hit that was NOT preceded by a miss
// on the same key is never folded — collapsing repeated hot-path hits would
// deflate the hit rate rather than correct it.
//
// Even with the fold the number is a function of traffic, not just cache
// health: a hit rate is bounded by 1 - 1/(lambda * TTL), so the >=90% target is
// unreachable on a 60s-TTL key below ~10 reads/key/minute. That is why the
// target and sample floor live in config('partna.cache.slo.*') per environment.
class RecordCacheMetrics
{
    // Prefixes that add noise without insight: lock acquisition and heartbeat
    // keys, plus framework internals — queue workers poll
    // `illuminate:queue:restart` every loop and it is only ever set by
    // `queue:restart`, making it a permanent 100% miss that dominated the hash.
    public const SKIP_PREFIXES = ['lock', 'scheduler', 'illuminate'];

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
     * The logical read currently in flight, opened by a CacheMissed on a primary
     * key. bucketKey/prefix are captured HERE, when the read opens — not when it
     * is later resolved — so a read that opens right before an hour boundary is
     * still credited to the hour it actually happened in. `scored` records
     * whether its hit/miss has already been written; an unscored read still owes
     * a miss when it closes.
     *
     * @var array{key: string, bucketKey: string, prefix: string, scored: bool}|null
     */
    private ?array $currentRead = null;

    public function handle(CacheHit|CacheMissed|KeyWritten $event): void
    {
        $key = $event->key;
        $isStaleKey = str_ends_with($key, ':stale');

        // Paired SWR probe: the open read's own ":stale" companion, arriving as
        // the very next event. Fold into ONE hit/miss under the primary's
        // prefix/bucket instead of counting both Redis reads.
        if ($isStaleKey && $this->currentRead !== null && ! $this->currentRead['scored']
            && $key === $this->currentRead['key'].':stale') {
            // Only a real CacheHit counts as a hit. Classifying on "not a miss"
            // would score a KeyWritten on a ":stale" key as a hit — unreachable
            // today (every writer fills the primary first, which closes the
            // read) but a trap the moment one doesn't.
            $this->scoreCurrentRead($event instanceof CacheHit ? 'hits' : 'misses');

            return;
        }

        // Same key as the read in flight: probe 3 of the single-flight dance.
        if ($this->currentRead !== null && $key === $this->currentRead['key']) {
            if ($event instanceof KeyWritten) {
                // The recompute this read triggered. Settle the read's own
                // hit/miss first, then count the write under the current bucket.
                $prefix = $this->currentRead['prefix'];
                $this->closeCurrentRead();
                $this->recordOrDefer($this->currentBucketKey(), "{$prefix}:writes");

                return;
            }

            // A post-lock re-check that found the key warm means a racing worker
            // filled it while we queued for the lock — we served it without
            // recomputing, so the read is a hit. A repeat miss adds nothing:
            // leave the read open and still owing its miss.
            if (! $this->currentRead['scored'] && $event instanceof CacheHit) {
                $this->scoreCurrentRead('hits');
            }

            return;
        }

        // An event for any other key ends the read in flight — a cold read with
        // no live stale sibling and no recompute we can see. It settles under
        // the bucket/prefix captured when it opened, then this event is handled
        // on its own merits.
        $this->closeCurrentRead();

        if ($isStaleKey) {
            // writeWithJitter() always writes the primary key then the
            // ":stale" copy for one logical write — the KeyWritten on ":stale"
            // is the same write, not a second one.
            //
            // Any other unpaired ":stale" event (didn't match the pairing check
            // above) is SWR housekeeping — e.g. a forget()+reget healing path —
            // never a metric subject in its own right.
            return;
        }

        $prefix = $this->extractPrefix($key);

        if ($prefix === null) {
            return;
        }

        // Open a logical read — resolved above by its ":stale" companion or a
        // post-lock re-check, or settled by flush() at request/job end.
        if ($event instanceof CacheMissed) {
            $this->currentRead = [
                'key' => $key,
                'bucketKey' => $this->currentBucketKey(),
                'prefix' => $prefix,
                'scored' => false,
            ];

            // Opening a read doesn't touch `pending`, so without this a read
            // that never resolves would have no app()->terminating() callback
            // registered to settle it — it would only surface via the
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

    // Write the open read's hit/miss under the bucket/prefix captured when it
    // opened. The read stays open so the post-lock re-check on the same key is
    // recognised as part of it rather than counted again.
    private function scoreCurrentRead(string $type): void
    {
        $read = $this->currentRead;

        if ($read === null) {
            return;
        }

        $this->currentRead['scored'] = true;
        $this->recordOrDefer($read['bucketKey'], "{$read['prefix']}:{$type}");
    }

    // End the read in flight, settling the miss it still owes if nothing
    // resolved it.
    private function closeCurrentRead(): void
    {
        $read = $this->currentRead;

        if ($read === null) {
            return;
        }

        $this->currentRead = null;

        if (! $read['scored']) {
            $this->recordOrDefer($read['bucketKey'], "{$read['prefix']}:misses");
        }
    }

    /**
     * Flush all counts accumulated during this request as one HINCRBY per field.
     * Registered on app()->terminating() so it runs after the response is sent.
     * Also settles a read still in flight that nothing resolved, so it isn't
     * silently dropped when the request ends. That count goes straight into
     * `pending` rather than through recordOrDefer(), which would re-register a
     * terminating callback from inside the one already running.
     */
    public function flush(): void
    {
        if ($this->currentRead !== null) {
            $read = $this->currentRead;
            $this->currentRead = null;

            if (! $read['scored']) {
                $field = "{$read['prefix']}:misses";
                $this->pending[$read['bucketKey']][$field] = ($this->pending[$read['bucketKey']][$field] ?? 0) + 1;
            }
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
     * request-termination hook to rely on: a read left in flight with no
     * resolving event and no explicit flush() call would otherwise vanish when
     * the scoped instance is torn down between jobs. forgetScopedInstances() drops the
     * container's only reference, so PHP's refcounting destructs the object
     * deterministically right there — this is the last chance to write it.
     * Deliberately does NOT unconditionally call flush(): the HTTP path already
     * has its own dedicated app()->terminating() flush, and calling flush()
     * here regardless would drain still-batching `pending` counts early on an
     * object that's simply going out of scope mid-request (e.g. in tests).
     */
    public function __destruct()
    {
        if ($this->currentRead !== null && ! $this->currentRead['scored']) {
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
                $newValue = $this->redis()->hIncrBy($bucketKey, $field, $count);

                // HINCRBY on a fresh field (base 0) returns exactly $count, so an
                // equal result means this field/hash was just created and needs its
                // TTL set. A pre-existing field returns prior+count > count, and its
                // hash TTL is already set — skip the redundant EXPIRE. Two concurrent
                // first-writes may both EXPIRE; that's idempotent for the same TTL.
                if ($newValue === $count) {
                    $this->redis()->expire($bucketKey, self::BUCKET_TTL_SECONDS);
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

    /**
     * `app`, not the bare facade default. write() runs on both the HTTP path
     * (deferred flush) and the console/queue write-through path, but neither
     * command here blocks server-side (HINCRBY/EXPIRE), so the tight
     * request-path bound is safe in both contexts — unlike a BLPOP, there is
     * no worker-side reason for this to wait 15.0s instead of `default`'s. See
     * drill 03 (2026-08-05).
     */
    private function redis(): Connection
    {
        return Redis::connection('app');
    }

    private function extractPrefix(string $key): ?string
    {
        $prefix = explode(':', $key)[0];

        if (in_array($prefix, self::SKIP_PREFIXES, true)) {
            return null;
        }

        // ThrottleRequests hashes its request signature, so every rate-limited
        // caller mints a single-use "prefix" of its own. Those can never
        // aggregate into a meaningful rate — they just accumulate fields in a
        // hash that lives 48 h.
        if (preg_match('/^[0-9a-f]{32,}$/', $prefix) === 1) {
            return null;
        }

        return $prefix;
    }
}
