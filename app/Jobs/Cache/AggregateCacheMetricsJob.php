<?php

namespace App\Jobs\Cache;

use App\Listeners\RecordCacheMetrics;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

// Reads the previous hour's cache hit/miss counters from Redis and logs structured
// metrics so Nightwatch can surface cache health trends. Calls report() on SLO
// violations so they appear as exception events in Nightwatch rather than silent logs.
// Scheduled: hourly via routes/console.php.
class AggregateCacheMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public int $timeout = 30;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $bucket = now('UTC')->subHour()->format('Y-m-d-H');
        $bucketKey = "cache_metrics:{$bucket}";

        $raw = Redis::hGetAll($bucketKey);

        if (empty($raw)) {
            return;
        }

        // Group raw hash fields (e.g. "site:hits" => "42") by prefix.
        $stats = [];
        foreach ($raw as $field => $value) {
            [$prefix, $type] = array_pad(explode(':', $field, 2), 2, '');
            if ($prefix === '' || $type === '') {
                continue;
            }
            $stats[$prefix][$type] = (int) $value;
        }

        foreach ($stats as $prefix => $counts) {
            $hits = $counts['hits'] ?? 0;
            $misses = $counts['misses'] ?? 0;
            $writes = $counts['writes'] ?? 0;
            $total = $hits + $misses;
            $hitRate = $total > 0 ? round($hits / $total, 4) : null;

            Log::info('cache.metrics', [
                'prefix' => $prefix,
                'bucket' => $bucket,
                'hits' => $hits,
                'misses' => $misses,
                'writes' => $writes,
                'hit_rate' => $hitRate,
            ]);

            // SLO check: hot prefixes should sustain ≥ 90% hit rate. Require
            // at least 10 requests to filter out noise on cold or sparse buckets.
            if (
                in_array($prefix, RecordCacheMetrics::SLO_PREFIXES, true)
                && $hitRate !== null
                && $total >= 10
                && $hitRate < RecordCacheMetrics::SLO_MIN_HIT_RATE
            ) {
                $pct = number_format($hitRate * 100, 1);
                report(new \RuntimeException(
                    "Cache SLO violation: prefix={$prefix} hit_rate={$pct}% (SLO: ≥90%) bucket={$bucket}"
                ));
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        report($e);
    }
}
