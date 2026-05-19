<?php

namespace App\Http\Controllers\Api\Professional\Brand;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Commerce\CommissionPayout;
use App\Models\Commerce\Order;
use App\Models\Core\Professional\BrandPartnerLink;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Cache\CacheLockService;
use App\Services\Professional\Brand\BrandPartnerLinkLifecycleService;
use App\Services\Professional\Brand\BrandPartnerLinkService;
use App\Services\Professional\DTO\DisconnectRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// V2: Brand views and disconnects their connected affiliates. Single-brand constraint means each affiliate has exactly one brand.
class BrandAffiliateController extends ApiController
{
    use ResolveCurrentProfessional;

    public function __construct(private CacheLockService $cacheLock) {}

    public function index(Request $request): JsonResponse
    {
        // Role gating handled by `brand.only` middleware (EnsureBrandAccount).
        $professional = $this->currentProfessional($request);
        $brandId = $professional->id;

        $links = BrandPartnerLink::query()
            ->where('brand_professional_id', $brandId)
            ->orderByDesc('updated_at')
            ->get(['affiliate_professional_id', 'slot', 'custom_photos_enabled', 'site_url', 'updated_at']);

        $affiliateIds = $links
            ->pluck('affiliate_professional_id')
            ->unique()
            ->values()
            ->all();

        $sitesByProfessionalId = Site::query()
            ->with(['professional'])
            ->whereIn('professional_id', $affiliateIds)
            ->whereHas('professional', function ($query): void {
                // Exclude brands: dual-read inverse covers rows in the §28.1 window where
                // account_type may still be null but professional_type='brand' means brand.
                $query
                    ->where('status', 'active')
                    ->where(function ($q): void {
                        $q->where('account_type', '!=', 'brand')
                            ->orWhere(function ($q2): void {
                                $q2->whereNull('account_type')->where('professional_type', '!=', 'brand');
                            });
                    });
            })
            ->get()
            ->keyBy('professional_id');

        $affiliates = $links
            ->map(function (BrandPartnerLink $link) use ($sitesByProfessionalId): ?array {
                /** @var Site|null $site */
                $site = $sitesByProfessionalId->get($link->affiliate_professional_id);
                if (! $site) {
                    return null;
                }

                $connectedProfessional = $site->professional;
                $name = trim(implode(' ', array_filter([
                    $connectedProfessional?->first_name,
                    $connectedProfessional?->last_name,
                ])));

                return [
                    'id' => $connectedProfessional?->id,
                    'full_name' => $name !== '' ? $name : ($connectedProfessional?->display_name ?? $connectedProfessional?->handle ?? 'Unknown'),
                    'display_name' => $connectedProfessional?->display_name,
                    'handle' => $connectedProfessional?->handle,
                    'professional_type' => $connectedProfessional?->professional_type,
                    // account_type alongside legacy professional_type for Nightwatch continuity
                    // and progressive frontend reads during the §28.1 dual-write window.
                    'account_type' => $connectedProfessional?->account_type?->value,
                    'email' => $connectedProfessional?->primary_email ?? $connectedProfessional?->public_contact_email,
                    'phone' => $connectedProfessional?->phone ?? $connectedProfessional?->public_contact_number,
                    'connected_at' => optional($link->updated_at)->toIso8601String(),
                    'is_primary' => (int) $link->slot === BrandPartnerLinkService::PRIMARY_SLOT,
                    'custom_photos_enabled' => $link->custom_photos_enabled,
                    // Trigger-managed by Postgres from site subdomain + brand handle;
                    // read directly rather than recomputing from related models.
                    'site_url' => $link->site_url,
                ];
            })
            ->filter(fn (?array $affiliate): bool => is_array($affiliate) && filled($affiliate['id']))
            ->values()
            ->all();

        return $this->success([
            'affiliates' => $affiliates,
        ]);
    }

    public function disconnect(
        Request $request,
        string $affiliateId,
        BrandPartnerLinkLifecycleService $lifecycle,
    ): JsonResponse {
        // Role gating handled by `brand.only` middleware (EnsureBrandAccount).
        $professional = $this->currentProfessional($request);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $affiliate = Professional::query()->whereKey($affiliateId)->first();
        if (! $affiliate) {
            return $this->error('Affiliate not found.', 404);
        }

        $result = $lifecycle->disconnect(DisconnectRequest::forBrand(
            brand: $professional,
            affiliate: $affiliate,
            reason: $data['reason'] ?? null,
        ));

        if (! $result->disconnected) {
            return $this->error('Affiliate not found for this brand.', 404);
        }

        return $this->success([
            'disconnected' => true,
            'affiliate_id' => $affiliateId,
            'selections_removed' => $result->selectionsRemoved,
        ]);
    }

    /**
     * Per-affiliate snapshot for the brand affiliate-detail modal.
     *
     * One round-trip replaces the modal's previous "try one analytics
     * endpoint, then fall back to a list endpoint" dance. Aggregates are
     * lifetime-to-date — the modal isn't a date-range explorer.
     *
     * @return JsonResponse{ data: { affiliate_id, identity, totals, commission, page_views, recent_payouts, currency_code } }
     */
    public function snapshot(Request $request, string $affiliateId): JsonResponse
    {
        // Role gating handled by `brand.only` middleware (EnsureBrandAccount).
        $professional = $this->currentProfessional($request);
        $brandId = (string) $professional->id;

        // Auth check — only return data for affiliates linked to this brand.
        $link = BrandPartnerLink::query()
            ->where('brand_professional_id', $brandId)
            ->where('affiliate_professional_id', $affiliateId)
            ->first();

        if (! $link) {
            return $this->error('Affiliate not found for this brand.', 404);
        }

        $affiliate = Professional::query()->whereKey($affiliateId)->first();
        if (! $affiliate) {
            return $this->error('Affiliate not found.', 404);
        }

        // Lifetime commerce + page-view aggregates. Cached at 60s with
        // single-flight lock + jitter (matches the Phase 3 analytics
        // controller pattern) — the modal's snapshot is the same shape no
        // matter who hits it, and lifetime sums change at most once per
        // webhook ingest, so a short TTL is safe and protects against
        // dashboard re-open thrash.
        $cacheKey = "analytics:commerce:brand_affiliate_snapshot:v1:{$brandId}:{$affiliateId}";
        $aggregates = $this->cacheLock->rememberLocked($cacheKey, 60, function () use ($brandId, $affiliateId): array {
            // Pick the dominant currency from the per-day rollup so multi-currency
            // affiliates resolve to their primary corridor for KPI display.
            $currencyRow = DB::table('commerce.brand_affiliate_rollup')
                ->where('brand_professional_id', $brandId)
                ->where('affiliate_professional_id', $affiliateId)
                ->selectRaw('currency_code, SUM(orders_count) AS cnt')
                ->groupBy('currency_code')
                ->orderByDesc('cnt')
                ->first();

            $currencyCode = $currencyRow->currency_code ?? 'AUD';

            // Lifetime totals from the trigger-maintained rollup. net_cents is
            // already gross-minus-refund per row; commission_net is
            // commission_cents - reversed_commission_cents — the affiliate's
            // earnings net of clawbacks/refund-driven reversals.
            $rollupRow = DB::table('commerce.brand_affiliate_rollup')
                ->where('brand_professional_id', $brandId)
                ->where('affiliate_professional_id', $affiliateId)
                ->where('currency_code', $currencyCode)
                ->selectRaw('
                    COALESCE(SUM(orders_count), 0) AS orders_count,
                    COALESCE(SUM(gross_cents), 0) AS gross_cents,
                    COALESCE(SUM(net_cents), 0) AS net_cents,
                    COALESCE(SUM(commission_cents - reversed_commission_cents), 0) AS commission_net_cents
                ')
                ->first();

            // customers_count isn't in the rollup; count distinct customer_id
            // from non-excluded orders. Lifetime, same currency filter.
            $customersRow = DB::table('commerce.orders')
                ->where('brand_professional_id', $brandId)
                ->where('affiliate_professional_id', $affiliateId)
                ->where('currency_code', $currencyCode)
                ->whereNotIn('status', Order::EXCLUDED_FROM_AGGREGATES)
                ->selectRaw('COUNT(DISTINCT customer_id) AS customers_count')
                ->first();

            // Lifetime page views from raw site_visits — analytics.site_visits
            // is the source of truth post-Phase-3.
            $viewsRow = DB::table('analytics.site_visits')
                ->where('professional_id', $affiliateId)
                ->selectRaw('COUNT(*) AS visits_count, COUNT(DISTINCT visitor_id) AS unique_visitors')
                ->first();

            return [
                'currency_code' => $currencyCode,
                'totals' => [
                    'orders_count' => (int) ($rollupRow->orders_count ?? 0),
                    'gross_cents' => (int) ($rollupRow->gross_cents ?? 0),
                    'net_cents' => (int) ($rollupRow->net_cents ?? 0),
                    'commission_net_cents' => (int) ($rollupRow->commission_net_cents ?? 0),
                    'customers_count' => (int) ($customersRow->customers_count ?? 0),
                ],
                'visits_count' => (int) ($viewsRow->visits_count ?? 0),
                'unique_visitors' => (int) ($viewsRow->unique_visitors ?? 0),
            ];
        });

        $currencyCode = $aggregates['currency_code'];
        $totals = $aggregates['totals'];
        $visits = $aggregates['visits_count'];
        $uniqueVisitors = $aggregates['unique_visitors'];
        $conversionRate = $uniqueVisitors > 0
            ? round(($totals['orders_count'] / $uniqueVisitors) * 100, 2)
            : 0.0;

        // Commission state buckets — pending = unpaid + in-flight,
        // paid = completed, voided = cancelled-by-grace + failed. The
        // status pill in the modal reads these to surface "amount owed
        // / amount paid / amount lost" at a glance.
        $payouts = CommissionPayout::query()
            ->where('brand_professional_id', $brandId)
            ->where('affiliate_professional_id', $affiliateId)
            ->get();

        $bucketByStatus = $payouts->groupBy('status');
        $sumNet = fn (string $status): int => (int) ($bucketByStatus->get($status)?->sum('net_payout_cents') ?? 0);

        $commission = [
            'pending_cents' => $sumNet('pending') + $sumNet('processing'),
            'paid_cents' => $sumNet('completed'),
            'voided_cents' => $sumNet('cancelled') + $sumNet('failed'),
        ];

        // Last 5 payouts so the modal can show a recent-history strip
        // without paginating. Ordered by processed_at desc (falls back to
        // created_at when not yet processed).
        $recentPayouts = $payouts
            ->sortByDesc(fn (CommissionPayout $p) => optional($p->processed_at ?? $p->created_at)->getTimestamp() ?? 0)
            ->take(5)
            ->map(fn (CommissionPayout $p) => [
                'id' => $p->id,
                'status' => $p->status,
                'net_payout_cents' => (int) $p->net_payout_cents,
                'currency_code' => strtoupper((string) ($p->currency_code ?? $currencyCode)),
                'processed_at' => optional($p->processed_at)->toIso8601String(),
                'created_at' => optional($p->created_at)->toIso8601String(),
            ])
            ->values()
            ->all();

        $identityName = trim(implode(' ', array_filter([$affiliate->first_name, $affiliate->last_name])));

        return $this->success([
            'affiliate_id' => $affiliateId,
            'identity' => [
                'full_name' => $identityName !== '' ? $identityName : ($affiliate->display_name ?? $affiliate->handle ?? 'Unknown'),
                'display_name' => $affiliate->display_name,
                'handle' => $affiliate->handle,
                'professional_type' => $affiliate->professional_type,
                // account_type alongside legacy professional_type for Nightwatch continuity
                // and progressive frontend reads during the §28.1 dual-write window.
                'account_type' => $affiliate->account_type?->value,
            ],
            'totals' => $totals,
            'commission' => $commission,
            'page_views' => [
                'visits_count' => $visits,
                'unique_visitors' => $uniqueVisitors,
                'conversion_rate_percent' => $conversionRate,
            ],
            'recent_payouts' => $recentPayouts,
            'currency_code' => strtoupper((string) $currencyCode),
        ]);
    }

    public function updateCustomPhotos(Request $request, string $affiliateId): JsonResponse
    {
        // Role gating handled by `brand.only` middleware (EnsureBrandAccount).
        $professional = $this->currentProfessional($request);

        $request->validate([
            'enabled' => ['present', 'nullable', 'boolean'],
        ]);

        $link = BrandPartnerLink::query()
            ->where('brand_professional_id', $professional->id)
            ->where('affiliate_professional_id', $affiliateId)
            ->first();

        if (! $link) {
            return $this->error('Affiliate not found for this brand.', 404);
        }

        $link->custom_photos_enabled = $request->input('enabled');
        $link->save();

        return $this->success([
            'affiliate_id' => $affiliateId,
            'custom_photos_enabled' => $link->custom_photos_enabled,
        ]);
    }
}
