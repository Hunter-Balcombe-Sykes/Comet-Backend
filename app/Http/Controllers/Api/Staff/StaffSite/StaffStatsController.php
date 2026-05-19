<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Services\Cache\CacheLockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// V2: Platform-wide stats for the staff ops dashboard. Single aggregation call — counts by professional_type, active subscriptions, pending commissions.
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
     *     professionals: array{brands: int, influencers: int, professionals: int, total: int, by_account_type: array<string, int>},
     *     subscriptions: array{active_count: int},
     *     commissions: array{pending_cents: int}
     * }
     */
    private function buildPayload(): array
    {
        // Legacy breakdown on professional_type — kept until staff dashboard migrates
        // to account_type labels (§28.11 Option A: expose both, defer cutover to follow-up).
        $typeCounts = DB::table('core.professionals')
            ->whereNull('deleted_at')
            ->selectRaw('professional_type, count(*) as total')
            ->groupBy('professional_type')
            ->pluck('total', 'professional_type');

        // New breakdown on account_type — bucket labels are brand|partner|individual.
        // NULL rows (pre-migration) fall into the 'unknown' bucket so total doesn't drift.
        $accountTypeCounts = DB::table('core.professionals')
            ->whereNull('deleted_at')
            ->selectRaw("COALESCE(account_type::text, 'unknown') as account_type, count(*) as total")
            ->groupByRaw("COALESCE(account_type::text, 'unknown')")
            ->pluck('total', 'account_type');

        $activeSubscriptions = DB::table('billing.subscriptions')
            ->whereNull('ended_at')
            ->count();

        $pendingCommissionCents = DB::table('commerce.commission_movements')
            ->where('status', 'pending')
            ->sum('amount_cents');

        $brands = (int) ($typeCounts->get('brand') ?? 0);
        $influencers = (int) ($typeCounts->get('influencer') ?? 0);
        $professionals = (int) ($typeCounts->get('professional') ?? 0);

        return [
            'professionals' => [
                'brands' => $brands,
                'influencers' => $influencers,
                'professionals' => $professionals,
                'total' => $brands + $influencers + $professionals,
                // New canonical breakdown — frontend should migrate to reading this.
                'by_account_type' => $accountTypeCounts->all(),
            ],
            'subscriptions' => [
                'active_count' => (int) $activeSubscriptions,
            ],
            'commissions' => [
                'pending_cents' => (int) $pendingCommissionCents,
            ],
        ];
    }
}
