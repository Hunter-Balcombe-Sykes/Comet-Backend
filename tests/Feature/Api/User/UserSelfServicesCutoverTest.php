<?php

use App\Models\Core\User\User;
use App\Services\Cache\UserCacheService;
use App\Services\Migration\ServiceBackfiller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// B2 (final whole-branch review): UserCacheService::getActiveServices() —
// which UserSelfController::show() (GET /api/me) serves as the dashboard
// bootstrap 'services' key — was never cut over to content.* and carried NO
// `source` filter at all, so it read straight off site.services. For an
// owner-authored (content.*) service that meant: a create never appeared, an
// edit kept serving pre-cutover values forever, and a delete kept showing as
// live. Fixed the same way UserServiceController::index()'s dashboard list
// and UserCacheService::getDashboardServices() were: merge content.* (via
// ManualServiceItems) with `site.services WHERE source IS NOT NULL`.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupServicesTable();
    setupBlocksTable();
    // store()/update() take the same pg_advisory_xact_lock as the
    // pre-cutover code — shim it under SQLite so the real path runs.
    shimPgAdvisoryLockForSqlite();
    Queue::fake();
});

it('a service created through the cutover endpoint appears in getActiveServices (the /me payload source)', function () {
    [$userId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    $id = actingAsUser($user)->postJson('/api/services', ['title' => 'New Service', 'price_cents' => 8000])
        ->assertCreated()->json('service.id');

    $services = app(UserCacheService::class)->getActiveServices($userId);

    expect(array_column($services, 'id'))->toContain($id);
    expect(array_column($services, 'title'))->toContain('New Service');
});

it('an edited owner-authored service serves the CURRENT value, not the pre-cutover one', function () {
    [$userId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    $id = actingAsUser($user)->postJson('/api/services', ['title' => 'Original', 'price_cents' => 8000])
        ->assertCreated()->json('service.id');

    // Warm the cache with the pre-edit value BEFORE the edit — proves the
    // write path actually busts the key (invalidateServices()), not just
    // that a cold read happens to be correct.
    app(UserCacheService::class)->getActiveServices($userId);

    actingAsUser($user)->patchJson("/api/services/{$id}", ['title' => 'Edited', 'price_cents' => 9000])->assertOk();

    $services = app(UserCacheService::class)->getActiveServices($userId);
    $service = collect($services)->firstWhere('id', $id);

    expect($service['title'])->toBe('Edited');
    expect($service['price_cents'])->toBe(9000);
});

it('a deleted owner-authored service no longer shows as live', function () {
    [$userId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    $id = actingAsUser($user)->postJson('/api/services', ['title' => 'Gone Soon', 'price_cents' => 1500])
        ->assertCreated()->json('service.id');

    app(UserCacheService::class)->getActiveServices($userId);

    actingAsUser($user)->deleteJson("/api/services/{$id}")->assertOk();

    $services = app(UserCacheService::class)->getActiveServices($userId);
    expect(array_column($services, 'id'))->not->toContain($id);
});

it('a backfilled legacy row deleted through the cutover endpoint does not linger as live via the untouched site.services row', function () {
    // The precise "deleted one still shows as live" scenario the finding
    // names: a PRE-cutover site.services row, backfilled into content.*
    // (ServiceBackfiller never deletes the legacy row). The old, uncut-over
    // getActiveServices() read site.services directly (is_active=true,
    // deleted_at=null — both still true on the stale legacy row) and would
    // show it as live forever, no matter what happened on the content.* side.
    [$userId] = seedUserWithSite();
    $legacyId = ownerService($userId, ['title' => 'Legacy Owner Service', 'price_cents' => 6500]);
    app(ServiceBackfiller::class)->run();

    $itemId = DB::table('content.source_items')->where('coord', 'manual:'.$legacyId)->value('item_id');
    expect($itemId)->not->toBeNull();

    $user = User::query()->with('site')->findOrFail($userId);
    actingAsUser($user)->deleteJson("/api/services/{$itemId}")->assertOk();

    $services = app(UserCacheService::class)->getActiveServices($userId);
    expect(array_column($services, 'id'))->not->toContain($legacyId);
    expect(array_column($services, 'id'))->not->toContain($itemId);
});

it('a hidden (is_active=false) owner-authored service is excluded, matching the public read', function () {
    [$userId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    $id = actingAsUser($user)->postJson('/api/services', ['title' => 'Hide Me', 'price_cents' => 3000])
        ->assertCreated()->json('service.id');

    actingAsUser($user)->patchJson("/api/services/{$id}", ['is_active' => false])->assertOk();

    $services = app(UserCacheService::class)->getActiveServices($userId);
    expect(array_column($services, 'id'))->not->toContain($id);
});

it('GET /api/me serves the owner-authored service under the services key', function () {
    setupPartnaStaffTable();
    setupCustomersTable();

    [$userId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    $id = actingAsUser($user)->postJson('/api/services', ['title' => 'Dashboard Service', 'price_cents' => 4200])
        ->assertCreated()->json('service.id');

    actingAsUser($user)->getJson('/api/me')->assertOk()
        ->assertJsonPath('services.0.id', $id)
        ->assertJsonPath('services.0.title', 'Dashboard Service');
});
