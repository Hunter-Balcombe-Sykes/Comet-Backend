<?php

use App\Http\Controllers\Api\User\SiteManagement\UserServiceController;
use App\Http\Requests\Api\User\Services\ReorderServiceLayoutRequest;
use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Content\ManualServiceWriter;
use App\Services\Migration\ServiceBackfiller;
use App\Services\PublicSite\SitepageDataResolverService;
use App\Site\Documents\BuildState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

// M2 (final whole-branch review): ManualServiceItems::hydrate() stamps a
// hidden/unpinned owner-authored service with PHP_INT_MAX (9223372036854775807)
// so it sorts last in the merged manual+Fresha list internally — that
// sentinel was shipping verbatim on the dashboard wire, above JS's
// Number.MAX_SAFE_INTEGER. ServiceResource now maps it to null at the wire
// boundary; the sort-last behaviour must still hold internally.
it('emits null (not the internal PHP_INT_MAX sentinel) for a hidden owner service\'s sort_order, while still sorting it last', function () {
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    $freshaId = ownerService($userId, ['title' => 'Fresha A', 'source' => 'fresha', 'external_id' => 's:1', 'sort_order' => 0]);
    $manualId = actingAsUser($user)->postJson('/api/services', ['title' => 'Manual A', 'price_cents' => 1000])
        ->assertCreated()->json('service.id');

    actingAsUser($user)->patchJson("/api/services/{$manualId}", ['is_active' => false])->assertOk();

    $response = actingAsUser($user)->getJson('/api/services?include_archived=1')->assertOk();
    $services = collect($response->json('services'));

    expect($services->pluck('id')->all())->toBe([$freshaId, $manualId]);
    expect($services->firstWhere('id', $manualId)['sort_order'])->toBeNull();
    expect($services->firstWhere('id', $freshaId)['sort_order'])->toBe(0);
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

// B1 (final whole-branch review): projectionFor() built the content.* facet
// projection with array_filter(), which dropped a null/empty 'body' or the
// whole 'f_duration' facet from the payload — ProjectionWriter::
// upsertSingletonFacet() only touches columns actually present in what it's
// given, so an omitted facet key left the EXISTING row untouched. An
// explicit PATCH {"description": null} therefore silently no-opped instead
// of clearing the field.
it('an explicit PATCH {"description": null} actually clears the description', function () {
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    $id = actingAsUser($user)->postJson('/api/services', [
        'title' => 'Full', 'description' => 'Details here', 'price_cents' => 5000,
    ])->assertCreated()->json('service.id');

    $response = actingAsUser($user)->patchJson("/api/services/{$id}", ['description' => null])->assertOk();
    expect($response->json('service.description'))->toBeNull();

    // Re-read fresh (not the just-written response) and check the public
    // payload + the raw facet row too — the three places B1 was proven live.
    actingAsUser($user)->getJson("/api/services/{$id}")->assertOk()
        ->assertJsonPath('service.description', null);

    $site = Site::query()->find($siteId);
    $data = app(SitepageDataResolverService::class)->buildServicesData($site, $userId);
    $service = collect($data['services'])->firstWhere('id', $id);
    expect($service['description'])->toBeNull();

    $sourceId = DB::table('content.sources')->where('user_id', $userId)->where('kind', 'manual')->value('id');
    expect(DB::table('content.f_text')->where('item_id', $id)->where('source_id', $sourceId)->value('body'))->toBeNull();
});

it('an explicit PATCH {"duration_minutes": null} actually clears the duration', function () {
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    $id = actingAsUser($user)->postJson('/api/services', [
        'title' => 'Full', 'price_cents' => 5000, 'duration_minutes' => 45,
    ])->assertCreated()->json('service.id');

    $response = actingAsUser($user)->patchJson("/api/services/{$id}", ['duration_minutes' => null])->assertOk();
    expect($response->json('service.duration_minutes'))->toBeNull();

    actingAsUser($user)->getJson("/api/services/{$id}")->assertOk()
        ->assertJsonPath('service.duration_minutes', null);

    $sourceId = DB::table('content.sources')->where('user_id', $userId)->where('kind', 'manual')->value('id');
    expect(DB::table('content.f_duration')->where('item_id', $id)->where('source_id', $sourceId)->value('seconds'))->toBeNull();
});

it('falls back to the untouched Fresha row when the id is not a manual content item', function () {
    // §C2 (review correction): "cut over fully rather than dual-writing"
    // meant owner-authored writes go ONLY to content.*, not that a Fresha id
    // stops being addressable — site.services holds 61 untouched Fresha rows
    // until 3b, and these endpoints must still reach them.
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);
    $freshaId = ownerService($userId, ['title' => 'Cut', 'source' => 'fresha', 'external_id' => 's:1']);

    $response = actingAsUser($user)->getJson("/api/services/{$freshaId}")->assertOk();
    expect($response->json('service.id'))->toBe($freshaId);
    expect($response->json('service.source'))->toBe('fresha');
});

it('editing a Fresha service through PATCH detaches it from the live sync (is_manual)', function () {
    // Without this legacy branch resync/resyncBulk become dead code — nothing
    // else can ever set is_manual=true again.
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);
    $freshaId = ownerService($userId, ['title' => 'Cut', 'source' => 'fresha', 'external_id' => 's:1', 'is_manual' => 0]);

    $response = actingAsUser($user)->patchJson("/api/services/{$freshaId}", ['title' => 'Owner-edited price'])->assertOk();

    expect($response->json('service.is_manual'))->toBeTrue();
    expect(DB::table('site.services')->where('id', $freshaId)->value('is_manual'))->toBeTruthy();
});

it('deleting a Fresha service records deleted_origin=user so a sync never resurrects it', function () {
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);
    $freshaId = ownerService($userId, ['title' => 'Cut', 'source' => 'fresha', 'external_id' => 's:1']);

    actingAsUser($user)->deleteJson("/api/services/{$freshaId}")->assertOk()->assertJson(['deleted' => true]);

    expect(DB::table('site.services')->where('id', $freshaId)->value('deleted_origin'))->toBe('user');
    expect(DB::table('site.services')->where('id', $freshaId)->value('deleted_at'))->not->toBeNull();
});

it('restoring a Fresha service clears deleted_origin and recomputes sort_order', function () {
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);
    $freshaId = ownerService($userId, [
        'title' => 'Cut', 'source' => 'fresha', 'external_id' => 's:1',
        'deleted_at' => now(), 'deleted_origin' => 'user',
    ]);

    $response = actingAsUser($user)->postJson("/api/services/{$freshaId}/restore")->assertOk();

    expect($response->json('restored'))->toBeTrue();
    expect(DB::table('site.services')->where('id', $freshaId)->value('deleted_at'))->toBeNull();
    expect(DB::table('site.services')->where('id', $freshaId)->value('deleted_origin'))->toBeNull();
});

it('reorder routes a Fresha id to sort_order and a manual id to sort_key in one request', function () {
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    $manualId = actingAsUser($user)->postJson('/api/services', ['title' => 'Manual One', 'price_cents' => 1000])
        ->assertCreated()->json('service.id');
    $freshaId = ownerService($userId, ['title' => 'Fresha One', 'source' => 'fresha', 'external_id' => 's:2', 'sort_order' => 0]);
    $freshaId2 = ownerService($userId, ['title' => 'Fresha Two', 'source' => 'fresha', 'external_id' => 's:3', 'sort_order' => 1]);

    actingAsUser($user)->postJson('/api/services/reorder', ['ids' => [$freshaId2, $freshaId, $manualId]])
        ->assertOk()->assertJson(['ok' => true]);

    expect(DB::table('site.services')->where('id', $freshaId2)->value('sort_order'))->toBe(0);
    expect(DB::table('site.services')->where('id', $freshaId)->value('sort_order'))->toBe(1);
    $manualSortKey = DB::table('site.section_items')->where('item_id', $manualId)->value('sort_key');
    expect($manualSortKey)->not->toBeNull();
});

// C1 regression coverage deliberately lives in
// tests/Feature/Security/TenantIsolation/ServicesIsolationTest.php, not
// here: EnforcePendingDeletionReadOnly (registered on the whole user.api
// route group, routes/api/user.php:55) returns 423 before the controller
// ever runs for an HTTP-layer request, so an HTTP-layer version of this
// test passes even with authorizeForUser() deleted from reorder() — it was
// removed from this file for exactly that reason (review round 2). Only a
// direct controller call (bypassing the middleware pipeline, as
// ServicesIsolationTest does) actually exercises ServicePolicy::update's
// own pending-deletion gate.

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
    $beforeRevision = BuildState::read($siteId)['content_revision'];

    actingAsUser($user)->postJson('/api/services', ['title' => 'Lanes', 'price_cents' => 1000])->assertCreated();

    // writeManualItem() bumps once on its own (ProjectionWriter::bumpSite());
    // ManualServiceWriter::invalidate() must bump AGAIN on top of that —
    // mirrors ServiceBackfillerTest's identical assertion for the same
    // reason: a merely ">0" check would still pass with invalidate()'s own
    // bump call deleted, since writeManualItem()'s bump alone clears that bar.
    expect(DB::table('site.site_build_state')->where('site_id', $siteId)->value('content_revision'))
        ->toBe($beforeRevision + 2);
    expect(DB::table('site.sites')->where('id', $siteId)->value('updated_at'))->not->toBe($before);
    Queue::assertPushed(CloudflareCachePurgeJob::class);
});

it('a connector-shaped merge after the cutover backfill keeps the owner item alive (§8.3 regression)', function () {
    // I2 fix: the previous version of this test never ran the resolver — it
    // only re-read an untouched `coord` column, so it stayed green with
    // ProjectionWriter::preferOwnerAnchored()/mergeInto()'s hasCuration
    // protection entirely removed. This version actually forces a merge.
    [$userId, $siteId] = seedUserWithSite();
    $legacyId = ownerService($userId, ['title' => 'Legacy Owner Service', 'price_cents' => 6500, 'duration_minutes' => 45]);
    app(ServiceBackfiller::class)->run();

    $manualSourceItemId = DB::table('content.source_items')->where('coord', 'manual:'.$legacyId)->value('id');
    $manualItemId = DB::table('content.source_items')->where('coord', 'manual:'.$legacyId)->value('item_id');
    expect($manualSourceItemId)->not->toBeNull();

    // Simulate a connector landing a DIFFERENT-sourced record the identity
    // resolver considers the SAME service (a shared canonical URL — the
    // 'link' kind gate in Resolver::mayUnion() means either side may carry
    // it, and CanonicalUrl is a JOINING key so it unions without needing to
    // be cross-source). Bound BEFORE the manual item — so a naive
    // oldest-binding-wins rule (bindGroup()'s fallback) would pick this row,
    // not the owner's, if preferOwnerAnchored() didn't override it.
    $connectionSourceId = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $connectionSourceId, 'user_id' => $userId, 'kind' => 'connection',
        'priority' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $connectorItemId = (string) Str::uuid();
    DB::table('content.items')->insert([
        'id' => $connectorItemId, 'user_id' => $userId, 'kind' => 'service',
        'headline_cache' => 'CONNECTOR PLACEHOLDER — must not survive the merge',
        'facets_cache' => '{}', 'eligible_cache' => '{}',
        'first_seen_at' => now()->subDay(), 'last_seen_at' => now()->subDay(),
        'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
    ]);
    $connectorSourceItemId = (string) Str::uuid();
    DB::table('content.source_items')->insert([
        'id' => $connectorSourceItemId, 'source_id' => $connectionSourceId,
        'coord' => 'fresha:acct:s:merge-test', 'item_id' => $connectorItemId, 'kind' => 'service',
        'projector_version' => 1, 'first_seen_at' => now()->subDay(), 'last_seen_at' => now()->subDay(),
    ]);
    DB::table('content.item_anchors')->insert([
        'coord' => 'fresha:acct:s:merge-test', 'user_id' => $userId, 'item_id' => $connectorItemId,
        'bound_at' => now()->subDay(),
    ]);
    // Only the CONNECTOR side's key is pre-seeded here — the manual side's
    // matching key is supplied via the write() call below, in the SAME
    // projection's f_link.url. writeManualItem()'s writeIdentityKeys()
    // DELETEs and re-derives every key for the coord it's given on every
    // call, so a key inserted here directly for the manual source_item
    // would be wiped before resolveItems() ever runs — it must arrive
    // through the projection instead.
    DB::table('content.identity_keys')->insert([
        'source_item_id' => $connectorSourceItemId, 'key_class' => 'canonical_url',
        'key_value' => 'https://example.test/shared-service', 'tier' => 'joining', 'created_at' => now(),
    ]);

    // Trigger the resolver over BOTH source items — re-writing the same
    // manual coord is exactly what update() does on every content edit, and
    // resolveItems() scans every live source_item for (user, kind)
    // regardless of which write triggered it. f_link.url is NOT part of
    // ManualServiceWriter::projectionFor()'s real mapping (services carry no
    // URL) — added here only to give the manual source_item the SAME
    // canonical-url identity key as the synthetic connector row, forcing the
    // union this test exists to exercise.
    $writer = app(ManualServiceWriter::class);
    $writer->write($userId, 'manual:'.$legacyId, [
        'kind' => 'service',
        'headline' => 'Legacy Owner Service',
        'facets' => [
            'f_text' => ['headline' => 'Legacy Owner Service'],
            'f_link' => ['url' => 'https://example.test/shared-service'],
        ],
    ]);

    // The merge DID happen (content.item_merges has a row) — the interesting
    // question is which side survived as the LIVE, publicly-readable item.
    // mergeInto() spares a discarded item that carries curation from hard
    // delete either way (it's never deleted here), but ValueResolver's
    // source-priority headline recompute means the DISPLAYED title is
    // "Legacy Owner Service" regardless of which item id won — priority
    // alone would mask a preferOwnerAnchored() regression, so the assertion
    // that actually detects one is WHICH ITEM ID the public read resolves
    // to, not what title it carries.
    expect(DB::table('content.item_merges')->where('user_id', $userId)->exists())->toBeTrue();

    $site = Site::query()->find($siteId);
    $data = app(SitepageDataResolverService::class)->buildServicesData($site, $userId);
    expect(array_column($data['services'], 'id'))->toBe([$manualItemId]);
    expect(array_column($data['services'], 'title'))->toBe(['Legacy Owner Service']);
});

// §NEW-C1 (review round 2): a professional holding both halves must not 500,
// and the combined order must round-trip through GET.
it('reorder mixing manual and Fresha ids does not 500 and round-trips through GET in the submitted order', function () {
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    // A still-present LEGACY manual row (ServiceBackfiller never deletes
    // site.services rows) sitting at a low sort_order, exactly like the dev
    // reproduction (2 legacy manual rows at 0-1, Fresha rows at 2+).
    $legacyManualId = ownerService($userId, ['title' => 'Legacy Manual', 'sort_order' => 0]);
    app(ServiceBackfiller::class)->run();

    $newManualId = actingAsUser($user)->postJson('/api/services', ['title' => 'New Manual', 'price_cents' => 1000])
        ->assertCreated()->json('service.id');
    $freshaA = ownerService($userId, ['title' => 'Fresha A', 'source' => 'fresha', 'external_id' => 's:1', 'sort_order' => 1]);
    $freshaB = ownerService($userId, ['title' => 'Fresha B', 'source' => 'fresha', 'external_id' => 's:2', 'sort_order' => 2]);

    // Interleaved: Fresha, manual, Fresha, manual.
    $manualItemIdForLegacy = DB::table('content.source_items')->where('coord', 'manual:'.$legacyManualId)->value('item_id');
    $submitted = [$freshaB, $manualItemIdForLegacy, $freshaA, $newManualId];

    actingAsUser($user)->postJson('/api/services/reorder', ['ids' => $submitted])
        ->assertOk()->assertJson(['ok' => true]);

    $response = actingAsUser($user)->getJson('/api/services?include_archived=1')->assertOk();
    $ids = collect($response->json('services'))->pluck('id')->all();

    expect($ids)->toBe($submitted);
});

// A brand-new manual service (created through the cut-over endpoint) has NO
// legacy site.services row at all, so renumberLegacySortOrder() cannot
// assign it a sort_order and must skip it entirely when translating the
// submitted order — this is the exact case where a naive re-compacted
// renumber (e.g. delegating wholesale to ReorderService::reorder(), which
// was tried and reverted) silently shifts every Fresha id that comes AFTER
// the unresolvable manual id, breaking the interleave the moment more than
// one Fresha id follows it.
it('round-trips even when two brand-new manual services (no legacy row) sit ahead of two Fresha ids', function () {
    // §NEW-C1 review round 3 (item 3): the ORIGINAL version of this test
    // (one unresolvable manual id, one Fresha id after it) stayed green
    // under a dense recompaction of $targets — the exact
    // ReorderService::reorder()-delegation defect this test exists to
    // guard against — because dropping ONE unresolvable id from the front
    // shifts nothing (there's nothing after it to shift past). The break
    // needs at least two Fresha ids following the unresolvable id(s) for a
    // dense re-index to diverge from the gapped one. [m1_new, m2_new, f1,
    // f2] is the minimal shape that actually exercises it — verified below
    // by reproducing the ReorderService-delegation mutation and confirming
    // THIS test (and no other) goes red under it.
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    $freshaA = ownerService($userId, ['title' => 'Fresha A', 'source' => 'fresha', 'external_id' => 's:1', 'sort_order' => 0]);
    $freshaB = ownerService($userId, ['title' => 'Fresha B', 'source' => 'fresha', 'external_id' => 's:2', 'sort_order' => 1]);
    $newManualId1 = actingAsUser($user)->postJson('/api/services', ['title' => 'Brand New 1', 'price_cents' => 1000])
        ->assertCreated()->json('service.id');
    $newManualId2 = actingAsUser($user)->postJson('/api/services', ['title' => 'Brand New 2', 'price_cents' => 2000])
        ->assertCreated()->json('service.id');

    $submitted = [$newManualId1, $newManualId2, $freshaA, $freshaB];

    actingAsUser($user)->postJson('/api/services/reorder', ['ids' => $submitted])
        ->assertOk()->assertJson(['ok' => true]);

    $response = actingAsUser($user)->getJson('/api/services?include_archived=1')->assertOk();
    $ids = collect($response->json('services'))->pluck('id')->all();

    expect($ids)->toBe($submitted);
});

// §NEW-4 (review round 3): ManualServiceItems::hydrate() used to map a null
// sort_key (ManualServiceWriter::exclude()'s deliberate null for a hidden
// service) to sort_order=0 — the MINIMUM of the shared scale NEW-I1's merge
// sort depends on — so a hidden manual service sorted to the HEAD of the
// merged list instead of somewhere sane. Round 1's manual-first
// concatenation masked this; the round 2 fix (sort by sort_order) exposed
// it. Covers both index()'s uncached merge and
// UserCacheService::getDashboardServices()'s cached one.
it('a hidden manual service sorts LAST, not first, in the merged list on both cache paths', function () {
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    $freshaA = ownerService($userId, ['title' => 'Fresha A', 'source' => 'fresha', 'external_id' => 's:1', 'sort_order' => 0]);
    $freshaB = ownerService($userId, ['title' => 'Fresha B', 'source' => 'fresha', 'external_id' => 's:2', 'sort_order' => 1]);
    $manualId = actingAsUser($user)->postJson('/api/services', ['title' => 'Manual A', 'price_cents' => 1000])
        ->assertCreated()->json('service.id');

    actingAsUser($user)->postJson('/api/services/reorder', ['ids' => [$freshaA, $freshaB, $manualId]])
        ->assertOk();

    actingAsUser($user)->patchJson("/api/services/{$manualId}", ['is_active' => false])->assertOk();

    $expected = [$freshaA, $freshaB, $manualId];

    // Uncached path (index()'s ?include_archived branch — hidden items are
    // always live, never removed, so they show regardless of this flag).
    $uncached = actingAsUser($user)->getJson('/api/services?include_archived=1')->assertOk();
    expect(collect($uncached->json('services'))->pluck('id')->all())->toBe($expected);

    // Cached (hot) path — the PATCH above must have busted the key the
    // reorder call also wrote through.
    $cached = actingAsUser($user)->getJson('/api/services')->assertOk();
    expect(collect($cached->json('services'))->pluck('id')->all())->toBe($expected);
});

it('reorder 422s an id that belongs to neither the manual nor Fresha store', function () {
    [$userId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);
    ownerService($userId, ['title' => 'Fresha A', 'source' => 'fresha', 'external_id' => 's:1']);

    actingAsUser($user)->postJson('/api/services/reorder', ['ids' => [(string) Str::uuid()]])
        ->assertStatus(422);
});

// A ReorderServiceLayoutRequest whose validated() returns $payload verbatim,
// bypassing ReorderServiceLayoutRequest::rules()'s FormRequest-level
// 'distinct' rule on categories.*.service_ids.* — confirmed empirically
// (see the two tests below) to reject ANY repeated id anywhere in the
// payload, same block or different, so a real HTTP request can never reach
// UserServiceController::reorderLayout()'s own within-block-duplicate or
// categorised-and-uncategorised guards. Constructs the controller-facing
// shape directly so those two guards can be proven on their own terms.
function reorderLayoutRequestBypassingDistinct(User $pro, array $payload): ReorderServiceLayoutRequest
{
    $request = new class extends ReorderServiceLayoutRequest
    {
        public array $canned = [];

        public function validated($key = null, $default = null)
        {
            return $this->canned;
        }
    };
    $request->attributes->set('professional', $pro);
    $request->canned = $payload;

    return $request;
}

it('reorderLayout rejects an invalid category id (422)', function () {
    [$userId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);
    $cat = createServiceCategoryFor($user, ['sort_order' => 0]);
    $svc = createServiceFor($user, ['category_id' => $cat->id, 'sort_order' => 0, 'source' => 'fresha']);

    $response = actingAsUser($user)->postJson('/api/services/reorder-layout', [
        'categories' => [
            ['id' => (string) Str::uuid(), 'service_ids' => [$svc->id]],
        ],
    ]);

    // A bare assertStatus(422) is vacuous here: deleting the FIRST controller
    // check this test names ("category IDs are invalid") does NOT turn the
    // request green — it falls through to the LATER "must include all
    // category IDs" check and still 422s, just with a different message.
    // Asserting the exact message is what makes this test detect that its
    // OWN guard, specifically, fired.
    $response->assertStatus(422)->assertJsonPath('message', 'One or more category IDs are invalid.');
});

it('reorderLayout rejects a duplicate service id within one category block (422)', function () {
    [$userId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);
    $cat = createServiceCategoryFor($user, ['sort_order' => 0]);
    $svc = createServiceFor($user, ['category_id' => $cat->id, 'sort_order' => 0, 'source' => 'fresha']);

    $request = reorderLayoutRequestBypassingDistinct($user, [
        'categories' => [
            ['id' => $cat->id, 'service_ids' => [$svc->id, $svc->id]],
        ],
    ]);

    try {
        app(UserServiceController::class)->reorderLayout($request);
        expect(false)->toBeTrue('Expected an HttpException (422)');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(422);
        expect($e->getMessage())->toBe('Duplicate service IDs detected within a category block.');
    }
});

it('reorderLayout rejects a service that is both categorised and uncategorised (422)', function () {
    [$userId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);
    $cat = createServiceCategoryFor($user, ['sort_order' => 0]);
    $svc = createServiceFor($user, ['category_id' => $cat->id, 'sort_order' => 0, 'source' => 'fresha']);

    $request = reorderLayoutRequestBypassingDistinct($user, [
        'categories' => [
            ['id' => $cat->id, 'service_ids' => [$svc->id]],
            ['id' => null, 'service_ids' => [$svc->id]],
        ],
    ]);

    try {
        app(UserServiceController::class)->reorderLayout($request);
        expect(false)->toBeTrue('Expected an HttpException (422)');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(422);
        expect($e->getMessage())->toBe('A service cannot be both categorised and uncategorised.');
    }
});

it('reorderLayout rejects a payload missing one of the owner\'s live category ids (422)', function () {
    [$userId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);
    $catA = createServiceCategoryFor($user, ['sort_order' => 0]);
    // catB carries NO services — if it did, omitting it from the payload
    // would ALSO leave its service uncovered and trip the earlier
    // "must include all service IDs" check first, making this test
    // indistinguishable from that one (the original vacuous shape).
    $catB = createServiceCategoryFor($user, ['sort_order' => 1]);
    $svcA = createServiceFor($user, ['category_id' => $catA->id, 'sort_order' => 0, 'source' => 'fresha']);

    // $catB never appears in the payload at all, but every live SERVICE
    // (svcA is the only one) is still covered — only the category-coverage
    // check can fire.
    $response = actingAsUser($user)->postJson('/api/services/reorder-layout', [
        'categories' => [
            ['id' => $catA->id, 'service_ids' => [$svcA->id]],
        ],
    ]);

    $response->assertStatus(422)->assertJsonPath(
        'message',
        'Layout payload must include all category IDs (use one block with id=null for uncategorised).'
    );
});
