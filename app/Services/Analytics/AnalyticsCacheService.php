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
        private readonly InsightEngine $insightEngine,
    ) {}

    /**
     * Fixed rolling window (days) the insight engine analyses — independent of
     * the dashboard's selected range. 14d = two full weeks, the minimum the
     * week-over-week insights need.
     */
    private const INSIGHT_WINDOW_DAYS = 14;

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
     * Derived insights for the dashboard's "Insights" card. Computed over a fixed
     * INSIGHT_WINDOW_DAYS rolling window (NOT the dashboard's selected range),
     * cached per user with the shared summary version token (1h TTL) so the
     * heavier week-over-week reads run at most hourly however many ranges the
     * dashboard requests.
     *
     * Fail-open at every layer: each query provider try/catches to empty, the
     * engine emits nothing below threshold, and this wrapper backstops any
     * surprise to [] — insights can never break the analytics response.
     *
     * CCH-4: computeInsights() distinguishes "genuinely no insights this
     * window" ([]) from "compute blew up" (null) so a transient fault doesn't
     * squat on the same 3600s key a healthy [] result would use — null gets a
     * short 60s nullTtl via rememberLockedNullable, so the next request retries
     * soon instead of fleet-wide caching an empty panel for up to an hour.
     *
     * @return list<array{id:string, kind:string, headline:string, supporting_stat:array<string,mixed>, period:array<string,mixed>}>
     */
    public function insights(User $professional, Site $site): array
    {
        $version = (int) Cache::get(CacheKeyGenerator::analyticsSummaryVersion($professional->id), 0);
        $cacheKey = CacheKeyGenerator::analyticsInsights($professional->id).":v{$version}";

        $result = $this->cacheLock->rememberLockedNullable(
            $cacheKey,
            3600,
            fn (): ?array => $this->computeInsights($professional),
            nullTtl: 60,
        );

        return $result ?? [];
    }

    /**
     * Fetch the aggregates each insight needs and run them through the pure
     * InsightEngine. Windows: time-of-day / weekday / dwell use the full
     * INSIGHT_WINDOW_DAYS window; page riser-faller + source shift compare the
     * current 7-day week against the prior 7-day week.
     *
     * Returns null (not []) on failure so insights() can cache the fail-open
     * result under its own short TTL instead of the normal 3600s success TTL —
     * [] is a legitimate "nothing rose above threshold" result and must not be
     * conflated with "the compute threw".
     *
     * @return list<array<string, mixed>>|null
     */
    private function computeInsights(User $professional): ?array
    {
        try {
            $proId = $professional->id;
            $now = Carbon::now()->utc();
            $windowStart = $now->copy()->subDays(self::INSIGHT_WINDOW_DAYS)->startOfDay();
            $thisWeekStart = $now->copy()->subDays(7)->startOfDay();
            $priorWeekStart = $thisWeekStart->copy()->subDays(7);

            $windowPeriod = [
                'from' => $windowStart->toDateString(),
                'to' => $now->toDateString(),
                'label' => 'Last '.self::INSIGHT_WINDOW_DAYS.' days',
            ];
            $wowPeriod = [
                'from' => $thisWeekStart->toDateString(),
                'to' => $now->toDateString(),
                'label' => 'This week vs last week',
            ];

            $insights = [];

            $timeOfDay = $this->insightEngine->timeOfDay(
                $this->queries->hourlyClickBuckets($proId, $windowStart, $now),
                $windowPeriod,
            );
            if ($timeOfDay !== null) {
                $insights[] = $timeOfDay;
            }

            $weekdayPeak = $this->insightEngine->weekdayPeak(
                $this->queries->weekdayVisitBuckets($proId, $windowStart, $now),
                $windowPeriod,
            );
            if ($weekdayPeak !== null) {
                $insights[] = $weekdayPeak;
            }

            foreach ($this->insightEngine->pageRisersFallers(
                $this->queries->pageViewsWeekOverWeek($proId, $thisWeekStart, $now),
                $wowPeriod,
            ) as $pageInsight) {
                $insights[] = $pageInsight;
            }

            $sourceShift = $this->insightEngine->trafficSourceShift(
                $this->queries->sourceCountsForWindow($proId, $thisWeekStart, $now),
                $this->queries->sourceCountsForWindow($proId, $priorWeekStart, $thisWeekStart),
                $wowPeriod,
            );
            if ($sourceShift !== null) {
                $insights[] = $sourceShift;
            }

            $dwellOutlier = $this->insightEngine->dwellOutlier(
                $this->queries->pageDwellStats($proId, $windowStart, $now),
                $windowPeriod,
            );
            if ($dwellOutlier !== null) {
                $insights[] = $dwellOutlier;
            }

            return $insights;
        } catch (Throwable $e) {
            Log::warning('analytics.insights_failed', ['user_id' => $professional->id, 'error' => $e->getMessage()]);

            // CCH-4: mirrors bumpVersion() — a single blip stays a breadcrumb,
            // a sustained run (Redis/DB genuinely down) escalates to Nightwatch.
            self::escalateIfSustained($e, 'insights_compute');

            return null;
        }
    }

    /**
     * @return array{visitsAgg: \stdClass, clicksAgg: \stdClass, totalVisits: int, totalClicks: int, ctr: float}
     */
    private function baseTotals(string $proId, Carbon $from, Carbon $to): array
    {
        $visitsAgg = $this->queries->visitsAggregate($proId, $from, $to);
        $clicksAgg = $this->queries->clicksAggregate($proId, $from, $to);

        $totalVisits = (int) ($visitsAgg->total_visits ?? 0);
        $totalClicks = (int) ($clicksAgg->total_clicks ?? 0);
        $ctr = $totalVisits > 0 ? round(($totalClicks / $totalVisits) * 100, 2) : 0.0;

        return compact('visitsAgg', 'clicksAgg', 'totalVisits', 'totalClicks', 'ctr');
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

        ['visitsAgg' => $visitsAgg, 'clicksAgg' => $clicksAgg, 'totalVisits' => $totalVisits, 'totalClicks' => $totalClicks, 'ctr' => $ctr] = $this->baseTotals($proId, $from, $to);
        $sessionsAgg = $this->queries->sessionsAggregate($proId, $from, $to);

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
            // TRANSITION ALIAS: emit BOTH keys, each backed by its own true
            // projection, until OV-G-FE ships. `top_sections` = section-grain
            // (what the live dashboard still reads today); `top_pages` =
            // section_views folded into the 16-page sitepage taxonomy at the query
            // layer (the new page-views metric). Stored section_key rows are
            // immutable — folding/relabelling is query-layer only. Drop
            // `top_sections` in a one-line follow-up once the OV-G-FE deploy
            // reading `top_pages` lands.
            'top_sections' => $this->queries->topSections($proId, $from, $to),
            'top_pages' => $this->queries->topPages($proId, $from, $to),
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
        ['visitsAgg' => $visitsAgg, 'clicksAgg' => $clicksAgg, 'totalVisits' => $totalVisits, 'totalClicks' => $totalClicks, 'ctr' => $ctr] = $this->baseTotals($proId, $from, $to);

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
