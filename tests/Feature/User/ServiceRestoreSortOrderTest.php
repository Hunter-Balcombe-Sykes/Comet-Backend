<?php

use App\Http\Controllers\Api\User\SiteManagement\UserServiceController;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupServicesTable();
    setupServiceCategoriesTable();

    // Mirror the production partial unique index so the constraint fires during tests.
    // SQLite (used in test env) requires schema prefix on the index name, not the table name.
    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS site.services_pro_sort_order_uq
         ON services (user_id, sort_order)
         WHERE deleted_at IS NULL'
    );
});

it('restores a service without 500 when another service claimed its freed sort_order', function () {
    $owner = createTenant('svc-restore-sort-order-cr004');

    // Service A in category A gets sort_order=0, then is soft-deleted, freeing that slot.
    $catA = createServiceCategoryFor($owner, ['sort_order' => 0]);
    $serviceA = createServiceFor($owner, ['category_id' => $catA->id, 'sort_order' => 0]);
    $serviceA->delete();

    // Service B in category B claims sort_order=0 (the freed slot).
    $catB = createServiceCategoryFor($owner, ['sort_order' => 1]);
    createServiceFor($owner, ['category_id' => $catB->id, 'sort_order' => 0]);

    // Restoring service A must not crash and must land on sort_order=1.
    $req = tenantRequestAs($owner, [], 'POST');
    $response = app(UserServiceController::class)->restore($req, $serviceA);

    expect($response->getStatusCode())->toBe(200);

    $serviceA->refresh();
    expect($serviceA->deleted_at)->toBeNull();
    expect($serviceA->sort_order)->toBe(1);
});
