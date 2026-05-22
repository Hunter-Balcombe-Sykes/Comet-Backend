<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Services\Cache\CacheLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// V2: Platform-wide stats for the staff ops dashboard.
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
     *     professionals: array{total: int},
     * }
     */
    private function buildPayload(): array
    {
        $total = DB::table('core.users')
            ->whereNull('deleted_at')
            ->count();

        return [
            'professionals' => [
                'total' => $total,
            ],
        ];
    }
}
