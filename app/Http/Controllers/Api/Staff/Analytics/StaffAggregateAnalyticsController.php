<?php

namespace App\Http\Controllers\Api\Staff\Analytics;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Segments\UserSegment;
use App\Services\Analytics\AnalyticsQueryService;
use App\Services\Segments\SegmentResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * OV-A: platform-wide (or segment-scoped) analytics for the staff dashboard.
 * Reuses AnalyticsQueryService via its user-scope injection (null = all users,
 * array = segment member set) — totals, time series, top pages/platforms.
 *
 * First cut by design: whereIn scope is fine at pilot scale; if segments grow
 * past ~10k members, materialize the id set into a temp table or pre-aggregate.
 */
class StaffAggregateAnalyticsController extends ApiController
{
    private const CACHE_TTL_SECONDS = 60;

    public function __construct(
        private readonly AnalyticsQueryService $queries,
        private readonly SegmentResolver $resolver,
    ) {}

    /**
     * GET /api/staff/analytics/summary?days=30 | ?from=Y-m-d&to=Y-m-d, optional &segment_id=
     * Any staff role (staff_view_aggregate_analytics); segment 404s when unknown.
     */
    public function summary(Request $request): JsonResponse
    {
        $staff = $request->attributes->get('partna_staff');
        $this->authorizeForUser($staff, 'staffView', UserSegment::class);

        $days = max(1, min(365, (int) $request->query('days', 30)));

        try {
            $from = $request->query('from')
                ? Carbon::parse((string) $request->query('from'))->startOfDay()
                : Carbon::now()->subDays($days)->startOfDay();
            $to = $request->query('to')
                ? Carbon::parse((string) $request->query('to'))->endOfDay()
                : Carbon::now()->endOfDay();
        } catch (Throwable) {
            return $this->error('Invalid date range. Use YYYY-MM-DD for from/to.', 422);
        }

        if ($from->gt($to)) {
            return $this->error('Invalid date range: from must be before to.', 422);
        }

        // Scope: null = all users; segment → resolved member ids.
        $scope = null;
        $audience = ['scope' => 'all'];

        $segmentId = $request->query('segment_id');
        if (is_string($segmentId) && $segmentId !== '') {
            $segment = UserSegment::query()->find($segmentId);
            if ($segment === null) {
                return $this->error('Segment not found.', 404);
            }

            $scope = $this->resolver->userIds($segment);
            $audience = [
                'scope' => 'segment',
                'segment_id' => $segment->id,
                'segment_name' => $segment->name,
                'user_count' => count($scope),
            ];
        }

        $cacheKey = sprintf(
            'staff:aggregate-analytics:%s:%s:%s',
            $scope === null ? 'all' : sha1(implode(',', $scope)),
            $from->timestamp,
            $to->timestamp,
        );

        $payload = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($scope, $from, $to) {
            $hourly = $from->diffInHours($to) <= 48;

            $visits = $this->queries->visitsAggregate($scope, $from, $to);
            $clicks = $this->queries->clicksAggregate($scope, $from, $to);
            $sessions = $this->queries->sessionsAggregate($scope, $from, $to);

            return [
                'totals' => [
                    'views' => (int) $visits->total_visits,
                    'unique_visitors' => (int) $visits->unique_visitors,
                    'clicks' => (int) $clicks->total_clicks,
                    'unique_clickers' => (int) $clicks->unique_clickers,
                    'sessions' => (int) $sessions->total_sessions,
                    'engaged_sessions' => (int) $sessions->engaged_sessions,
                    'avg_session_seconds' => (int) $sessions->avg_duration_seconds,
                ],
                'series' => [
                    'granularity' => $hourly ? 'hour' : 'day',
                    'visits' => $this->queries->visitsByBucket($scope, $from, $to, $hourly)
                        ->map(fn ($row) => ['bucket' => (string) $row->day, 'count' => (int) $row->count])->values(),
                    'clicks' => $this->queries->clicksByBucket($scope, $from, $to, $hourly)
                        ->map(fn ($row) => ['bucket' => (string) $row->day, 'count' => (int) $row->count])->values(),
                ],
                'top_pages' => $this->queries->topSections($scope, $from, $to)->values(),
                'top_platforms' => $this->queries->platformClicks($scope, $from, $to),
            ];
        });

        return $this->success([
            'range' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
            'audience' => $audience,
            ...$payload,
        ]);
    }
}
