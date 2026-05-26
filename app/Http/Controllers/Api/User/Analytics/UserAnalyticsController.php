<?php

namespace App\Http\Controllers\Api\User\Analytics;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Services\Analytics\AnalyticsCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

// V3: Site visit and link analytics for the authenticated professional's dashboard.
// HTTP-layer concerns only — date parsing, validation, response envelope.
// All query, cache, and composition logic lives in AnalyticsCacheService.
class UserAnalyticsController extends ApiController
{
    use ResolveCurrentUser;
    use ResolveCurrentSite;

    public function __construct(private readonly AnalyticsCacheService $analytics) {}

    public function summary(Request $request): JsonResponse
    {
        $professional = $this->currentUser($request);

        $days = (int) $request->query('days', 30);
        $days = max(1, min(365, $days));
        $groupBy = mb_strtolower(trim((string) $request->query('group_by', 'day')));
        $forceHourly = $groupBy === 'hour';

        $fromParam = $request->query('from');
        $toParam = $request->query('to');

        try {
            if ($forceHourly && ! $fromParam && ! $toParam) {
                $to = Carbon::now()->utc();
                $from = $to->copy()->subHours(24)->startOfHour();
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

        if ($from->diffInDays($to) > 365) {
            return $this->error('Date range cannot exceed 365 days.', 422);
        }

        $site = $professional->site;
        if (! $site) {
            return $this->error('professional has no site.', 404);
        }

        // Hourly granularity is used either by explicit ?group_by=hour or when
        // the entire requested window falls inside the last 24h.
        $hourlyCutoff = now()->utc()->subHours(24);
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

        return $this->success($data);
    }
}
