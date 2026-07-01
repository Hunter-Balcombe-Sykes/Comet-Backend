<?php

namespace App\Http\Controllers\Api\User\Account;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Controllers\Concerns\ResolveCurrentUser;
use App\Http\Requests\Api\User\UpdateUserRequest;
use App\Http\Requests\Api\User\UserShowRequest;
use App\Http\Resources\SiteResource;
use App\Http\Resources\UserDashboardResource;
use App\Services\Cache\SiteCacheService;
use App\Services\Cache\UserCacheService;
use App\Services\User\SyncUserAboutService;
use Illuminate\Support\Facades\DB;

// V2: Returns authenticated professional's full profile with site, services, and blocks. Dashboard entry point.
class UserSelfController extends ApiController
{
    use ResolveCurrentSite;
    use ResolveCurrentUser;

    public function __construct(
        private readonly SyncUserAboutService $aboutSync,
    ) {}

    public function show(UserShowRequest $request)
    {
        $uid = $request->attributes->get('supabase_uid');

        $pro = $this->currentUser($request);

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

        // Strip credentials/experience from the validated payload before fill()
        // so the legacy about JSON column never re-accumulates them. They are
        // written to child tables by SyncUserAboutService instead (FOUND-5).
        $about = $validated['about'] ?? null;
        if (is_array($about)) {
            unset($validated['about']['credentials'], $validated['about']['experience']);
            // If about is now empty after stripping, collapse to null so the column
            // is cleared rather than persisting an empty object.
            if ($validated['about'] === []) {
                $validated['about'] = null;
            }
        }

        DB::transaction(function () use ($professional, $validated, $about): void {
            $professional->fill($validated);
            $professional->save();

            // Sync credentials/experience from the original (pre-strip) about payload.
            if (is_array($about)) {
                $this->aboutSync->sync($professional, $about);
            }
        });

        return $this->success([
            'professional' => new UserDashboardResource($professional->fresh()),
        ]);
    }
}
