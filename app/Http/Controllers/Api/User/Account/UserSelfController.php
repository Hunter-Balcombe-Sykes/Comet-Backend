<?php

namespace App\Http\Controllers\Api\User\Account;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\UpdateUserRequest;
use App\Http\Requests\Api\User\UserShowRequest;
use App\Http\Resources\UserDashboardResource;
use App\Services\Cache\SiteCacheService;
use App\Services\Cache\UserCacheService;
use Illuminate\Support\Facades\DB;

// V2: Returns authenticated professional's full profile with site, services, and blocks. Dashboard entry point.
class UserSelfController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function show(UserShowRequest $request)
    {
        $uid = $request->attributes->get('supabase_uid');

        $pro = $this->currentUser($request);

        $cache = app(UserCacheService::class);

        $siteSettings = [];
        if ($pro->site) {
            $siteSettings = is_array($pro->site->settings) ? $pro->site->settings : [];
        }

        $payload = [
            'professional' => new UserDashboardResource($pro),
            'site' => $pro->site ? [
                'id' => $pro->site->id,
                'subdomain' => $pro->site->subdomain,
                // ISO timestamp at which the next subdomain change is allowed (null = available now,
                // never been changed). Mirrors the cooldown enforced in UpdateSiteAction so the UI
                // can disable the field upfront instead of relying on a 422 round-trip.
                'subdomain_change_available_at' => $pro->site->subdomain_changed_at
                    ? $pro->site->subdomain_changed_at->copy()->addDays((int) config('partna.handle.subdomain_cooldown_days', 30))->toIso8601String()
                    : null,
                'is_published' => (bool) $pro->site->is_published,
                // skeleton_id is a TEXT enum on site.sites (replaces the
                // old theme model). The dashboard's design editor reads
                // this to highlight the active skeleton; without it,
                // map-snapshot-to-account falls through to null and the
                // picker defaults to skeleton-1 on every render.
                'skeleton_id' => $pro->site->skeleton_id,
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

    public function update(UpdateUserRequest $request)
    {
        $professional = $this->currentUser($request);
        $this->authorizeForUser($professional, 'update', $professional);
        DB::transaction(function () use ($professional, $request): void {
            $professional->fill($request->validated());
            $professional->save();
        });

        return $this->success([
            'professional' => new UserDashboardResource($professional->fresh()),
        ]);
    }
}
