<?php

namespace App\Jobs\Cache;

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
        $this->reportTtlCeilingBreach();

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

        // CACHE-3: the target and the noise floor are per-environment — a hit
        // rate is capped by traffic density, not just cache health (see
        // config/partna.php 'cache.slo').
        // The target is also per-prefix: a hit rate is capped at 1 - 1/(lambda*TTL),
        // so a 60s-TTL prefix and a 900s-TTL prefix cannot share one threshold.
        // Unmapped prefixes fall back to the scalar.
        $sloPrefixes = (array) config('partna.cache.slo.prefixes', []);
        $hitRateByPrefix = (array) config('partna.cache.slo.min_hit_rate_by_prefix', []);
        $fallbackHitRate = (float) config('partna.cache.slo.min_hit_rate');
        $minSample = (int) config('partna.cache.slo.min_sample');

        foreach ($stats as $prefix => $counts) {
            $hits = $counts['hits'] ?? 0;
            $misses = $counts['misses'] ?? 0;
            $writes = $counts['writes'] ?? 0;
            $total = $hits + $misses;
            $hitRate = $total > 0 ? round($hits / $total, 4) : null;
            $minHitRate = (float) ($hitRateByPrefix[$prefix] ?? $fallbackHitRate);

            Log::info('cache.metrics', [
                'prefix' => $prefix,
                'bucket' => $bucket,
                'hits' => $hits,
                'misses' => $misses,
                'writes' => $writes,
                'hit_rate' => $hitRate,
            ]);

            // SLO check: tracked prefixes should sustain the configured hit
            // rate, judged only once the bucket carries enough reads to mean
            // anything.
            if (
                in_array($prefix, $sloPrefixes, true)
                && $hitRate !== null
                && $total >= $minSample
                && $hitRate < $minHitRate
            ) {
                $pct = number_format($hitRate * 100, 1);
                // Trailing zeros trimmed so the default reads "≥90%", keeping the
                // Nightwatch issue title stable rather than forking a new issue.
                $slo = rtrim(rtrim(number_format($minHitRate * 100, 1), '0'), '.');
                report(new \RuntimeException(
                    "Cache SLO violation: prefix={$prefix} hit_rate={$pct}% (SLO: ≥{$slo}%) bucket={$bucket}"
                ));
            }
        }
    }

    /**
     * Guard the premise that makes orphaned rotation keys harmless.
     *
     * public.profile:{handle}:{ts} is never busted — a mutation mints a new key
     * and abandons the old one for the rest of its TTL. Resident orphans are
     * therefore edit_rate x TTL, bounded by edit flow rather than site count,
     * which is what makes the 60s default a non-issue. TTL is the one term an
     * operator controls and it is env-tunable in Cloud with no deploy, so nothing
     * else in the codebase would notice it moving. Ceiling lives in config as
     * code so raising it is a reviewed commit.
     *
     * Runs before handle()'s empty-bucket return: this is a property of
     * configuration, not of traffic. Silent when in bounds — an hourly "still 60"
     * line is noise, and the empty-bucket test asserts no info log.
     */
    private function reportTtlCeilingBreach(): void
    {
        $ttl = (int) config('partna.public_profile.cache_ttl_seconds', 60);
        $ceiling = (int) config('partna.public_profile.cache_ttl_ceiling_seconds', 300);

        if ($ceiling <= 0 || $ttl <= $ceiling) {
            return;
        }

        // Message carries only ttl and ceiling, both of which change solely when
        // a human changes them — so Nightwatch groups repeat hours into one issue
        // rather than forking a new one every run.
        report(new \RuntimeException(
            "Public-profile cache TTL exceeds its orphan-key ceiling: ttl={$ttl}s (ceiling: {$ceiling}s). "
            .'Orphaned public.profile:* keys live out this TTL, so raising it multiplies resident orphan '
            .'bytes on the Valkey instance the queue shares. See cache-gold-standard-2026-07-28.md §2.4.'
        ));
    }

    public function failed(\Throwable $e): void
    {
        report($e);
    }
}
