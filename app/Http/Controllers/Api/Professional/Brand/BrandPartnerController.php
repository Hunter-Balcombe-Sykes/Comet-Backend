<?php

namespace App\Http\Controllers\Api\Professional\Brand;

use App\Enums\BrandStatus;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\NormalizesPerPage;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ReturnsPaginatedResponse;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Media\BrandDesignMediaService;
use App\Services\Professional\Brand\BrandPartnerLinkLifecycleService;
use App\Services\Professional\Brand\BrandPartnerLinkService;
use App\Services\Professional\Brand\BrandPartnerSiteSettingsSync;
use App\Services\Professional\DTO\DisconnectRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

// V2: Affiliate connects to/disconnects from brand partners. Simplified to single-brand model in V2.
class BrandPartnerController extends ApiController
{
    use NormalizesPerPage;
    use ResolveCurrentProfessional;
    use ReturnsPaginatedResponse;

    public function connect(
        Request $request,
        string $brandProfessionalId,
        BrandPartnerLinkService $brandPartnerLinks,
        BrandPartnerSiteSettingsSync $sync,
    ): JsonResponse {
        $professional = $this->currentProfessional($request);

        if ($professional->isBrand()) {
            return $this->error('Brand accounts cannot manage brand partner connections.', 403);
        }

        $site = Site::query()->where('professional_id', $professional->id)->first();
        if (! $site) {
            return $this->error('Site not found.', 404);
        }

        // Dual-read: matches brands whether account_type is set or still in the
        // §28.1 dual-write window where account_type may be null but professional_type='brand'.
        $brand = Professional::query()
            ->whereKey($brandProfessionalId)
            ->where(function ($q): void {
                $q->where('account_type', 'brand')
                    ->orWhere(function ($q2): void {
                        $q2->whereNull('account_type')->where('professional_type', 'brand');
                    });
            })
            ->where('status', 'active')
            ->first();

        if (! $brand) {
            return $this->error('Brand partner not found.', 404);
        }

        $brandProfile = $brand->brandProfile;

        $brandStatus = $brandProfile?->brand_status ?? BrandStatus::SystemsDown->value;
        if ($brandStatus === BrandStatus::SystemsDown->value) {
            return $this->error('This brand is temporarily unavailable due to a platform issue.', 403);
        }

        try {
            $link = $brandPartnerLinks->connectBrandToAffiliate((string) $professional->id, (string) $brandProfessionalId);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        $sync->sync($site, (string) $professional->id);
        $sync->invalidateAffiliateCaches($site);

        return $this->success([
            'connected' => true,
            'brand_professional_id' => $brandProfessionalId,
            'slot' => (int) $link->slot,
        ]);
    }

    public function index(
        Request $request,
        BrandPartnerLinkService $brandPartnerLinks,
        BrandDesignMediaService $mediaService,
    ): JsonResponse {
        $professional = $this->currentProfessional($request);
        $perPage = $this->normalizePerPage($request, 25, 100);

        // Dual-read for brands in the §28.1 dual-write window (account_type may still be null).
        $page = Professional::query()
            ->where(function ($q): void {
                $q->where('account_type', 'brand')
                    ->orWhere(function ($q2): void {
                        $q2->whereNull('account_type')->where('professional_type', 'brand');
                    });
            })
            ->where('status', 'active')
            ->whereHas('brandProfile', fn ($q) => $q->where('affiliate_visibility', 'public')->where('brand_status', BrandStatus::ReadyForAffiliates->value))
            ->with('site')
            ->orderByRaw('COALESCE(display_name, handle) asc')
            ->paginate($perPage)
            ->appends($request->query());

        $pageSiteIds = $page->getCollection()->pluck('site.id')->filter()->map(fn ($id): string => (string) $id)->all();
        $logoUrls = $mediaService->getLogoFullUrls($pageSiteIds);

        $brands = $page->getCollection()
            ->map(fn (Professional $p): array => $this->brandToArray($p, $logoUrls[(string) $p->site?->id] ?? null))
            ->keyBy('id');

        // Always include brands the caller is already connected to, regardless of visibility,
        // so the frontend can display their logo and name on the settings card.
        $connectedIds = $brandPartnerLinks->getLinksForAffiliate((string) $professional->id)
            ->pluck('brand_professional_id')
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->diff($brands->keys())
            ->values();

        if ($connectedIds->isNotEmpty()) {
            $extraBrands = Professional::query()
                ->whereIn('id', $connectedIds->all())
                ->where(function ($q): void {
                    $q->where('account_type', 'brand')
                        ->orWhere(function ($q2): void {
                            $q2->whereNull('account_type')->where('professional_type', 'brand');
                        });
                })
                ->with('site')
                ->get();

            $extraLogoUrls = $mediaService->getLogoFullUrls(
                $extraBrands->pluck('site.id')->filter()->map(fn ($id): string => (string) $id)->all(),
            );

            $extraBrands->each(function (Professional $p) use ($brands, $extraLogoUrls): void {
                $brands->put((string) $p->id, $this->brandToArray($p, $extraLogoUrls[(string) $p->site?->id] ?? null));
            });
        }

        $payload = $this->paginatedResponse($page, 'brands');
        $payload['brands'] = $brands->values()->all();

        return $this->success($payload);
    }

    public function disconnect(
        Request $request,
        string $brandProfessionalId,
        BrandPartnerLinkLifecycleService $lifecycle,
    ): JsonResponse {
        $professional = $this->currentProfessional($request);

        if ($professional->isBrand()) {
            return $this->error('Brand accounts cannot manage brand partner connections.', 403);
        }

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $brand = Professional::query()->whereKey($brandProfessionalId)->first();
        if (! $brand) {
            return $this->error('Brand partner not found.', 404);
        }

        $result = $lifecycle->disconnect(DisconnectRequest::forAffiliate(
            brand: $brand,
            affiliate: $professional,
            reason: $data['reason'] ?? null,
        ));

        if (! $result->disconnected) {
            return $this->error('Brand partner not found in your connections.', 404);
        }

        $response = [
            'disconnected' => true,
            'brand_professional_id' => $brandProfessionalId,
            'selections_removed' => $result->selectionsRemoved,
        ];

        if ($result->staleSettingsCleaned) {
            $response['stale_settings_cleaned'] = true;
        }

        return $this->success($response);
    }

    private function brandToArray(Professional $professional, ?string $logoFullUrl): array
    {
        $siteSettings = is_array($professional->site?->settings ?? null) ? $professional->site->settings : [];
        $designSettings = is_array($siteSettings['design'] ?? null) ? $siteSettings['design'] : [];

        return [
            'id' => $professional->id,
            'display_name' => $professional->display_name,
            'handle' => $professional->handle,
            'subdomain' => $professional->site?->subdomain,
            // Resolved from site_media (purpose=logo_full) — same source the brand uploads to via
            // the Design page. The legacy site.settings.design.media.brand_logo_url JSON path is
            // not written by the modern uploader, so reading it produced null for every brand.
            'brand_logo_url' => $logoFullUrl,
            'brand_color' => is_string($designSettings['dark_color'] ?? $designSettings['darkColor'] ?? null)
                ? ($designSettings['dark_color'] ?? $designSettings['darkColor'])
                : null,
            'brand_contrast_color' => is_string($designSettings['white_color'] ?? $designSettings['whiteColor'] ?? null)
                ? ($designSettings['white_color'] ?? $designSettings['whiteColor'])
                : null,
        ];
    }
}
