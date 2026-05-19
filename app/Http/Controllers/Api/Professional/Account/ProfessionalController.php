<?php

namespace App\Http\Controllers\Api\Professional\Account;

use App\Enums\BrandStatus;
use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Requests\Api\Professional\ProfessionalShowRequest;
use App\Http\Requests\Api\Professional\UpdateProfessionalRequest;
use App\Http\Resources\ProfessionalDashboardResource;
use App\Models\Core\Professional\BrandProfile;
use App\Models\Core\Site\Block;
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Cache\SiteCacheService;
use App\Services\Site\UpdateSiteAction;
use Illuminate\Support\Facades\DB;

// V2: Returns authenticated professional's full profile with site, services, and blocks. Dashboard entry point.
class ProfessionalController extends ApiController
{
    /** @return array<int, string> */
    private function professionalOnlySectionTypes(): array
    {
        return config('partna.professional_only_section_types', []);
    }

    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    public function show(ProfessionalShowRequest $request)
    {
        $uid = $request->attributes->get('supabase_uid');

        $pro = $this->currentProfessional($request);
        // squareIntegration eager-loads inside the AUTH-1 cached Professional
        // (60s SWR), so no extra round-trip is needed here on cache hits.

        $cache = app(ProfessionalCacheService::class);

        $siteSettings = [];
        $primaryBrandStatus = null;
        $primaryBrandName = null;
        if ($pro->site) {
            $siteSettings = is_array($pro->site->settings) ? $pro->site->settings : [];
        }

        // Resolve primary_brand_status so the dashboard can surface affiliate-
        // facing banners + gate brand-only actions (e.g. "+ Add invite"). For
        // brands this is THEIR OWN brand_profile.brand_status; for affiliates
        // it's their connected brand partner's status.
        if ($pro->isBrand()) {
            $brandProfile = BrandProfile::query()->where('professional_id', $pro->id)->first();
            $primaryBrandStatus = $brandProfile?->brand_status ?? BrandStatus::Onboarding->value;
            $primaryBrandName = $pro->display_name;
        } elseif (! empty($siteSettings)) {
            $brandPartnerId = $siteSettings['brand_partner']['professional_id'] ?? null;
            if ($brandPartnerId) {
                $partner = $cache->getBrandPartnerStatus((string) $brandPartnerId);
                $primaryBrandStatus = $partner['brand_status'] ?? BrandStatus::Onboarding->value;
                $primaryBrandName = $partner['display_name'] ?? null;
            }
        }

        $payload = [
            'professional' => new ProfessionalDashboardResource($pro),
            'site' => $pro->site ? [
                'id' => $pro->site->id,
                'subdomain' => $pro->site->subdomain,
                // ISO timestamp at which the next subdomain change is allowed (null = available now,
                // never been changed). Mirrors the cooldown enforced in UpdateSiteAction so the UI
                // can disable the field upfront instead of relying on a 422 round-trip.
                'subdomain_change_available_at' => $pro->site->subdomain_changed_at
                    ? $pro->site->subdomain_changed_at->copy()->addDays(UpdateSiteAction::SUBDOMAIN_COOLDOWN_DAYS)->toIso8601String()
                    : null,
                'is_published' => (bool) $pro->site->is_published,
                'settings' => $siteSettings,
            ] : null,
        ];

        $services = $cache->getActiveServices($pro->id);
        $customersCount = $cache->getCustomerCount($pro->id);
        $blocks = $pro->site
            ? app(SiteCacheService::class)->getSiteLinkBlocks($pro->site->id)
            : [];

        return $this->success([
            'uid' => $uid,
            ...$payload,
            'blocks' => $blocks,
            'services' => $services,
            'customers_count' => $customersCount,
            'primary_brand_status' => $primaryBrandStatus,
            'primary_brand_name' => $primaryBrandName,
        ]);
    }

    public function update(UpdateProfessionalRequest $request)
    {
        $professional = $this->currentProfessional($request);
        $previousProfessionalType = mb_strtolower(trim((string) ($professional->professional_type ?? '')));
        DB::transaction(function () use ($professional, $request, $previousProfessionalType): void {
            $professional->fill($request->validated());
            $professional->save();

            $nextProfessionalType = mb_strtolower(trim((string) ($professional->professional_type ?? '')));
            if ($previousProfessionalType !== 'influencer' && $nextProfessionalType === 'influencer') {
                $this->disableProfessionalOnlySections($professional->id);
            }
        });

        return $this->success([
            'professional' => new ProfessionalDashboardResource($professional->fresh()),
        ]);
    }

    private function disableProfessionalOnlySections(string $professionalId): void
    {
        if ($professionalId === '') {
            return;
        }

        Block::query()
            ->where('professional_id', $professionalId)
            ->where('block_group', 'sections')
            ->whereIn('block_type', $this->professionalOnlySectionTypes())
            ->where('is_active', true)
            ->update([
                'is_active' => false,
            ]);
    }
}
