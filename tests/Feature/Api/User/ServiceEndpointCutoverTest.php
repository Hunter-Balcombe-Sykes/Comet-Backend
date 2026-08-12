<?php

use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Migration\ServiceBackfiller;
use App\Services\PublicSite\SitepageDataResolverService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

// Slice 3a Task 5: the 8 owner-authored routes on UserServiceController now
// read and write content.* instead of site.services. The bug this guards
// against is the one slice 2 shipped: a dashboard write landing somewhere
// the public read doesn't consult, so an edit silently never reaches the
// site.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
    setupServicesTable();
    setupBlocksTable();
    // store()/update()/reorder()/reorderLayout() all take the same
    // pg_advisory_xact_lock(hashtext(...)) as the pre-cutover code — shim it
    // under SQLite so the real locked code path still runs.
    shimPgAdvisoryLockForSqlite();
    Queue::fake();
});

it('creates a service that appears in the public payload immediately', function () {
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    $response = actingAsUser($user)->postJson('/api/services', [
        'title' => 'New Service', 'price_cents' => 8000, 'currency_code' => 'AUD',
        'duration_minutes' => 30,
    ]);

    $response->assertCreated();

    $site = Site::query()->find($siteId);
    $data = app(SitepageDataResolverService::class)->buildServicesData($site, $userId);

    expect(array_column($data['services'], 'title'))->toContain('New Service');
    expect(array_column($data['services'], 'price_cents'))->toContain(8000);
});

it('keeps the response shape unchanged', function () {
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    $response = actingAsUser($user)->postJson('/api/services', [
        'title' => 'Consultation', 'price_cents' => 6500, 'currency_code' => 'AUD',
    ]);

    $response->assertCreated()->assertJsonStructure([
        'service' => [
            'id', 'user_id', 'category_id', 'category_ids', 'title', 'description',
            'price_cents', 'currency_code', 'duration_minutes', 'is_active',
            'sort_order', 'source', 'is_manual', 'external_id',
            'created_at', 'updated_at', 'deleted_at',
        ],
    ]);

    $id = $response->json('service.id');

    actingAsUser($user)->getJson('/api/services')->assertOk()->assertJsonStructure([
        'services' => [['id', 'user_id', 'category_id', 'category_ids', 'title', 'description', 'price_cents', 'currency_code', 'duration_minutes', 'is_active', 'sort_order', 'source', 'is_manual', 'external_id', 'created_at', 'updated_at', 'deleted_at']],
        'filters' => ['include_archived', 'only_archived'],
    ]);

    actingAsUser($user)->getJson("/api/services/{$id}")->assertOk()->assertJsonStructure([
        'service' => ['id', 'title', 'price_cents', 'is_active', 'source', 'is_manual'],
    ]);
});

it('reorders by moving the pin, and the public order follows', function () {
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    $first = actingAsUser($user)->postJson('/api/services', ['title' => 'First', 'price_cents' => 1000])
        ->assertCreated()->json('service.id');
    $second = actingAsUser($user)->postJson('/api/services', ['title' => 'Second', 'price_cents' => 2000])
        ->assertCreated()->json('service.id');

    // Freshly created, First then Second — reorder to put Second first.
    actingAsUser($user)->postJson('/api/services/reorder', ['ids' => [$second, $first]])
        ->assertOk()->assertJson(['ok' => true]);

    $site = Site::query()->find($siteId);
    $data = app(SitepageDataResolverService::class)->buildServicesData($site, $userId);

    expect(array_column($data['services'], 'title'))->toBe(['Second', 'First']);
});

it('deletes to items.removed_at and never to source_items.removed_at', function () {
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    $id = actingAsUser($user)->postJson('/api/services', ['title' => 'Gone Soon', 'price_cents' => 1500])
        ->assertCreated()->json('service.id');

    actingAsUser($user)->deleteJson("/api/services/{$id}")->assertOk()->assertJson(['deleted' => true]);

    expect(DB::table('content.items')->where('id', $id)->value('removed_at'))->not->toBeNull();
    expect(DB::table('content.source_items')->where('item_id', $id)->whereNotNull('removed_at')->count())->toBe(0);

    // Gone from the public payload and from the (uncached) dashboard read.
    $site = Site::query()->find($siteId);
    $data = app(SitepageDataResolverService::class)->buildServicesData($site, $userId);
    expect(array_column($data['services'], 'id'))->not->toContain($id);

    actingAsUser($user)->getJson("/api/services/{$id}")->assertNotFound();
});

it('restores by clearing items.removed_at', function () {
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    $id = actingAsUser($user)->postJson('/api/services', ['title' => 'Undelete Me', 'price_cents' => 4000])
        ->assertCreated()->json('service.id');
    actingAsUser($user)->deleteJson("/api/services/{$id}")->assertOk();

    expect(DB::table('content.items')->where('id', $id)->value('removed_at'))->not->toBeNull();

    $response = actingAsUser($user)->postJson("/api/services/{$id}/restore")
        ->assertOk()->assertJson(['restored' => true]);

    expect($response->json('service.id'))->toBe($id);
    expect(DB::table('content.items')->where('id', $id)->value('removed_at'))->toBeNull();

    $site = Site::query()->find($siteId);
    $data = app(SitepageDataResolverService::class)->buildServicesData($site, $userId);
    expect(array_column($data['services'], 'id'))->toContain($id);
});

it('a projection run after a restore does not re-clear or re-set removed_at', function () {
    // The one-way rule is a property of the SYNC path (ProjectionWriter::
    // writeManualItem, called on every content-only edit) and stays intact —
    // only the explicit /restore endpoint may clear removed_at.
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    $id = actingAsUser($user)->postJson('/api/services', ['title' => 'Sticky', 'price_cents' => 2500])
        ->assertCreated()->json('service.id');
    actingAsUser($user)->deleteJson("/api/services/{$id}")->assertOk();

    $coord = DB::table('content.source_items')->where('item_id', $id)->value('coord');

    // A non-restore write (the same path update() drives) must NOT resurrect
    // a user-deleted item.
    app(ProjectionWriter::class)->writeManualItem($userId, $coord, [
        'kind' => 'service',
        'headline' => 'Sticky',
        'facets' => ['f_text' => ['headline' => 'Sticky']],
    ]);
    expect(DB::table('content.items')->where('id', $id)->value('removed_at'))->not->toBeNull();

    // Restore clears it...
    actingAsUser($user)->postJson("/api/services/{$id}/restore")->assertOk();
    expect(DB::table('content.items')->where('id', $id)->value('removed_at'))->toBeNull();

    // ...and a subsequent content edit (the same write() call update() makes)
    // does not re-set it.
    actingAsUser($user)->patchJson("/api/services/{$id}", ['title' => 'Sticky Again'])->assertOk();
    expect(DB::table('content.items')->where('id', $id)->value('removed_at'))->toBeNull();
});

it('update() moves an active service to a pool exclude when is_active is set false', function () {
    // The precise regression this slice exists to prevent: an owner "hiding"
    // a service must actually stop it rendering, not just flip a column
    // nothing reads anymore.
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    $id = actingAsUser($user)->postJson('/api/services', ['title' => 'Hide Me', 'price_cents' => 3000])
        ->assertCreated()->json('service.id');

    $site = Site::query()->find($siteId);
    expect(array_column(app(SitepageDataResolverService::class)->buildServicesData($site, $userId)['services'], 'id'))->toContain($id);

    actingAsUser($user)->patchJson("/api/services/{$id}", ['is_active' => false])
        ->assertOk()->assertJson(['service' => ['is_active' => false]]);

    $pin = DB::table('site.section_items')->join('content.source_items as si', 'si.item_id', '=', 'site.section_items.item_id')
        ->where('site.section_items.item_id', $id)
        ->first(['site.section_items.state as state', 'site.section_items.sort_key as sort_key']);
    expect($pin->state)->toBe('excluded');
    expect($pin->sort_key)->toBeNull();

    $data = app(SitepageDataResolverService::class)->buildServicesData($site, $userId);
    expect(array_column($data['services'], 'id'))->not->toContain($id);
});

it('update() moves an excluded service back to a pin when is_active is set true', function () {
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    $id = actingAsUser($user)->postJson('/api/services', ['title' => 'Toggle Me', 'price_cents' => 3000, 'is_active' => false])
        ->assertCreated()->json('service.id');

    $site = Site::query()->find($siteId);
    expect(app(SitepageDataResolverService::class)->buildServicesData($site, $userId)['services'])->toBe([]);

    actingAsUser($user)->patchJson("/api/services/{$id}", ['is_active' => true])
        ->assertOk()->assertJson(['service' => ['is_active' => true]]);

    $data = app(SitepageDataResolverService::class)->buildServicesData($site, $userId);
    expect(array_column($data['services'], 'id'))->toContain($id);
});

it('a partial update does not blank out fields it did not send', function () {
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    $id = actingAsUser($user)->postJson('/api/services', [
        'title' => 'Full', 'description' => 'Details here', 'price_cents' => 5000,
        'duration_minutes' => 45,
    ])->assertCreated()->json('service.id');

    // is_active-only PATCH.
    $response = actingAsUser($user)->patchJson("/api/services/{$id}", ['is_active' => false])->assertOk();

    expect($response->json('service.title'))->toBe('Full');
    expect($response->json('service.description'))->toBe('Details here');
    expect($response->json('service.price_cents'))->toBe(5000);
    expect($response->json('service.duration_minutes'))->toBe(45);
});

it('never lets an owner reach a Fresha-sourced service through the cutover endpoints', function () {
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);
    $freshaId = ownerService($userId, ['source' => 'fresha', 'external_id' => 's:1']);

    // Still physically in site.services (untouched — 3b's row), but the
    // cutover endpoints only ever look in content.*, so it 404s here.
    actingAsUser($user)->getJson("/api/services/{$freshaId}")->assertNotFound();
});

it('rejects a cross-tenant service id as not found', function () {
    [$ownerId] = seedUserWithSite();
    $ownerUser = User::query()->with('site')->findOrFail($ownerId);
    $id = actingAsUser($ownerUser)->postJson('/api/services', ['title' => 'Mine', 'price_cents' => 1000])
        ->assertCreated()->json('service.id');

    [$strangerId] = seedUserWithSite();
    $stranger = User::query()->with('site')->findOrFail($strangerId);

    actingAsUser($stranger)->getJson("/api/services/{$id}")->assertNotFound();
    actingAsUser($stranger)->patchJson("/api/services/{$id}", ['title' => 'Hijack'])->assertNotFound();
    actingAsUser($stranger)->deleteJson("/api/services/{$id}")->assertNotFound();
});

it('fires all three invalidation lanes on a raw content write', function () {
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    DB::table('site.sites')->where('id', $siteId)->update(['updated_at' => now()->subMinute()]);
    $before = DB::table('site.sites')->where('id', $siteId)->value('updated_at');

    actingAsUser($user)->postJson('/api/services', ['title' => 'Lanes', 'price_cents' => 1000])->assertCreated();

    expect(DB::table('site.sites')->where('id', $siteId)->value('updated_at'))->not->toBe($before);
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('a connector projection run after the cutover backfill destroys nothing (§8.3 regression)', function () {
    [$userId, $siteId] = seedUserWithSite();
    $legacyId = ownerService($userId, ['title' => 'Legacy Owner Service']);
    app(ServiceBackfiller::class)->run();

    $site = Site::query()->find($siteId);
    $before = app(SitepageDataResolverService::class)->buildServicesData($site, $userId)['services'];
    expect($before)->toHaveCount(1);

    // A later connector-style projection over a DIFFERENT kind must not
    // disturb the manual service item.
    $coord = DB::table('content.source_items')->where('coord', 'manual:'.$legacyId)->value('coord');
    expect($coord)->toBe('manual:'.$legacyId);

    $after = app(SitepageDataResolverService::class)->buildServicesData($site, $userId)['services'];
    expect($after)->toBe($before);
});
