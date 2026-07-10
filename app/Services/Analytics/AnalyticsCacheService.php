<?php

namespace App\Services\Analytics;

use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Analytics\Concerns\EscalatesRepeatedFaults;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use App\Services\Cache\Concerns\JitteredTtl;
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
    use EscalatesRepeatedFaults;
    use JitteredTtl;

    public function __construct(
        private readonly CacheLockService $cacheLock,
        private readonly AnalyticsQueryService $queries,
    ) {}

    /**
     * Debounced bust of every cached summary variant for a user: increments the
     * version token (at most once per debounce window/user — config-driven, jittered,
     * ~30s default) that summary() folds into the cache
     * key, atomically invalidating all (range, granularity) variants. Called after a
     * new ingest lands — inline by SyncIngestor and from RecordAnalyticsEventJob — so
     * both ingest paths share one implementation. Fail-open: a cache fault is swallowed
     * (logged) so it can never fail an ingest whose write already committed.
     */
    public function bumpVersion(string $userId): void
    {
        try {
            // CCH-1: jittered so a burst of ingests across many users doesn't debounce
            // (and re-bump) in lockstep every exact 30s.
            $ttl = self::applyJitter((int) config('partna.analytics.ingest_debounce_seconds', 30));

            if (Cache::add(CacheKeyGenerator::analyticsIngestDebounce($userId), 1, $ttl)) {
                Cache::increment(CacheKeyGenerator::analyticsSummaryVersion($userId));
            }
        } catch (Throwable $e) {
            Log::warning('analytics.cache_bump_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);

            // A single blip stays a breadcrumb; a sustained run (Redis genuinely down)
            // escalates to Nightwatch — see EscalatesRepeatedFaults.
            self::escalateIfSustained($e, 'cache_bump');
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

        // CFG-1: TTL for live (today's) data vs. fully-historical ranges, config-driven.
        // Int seconds (not Carbon) so CacheLockService applies ±20% jitter on
        // write — without it every dashboard cache fills at the same moment
        // and expires together, hammering the DB on each rollover.
        $cacheTTL = $to->isToday()
            ? (int) config('partna.analytics.summary_ttl_today_seconds', 300)
            : (int) config('partna.analytics.summary_ttl_historical_seconds', 86400);

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
        $sessionsAgg = $this->queries->sessionsAggregate($proId, $from, $to);

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
                // AU state split (region_code from the edge); countries stays the
                // world view, regions zooms into the home market.
                'regions' => $this->queries->regions($proId, $from, $to),
                // Top cities by unique visitors — powers the demographics map's
                // city view (region_code gives states; city zooms one level in).
                'cities' => $this->queries->cities($proId, $from, $to),
                'referrers' => $this->queries->referrers($proId, $from, $to),
                'platforms' => $this->queries->platformClicks($proId, $from, $to),
            ],
            'totals' => [
                'visits' => $totalVisits,
                'unique_visitors' => (int) ($visitsAgg->unique_visitors ?? 0),
                'clicks' => $totalClicks,
                'unique_clickers' => (int) ($clicksAgg->unique_clickers ?? 0),
                'ctr_percent' => $ctr,
                'avg_session_seconds' => (int) ($sessionsAgg->avg_duration_seconds ?? 0),
                'engaged_sessions' => (int) ($sessionsAgg->engaged_sessions ?? 0),
                'total_sessions' => (int) ($sessionsAgg->total_sessions ?? 0),
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
            // Shop/booking/event sitepage sections all carry product_id on the same
            // analytics.link_clicks table — each key is scoped to its own section_key
            // set (AnalyticsQueryService::topItemsBySection()) so the three lists stay
            // disjoint.
            'top_products' => $this->queries->topProducts($proId, $from, $to),
            'top_services' => $this->queries->topServices($proId, $from, $to),
            'top_events' => $this->queries->topEvents($proId, $from, $to),
            'conversions' => $this->queries->conversions($proId, $from, $to),
        ];
    }

    /**
     * Staff-view counterpart to summary() — same AnalyticsQueryService reads as the
     * professional's own dashboard (FOUND-1: the old controller's inline `site.blocks`
     * INNER JOIN silently dropped every v2 url-based click). Narrower payload (no
     * breakdowns/top_sections/conversions) matching the staff view's actual UI needs.
     *
     * @return array<string, mixed>
     */
    public function staffSummary(User $professional, Site $site, Carbon $from, Carbon $to): array
    {
        $version = (int) Cache::get(CacheKeyGenerator::analyticsSummaryVersion($professional->id), 0);
        $cacheKey = CacheKeyGenerator::staffAnalyticsSummary(
            $professional->id,
            $from->toDateString(),
            $to->toDateString(),
        ).":v{$version}";

        // CFG-1: byte-identical staff cache-key contract to the legacy controller (CACHE-3 guard) — TTL now config-driven.
        $ttl = (int) config('partna.analytics.staff_summary_ttl_seconds', 60);

        return $this->cacheLock->rememberLocked($cacheKey, $ttl, fn () => $this->composeStaff($professional, $site, $from, $to));
    }

    /**
     * @return array<string, mixed>
     */
    private function composeStaff(User $professional, Site $site, Carbon $from, Carbon $to): array
    {
        $proId = $professional->id;
        $visitsAgg = $this->queries->visitsAggregate($proId, $from, $to);
        $clicksAgg = $this->queries->clicksAggregate($proId, $from, $to);
        $totalVisits = (int) ($visitsAgg->total_visits ?? 0);
        $totalClicks = (int) ($clicksAgg->total_clicks ?? 0);
        $ctr = $totalVisits > 0 ? round(($totalClicks / $totalVisits) * 100, 2) : 0.0;

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'professional' => [
                'id' => (string) $professional->id,
                'handle' => $professional->handle,
                'display_name' => $professional->display_name,
            ],
            'site' => [
                'id' => (string) $site->id,
                'subdomain' => $site->subdomain,
                'published' => (bool) $site->is_published,
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
                // Daily granularity only (staff view has no hourly toggle). Now
                // unique-per-day via visitsByBucket/clicksByBucket, matching the
                // professional dashboard (was COUNT(*) total-per-day inline) —
                // intentional parity change.
                'visits_by_day' => $this->queries->visitsByBucket($proId, $from, $to, false),
                'clicks_by_day' => $this->queries->clicksByBucket($proId, $from, $to, false),
            ],
            // v2 self-describing clicks (url/platform/label/section_key). Replaces the
            // old site.blocks inner join that dropped every url-based (link_block_id
            // NULL) click — see FOUND-1.
            'top_links' => $this->queries->topLinks($proId, $from, $to),
        ];
    }
}
