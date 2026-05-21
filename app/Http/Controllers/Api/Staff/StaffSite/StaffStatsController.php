<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Services\Cache\CacheLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// V2: Platform-wide stats for the staff ops dashboard. Counts by account_type.
class StaffStatsController extends ApiController
{
    // Single shared cache key — these stats are platform-wide, not per-user.
    // 60s TTL caps DB load if anything ever polls this (status board, monitoring,
    // mistaken auto-refresh) without making the numbers visibly stale to humans.
    private const CACHE_KEY = 'staff:ops:stats';

    private const CACHE_TTL_SECONDS = 60;

    public function __construct(private readonly CacheLockService $cacheLock) {}

    public function show(Request $request): JsonResponse
    {
        $payload = $this->cacheLock->rememberLocked(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->buildPayload(),
        );

        return $this->success($payload);
    }

    /**
     * @return array{
     *     professionals: array{total: int, by_account_type: array<string, int>},
     * }
     */
    private function buildPayload(): array
    {
        // Breakdown on account_type — bucket labels are brand|partner|individual.
        // NULL rows (pre-§28.1 backfill) fall into the 'unknown' bucket so total
        // never drifts away from the row count.
        $accountTypeCounts = DB::table('core.professionals')
            ->whereNull('deleted_at')
            ->selectRaw("COALESCE(account_type::text, 'unknown') as account_type, count(*) as total")
            ->groupByRaw("COALESCE(account_type::text, 'unknown')")
            ->pluck('total', 'account_type')
            ->all();

        return [
            'professionals' => [
                'total' => array_sum($accountTypeCounts),
                'by_account_type' => $accountTypeCounts,
            ],
        ];
    }
}
