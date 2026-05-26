<?php

/**
 * Platform-link cap enforcement on the StoreLinkBlockRequest pipeline.
 *
 * The cap (config('partna.platform_links_max'), default 7) is the API-side
 * defence-in-depth on top of the dashboard's disabled-Add-button. It must
 * fire on BOTH the professional self-management endpoint and the staff
 * management endpoint:
 *
 *   - Self path  → resolved via $request->attributes->get('professional')
 *                  (set by Context\LoadCurrentUser middleware).
 *   - Staff path → resolved via $request->route('professional')
 *                  (route-bound target professional whose site is being
 *                  edited; the staff member's own pro lives on the
 *                  attribute and is NOT the right row to count against).
 *
 * StaffStoreLinkRequest extends StoreLinkBlockRequest, so the cap logic
 * runs in shared code — both subclasses must resolve the right professional.
 */

use App\Http\Requests\Api\User\Site\StoreLinkBlockRequest;
use App\Http\Requests\Api\Staff\UserSite\Links\StaffStoreLinkRequest;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Validator;

/**
 * Seed `count` link blocks for the given professional in a capped category.
 * Mirrors the production schema enough to satisfy the cap query
 * (`whereIn('settings->category', $cappedCategories)`).
 */
function seedCappedLinks(string $userId, string $siteId, int $count, string $category = 'social'): void
{
    $now = now()->toDateTimeString();
    for ($i = 0; $i < $count; $i++) {
        DB::connection('pgsql')->table('site.blocks')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'site_id' => $siteId,
            'block_group' => 'links',
            'block_type' => 'link',
            'settings' => json_encode(['category' => $category, 'platform' => 'instagram', 'handle' => "h{$i}"]),
            'sort_order' => $i,
            'is_active' => 1,
            'is_enabled' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

/**
 * Run withValidator's after-callbacks against the given FormRequest.
 * Returns the resulting error bag.
 */
function runStoreLinkValidator(StoreLinkBlockRequest $request): \Illuminate\Support\MessageBag
{
    /** @var Validator $validator */
    $validator = \Illuminate\Support\Facades\Validator::make($request->all(), $request->rules());
    $request->withValidator($validator);
    $validator->fails(); // triggers `after` callbacks

    return $validator->errors();
}

it('rejects an 8th capped-category link on the professional self path when the cap is 7', function () {
    config(['partna.platform_links_max' => 7]);
    config(['partna.platform_links_categories' => ['social', 'content', 'events', 'streaming']]);

    setupBlocksTable();
    $pro = createTenant('cap-self-pro');
    seedCappedLinks($pro->id, $pro->site->id, 7);

    $request = StoreLinkBlockRequest::create('/test', 'POST', [
        'platform' => 'instagram',
        'handle' => 'overthecap',
        'category' => 'social',
    ]);
    // Self-path resolution: middleware would put the pro on the request
    // attributes. Simulate that here.
    $request->attributes->set('professional', $pro);

    $errors = runStoreLinkValidator($request);

    expect($errors->has('category'))->toBeTrue();
    expect($errors->first('category'))->toContain('limit of 7 platform links');
});

it('allows the 7th capped-category link on the professional self path (boundary)', function () {
    config(['partna.platform_links_max' => 7]);
    config(['partna.platform_links_categories' => ['social', 'content', 'events', 'streaming']]);

    setupBlocksTable();
    $pro = createTenant('cap-self-boundary-pro');
    seedCappedLinks($pro->id, $pro->site->id, 6);

    $request = StoreLinkBlockRequest::create('/test', 'POST', [
        'platform' => 'instagram',
        'handle' => 'underthecap',
        'category' => 'social',
    ]);
    $request->attributes->set('professional', $pro);

    $errors = runStoreLinkValidator($request);

    expect($errors->has('category'))->toBeFalse();
});

it('rejects an 8th capped-category link on the staff path against the route-bound target professional', function () {
    config(['partna.platform_links_max' => 7]);
    config(['partna.platform_links_categories' => ['social', 'content', 'events', 'streaming']]);

    setupBlocksTable();
    $staffPro = createTenant('cap-staff-actor');
    $targetPro = createTenant('cap-staff-target');

    // Cap is enforced against the TARGET pro, not the staff actor.
    seedCappedLinks($targetPro->id, $targetPro->site->id, 7);

    $request = StaffStoreLinkRequest::create('/test', 'POST', [
        'platform' => 'instagram',
        'handle' => 'overthecap',
        'category' => 'social',
    ]);
    // Staff context: the actor is on the attribute, the target is on the route.
    $request->attributes->set('professional', $staffPro);
    $request->setRouteResolver(function () use ($targetPro) {
        $route = new Route(['POST'], '/test', []);
        $route->parameters = ['professional' => $targetPro];

        return $route;
    });

    $errors = runStoreLinkValidator($request);

    expect($errors->has('category'))->toBeTrue();
    expect($errors->first('category'))->toContain('limit of 7 platform links');
});

it('does NOT count the staff actor\'s blocks against the target on the staff path', function () {
    config(['partna.platform_links_max' => 7]);
    config(['partna.platform_links_categories' => ['social', 'content', 'events', 'streaming']]);

    setupBlocksTable();
    $staffPro = createTenant('cap-staff-actor-2');
    $targetPro = createTenant('cap-staff-target-2');

    // Staff member is over the cap on their OWN site, but the target is empty.
    // The cap must scope to the target — staff submission should be allowed.
    seedCappedLinks($staffPro->id, $staffPro->site->id, 7);

    $request = StaffStoreLinkRequest::create('/test', 'POST', [
        'platform' => 'instagram',
        'handle' => 'allowed',
        'category' => 'social',
    ]);
    $request->attributes->set('professional', $staffPro);
    $request->setRouteResolver(function () use ($targetPro) {
        $route = new Route(['POST'], '/test', []);
        $route->parameters = ['professional' => $targetPro];

        return $route;
    });

    $errors = runStoreLinkValidator($request);

    expect($errors->has('category'))->toBeFalse();
});
