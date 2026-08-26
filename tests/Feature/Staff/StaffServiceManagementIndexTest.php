<?php

/**
 * P3-32: StaffServiceManagementController::index() must cap the services query at
 * limit(500) and the categories query at limit(200) — same caps as the user-facing
 * twins — so that a user with an unusually large catalogue cannot cause an unbounded
 * DB read through the staff endpoint.
 */

use App\Http\Controllers\Api\Staff\UserSiteManagement\StaffServiceManagementController;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Services\Content\ManualServiceWriter;
use App\Services\Content\ServiceCollections;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Builds a Request carrying a partna_staff attribute — #SEC-5 gates index(). */
function staffServiceIndexTest_request(string $uri): Request
{
    $request = Request::create($uri, 'GET');
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $request->attributes->set('partna_staff', $staff);

    return $request;
}

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupServicesTable(); // also sets up site.service_categories
    // Services cutover: index() merges BOTH halves out of content.* (the
    // owner-authored one scoped to the site's services pool section), and its
    // grouped branch lists categories from content.collections alone.
    setupIngestTables();
    setupContentTables();
});

/**
 * Slice 3b Task 11: this now goes through createTenant() rather than a
 * hand-rolled core.users insert, because the professional needs a SITE — the
 * owner-authored half of the merged list is scoped to that site's services
 * pool section, and a pin (which is how a manual service gets an order at all)
 * is written against it.
 */
function makeStaffServicePro(): User
{
    return createTenant('svcidx-'.Str::lower(Str::random(8)));
}

/**
 * Bulk-seed $count Fresha services the merged staff list can actually see.
 *
 * Services cutover: BOTH halves are content.* now — this one under a
 * kind='connection' source, the owner-authored half (seedManualService())
 * under the manual one. The cap is asserted over the MERGE of the two, which
 * is what it actually bounds. Seeding legacy site.services rows would leave
 * the list EMPTY and the cap trivially satisfied — the exact shape of test
 * that reads green while proving nothing.
 *
 * Bulk-inserted rather than driven through the projector: 510 real ingest
 * runs would dominate the suite's runtime, and the shape is fixed.
 */
function bulkSeedServices(string $userId, int $count): void
{
    $now = now()->toDateTimeString();
    $sourceId = (string) Str::uuid();
    DB::connection('pgsql')->table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $userId, 'kind' => 'connection',
        'priority' => 100, 'created_at' => $now, 'updated_at' => $now,
    ]);

    $items = [];
    $sourceItems = [];
    $anchors = [];
    for ($i = 0; $i < $count; $i++) {
        $itemId = (string) Str::uuid();
        $items[] = [
            'id' => $itemId,
            'user_id' => $userId,
            'kind' => 'service',
            'headline_cache' => "Service {$i}",
            'facets_cache' => '{}',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $sourceItems[] = [
            'id' => (string) Str::uuid(),
            'item_id' => $itemId,
            'source_id' => $sourceId,
            'coord' => "fresha:s:{$i}",
            'record_key' => "s:{$i}",
            'kind' => 'service',
            'first_seen_at' => $now,
            'last_seen_at' => $now,
        ];
        // Anchored like a connector-landed row: resolveItems() re-binds every
        // live source item on any manual write, and an unanchored coord would
        // be re-pointed at a freshly minted item.
        $anchors[] = [
            'coord' => "fresha:s:{$i}",
            'user_id' => $userId,
            'item_id' => $itemId,
            'bound_at' => $now,
        ];
    }
    // Chunked insert to avoid SQLite variable limit per statement.
    foreach (array_chunk($items, 50) as $chunk) {
        DB::connection('pgsql')->table('content.items')->insert($chunk);
    }
    foreach (array_chunk($sourceItems, 50) as $chunk) {
        DB::connection('pgsql')->table('content.source_items')->insert($chunk);
    }
    foreach (array_chunk($anchors, 50) as $chunk) {
        DB::connection('pgsql')->table('content.item_anchors')->insert($chunk);
    }
}

/**
 * One owner-authored service through the real content.* writer, so the cap is
 * exercised against a genuinely MERGED collection rather than one half of it.
 *
 * PINNED AT -1.0, deliberately. An unpinned manual item hydrates with
 * sort_order = PHP_INT_MAX (ManualServiceItems::hydrate()'s sort-last
 * sentinel), so the merged sort puts it dead last and `take(500)` drops it —
 * the case would then still pass with the manual half not merged at all, which
 * is the vacuous version of this test. A pin ahead of every Fresha row
 * (sort_order 0..N) puts it inside the returned window, so the caller can
 * assert it is actually there.
 */
function seedManualService(User $pro, string $title): string
{
    $writer = app(ManualServiceWriter::class);

    $itemId = $writer->write($pro->id, 'manual:'.(string) Str::uuid(), $writer->projectionFor((object) [
        'title' => $title,
        'description' => null,
        'price_cents' => 1000,
        'currency_code' => 'AUD',
        'duration_minutes' => null,
    ]));

    $writer->pin($pro->site, $itemId, -1.0);

    return $itemId;
}

/**
 * Services cutover: ONE category id space — content.collections. Bulk-inserted
 * with is_user_created = 1 so ServiceCollections::list() keeps them (it drops
 * connector-derived collections that hold no items).
 */
function bulkSeedServiceCategories(string $userId, int $count): void
{
    $now = now()->toDateTimeString();
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'label' => "Category {$i}",
            'kind' => 'service_category',
            'position' => $i,
            'is_user_created' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    foreach (array_chunk($rows, 50) as $chunk) {
        DB::connection('pgsql')->table('content.collections')->insert($chunk);
    }
}

it('caps the flat services list at 500 even when more than 500 exist', function () {
    $pro = makeStaffServicePro();
    bulkSeedServices($pro->id, 510);
    $manualId = seedManualService($pro, 'Owner authored');

    $controller = new StaffServiceManagementController;
    $response = $controller->index(staffServiceIndexTest_request('/'), $pro);
    $body = $response->getData(true);

    expect($response->getStatusCode())->toBe(200);
    // The cap is 500 — 511 seeded across BOTH halves, 500 should be returned.
    // success() wraps directly: body = ['services' => [...], 'filters' => [...]].
    expect($body['services'])->toHaveCount(500);
    // ...and the window is over the MERGED list, not the Fresha half alone:
    // without this the case passes with the content.* half dropped entirely.
    expect(collect($body['services'])->pluck('id'))->toContain($manualId);
});

it('caps the grouped services list at 500 even when more than 500 exist', function () {
    $pro = makeStaffServicePro();
    bulkSeedServices($pro->id, 510);
    seedManualService($pro, 'Owner authored');

    $controller = new StaffServiceManagementController;
    // Pass grouped as a query parameter so $request->boolean('grouped') resolves to true.
    $request = Request::create('/', 'GET', ['grouped' => '1']);
    $request->attributes->set('partna_staff', tap(new PartnaStaff, fn ($s) => $s->role = PartnaStaff::ROLE_ADMIN));
    $response = $controller->index($request, $pro);
    $body = $response->getData(true);

    expect($response->getStatusCode())->toBe(200);

    // success() wraps directly: body = ['categories' => [...], 'uncategorised_services' => [...], 'filters' => [...]].
    // All services are uncategorised (no category_id). Count them across both buckets.
    $uncategorisedCount = count($body['uncategorised_services'] ?? []);
    $categorisedCount = collect($body['categories'] ?? [])->sum(fn ($cat) => count($cat['services']));

    expect($uncategorisedCount + $categorisedCount)->toBe(500);
});

it('caps the grouped categories list at 200 even when more than 200 exist', function () {
    $pro = makeStaffServicePro();
    // Services cutover: one id space, so the cap bounds content.collections
    // alone. A handful go through the real writer as well, to prove the
    // bulk-inserted shape and the written one are read the same way.
    bulkSeedServiceCategories($pro->id, 205);
    $collections = app(ServiceCollections::class);
    for ($i = 0; $i < 5; $i++) {
        $collections->create($pro->id, "Collection {$i}");
    }

    $controller = new StaffServiceManagementController;
    $request = Request::create('/', 'GET', ['grouped' => '1']);
    $request->attributes->set('partna_staff', tap(new PartnaStaff, fn ($s) => $s->role = PartnaStaff::ROLE_ADMIN));
    $response = $controller->index($request, $pro);
    $body = $response->getData(true);

    expect($response->getStatusCode())->toBe(200);
    // success() wraps directly: body = ['categories' => [...], ...].
    expect($body['categories'])->toHaveCount(200);
});
