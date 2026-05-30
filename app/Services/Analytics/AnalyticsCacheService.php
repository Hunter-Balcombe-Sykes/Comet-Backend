<?php

namespace App\Services\Analytics;

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cache orchestration + response composition for the professional analytics summary.
 *
 * Owns the cache key contract (must stay byte-identical to the legacy controller
 * impl — live Redis entries are keyed by it) and TTL policy. Delegates every
 * underlying read to AnalyticsQueryService.
 *
 * Cache key format:
 *   {analyticsSummary(proId, fromYmdH, toYmdH)}:{granularity}:v{summaryVersion}
 * — where summaryVersion is incremented by AnalyticsController on new ingest,
 * which atomically busts every (range, granularity) variant for a professional.
 */
class AnalyticsCacheService
{
    public function __construct(
        private readonly CacheLockService $cacheLock,
        private readonly AnalyticsQueryService $queries,
    ) {}

    /**
     * Debounced bust of every cached summary variant for a user: increments the
     * version token (at most once per 30s/user) that summary() folds into the cache
     * key, atomically invalidating all (range, granularity) variants. Called after a
     * new ingest lands — inline by SyncIngestor and from RecordAnalyticsEventJob — so
     * both ingest paths share one implementation. Fail-open: a cache fault is swallowed
     * (logged) so it can never fail an ingest whose write already committed.
     */
    public function bumpVersion(string $userId): void
    {
        try {
            if (Cache::add("analytics:ingest-debounce:{$userId}", 1, 30)) {
                Cache::increment(CacheKeyGenerator::analyticsSummaryVersion($userId));
            }
        } catch (Throwable $e) {
            Log::warning('analytics.cache_bump_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Build (or read from cache) the full analytics summary payload.
     *
     * @return array<string, mixed>
     */
    public function summary(
        User $professional,
        Site $site,
        Carbon $from,
        Carbon $to,
        bool $useHourlyBuckets,
        string $professionalTimezone,
    ): array {
        $summaryVersion = (int) Cache::get(
            CacheKeyGenerator::analyticsSummaryVersion($professional->id),
            0
        );

        $cacheKey = CacheKeyGenerator::analyticsSummary(
            $professional->id,
            $from->format('YmdH'),
            $to->format('YmdH')
        ).':'.($useHourlyBuckets ? 'hour' : 'day').":v{$summaryVersion}";

        // 5min TTL for live (today's) data; 24h for fully-historical ranges.
        // Int seconds (not Carbon) so CacheLockService applies ±20% jitter on
        // write — without it every dashboard cache fills at the same moment
        // and expires together, hammering the DB on each rollover.
        $cacheTTL = $to->isToday() ? 300 : 86400;

        return $this->cacheLock->rememberLocked(
            $cacheKey,
            $cacheTTL,
            fn () => $this->compose($professional, $site, $from, $to, $useHourlyBuckets, $professionalTimezone),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function compose(
        User $professional,
        Site $site,
        Carbon $from,
        Carbon $to,
        bool $useHourlyBuckets,
        string $professionalTimezone,
    ): array {
        $proId = $professional->id;

        $visitsAgg = $this->queries->visitsAggregate($proId, $from, $to);
        $clicksAgg = $this->queries->clicksAggregate($proId, $from, $to);

        $totalVisits = (int) ($visitsAgg->total_visits ?? 0);
        $totalClicks = (int) ($clicksAgg->total_clicks ?? 0);
        $ctr = $totalVisits > 0 ? round(($totalClicks / $totalVisits) * 100, 2) : 0.0;

        return [
            'range' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
            'granularity' => $useHourlyBuckets ? 'hour' : 'day',
            'bucket_timezone' => $useHourlyBuckets ? 'UTC' : $professionalTimezone,
            'professional' => [
                'id' => $professional->id,
                'handle' => $professional->handle,
                'display_name' => $professional->display_name,
            ],
            'site' => [
                'id' => $site->id,
                'subdomain' => $site->subdomain,
                'published' => (bool) $site->is_published,
            ],
            'breakdowns' => [
                'devices' => $this->queries->deviceTotals($proId, $from, $to),
                'countries' => $this->queries->countries($proId, $from, $to),
                'referrers' => $this->queries->referrers($proId, $from, $to),
            ],
            'totals' => [
                'visits' => $totalVisits,
                'unique_visitors' => (int) ($visitsAgg->unique_visitors ?? 0),
                'clicks' => $totalClicks,
                'unique_clickers' => (int) ($clicksAgg->unique_clickers ?? 0),
                'ctr_percent' => $ctr,
                'last_visit_at' => $visitsAgg->last_visit_at ? Carbon::parse($visitsAgg->last_visit_at)->toISOString() : null,
                'last_click_at' => $clicksAgg->last_click_at ? Carbon::parse($clicksAgg->last_click_at)->toISOString() : null,
            ],
            'charts' => [
                'visits_by_day' => $this->queries->visitsByBucket($proId, $from, $to, $useHourlyBuckets),
                'clicks_by_day' => $this->queries->clicksByBucket($proId, $from, $to, $useHourlyBuckets),
                'visits_by_day_by_device' => $this->queries->visitsByDayByDevice($proId, $from, $to),
            ],
            'top_sections' => $this->queries->topSections($proId, $from, $to),
            'top_links' => $this->queries->topLinks($proId, $from, $to),
        ];
    }
}
