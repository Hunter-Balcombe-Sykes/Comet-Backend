<?php

use App\Models\Core\User\Service;
use App\Models\Core\User\ServiceCategory;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;

// POST /api/services/reorder-layout (UserServiceController::reorderLayout) — full
// layout save (categories + the services within each). Regression coverage for a
// 500 (Postgres 23505) that fired whenever the layout had 2+ categories each
// holding a service: the per-category loop restarted `sort_order` at 0 for every
// bucket, but services_user_sort_order_uq is a partial UNIQUE index on
// (user_id, sort_order) WHERE deleted_at IS NULL — GLOBAL per professional, not
// per category — so two services in two different buckets both landing on
// sort_order=0 collided. service_categories carries no equivalent unique index
// (only a plain sort index + a unique-title index), so its sort_order write
// needed no equivalent fix.
//
// Slice 3a Task 5 (§C3 review fix): reorderLayout()'s category-block +
// sort_order/service_category_assignments machinery is now Fresha-only —
// owner-authored (source IS NULL) services moved to content.* and are
// reordered via section_items.sort_key instead, and carry no category
// concept at all. Every fixture below is explicitly 'source' => 'fresha' so
// it keeps exercising the SAME legacy code path this regression guard was
// written against (byte-for-byte restored post-cutover, per the review) —
// a source-less fixture would 422 here now (not a manual or Fresha id).
beforeEach(function () {
    tenantHelpersEnsureTables();
    setupServicesTable();
    setupServiceCategoriesTable();
    // reorderLayout() also touches content.*/site.sections regardless of
    // whether the professional has any owner-authored services — the
    // ManualServiceItems read that determines "how many manual ids must this
    // payload cover" (zero, here) still queries those tables.
    setupIngestTables();
    setupContentTables();
    setupBlocksTable();
    // reorderLayout() takes pg_advisory_xact_lock(hashtext(...)) — both Postgres-only;
    // shim them as SQLite UDFs so the real production code path runs under test.
    shimPgAdvisoryLockForSqlite();

    // Mirror the production partial unique index (see ServiceRestoreSortOrderTest)
    // so the collision actually fires in SQLite the way it does on Postgres.
    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS site.services_pro_sort_order_uq
         ON services (user_id, sort_order)
         WHERE deleted_at IS NULL'
    );
});

it('reorders a layout across two categories that each hold a service without a 500', function () {
    $pro = createTenant('svc-layout-two-cats');

    $catA = createServiceCategoryFor($pro, ['sort_order' => 0]);
    $catB = createServiceCategoryFor($pro, ['sort_order' => 1]);
    $serviceA = createServiceFor($pro, ['category_id' => $catA->id, 'sort_order' => 0, 'source' => 'fresha']);
    $serviceB = createServiceFor($pro, ['category_id' => $catB->id, 'sort_order' => 1, 'source' => 'fresha']);

    $response = actingAsUser($pro)->postJson('/api/services/reorder-layout', [
        'categories' => [
            ['id' => $catA->id, 'service_ids' => [$serviceA->id]],
            ['id' => $catB->id, 'service_ids' => [$serviceB->id]],
        ],
    ]);

    $response->assertOk();

    $serviceA->refresh();
    $serviceB->refresh();
    // Global running counter across buckets in payload order, not a per-bucket
    // restart — the two services must NOT both land on sort_order=0.
    expect([$serviceA->sort_order, $serviceB->sort_order])->toEqualCanonicalizing([0, 1]);
});

it('moves a service to a different category and persists the new category_id', function () {
    $pro = createTenant('svc-layout-move');

    $catA = createServiceCategoryFor($pro, ['sort_order' => 0]);
    $catB = createServiceCategoryFor($pro, ['sort_order' => 1]);
    // services_pro_sort_order_uq is GLOBAL per professional, not per category —
    // every fixture service below needs its own distinct starting sort_order.
    $stays = createServiceFor($pro, ['category_id' => $catA->id, 'sort_order' => 0, 'source' => 'fresha']);
    $moves = createServiceFor($pro, ['category_id' => $catA->id, 'sort_order' => 1, 'source' => 'fresha']);
    $resident = createServiceFor($pro, ['category_id' => $catB->id, 'sort_order' => 2, 'source' => 'fresha']);

    // $moves leaves category A for category B, landing after $resident.
    $response = actingAsUser($pro)->postJson('/api/services/reorder-layout', [
        'categories' => [
            ['id' => $catA->id, 'service_ids' => [$stays->id]],
            ['id' => $catB->id, 'service_ids' => [$resident->id, $moves->id]],
        ],
    ]);

    $response->assertOk();

    $moves->refresh();
    expect($moves->categories()->pluck('site.service_categories.id')->map(fn ($id) => (string) $id)->all())->toBe([(string) $catB->id]);

    // Every active service's sort_order is globally unique post-reorder.
    $sortOrders = Service::query()->where('user_id', $pro->id)->pluck('sort_order');
    expect($sortOrders->unique())->toHaveCount($sortOrders->count());
});

it('swaps the order of two services within a category and keeps sort_order globally unique', function () {
    $pro = createTenant('svc-layout-swap');

    $catA = createServiceCategoryFor($pro, ['sort_order' => 0]);
    $catB = createServiceCategoryFor($pro, ['sort_order' => 1]);
    // services_pro_sort_order_uq is GLOBAL per professional — distinct starting values.
    $first = createServiceFor($pro, ['category_id' => $catA->id, 'sort_order' => 0, 'source' => 'fresha']);
    $second = createServiceFor($pro, ['category_id' => $catA->id, 'sort_order' => 1, 'source' => 'fresha']);
    $other = createServiceFor($pro, ['category_id' => $catB->id, 'sort_order' => 2, 'source' => 'fresha']);

    // Swap $first/$second's relative order within category A; category B unchanged.
    $response = actingAsUser($pro)->postJson('/api/services/reorder-layout', [
        'categories' => [
            ['id' => $catA->id, 'service_ids' => [$second->id, $first->id]],
            ['id' => $catB->id, 'service_ids' => [$other->id]],
        ],
    ]);

    $response->assertOk();

    $first->refresh();
    $second->refresh();
    $other->refresh();

    // Both stayed in category A; $second now sorts ahead of $first.
    expect($first->categories()->pluck('site.service_categories.id')->map(fn ($id) => (string) $id)->all())->toBe([(string) $catA->id]);
    expect($second->categories()->pluck('site.service_categories.id')->map(fn ($id) => (string) $id)->all())->toBe([(string) $catA->id]);
    expect($second->sort_order)->toBeLessThan($first->sort_order);

    // Globally unique across all three active services (the fix's core guarantee).
    $sortOrders = Service::query()->where('user_id', $pro->id)->pluck('sort_order');
    expect($sortOrders->unique())->toHaveCount(3);

    // Category sort_order untouched by the fix — service_categories carries no
    // unique index, so it never needed the two-pass treatment.
    expect(ServiceCategory::withoutGlobalScopes()->whereKey($catA->id)->value('sort_order'))->toBe(0);
    expect(ServiceCategory::withoutGlobalScopes()->whereKey($catB->id)->value('sort_order'))->toBe(1);
});

// New coverage (Task 5 fix round): a manual (content.*) service in the SAME
// payload gets section_items.sort_key, never touches
// service_category_assignments, and is exempt from the "categorised or
// uncategorised, never both" check that governs the Fresha half.
it('reorders a manual service by sort_key alongside a Fresha layout in one request', function () {
    [$userId, $siteId] = seedUserWithSite();
    $pro = User::query()->with('site')->findOrFail($userId);

    $catA = createServiceCategoryFor($pro, ['sort_order' => 0]);
    $freshaService = createServiceFor($pro, ['category_id' => $catA->id, 'sort_order' => 0, 'source' => 'fresha']);
    $manualId = actingAsUser($pro)->postJson('/api/services', ['title' => 'Manual', 'price_cents' => 1000])
        ->assertCreated()->json('service.id');

    $response = actingAsUser($pro)->postJson('/api/services/reorder-layout', [
        'categories' => [
            ['id' => $catA->id, 'service_ids' => [$freshaService->id]],
            ['id' => null, 'service_ids' => [$manualId]],
        ],
    ]);

    $response->assertOk();

    expect(DB::table('site.section_items')->where('item_id', $manualId)->value('sort_key'))->not->toBeNull();
    expect(DB::table('site.service_category_assignments')->where('service_id', $manualId)->exists())->toBeFalse();
});
