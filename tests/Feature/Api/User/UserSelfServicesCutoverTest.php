<?php

use App\Models\Core\User\User;
use App\Services\Cache\UserCacheService;
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

it('a service deleted through the endpoint does not linger as live in the cached active list', function () {
    // Was: "a backfilled legacy row deleted through the cutover endpoint does
    // not linger as live via the untouched site.services row". That named a
    // PRE-cutover shape — a site.services row backfilled into content.*, which
    // the old getActiveServices() read directly (is_active=true,
    // deleted_at=null on the stale legacy row) and would show as live forever.
    // Both the table and ServiceBackfiller are gone, so the stale-legacy-row
    // half cannot occur. What still matters, and is what this asserts, is the
    // propagation itself: a delete through the endpoint must leave the cached
    // /me list, not just the dashboard read.
    [$userId] = seedUserWithSite();
    $itemId = ownerServiceItem($userId, ['title' => 'Legacy Owner Service', 'price_cents' => 6500]);
    expect($itemId)->not->toBeNull();

    $user = User::query()->with('site')->findOrFail($userId);

    // Positive control FIRST: a bare `not->toContain` after the delete passes
    // just as happily on a list that never held the item at all.
    expect(array_column(app(UserCacheService::class)->getActiveServices($userId), 'id'))
        ->toContain($itemId);

    actingAsUser($user)->deleteJson("/api/services/{$itemId}")->assertOk();

    expect(array_column(app(UserCacheService::class)->getActiveServices($userId), 'id'))
        ->not->toContain($itemId);
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
