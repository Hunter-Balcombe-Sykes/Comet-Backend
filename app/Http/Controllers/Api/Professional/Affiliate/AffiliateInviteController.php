<?php

namespace App\Http\Controllers\Api\Professional\Affiliate;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Core\Professional\BrandAffiliateInvite;
use App\Services\Media\BrandDesignMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// V2: Non-brand professionals view their pending affiliate invitations from brands.
class AffiliateInviteController extends ApiController
{
    use ResolveCurrentProfessional;

    public function index(Request $request, BrandDesignMediaService $mediaService): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        if ($professional->isBrand()) {
            return $this->error('Brand accounts cannot view affiliate invites.', 403);
        }

        $emails = array_filter([
            mb_strtolower(trim((string) ($professional->primary_email ?? ''))),
            mb_strtolower(trim((string) ($professional->public_contact_email ?? ''))),
        ], fn (string $email): bool => $email !== '');

        if ($emails === []) {
            return $this->success(['invites' => []]);
        }

        $inviteRows = BrandAffiliateInvite::query()
            ->with('brandProfessional.site')
            ->whereIn('email_lc', array_unique($emails))
            ->where('status', 'pending')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('created_at')
            ->get();

        // Bulk-resolve full logo URLs from site_media (purpose=logo_full).
        $brandSiteIds = $inviteRows
            ->map(fn (BrandAffiliateInvite $invite): ?string => $invite->brandProfessional?->site?->id ? (string) $invite->brandProfessional->site->id : null)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $logoUrls = $mediaService->getLogoFullUrls($brandSiteIds);

        $invites = $inviteRows
            ->map(function (BrandAffiliateInvite $invite) use ($logoUrls): array {
                $brand = $invite->brandProfessional;
                $siteSettings = is_array($brand?->site?->settings ?? null) ? $brand->site->settings : [];
                $designSettings = is_array($siteSettings['design'] ?? null) ? $siteSettings['design'] : [];
                $brandSiteId = $brand?->site?->id ? (string) $brand->site->id : null;

                return [
                    'id' => $invite->id,
                    'token' => $invite->token,
                    'status' => $invite->status,
                    'message' => $invite->message,
                    'expires_at' => optional($invite->expires_at)->toIso8601String(),
                    'created_at' => optional($invite->created_at)->toIso8601String(),
                    'brand' => [
                        'id' => $brand?->id,
                        'display_name' => $brand?->display_name,
                        'handle' => $brand?->handle,
                        'brand_logo_url' => $brandSiteId ? ($logoUrls[$brandSiteId] ?? null) : null,
                        'brand_color' => is_string($designSettings['dark_color'] ?? $designSettings['darkColor'] ?? null)
                            ? ($designSettings['dark_color'] ?? $designSettings['darkColor'])
                            : null,
                    ],
                ];
            })
            ->values()
            ->all();

        return $this->success(['invites' => $invites]);
    }
}
