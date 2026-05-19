<?php

namespace App\Http\Controllers\Api\Professional\Analytics;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Professional\Analytics\StatsRequest;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Http\JsonResponse;

/**
 * GET /analytics — Phase 5 brand + affiliate stats surface.
 *
 * Returns the full payload (all metrics × six time windows) for the caller's role.
 * Cross-role calls (brand requesting role=affiliate or vice versa) get a 403.
 * Cached 5min via AnalyticsService::cacheLock.
 */
class StatsController extends Controller
{
    public function __construct(private readonly AnalyticsService $analyticsService) {}

    public function index(StatsRequest $request): JsonResponse
    {
        $pro = $request->attributes->get('professional');
        $role = (string) $request->input('role');

        $isBrand = $pro->isBrand();
        if ($role === 'brand' && ! $isBrand) {
            return response()->json(['error' => 'cross_role'], 403);
        }
        if ($role === 'affiliate' && $isBrand) {
            return response()->json(['error' => 'cross_role'], 403);
        }

        $payload = $role === 'brand'
            ? $this->analyticsService->forBrand($pro)
            : $this->analyticsService->forAffiliate($pro);

        return response()->json($payload);
    }
}
