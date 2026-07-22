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

    public function handle(CacheHit|CacheMissed|KeyWritten $event): void
    {
        $prefix = $this->extractPrefix($event->key);

        if ($prefix === null) {
            return;
        }

        $bucket = now('UTC')->format('Y-m-d-H');
        $type = match (true) {
            $event instanceof CacheHit => 'hits',
            $event instanceof CacheMissed => 'misses',
            default => 'writes', // KeyWritten
        };

        $bucketKey = "cache_metrics:{$bucket}";
        $field = "{$prefix}:{$type}";

        if ($this->shouldDefer()) {
            $this->pending[$bucketKey][$field] = ($this->pending[$bucketKey][$field] ?? 0) + 1;
            $this->registerFlush();

            return;
        }

        // Console / queue context: write through immediately (see class docblock).
        $this->write($bucketKey, [$field => 1]);
    }

    /**
     * Flush all counts accumulated during this request as one HINCRBY per field.
     * Registered on app()->terminating() so it runs after the response is sent.
     */
    public function flush(): void
    {
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
            // Never let a metrics write fail a cache operation.
            Log::warning('cache.metrics.record_failed', ['error' => $e->getMessage()]);
        }
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

    private function extractPrefix(string $key): ?string
    {
        $prefix = explode(':', $key)[0];

        if (in_array($prefix, self::SKIP_PREFIXES, true)) {
            return null;
        }

        return $prefix;
    }
}
