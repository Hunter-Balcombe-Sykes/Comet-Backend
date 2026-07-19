<?php

use App\Models\Core\User\Service;
use Illuminate\Support\Facades\DB;

// PATCH /api/services/{service}/category (UserServiceController::updateCategory) —
// re-files one manual service into a different category (or Uncategorized) and
// appends it at max(sort_order)+1 across the owner's live services, under the
// same advisory-lock key reorderLayout() uses.
beforeEach(function () {
    tenantHelpersEnsureTables();
    setupServicesTable();
    setupServiceCategoriesTable();

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

it('assigns a service to one of the owner\'s categories', function () {
    $pro = createTenant('svc-cat-happy');
    $cat = createServiceCategoryFor($pro, ['sort_order' => 0]);
    $service = createServiceFor($pro, ['category_id' => null, 'sort_order' => 0]);

    $response = actingAsUser($pro)->patchJson("/api/services/{$service->id}/category", [
        'category_id' => $cat->id,
    ]);

    $response->assertOk();
    expect((string) $response->json('service.category_id'))->toBe((string) $cat->id);

    $service->refresh();
    expect((string) $service->category_id)->toBe((string) $cat->id);
});

it('moves a service to Uncategorized when category_id is null', function () {
    $pro = createTenant('svc-cat-null');
    $cat = createServiceCategoryFor($pro, ['sort_order' => 0]);
    $service = createServiceFor($pro, ['category_id' => $cat->id, 'sort_order' => 0]);

    $response = actingAsUser($pro)->patchJson("/api/services/{$service->id}/category", [
        'category_id' => null,
    ]);

    $response->assertOk();
    expect($response->json('service.category_id'))->toBeNull();

    $service->refresh();
    expect($service->category_id)->toBeNull();
});

it('rejects assigning the owner\'s service to another owner\'s category (422)', function () {
    $owner = createTenant('svc-cat-foreign-owner');
    $other = createTenant('svc-cat-foreign-other');
    $foreignCat = createServiceCategoryFor($other, ['sort_order' => 0]);
    $service = createServiceFor($owner, ['category_id' => null, 'sort_order' => 0]);

    $response = actingAsUser($owner)->patchJson("/api/services/{$service->id}/category", [
        'category_id' => $foreignCat->id,
    ]);

    // assertCategoryBelongsToProfessional() aborts 422 — the category isn't the owner's.
    $response->assertStatus(422);

    $service->refresh();
    expect($service->category_id)->toBeNull();
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

    $service->refresh();
    expect($service->category_id)->toBeNull();
});

it('appends the moved service at global max(sort_order)+1', function () {
    $pro = createTenant('svc-cat-append');
    $cat = createServiceCategoryFor($pro, ['sort_order' => 0]);

    // Three live services occupying 0,1,2 globally; the one at 0 moves into $cat.
    $mover = createServiceFor($pro, ['category_id' => null, 'sort_order' => 0]);
    createServiceFor($pro, ['category_id' => null, 'sort_order' => 1]);
    createServiceFor($pro, ['category_id' => null, 'sort_order' => 2]);

    $response = actingAsUser($pro)->patchJson("/api/services/{$mover->id}/category", [
        'category_id' => $cat->id,
    ]);

    $response->assertOk();

    $mover->refresh();
    expect((string) $mover->category_id)->toBe((string) $cat->id);
    // max live sort_order was 2 → mover appends at 3.
    expect($mover->sort_order)->toBe(3);

    // No collision: every live service keeps a globally-unique sort_order.
    $sortOrders = Service::query()->where('user_id', $pro->id)->pluck('sort_order');
    expect($sortOrders->unique())->toHaveCount($sortOrders->count());
});

it('coexists with reorder-layout under the shared advisory-lock key', function () {
    $pro = createTenant('svc-cat-coexist');
    $catA = createServiceCategoryFor($pro, ['sort_order' => 0]);
    $catB = createServiceCategoryFor($pro, ['sort_order' => 1]);
    // services_pro_sort_order_uq is GLOBAL per user — distinct starting sort_orders.
    $s1 = createServiceFor($pro, ['category_id' => $catA->id, 'sort_order' => 0]);
    $s2 = createServiceFor($pro, ['category_id' => $catA->id, 'sort_order' => 1]);

    // 1) PATCH moves $s2 from A to B, appending at global max+1 = 2.
    actingAsUser($pro)->patchJson("/api/services/{$s2->id}/category", [
        'category_id' => $catB->id,
    ])->assertOk();

    $s2->refresh();
    expect((string) $s2->category_id)->toBe((string) $catB->id);
    expect($s2->sort_order)->toBe(2);

    // 2) A full layout save afterwards still succeeds and stays collision-free —
    //    same lock key means the two write paths never interleave in production.
    $response = actingAsUser($pro)->postJson('/api/services/reorder-layout', [
        'categories' => [
            ['id' => $catA->id, 'service_ids' => [$s1->id]],
            ['id' => $catB->id, 'service_ids' => [$s2->id]],
        ],
    ]);
    $response->assertOk();

    $sortOrders = Service::query()->where('user_id', $pro->id)->pluck('sort_order');
    expect($sortOrders->unique())->toHaveCount(2);
    $s2->refresh();
    expect((string) $s2->category_id)->toBe((string) $catB->id);
});
