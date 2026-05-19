<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Enums\BrandStatus;
use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use App\Services\Media\BrandDesignMediaService;
use Illuminate\Http\JsonResponse;

class PublicOpenInviteController extends ApiController
{
    public function show(string $handle, BrandDesignMediaService $mediaService): JsonResponse
    {
        $handle = strtolower(trim($handle));
        if ($handle === '') {
            return $this->error('Brand not found.', 404);
        }

        // Dual-read: match brands during the §28.1 window where account_type may still be null.
        // Returns 404 (not 403) on miss — public endpoint; revealing existence enables enumeration.
        $brand = Professional::query()
            ->where('handle_lc', $handle)
            ->where(function ($q): void {
                $q->where('account_type', 'brand')
                    ->orWhere(function ($q2): void {
                        $q2->whereNull('account_type')->where('professional_type', 'brand');
                    });
            })
            ->where('status', 'active')
            ->with('brandProfile')
            ->first();

        if (! $brand) {
            return $this->error('Brand not found.', 404);
        }

        $brandStatus = $brand->brandProfile?->brand_status ?? BrandStatus::SystemsDown->value;
        if ($brandStatus === BrandStatus::SystemsDown->value) {
            return $this->error('Brand not found.', 404);
        }

        $brandSite = Site::query()
            ->where('professional_id', $brand->id)
            ->first();
        $siteSettings = is_array($brandSite?->settings ?? null) ? $brandSite->settings : [];
        $designSettings = is_array($siteSettings['design'] ?? null) ? $siteSettings['design'] : [];

        return $this->success([
            'brand' => [
                'professional_id' => $brand->id,
                'handle' => $brand->handle,
                'display_name' => $brand->display_name,
                // Resolved from site_media (purpose=logo_full) — same source the brand uploads to.
                'brand_logo_url' => $brandSite ? $mediaService->getLogoFullUrl((string) $brandSite->id) : null,
                'brand_color' => is_string($designSettings['dark_color'] ?? $designSettings['darkColor'] ?? null)
                    ? ($designSettings['dark_color'] ?? $designSettings['darkColor'])
                    : null,
            ],
        ]);
    }
}
