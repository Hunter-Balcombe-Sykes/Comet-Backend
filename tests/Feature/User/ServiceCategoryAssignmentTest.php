<?php

use App\Models\Core\User\Service;
use App\Services\Content\ServiceCollections;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// PATCH /api/services/{service}/category (UserServiceController::updateCategory) —
// re-files one service into a different category (or Uncategorized). Services
// cutover Task 4: BOTH halves file into content.collections through
// ServiceCollections' owner lane (source_id NULL), so the endpoint no longer
// resolves a site.services id, no longer appends at max(sort_order)+1, and no
// longer holds the ordering advisory lock — re-filing must not move a service
// in the owner's chosen order.
beforeEach(function () {
    tenantHelpersEnsureTables();
    setupServicesTable();
    setupServiceCategoriesTable();
    // The endpoint reads content.*/site.sections for every id it resolves;
    // site.services survives here only for the legacy-id cases below, which
    // pin that such an id resolves nowhere.
    setupIngestTables();
    setupContentTables();
    setupBlocksTable();

    // updateCategory() takes pg_advisory_xact_lock(hashtext(...)) — Postgres-only;
    // shim it (and hashtext) as SQLite UDFs so the real production code path runs.
    shimPgAdvisoryLockForSqlite();

    // Mirror the production partial unique index services_user_sort_order_uq so a
    // sort_order collision would actually fire in SQLite the way it does on Postgres.
    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS site.services_pro_sort_order_uq
         ON services (user_id, sort_order)
         WHERE deleted_at IS NULL'
    );
});

/**
 * One Fresha-landed service content item. Services cutover Task 4: both halves
 * of updateCategory() file into content.collections, so the fixture is a
 * content item, not a site.services row.
 */
function svcCutCatFreshaItem(string $userId, string $title = 'Fade', string $recordKey = 's:1'): string
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

it('assigns a service to one of the owner\'s categories', function () {
    $pro = createTenant('svc-cat-happy');
    $collectionId = app(ServiceCollections::class)->create($pro->id, 'Cuts');
    $itemId = svcCutCatFreshaItem($pro->id);

    $response = actingAsUser($pro)->patchJson("/api/services/{$itemId}/category", [
        'category_id' => $collectionId,
    ]);

    $response->assertOk();
    expect((string) $response->json('service.category_id'))->toBe($collectionId);

    // And it round-trips on the read, not just the write's own response.
    actingAsUser($pro)->getJson("/api/services/{$itemId}")
        ->assertOk()
        ->assertJsonPath('service.category_id', $collectionId);
});

it('stores BOTH memberships for two category_ids (owner ruling 2026-08-18: multi-category)', function () {
    // The 2026-08-14 one-category rule is retired: assignMany() replaces the
    // owner-lane membership set with every id sent — nothing collapses.
    $pro = createTenant('svc-cat-multi');
    $catA = app(ServiceCollections::class)->create($pro->id, 'Cuts');
    $catB = app(ServiceCollections::class)->create($pro->id, 'Colour');
    $itemId = svcCutCatFreshaItem($pro->id);

    actingAsUser($pro)
        ->patchJson("/api/services/{$itemId}/category", [
            'category_ids' => [$catA, $catB],
        ])
        ->assertOk();

    expect(DB::table('content.collection_items')->where('item_id', $itemId)->pluck('collection_id')->map(fn ($id) => (string) $id)->all())
        ->toEqualCanonicalizing([$catA, $catB]);
});

it('still accepts a single-element category_ids', function () {
    // Positive control: max:1 must admit one, not reject the array spelling
    // outright. Without this the case above passes on a rule of 'max:0'.
    $pro = createTenant('svc-cat-single');
    $collectionId = app(ServiceCollections::class)->create($pro->id, 'Cuts');
    $itemId = svcCutCatFreshaItem($pro->id);

    actingAsUser($pro)
        ->patchJson("/api/services/{$itemId}/category", [
            'category_ids' => [$collectionId],
        ])
        ->assertOk()
        ->assertJsonPath('service.category_ids.0', $collectionId);
});

it('moves a service to Uncategorized when category_id is null', function () {
    $pro = createTenant('svc-cat-null');
    $collections = app(ServiceCollections::class);
    $collectionId = $collections->create($pro->id, 'Cuts');
    $itemId = svcCutCatFreshaItem($pro->id);
    $collections->assign($pro->id, $itemId, $collectionId, null);

    $response = actingAsUser($pro)->patchJson("/api/services/{$itemId}/category", [
        'category_id' => null,
    ]);

    $response->assertOk();
    expect($response->json('service.category_id'))->toBeNull();
    expect(DB::table('content.collection_items')->where('item_id', $itemId)->count())->toBe(0);
});

it('rejects assigning the owner\'s service to another owner\'s category (422)', function () {
    $owner = createTenant('svc-cat-foreign-owner');
    $other = createTenant('svc-cat-foreign-other');
    $foreignCollectionId = app(ServiceCollections::class)->create($other->id, 'Theirs');
    $itemId = svcCutCatFreshaItem($owner->id);

    $response = actingAsUser($owner)->patchJson("/api/services/{$itemId}/category", [
        'category_id' => $foreignCollectionId,
    ]);

    // ServiceCollections::find() is owner-scoped, so a foreign collection is an
    // invalid input, not a 404 — the same 422 vocabulary the legacy
    // assertCategoryBelongsToProfessional() used.
    $response->assertStatus(422);

    expect(DB::table('content.collection_items')->where('item_id', $itemId)->count())->toBe(0);
});

it('rejects re-filing a service owned by another professional (404, no existence leak)', function () {
    $owner = createTenant('svc-cat-intruder-owner');
    $intruder = createTenant('svc-cat-intruder');
    $intruderCat = createServiceCategoryFor($intruder, ['sort_order' => 0]);
    $service = createServiceFor($owner, ['category_id' => null, 'sort_order' => 0]);

    $response = actingAsUser($intruder)->patchJson("/api/services/{$service->id}/category", [
        'category_id' => $intruderCat->id,
    ]);

    // ServicePolicy::update denies a non-owner via denyAsNotFound() → 404.
    $response->assertNotFound();

    expect(DB::table('site.service_category_assignments')->where('service_id', $service->id)->count())->toBe(0);
});

it('rejects category assignment on an owner-authored (manual) service (404)', function () {
    // Slice 3a §7: owner-authored categories have no destination in content.*
    // until 3b lands content.collections — ServicePolicy::updateCategory()
    // denies as not-found rather than accepting a write nothing serves.
    // Cross-referenced with the 'category' => 'Services' constant in
    // SitepageDataResolverService::buildServicesData().
    $pro = createTenant('svc-cat-manual-blocked');
    $cat = createServiceCategoryFor($pro, ['sort_order' => 0]);
    $service = createServiceFor($pro, ['category_id' => null, 'sort_order' => 0, 'source' => null]);

    $response = actingAsUser($pro)->patchJson("/api/services/{$service->id}/category", [
        'category_id' => $cat->id,
    ]);

    $response->assertNotFound();

    expect(DB::table('site.service_category_assignments')->where('service_id', $service->id)->count())->toBe(0);
});

it('does not move the service in the owner\'s order when it is re-filed', function () {
    // Was: "appends the moved service at global max(sort_order)+1". That append
    // existed only to keep the global services_user_sort_order_uq satisfiable
    // and dies with the table (services cutover Task 4). Its successor property
    // is the opposite one, and the stronger of the two: ordering lives on
    // site.section_items.sort_key, and a re-file must not silently move a
    // service in the order the owner chose (assignOwnerServiceCategory()'s
    // docblock states this for the manual half; it holds for both now).
    $pro = createTenant('svc-cat-append');
    $collectionId = app(ServiceCollections::class)->create($pro->id, 'Cuts');
    $itemId = svcCutCatFreshaItem($pro->id);

    $before = DB::table('site.section_items')->where('item_id', $itemId)->value('sort_key');

    actingAsUser($pro)->patchJson("/api/services/{$itemId}/category", [
        'category_id' => $collectionId,
    ])->assertOk();

    expect(DB::table('site.section_items')->where('item_id', $itemId)->value('sort_key'))->toBe($before);
});

it('rejects re-filing a legacy site.services id (services cutover, ruling 1)', function () {
    // Was: "coexists with reorder-layout under the shared advisory-lock key" —
    // a guard on the two legacy sort_order writers never interleaving. The
    // category endpoint renumbers nothing now, so it holds no ordering lock at
    // all (pinned by ServiceLayoutLockUnificationTest's exact count) and the
    // interleaving it guarded cannot occur. What replaces it is the break
    // itself: a legacy row that still EXISTS resolves nowhere and is untouched.
    $pro = createTenant('svc-cat-coexist');
    $catA = createServiceCategoryFor($pro, ['sort_order' => 0]);
    $legacy = createServiceFor($pro, ['category_id' => $catA->id, 'sort_order' => 0, 'source' => 'fresha']);

    actingAsUser($pro)->patchJson("/api/services/{$legacy->id}/category", [
        'category_id' => $catA->id,
    ])->assertNotFound();

    $legacy->refresh();
    expect($legacy->sort_order)->toBe(0);
    expect(DB::table('site.service_category_assignments')->where('service_id', $legacy->id)
        ->pluck('service_category_id')->map(fn ($id) => (string) $id)->all())->toBe([(string) $catA->id]);
});

it('persists category_ids at creation (owner, 2026-08-17 — the gap is closed)', function () {
    // store() used to validate the category then DROP it — the add sheet's
    // picker makes create + membership one gesture now.
    $pro = createTenant('svc-cat-create');
    $collectionId = app(ServiceCollections::class)->create($pro->id, 'Cuts');

    $response = actingAsUser($pro)->postJson('/api/services', [
        'title' => 'Beard trim',
        'price_cents' => 3500,
        'category_ids' => [$collectionId],
    ]);

    $response->assertStatus(201);
    expect($response->json('service.category_ids'))->toBe([$collectionId]);

    $itemId = (string) $response->json('service.id');
    actingAsUser($pro)->getJson("/api/services/{$itemId}")
        ->assertOk()
        ->assertJsonPath('service.category_ids.0', $collectionId);
});

it('still creates uncategorised when no category is sent', function () {
    $pro = createTenant('svc-cat-create-none');

    $response = actingAsUser($pro)->postJson('/api/services', [
        'title' => 'Walk-in consult',
        'price_cents' => 0,
    ]);

    $response->assertStatus(201);
    expect($response->json('service.category_ids'))->toBe([]);
});
