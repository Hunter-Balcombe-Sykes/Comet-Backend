<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\User\User;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Cache\CacheLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

// V2: Staff-accessible analytics view for a professional's site (visits, clicks, device breakdown).
class StaffAnalyticsController extends ApiController
{
    public function __construct(private readonly CacheLockService $cacheLock) {}

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

        // Version token ties invalidation to the professional's own analytics bust —
        // incrementing analyticsSummaryVersion (e.g. on new visit ingestion) makes
        // both the professional's dashboard and this staff view stale at the same time.
        $version = (int) Cache::get(CacheKeyGenerator::analyticsSummaryVersion($professional->id), 0);
        $cacheKey = CacheKeyGenerator::staffAnalyticsSummary(
            $professional->id,
            $from->toDateString(),
            $to->toDateString(),
        ).":v{$version}";

        $data = $this->cacheLock->rememberLocked($cacheKey, 60, function () use ($professional, $from, $to, $site): array {
            // Totals (visits)
            $visitsAgg = DB::table('analytics.site_visits')
                ->where('user_id', $professional->id)
                ->whereBetween('occurred_at', [$from, $to])
                ->selectRaw('COUNT(*) as total_visits')
                ->selectRaw('COUNT(DISTINCT COALESCE(visitor_id::text, ip_hash)) as unique_visitors')
                ->selectRaw('MAX(occurred_at) as last_visit_at')
                ->first();

            // Defaults ensure visit analytics still works if click analytics queries fail.
            $clicksAgg = (object) [
                'total_clicks' => 0,
                'unique_clickers' => 0,
                'last_click_at' => null,
            ];
            $clicksByDay = collect();
            $topLinks = collect();

            try {
                $clicksAgg = DB::table('analytics.link_clicks')
                    ->where('user_id', $professional->id)
                    ->whereBetween('occurred_at', [$from, $to])
                    ->selectRaw('COUNT(*) as total_clicks')
                    ->selectRaw('COUNT(DISTINCT COALESCE(visitor_id::text, ip_hash)) as unique_clickers')
                    ->selectRaw('MAX(occurred_at) as last_click_at')
                    ->first();
            } catch (Throwable $e) {
                Log::warning('staff.analytics.click_query_failed', ['professional_id' => $professional->id, 'error' => $e->getMessage()]);
                $clicksAgg = (object) [
                    'total_clicks' => 0,
                    'unique_clickers' => 0,
                    'last_click_at' => null,
                ];
            }

            $totalVisits = (int) ($visitsAgg->total_visits ?? 0);
            $totalClicks = (int) ($clicksAgg->total_clicks ?? 0);

            // Daily charts
            $visitsByDay = DB::table('analytics.site_visits')
                ->where('user_id', $professional->id)
                ->whereBetween('occurred_at', [$from, $to])
                ->selectRaw('DATE(occurred_at) as day, COUNT(*) as count')
                ->groupByRaw('DATE(occurred_at)')
                ->orderBy('day')
                ->get();

            try {
                $clicksByDay = DB::table('analytics.link_clicks')
                    ->where('user_id', $professional->id)
                    ->whereBetween('occurred_at', [$from, $to])
                    ->selectRaw('DATE(occurred_at) as day, COUNT(*) as count')
                    ->groupByRaw('DATE(occurred_at)')
                    ->orderBy('day')
                    ->get();
            } catch (Throwable $e) {
                Log::warning('staff.analytics.click_query_failed', ['professional_id' => $professional->id, 'error' => $e->getMessage()]);
                $clicksByDay = collect();
            }

            try {
                // Top links
                $topLinks = DB::table('analytics.link_clicks as lc')
                    ->join('site.blocks as b', 'b.id', '=', 'lc.link_block_id')
                    ->where('lc.user_id', $professional->id)
                    ->whereBetween('lc.occurred_at', [$from, $to])
                    ->whereRaw("LOWER(COALESCE(b.block_group, '')) = 'links'")
                    ->whereRaw("LOWER(COALESCE(b.block_type, '')) = 'link'")
                    ->selectRaw('b.id as block_id, b.title, b.url, COUNT(*) as clicks')
                    ->groupBy('b.id', 'b.title', 'b.url')
                    ->orderByDesc('clicks')
                    ->limit(10)
                    ->get();
            } catch (Throwable $e) {
                Log::warning('staff.analytics.click_query_failed', ['professional_id' => $professional->id, 'error' => $e->getMessage()]);
                $topLinks = collect();
            }

            $ctr = $totalVisits > 0 ? round(($totalClicks / $totalVisits) * 100, 2) : 0.0;

            return [
                'range' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                ],
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
                    'visits_by_day' => $visitsByDay,
                    'clicks_by_day' => $clicksByDay,
                ],
                // Cast block_id to string for type-stable UUID serialisation.
                'top_links' => $topLinks->map(function (object $row): object {
                    $row->block_id = (string) $row->block_id;

                    return $row;
                }),
            ];
        });

        return $this->success($data);
    }
}
