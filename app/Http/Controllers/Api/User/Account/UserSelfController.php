<?php

namespace App\Http\Controllers\Api\User\Account;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\UpdateUserRequest;
use App\Http\Requests\Api\User\UserShowRequest;
use App\Http\Resources\SiteResource;
use App\Http\Resources\UserDashboardResource;
use App\Models\Core\Staff\PartnaStaff;
use App\Services\Cache\SiteCacheService;
use App\Services\Cache\UserCacheService;
use App\Services\Site\SitePolicyResolver;
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

        // Resolve the staff link fresh and hang it on the (cached) model so
        // UserDashboardResource can expose is_staff without a lazy load (which
        // is prevented outside production) and without baking staff-ness into
        // the 60s user cache — staff promotion/demotion must reflect on the
        // next /me, not a cache-window later.
        $pro->setRelation('partnaStaff', PartnaStaff::query()
            ->where('auth_user_id', $pro->auth_user_id)
            ->first());

        $cache = app(UserCacheService::class);

        $payload = [
            'professional' => new UserDashboardResource($pro),
            // B18/API-3: use SiteResource (superset of the old hand-rolled array) merged with
            // the computed cooldown key. All previously-present keys remain; new ones (user_id,
            // subdomain_changed_at, unpublished_at, created_at, updated_at) are additive only.
            'site' => $pro->site ? array_merge(
                (new SiteResource($pro->site))->resolve($request),
                [
                    // ISO timestamp at which the next subdomain change is allowed (null = available
                    // now / never changed). Mirrors the cooldown enforced in UpdateSiteAction so
                    // the UI can disable the field upfront instead of relying on a 422 round-trip.
                    'subdomain_change_available_at' => $pro->site->subdomain_changed_at
                        ? $pro->site->subdomain_changed_at->copy()->addDays((int) config('partna.handle.subdomain_cooldown_days', 30))->toIso8601String()
                        : null,
                    // Resolved auto-generated policy texts (Privacy / Terms) —
                    // the dashboard's read-only "Automated policy" preview.
                    // Personalized with the stored workplace name (business
                    // accounts); the public payload's own resolution applies
                    // the same templates at render time.
                    'policy_auto_texts' => app(SitePolicyResolver::class)->autoTexts(
                        $pro,
                        $pro->site,
                        is_string(data_get($pro->site->settings, 'workplace.name'))
                            ? data_get($pro->site->settings, 'workplace.name')
                            : null,
                    ),
                ],
            ) : null,
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

        $validated = $request->validated();

        DB::transaction(function () use ($professional, $validated): void {
            $professional->fill($validated);
            $professional->save();
        });

        return $this->success([
            'professional' => new UserDashboardResource($professional->fresh()),
        ]);
    }
}
