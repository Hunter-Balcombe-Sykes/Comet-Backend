<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\User\User;
use App\Services\Analytics\AnalyticsCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Throwable;

// V2: Staff-accessible analytics view for a professional's site (visits, clicks, device breakdown).
class StaffAnalyticsController extends ApiController
{
    public function __construct(
        private readonly AnalyticsCacheService $cache,
    ) {}

    /**
     * GET /api/staff/professionals/{professional}/analytics?days=30
     * Optional: ?from=YYYY-MM-DD&to=YYYY-MM-DD
     *
     * Cached for 60 s keyed by (user_id, from, to) + analyticsSummaryVersion
     * so a cache-bust on the professional's own dashboard also refreshes this view.
     */
    public function summary(Request $request, User $professional): JsonResponse
    {
        $days = (int) $request->query('days', 30);
        $days = max(1, min(365, $days));

        $fromParam = $request->query('from');
        $toParam = $request->query('to');

        try {
            if ($fromParam || $toParam) {
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
        } catch (Throwable $e) {
            return $this->error(
                'Invalid date range. Use YYYY-MM-DD for from/to.',
                422,
                [
                    'from' => $fromParam ? ['Invalid date.'] : [],
                    'to' => $toParam ? ['Invalid date.'] : [],
                ]
            );
        }

        if ($from->gt($to)) {
            return $this->error('Invalid date range: from must be before to.', 422);
        }

        $site = $professional->site;
        if (! $site) {
            return $this->error('professional has no site.', 404);
        }

        // Same cache + query path as the professional's own dashboard (AnalyticsQueryService)
        // — keeps this view in parity instead of duplicating queries inline (see FOUND-1).
        return $this->success($this->cache->staffSummary($professional, $site, $from, $to));
    }
}
