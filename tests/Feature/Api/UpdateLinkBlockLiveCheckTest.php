<?php

/** @phpstan-ignore-all */

use App\Http\Requests\Api\User\Site\UpdateLinkBlockRequest;
use Illuminate\Support\Facades\Validator;

it('accepts live_check_enabled=true in settings for a streaming platform context', function () {
    config(['partna.streaming_platforms' => ['twitch', 'kick']]);
    config(['partna.link_block_settings_keys' => [
        'platform', 'handle', 'category', 'highlight', 'note',
        'open_in_new_tab', 'rel_nofollow', 'rel_sponsored', 'rel_ugc',
        'live_check_enabled',
    ]]);

    $request = new UpdateLinkBlockRequest;
    // Supply a valid UUID for 'id' — prepareForValidation is not called when
    // using Validator::make directly, so id must be provided explicitly.
    $validator = Validator::make(
        ['id' => (string) \Illuminate\Support\Str::uuid(), 'settings' => ['live_check_enabled' => true]],
        $request->rules()
    );

    expect($validator->fails())->toBeFalse();
});

it('rejects live_check_enabled as a non-boolean', function () {
    config(['partna.link_block_settings_keys' => [
        'platform', 'handle', 'category', 'highlight', 'note',
        'open_in_new_tab', 'rel_nofollow', 'rel_sponsored', 'rel_ugc',
        'live_check_enabled',
    ]]);

    $request = new UpdateLinkBlockRequest;
    // Supply a valid UUID for 'id' — prepareForValidation is not called when
    // using Validator::make directly, so id must be provided explicitly.
    $validator = Validator::make(
        ['id' => (string) \Illuminate\Support\Str::uuid(), 'settings' => ['live_check_enabled' => 'yes']],
        $request->rules()
    );

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->has('settings.live_check_enabled'))->toBeTrue();
});

it('rejects is_live in settings — it is read-only and not in the allowlist', function () {
    config(['partna.link_block_settings_keys' => [
        'platform', 'handle', 'category', 'highlight', 'note',
        'open_in_new_tab', 'rel_nofollow', 'rel_sponsored', 'rel_ugc',
        'live_check_enabled',
    ]]);

    // is_live is NOT in link_block_settings_keys — the withValidator allowlist check rejects it.
    // Use create() so the request carries the data; withValidator reads $this->input()
    // which only works when the request itself holds the payload.
    $blockId = (string) \Illuminate\Support\Str::uuid();
    $request = UpdateLinkBlockRequest::create('/test', 'PATCH', [
        'settings' => ['is_live' => true],
    ]);
    $request->setRouteResolver(function () use ($blockId) {
        $route = new \Illuminate\Routing\Route(['PATCH'], '/test', []);
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
            'platform', 'handle', 'category', 'highlight', 'note',
            'open_in_new_tab', 'rel_nofollow', 'rel_sponsored', 'rel_ugc',
            'live_check_enabled',
        ],
    ]);

    // createTenant sets up professionals + sites; blocks table must be
    // created separately before any site.blocks inserts.
    setupBlocksTable();

    $professional = createTenant('cap-test-pro');
    $site = $professional->site;

    // Seed 2 existing blocks that already have live_check_enabled=true.
    // Storing 'live_check_enabled' as the JSON string 'true' here is a
    // SQLite-only test fixture quirk: Postgres `->>` returns text "true" for a
    // JSON boolean (so production works fine), but SQLite's `->>` returns
    // integer 1, causing = 'true' to miss. Production stores a real JSON
    // boolean (Laravel's `boolean` rule + array cast) — only this fixture
    // diverges, to make the same query work across both dialects in tests.
    foreach (['a', 'b'] as $suffix) {
        \Illuminate\Support\Facades\DB::connection('pgsql')->table('site.blocks')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'site_id' => $site->id,
            'block_group' => 'links',
            'block_type' => 'link',
            'settings' => json_encode(['live_check_enabled' => 'true', 'platform' => 'twitch', 'handle' => "handle-{$suffix}"]),
            'sort_order' => 0,
            'is_active' => 1,
            'is_enabled' => 1,
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);
    }

    // A third block being updated to enable live_check should be rejected
    $newBlockId = (string) \Illuminate\Support\Str::uuid();
    \Illuminate\Support\Facades\DB::connection('pgsql')->table('site.blocks')->insert([
        'id' => $newBlockId,
        'site_id' => $site->id,
        'block_group' => 'links',
        'block_type' => 'link',
        'settings' => json_encode(['live_check_enabled' => false]),
        'sort_order' => 0,
        'is_active' => 1,
        'is_enabled' => 1,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $block = \App\Models\Core\Site\Block::query()->find($newBlockId);

    $request = UpdateLinkBlockRequest::create('/test', 'PATCH', [
        'settings' => ['live_check_enabled' => true],
    ]);
    $request->setRouteResolver(function () use ($block) {
        $route = new \Illuminate\Routing\Route(['PATCH'], '/test', []);
        // Assign parameters directly — setParameter() requires the route to be
        // "bound" (dispatched through the router), which doesn't happen in unit
        // tests. Setting the public property bypasses that guard.
        $route->parameters = ['linkBlock' => $block];

        return $route;
    });

    $validator = Validator::make($request->all(), $request->rules());
    $request->withValidator($validator);
    $validator->passes(); // triggers 'after' callbacks

    expect($validator->errors()->has('settings.live_check_enabled'))->toBeTrue();
});
