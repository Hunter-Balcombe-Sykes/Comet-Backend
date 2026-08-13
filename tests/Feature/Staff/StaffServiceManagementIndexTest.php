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
    // Slice 3b Task 11: index() merges the owner-authored half out of
    // content.* (scoped to the site's services pool section) with the Fresha
    // half still in site.services, and its grouped branch lists categories
    // from BOTH content.collections and site.service_categories.
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
 * Bulk-seed $count services the merged staff list can actually see.
 *
 * Slice 3b Task 11: `source` is now `'fresha'`, not NULL. index() merges the
 * owner-authored half out of content.* with the Fresha half out of
 * `site.services WHERE source IS NOT NULL`; a `source IS NULL` row is the
 * legacy shadow of a content.* item, superseded by its projection and
 * deliberately never read from there again (UserServiceController made the
 * same call in slice 3a). Seeding 510 of those would have left the list EMPTY
 * and the cap trivially satisfied — the exact shape of test that reads green
 * while proving nothing.
 *
 * The Fresha half is bulk-inserted because it is a plain table write; the
 * content.* half is seeded separately (seedManualService()) because it must go
 * through the real projection writer. The cap is asserted over the MERGE of
 * the two, which is what it now actually bounds.
 */
function bulkSeedServices(string $userId, int $count): void
{
    $now = now()->toDateTimeString();
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'title' => "Service {$i}",
            'price_cents' => 1000,
            'currency_code' => 'AUD',
            'is_active' => 1,
            'sort_order' => $i,
            'source' => 'fresha',
            'external_id' => "s:{$i}",
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    // Chunked insert to avoid SQLite variable limit per statement.
    foreach (array_chunk($rows, 50) as $chunk) {
        DB::connection('pgsql')->table('site.services')->insert($chunk);
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

function bulkSeedServiceCategories(string $userId, int $count): void
{
    $now = now()->toDateTimeString();
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'id' => (string) Str::uuid(),
            'user_id' => $userId,
            'title' => "Category {$i}",
            'sort_order' => $i,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
    foreach (array_chunk($rows, 50) as $chunk) {
        DB::connection('pgsql')->table('site.service_categories')->insert($chunk);
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
    bulkSeedServiceCategories($pro->id, 210);
    // Slice 3b Task 11: the grouped branch lists BOTH id spaces —
    // content.collections (where the owner's categories now live) AND the
    // legacy site.service_categories the Fresha half still points at. The cap
    // bounds the concatenation, so seed a few of the other kind too or the
    // case only ever proves the legacy half is bounded.
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
