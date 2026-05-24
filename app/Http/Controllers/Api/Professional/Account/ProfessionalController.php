<?php

namespace App\Http\Controllers\Api\Professional\Account;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Requests\Api\Professional\ProfessionalShowRequest;
use App\Http\Requests\Api\Professional\UpdateProfessionalRequest;
use App\Http\Resources\ProfessionalDashboardResource;
use App\Services\Cache\ProfessionalCacheService;
use App\Services\Cache\SiteCacheService;
use App\Services\Site\UpdateSiteAction;
use Illuminate\Support\Facades\DB;

// V2: Returns authenticated professional's full profile with site, services, and blocks. Dashboard entry point.
class ProfessionalController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    public function show(ProfessionalShowRequest $request)
    {
        $uid = $request->attributes->get('supabase_uid');

        $pro = $this->currentProfessional($request);

        $cache = app(ProfessionalCacheService::class);

        $siteSettings = [];
        if ($pro->site) {
            $siteSettings = is_array($pro->site->settings) ? $pro->site->settings : [];
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
        ]);
    }

    public function update(UpdateProfessionalRequest $request)
    {
        $professional = $this->currentProfessional($request);
        $this->authorizeForUser($professional, 'update', $professional);
        DB::transaction(function () use ($professional, $request): void {
            $professional->fill($request->validated());
            $professional->save();
        });

        return $this->success([
            'professional' => new ProfessionalDashboardResource($professional->fresh()),
        ]);
    }

}
