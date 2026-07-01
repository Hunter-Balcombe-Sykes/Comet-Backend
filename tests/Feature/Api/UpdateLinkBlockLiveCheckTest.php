<?php

/** @phpstan-ignore-all */

use App\Http\Requests\Api\User\Site\UpdateLinkBlockRequest;
use App\Models\Core\Site\Block;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

// Phase 2: live_check_enabled is a top-level field (promoted column), no longer
// nested under settings. The request rules and cap-trigger both read the top-level key.

it('accepts live_check_enabled=true as a top-level field (Phase 2)', function () {
    config(['partna.streaming_platforms' => ['twitch', 'kick']]);
    config(['partna.link_block_settings_keys' => [
        'handle', 'highlight', 'note',
        'open_in_new_tab', 'rel_nofollow', 'rel_sponsored', 'rel_ugc',
    ]]);

    $request = new UpdateLinkBlockRequest;
    // Supply a valid UUID for 'id' — prepareForValidation is not called when
    // using Validator::make directly, so id must be provided explicitly.
    $validator = Validator::make(
        ['id' => (string) Str::uuid(), 'live_check_enabled' => true],
        $request->rules()
    );

    expect($validator->fails())->toBeFalse();
});

it('rejects live_check_enabled as a non-boolean', function () {
    config(['partna.link_block_settings_keys' => [
        'handle', 'highlight', 'note',
        'open_in_new_tab', 'rel_nofollow', 'rel_sponsored', 'rel_ugc',
    ]]);

    $request = new UpdateLinkBlockRequest;
    $validator = Validator::make(
        ['id' => (string) Str::uuid(), 'live_check_enabled' => 'yes'],
        $request->rules()
    );

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('live_check_enabled'))->toBeTrue();
});

it('rejects is_live in settings — it is read-only and not in the allowlist', function () {
    config(['partna.link_block_settings_keys' => [
        'handle', 'highlight', 'note',
        'open_in_new_tab', 'rel_nofollow', 'rel_sponsored', 'rel_ugc',
    ]]);

    // is_live is NOT in link_block_settings_keys — the withValidator allowlist check rejects it.
    // Use create() so the request carries the data; withValidator reads $this->input()
    // which only works when the request itself holds the payload.
    $blockId = (string) Str::uuid();
    $request = UpdateLinkBlockRequest::create('/test', 'PATCH', [
        'settings' => ['is_live' => true],
    ]);
    $request->setRouteResolver(function () use ($blockId) {
        $route = new Route(['PATCH'], '/test', []);
        $route->setParameter('linkBlock', $blockId);

        return $route;
    });

    // prepareForValidation merges 'id' from the route, so rules() passes the uuid check.
    // withValidator's 'after' callback fires when fails()/passes() is called.
    $validator = Validator::make($request->all(), $request->rules());
    $request->withValidator($validator);
    $validator->fails(); // triggers 'after' callbacks

    // The settings allowlist in withValidator adds an error for unknown keys
    expect($validator->errors()->has('settings'))->toBeTrue();
});

it('rejects live_check_enabled=true when site already has max_live_check_per_site blocks enabled', function () {
    config([
        'partna.streaming.max_live_check_per_site' => 2,
        'partna.link_block_settings_keys' => [
            'handle', 'highlight', 'note',
            'open_in_new_tab', 'rel_nofollow', 'rel_sponsored', 'rel_ugc',
        ],
    ]);

    // createTenant sets up professionals + sites; blocks table must be
    // created separately before any site.blocks inserts.
    setupBlocksTable();

    $professional = createTenant('cap-test-pro');
    $site = $professional->site;

    // Seed 2 existing blocks that already have live_check_enabled=true (column).
    foreach (['a', 'b'] as $suffix) {
        DB::connection('pgsql')->table('site.blocks')->insert([
            'id' => (string) Str::uuid(),
            'site_id' => $site->id,
            'block_group' => 'links',
            'block_type' => 'link',
            'live_check_enabled' => 1,
            'platform' => 'twitch',
            'settings' => json_encode(['handle' => "handle-{$suffix}"]),
            'sort_order' => 0,
            'is_active' => 1,
            'is_enabled' => 1,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    // A third block being updated to enable live_check should be rejected
    $newBlockId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => $newBlockId,
        'site_id' => $site->id,
        'block_group' => 'links',
        'block_type' => 'link',
        'live_check_enabled' => 0,
        'settings' => json_encode([]),
        'sort_order' => 0,
        'is_active' => 1,
        'is_enabled' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $block = Block::query()->find($newBlockId);

    // Phase 2: live_check_enabled is top-level, not nested under settings.
    $request = UpdateLinkBlockRequest::create('/test', 'PATCH', [
        'live_check_enabled' => true,
    ]);
    $request->setRouteResolver(function () use ($block) {
        $route = new Route(['PATCH'], '/test', []);
        // Assign parameters directly — setParameter() requires the route to be
        // "bound" (dispatched through the router), which doesn't happen in unit
        // tests. Setting the public property bypasses that guard.
        $route->parameters = ['linkBlock' => $block];

        return $route;
    });

    $validator = Validator::make($request->all(), $request->rules());
    $request->withValidator($validator);
    $validator->passes(); // triggers 'after' callbacks

    // Error key is now top-level live_check_enabled (not settings.live_check_enabled).
    expect($validator->errors()->has('live_check_enabled'))->toBeTrue();
});
