<?php

namespace App\Http\Controllers\Api\User\Analytics;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Services\Analytics\AnalyticsCacheService;
use App\Services\Analytics\AnalyticsQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

// V3: Site visit and link analytics for the authenticated professional's dashboard.
// HTTP-layer concerns only — date parsing, validation, response envelope.
// All query, cache, and composition logic lives in AnalyticsCacheService.
class UserAnalyticsController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function __construct(
        private readonly AnalyticsCacheService $analytics,
        private readonly AnalyticsQueryService $queries,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $professional = $this->currentUser($request);

        $days = (int) $request->query('days', 30);
        // The "all time" request; the range itself is clamped below.
        $maxDaysAllTime = (int) config('partna.analytics.max_days_all_time', 3650);
        $days = max(1, min($maxDaysAllTime, $days));
        $groupBy = mb_strtolower(trim((string) $request->query('group_by', 'day')));
        $forceHourly = $groupBy === 'hour';

        $fromParam = $request->query('from');
        $toParam = $request->query('to');

        try {
            if ($forceHourly && ! $fromParam && ! $toParam) {
                $to = Carbon::now()->utc();
                $from = $to->copy()->subHours((int) config('partna.analytics.default_hourly_lookback_hours', 24))->startOfHour();
            } elseif ($fromParam || $toParam) {
                $from = $fromParam
                    ? Carbon::parse($fromParam)->startOfDay()
                    : Carbon::now()->subDays($days)->startOfDay();

                $to = $toParam
                    ? Carbon::parse($toParam)->endOfDay()
                    : Carbon::now()->endOfDay();
            } else {
                $to = Carbon::now()->endOfDay();
                $from = Carbon::now()->subDays($days)->startOfDay();
            }
        } catch (Throwable) {
            return $this->error(
                'Invalid date range.  Use YYYY-MM-DD for from/to.',
                422,
                [
                    'from' => $fromParam ? ['Invalid date.'] : [],
                    'to' => $toParam ? ['Invalid date.'] : [],
                ]
            );
        }

        if ($from->gt($to)) {
            return $this->error('Invalid date range: from must be before to. ', 422);
        }

        // Wide ranges CLAMP instead of 422ing. days=365 + startOfDay/endOfDay
        // padding made diffInDays 365.9 — so "Last Year" (and the client-capped
        // "All Time") hit the old hard >365 boundary and failed. Cap the window
        // at max_window_days: "all time" honestly covers the platform's whole life.
        $maxWindowDays = (int) config('partna.analytics.max_window_days', 730);
        if ($from->diffInDays($to) > $maxWindowDays) {
            $from = $to->copy()->subDays($maxWindowDays)->startOfDay();
        }

        // API-5: the 730-day clamp above bounds total range width, not per-bucket
        // cost — group_by=hour over a wide custom range would still emit thousands
        // of hourly rows a chart never usefully renders. Clamp (not 422) the
        // LOOKBACK when hourly is forced, matching this endpoint's existing
        // "wide ranges clamp" contract rather than introducing a new error case.
        $from = $this->clampHourlyLookback($from, $to, $forceHourly);

        $site = $professional->site;
        if (! $site) {
            return $this->error('professional has no site.', 404);
        }

        // Hourly granularity is used either by explicit ?group_by=hour or when
        // the entire requested window falls inside the last 24h.
        $hourlyCutoff = now()->utc()->subHours((int) config('partna.analytics.default_hourly_lookback_hours', 24));
        $useHourlyBuckets = $forceHourly || (
            $from->copy()->utc()->gte($hourlyCutoff)
            && $to->copy()->utc()->lte(now()->utc()->addMinute())
        );

        $professionalTimezone = trim((string) ($professional->timezone ?? '')) ?: 'UTC';

        $data = $this->analytics->summary(
            $professional,
            $site,
            $from,
            $to,
            $useHourlyBuckets,
            $professionalTimezone,
        );

        // Derived insights ride the summary payload (own fixed window + cache, so
        // they don't vary with the selected range). Fail-open to [] inside the
        // service — never blocks the summary.
        $data['insights'] = $this->analytics->insights($professional, $site);

        return $this->success($data);
    }

    /**
     * Bounds hourly-bucket cardinality (#API-5): when hour granularity is forced,
     * shrink the lookback to config('partna.analytics.hourly_bucket_max_days')
     * (default 7) if the requested range is wider — a year of hourly buckets is
     * never usefully rendered by the dashboard chart. No-op for daily granularity
     * or a range already inside the window.
     */
    private function clampHourlyLookback(Carbon $from, Carbon $to, bool $forceHourly): Carbon
    {
        if (! $forceHourly) {
            return $from;
        }

        $maxDays = (int) config('partna.analytics.hourly_bucket_max_days', 7);

        return $from->diffInDays($to) > $maxDays
            ? $to->copy()->subDays($maxDays)->startOfDay()
            : $from;
    }

    /**
     * Dedicated insights resource — the same derived-insight array the summary
     * embeds under `insights`, for the dashboard's Insights card to fetch on its
     * own. Computed over a fixed rolling window, independent of any date range.
     */
    public function insights(Request $request): JsonResponse
    {
        $professional = $this->currentUser($request);

        $site = $professional->site;
        if (! $site) {
            return $this->error('professional has no site.', 404);
        }

        return $this->success([
            'insights' => $this->analytics->insights($professional, $site),
        ]);
    }

    /**
     * Live-now count: sessions with a heartbeat in the last 75s. Deliberately
     * bypasses the summary cache (which holds up to 5min); the dashboard polls
     * this every ~15s while the analytics page is visible.
     */
    public function live(Request $request): JsonResponse
    {
        $professional = $this->currentUser($request);

        return $this->success([
            'live_visitors' => $this->queries->liveVisitors($professional->id),
        ]);
    }
}
