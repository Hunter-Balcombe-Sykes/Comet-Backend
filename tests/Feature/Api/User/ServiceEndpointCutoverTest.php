<?php

use App\Http\Controllers\Api\User\SiteManagement\UserServiceController;
use App\Http\Requests\Api\User\Services\ReorderServiceLayoutRequest;
use App\Ingest\Projection\ProjectionWriter;
use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\Site;
use App\Models\Core\User\User;
use App\Services\Content\ManualServiceWriter;
use App\Services\Content\ServiceCollections;
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

    $manualId = actingAsUser($user)->postJson('/api/services', ['title' => 'Manual A', 'price_cents' => 1000])
        ->assertCreated()->json('service.id');
    $freshaId = svcEndpointFreshaItem($userId, 'Fresha A', 's:1');
    // Give the Fresha item a real position so the sort has something to
    // order against — both halves ride section_items.sort_key now.
    actingAsUser($user)->postJson('/api/services/reorder', ['ids' => [$freshaId, $manualId]])->assertOk();

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

it('a legacy site.services id resolves nowhere on any management verb (services cutover, ruling 1)', function () {
    // This replaces the four §C2 cases that pinned the legacy fall-back:
    // show/update/destroy/restore reaching site.services by legacy id.
    // Services-cutover ruling 1 ends that deliberately — the management
    // surface addresses Fresha services by content.items.id, no mapping is
    // minted, and the wire manifest records the break. The row below still
    // EXISTS in site.services, which is what makes this a real proof rather
    // than an absent-row 404. Positive coverage of each verb's content-side
    // behaviour lives in ServicesCutoverFreshaManagementTest.
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);
    $legacyId = ownerService($userId, ['title' => 'Cut', 'source' => 'fresha', 'external_id' => 's:1']);

    actingAsUser($user)->getJson("/api/services/{$legacyId}")->assertNotFound();
    actingAsUser($user)->patchJson("/api/services/{$legacyId}", ['title' => 'Owner Name'])->assertNotFound();
    actingAsUser($user)->postJson("/api/services/{$legacyId}/restore")->assertNotFound();
    actingAsUser($user)->deleteJson("/api/services/{$legacyId}")->assertNotFound();

    // Untouched: a 404 must not have half-written anything to the legacy row.
    expect(DB::table('site.services')->where('id', $legacyId)->value('deleted_at'))->toBeNull();
});

/**
 * One Fresha-landed service content item. Services cutover: the Fresha half is
 * content.* under a kind='connection' source, addressed by content.items.id.
 * Anchored exactly as a connector-landed row is, so a later manual write's
 * resolveItems() pass keeps the coord bound to this item.
 */
function svcEndpointFreshaItem(string $userId, string $title, string $recordKey): string
{
    $sourceId = (string) Str::uuid();
    $itemId = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $userId, 'kind' => 'connection',
        'priority' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $userId, 'kind' => 'service',
        'headline_cache' => $title, 'facets_cache' => '{}', 'eligible_cache' => '{}',
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
        'coord' => 'fresha:'.$recordKey, 'record_key' => $recordKey, 'kind' => 'service',
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    // The anchor is what makes this stable: ProjectionWriter::resolveItems()
    // binds a coord to its item through content.item_anchors, and it re-runs
    // over EVERY live source item for the (user, kind) pair on any manual
    // write. Without an anchor row this coord resolves as an unrelated
    // singleton, gets a freshly minted item, and the id returned here is
    // orphaned — which is exactly what a connector-landed row never does.
    DB::table('content.item_anchors')->insert([
        'coord' => 'fresha:'.$recordKey, 'user_id' => $userId, 'item_id' => $itemId, 'bound_at' => now(),
    ]);

    return $itemId;
}

it('reorder puts a Fresha id and a manual id on ONE section_items scale', function () {
    // Was: "routes a Fresha id to sort_order and a manual id to sort_key".
    // Services cutover Task 5 (spec §3.4): there is one scale now —
    // site.section_items.sort_key on the services section — for both halves.
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    $manualId = actingAsUser($user)->postJson('/api/services', ['title' => 'Manual One', 'price_cents' => 1000])
        ->assertCreated()->json('service.id');
    $freshaId = svcEndpointFreshaItem($userId, 'Fresha One', 's:2');
    $freshaId2 = svcEndpointFreshaItem($userId, 'Fresha Two', 's:3');

    actingAsUser($user)->postJson('/api/services/reorder', ['ids' => [$freshaId2, $freshaId, $manualId]])
        ->assertOk()->assertJson(['ok' => true]);

    $keys = DB::table('site.section_items')->whereIn('item_id', [$freshaId2, $freshaId, $manualId])
        ->pluck('sort_key', 'item_id');
    expect((float) $keys[$freshaId2])->toBe(0.0)
        ->and((float) $keys[$freshaId])->toBe(1.0)
        ->and((float) $keys[$manualId])->toBe(2.0);
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

it('a connector-shaped merge keeps the curated owner item alive (§8.3 regression)', function () {
    // I2 fix: the previous version of this test never ran the resolver — it
    // only re-read an untouched `coord` column, so it stayed green with
    // ProjectionWriter::preferOwnerAnchored()/mergeInto()'s hasCuration
    // protection entirely removed. This version actually forces a merge.
    [$userId, $siteId] = seedUserWithSite();
    // Was seeded as a site.services row + ServiceBackfiller::run(); both are
    // gone with the services cutover, so the owner item is written through the
    // manual lane directly. The subject is unchanged — this case is about the
    // identity resolver, not about how the item got there.
    $manualItemId = ownerServiceItem($userId, ['title' => 'Legacy Owner Service', 'price_cents' => 6500, 'duration_minutes' => 45]);
    $manualRow = DB::table('content.source_items')->where('item_id', $manualItemId)->first(['id', 'coord']);
    $manualSourceItemId = $manualRow?->id;
    $manualCoord = (string) $manualRow?->coord;
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
    $writer->write($userId, $manualCoord, [
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
    // Both halves ride one section_items scale (Task 5) and the merged read
    // serves both from content.* (Task 6), so the submitted order survives
    // the round-trip through the API — the property the two tasks exist for.
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    $legacyManualId = actingAsUser($user)->postJson('/api/services', ['title' => 'Legacy Manual', 'price_cents' => 1000])
        ->assertCreated()->json('service.id');
    $newManualId = actingAsUser($user)->postJson('/api/services', ['title' => 'New Manual', 'price_cents' => 1000])
        ->assertCreated()->json('service.id');
    $freshaA = svcEndpointFreshaItem($userId, 'Fresha A', 's:1');
    $freshaB = svcEndpointFreshaItem($userId, 'Fresha B', 's:2');

    // Interleaved: Fresha, manual, Fresha, manual.
    $submitted = [$freshaB, $legacyManualId, $freshaA, $newManualId];

    actingAsUser($user)->postJson('/api/services/reorder', ['ids' => $submitted])
        ->assertOk()->assertJson(['ok' => true]);

    $keys = DB::table('site.section_items')->whereIn('item_id', $submitted)->pluck('sort_key', 'item_id');
    expect(collect($submitted)->map(fn ($id) => (float) $keys[$id])->all())->toBe([0.0, 1.0, 2.0, 3.0]);

    $ids = collect(actingAsUser($user)->getJson('/api/services?include_archived=1')->assertOk()->json('services'))
        ->pluck('id')->all();
    expect($ids)->toBe($submitted);
});

// Services cutover Task 5: the defect this case was written for — a dense
// re-compaction of the LEGACY renumber silently shifting every Fresha id that
// followed an id it could not resolve — is retired with the renumber itself.
// One scale, one traversal, every id resolvable, so the property that remains
// is the plain one: the submitted order is the stored order, gap-free.
it('round-trips two brand-new manual services sitting ahead of two Fresha ids', function () {
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    $newManualId1 = actingAsUser($user)->postJson('/api/services', ['title' => 'Brand New 1', 'price_cents' => 1000])
        ->assertCreated()->json('service.id');
    $newManualId2 = actingAsUser($user)->postJson('/api/services', ['title' => 'Brand New 2', 'price_cents' => 2000])
        ->assertCreated()->json('service.id');
    $freshaA = svcEndpointFreshaItem($userId, 'Fresha A', 's:1');
    $freshaB = svcEndpointFreshaItem($userId, 'Fresha B', 's:2');

    $submitted = [$newManualId1, $newManualId2, $freshaA, $freshaB];

    actingAsUser($user)->postJson('/api/services/reorder', ['ids' => $submitted])
        ->assertOk()->assertJson(['ok' => true]);

    $keys = DB::table('site.section_items')->whereIn('item_id', $submitted)->pluck('sort_key', 'item_id');
    expect(collect($submitted)->map(fn ($id) => (float) $keys[$id])->all())->toBe([0.0, 1.0, 2.0, 3.0]);

    $ids = collect(actingAsUser($user)->getJson('/api/services?include_archived=1')->assertOk()->json('services'))
        ->pluck('id')->all();
    expect($ids)->toBe($submitted);
});

// §NEW-4 (review round 3): ManualServiceItems::hydrate() used to map a null
// sort_key (ManualServiceWriter::exclude()'s deliberate null for a hidden
// service) to sort_order=0 — the MINIMUM of the shared scale the merged list
// sorts by — so a hidden manual service sorted to the HEAD instead of
// somewhere sane. PHP_INT_MAX is the sentinel that fixes it.
//
// Services cutover: the fixture is content-side on both halves; the assertion
// covers the scale AND the merged read, which serves both halves from
// content.* since Task 6.
it('leaves a hidden manual service unpositioned rather than at the head of the merged list', function () {
    [$userId, $siteId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);

    $manualId = actingAsUser($user)->postJson('/api/services', ['title' => 'Manual A', 'price_cents' => 1000])
        ->assertCreated()->json('service.id');
    $freshaA = svcEndpointFreshaItem($userId, 'Fresha A', 's:1');
    $freshaB = svcEndpointFreshaItem($userId, 'Fresha B', 's:2');

    actingAsUser($user)->postJson('/api/services/reorder', ['ids' => [$freshaA, $freshaB, $manualId]])
        ->assertOk();

    actingAsUser($user)->patchJson("/api/services/{$manualId}", ['is_active' => false])->assertOk();

    // Excluded: sort_key nulled by design, so it cannot occupy position 0 —
    // and the two live items keep the positions they were given.
    $keys = DB::table('site.section_items')->whereIn('item_id', [$freshaA, $freshaB, $manualId])
        ->pluck('sort_key', 'item_id');
    expect($keys[$manualId])->toBeNull()
        ->and((float) $keys[$freshaA])->toBe(0.0)
        ->and((float) $keys[$freshaB])->toBe(1.0);

    // On the merged list it sorts LAST (the PHP_INT_MAX sentinel), and the
    // wire never carries that sentinel — ServiceResource maps it to null.
    $services = collect(actingAsUser($user)->getJson('/api/services?include_archived=1')->assertOk()->json('services'));
    expect($services->pluck('id')->all())->toBe([$freshaA, $freshaB, $manualId]);
    expect($services->firstWhere('id', $manualId)['sort_order'])->toBeNull();
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
    $collectionId = app(ServiceCollections::class)->create($userId, 'Cuts');
    $svcId = svcEndpointFreshaItem($userId, 'Fresha One', 's:1');

    $request = reorderLayoutRequestBypassingDistinct($user, [
        'categories' => [
            ['id' => $collectionId, 'service_ids' => [$svcId, $svcId]],
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

it('accepts a service that appears in both a category block and the uncategorised block', function () {
    // Was: "rejects a service that is both categorised and uncategorised
    // (422)". That guard protected a MEMBERSHIP write: the payload decided
    // which categories a Fresha service belonged to, so "in a category and
    // also uncategorised" was contradictory. Slice 7 Task 12 stopped writing
    // memberships here and the services cutover left one id space, so the
    // blocks now carry ORDER only — a service listed in two blocks is the
    // already-supported multi-category case, and its first occurrence sets
    // its position. Membership is PATCH /services/{id}/category's job alone.
    [$userId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);
    $collectionId = app(ServiceCollections::class)->create($userId, 'Cuts');
    $svcId = svcEndpointFreshaItem($userId, 'Fresha One', 's:1');

    $request = reorderLayoutRequestBypassingDistinct($user, [
        'categories' => [
            ['id' => $collectionId, 'service_ids' => [$svcId]],
            ['id' => null, 'service_ids' => [$svcId]],
        ],
    ]);

    expect(app(UserServiceController::class)->reorderLayout($request)->getStatusCode())->toBe(200);
    expect(DB::table('content.collection_items')->where('item_id', $svcId)->count())->toBe(0);
});

it('reorderLayout rejects a payload missing one of the owner\'s live category ids (422)', function () {
    [$userId] = seedUserWithSite();
    $user = User::query()->with('site')->findOrFail($userId);
    $collections = app(ServiceCollections::class);
    $catA = $collections->create($userId, 'Cat A');
    // catB carries NO services — if it did, omitting it from the payload
    // would ALSO leave its service uncovered and trip the earlier
    // "must include all service IDs" check first, making this test
    // indistinguishable from that one (the original vacuous shape).
    $catB = $collections->create($userId, 'Cat B');
    $svcA = svcEndpointFreshaItem($userId, 'Fresha One', 's:1');
    $collections->assign($userId, $svcA, $catA, null);

    // $catB never appears in the payload at all, but every live SERVICE
    // (svcA is the only one) is still covered — only the category-coverage
    // check can fire.
    $response = actingAsUser($user)->postJson('/api/services/reorder-layout', [
        'categories' => [
            ['id' => $catA, 'service_ids' => [$svcA]],
        ],
    ]);

    $response->assertStatus(422)->assertJsonPath(
        'message',
        'Layout payload must include all category IDs (use one block with id=null for uncategorised).'
    );
});
