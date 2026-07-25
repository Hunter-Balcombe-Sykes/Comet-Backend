<?php

/**
 * #SVC-1 — Staff service create/update parity with the professional-facing
 * multi-category support (20260721180000): staff Requests now accept
 * category_ids (array) alongside the legacy single category_id, and
 * StaffServiceManagementController::store()/update() attach/sync ALL supplied
 * ids through the site.service_category_assignments pivot, asserting
 * ownership of EVERY id — not just the first — before touching the pivot.
 *
 * Route-level (real FormRequest validation), per this audit's own lesson that
 * controller-only tests with mocked FormRequests let a broken validation rule
 * go unnoticed for two months.
 */

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\Service;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupServicesTable();
    setupPartnaStaffTable();
    shimPgAdvisoryLockForSqlite();

    // staff.audit middleware writes here on every write request.
    DB::connection('pgsql')->statement('CREATE TABLE IF NOT EXISTS audit.staff_audit_log (
        id TEXT PRIMARY KEY,
        staff_id TEXT,
        staff_email_snapshot TEXT,
        impersonator_staff_id TEXT,
        impersonator_email_snapshot TEXT,
        user_id TEXT,
        professional_handle_snapshot TEXT,
        route TEXT NOT NULL DEFAULT \'\',
        http_method TEXT NOT NULL DEFAULT \'\',
        status_code INTEGER NOT NULL DEFAULT 0,
        payload_summary TEXT NOT NULL DEFAULT \'{}\',
        ip_hash TEXT,
        user_agent TEXT,
        created_at TEXT
    )');
});

function svcMultiCatTest_adminStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $staff->primary_email = 'admin@partna.au';

    return $staff;
}

function svcMultiCatTest_categoryIds(Service $service): array
{
    return $service->categories()->pluck('site.service_categories.id')->map(fn ($id) => (string) $id)->sort()->values()->all();
}

it('staff can create a service with multiple category_ids', function () {
    $pro = createTenant('svcmc-store');
    $catA = createServiceCategoryFor($pro);
    $catB = createServiceCategoryFor($pro);

    $response = actingAsStaff(svcMultiCatTest_adminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services", [
            'title' => 'Multi-cat service',
            'price_cents' => 5000,
            'category_ids' => [$catA->id, $catB->id],
        ])
        ->assertStatus(201);

    $serviceId = $response->json('service.id');
    $service = Service::query()->findOrFail($serviceId);

    expect(svcMultiCatTest_categoryIds($service))
        ->toEqualCanonicalizing([(string) $catA->id, (string) $catB->id]);
});

it('staff can update a service to multiple category_ids', function () {
    $pro = createTenant('svcmc-update');
    $catA = createServiceCategoryFor($pro);
    $catB = createServiceCategoryFor($pro);
    $catC = createServiceCategoryFor($pro);
    $service = createServiceFor($pro, ['category_id' => $catA->id]);

    actingAsStaff(svcMultiCatTest_adminStaff())
        ->patchJson("/api/staff/professionals/{$pro->id}/services/{$service->id}", [
            'category_ids' => [$catB->id, $catC->id],
        ])
        ->assertStatus(200);

    expect(svcMultiCatTest_categoryIds($service->fresh()))
        ->toEqualCanonicalizing([(string) $catB->id, (string) $catC->id]);
});

it('staff legacy single category_id still works on create (regression)', function () {
    $pro = createTenant('svcmc-legacy-store');
    $cat = createServiceCategoryFor($pro);

    $response = actingAsStaff(svcMultiCatTest_adminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services", [
            'title' => 'Legacy single-cat service',
            'price_cents' => 3000,
            'category_id' => $cat->id,
        ])
        ->assertStatus(201);

    $service = Service::query()->findOrFail($response->json('service.id'));

    expect(svcMultiCatTest_categoryIds($service))->toBe([(string) $cat->id]);
});

it('staff legacy single category_id still works on update and replaces the membership set (regression)', function () {
    $pro = createTenant('svcmc-legacy-update');
    $catA = createServiceCategoryFor($pro);
    $catB = createServiceCategoryFor($pro);
    $service = createServiceFor($pro, ['category_id' => $catA->id]);

    actingAsStaff(svcMultiCatTest_adminStaff())
        ->patchJson("/api/staff/professionals/{$pro->id}/services/{$service->id}", [
            'category_id' => $catB->id,
        ])
        ->assertStatus(200);

    expect(svcMultiCatTest_categoryIds($service->fresh()))->toBe([(string) $catB->id]);
});

it('rejects create when ANY supplied category_id belongs to a different professional', function () {
    $pro = createTenant('svcmc-store-foreign');
    $otherPro = createTenant('svcmc-store-other');
    $ownCat = createServiceCategoryFor($pro);
    $foreignCat = createServiceCategoryFor($otherPro);

    actingAsStaff(svcMultiCatTest_adminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services", [
            'title' => 'Should be rejected',
            'price_cents' => 4000,
            // foreign id is second in the list — proves every id is checked, not just the first.
            'category_ids' => [$ownCat->id, $foreignCat->id],
        ])
        ->assertStatus(422);

    expect(Service::query()->where('user_id', $pro->id)->count())->toBe(0);
});

it('rejects update when ANY supplied category_id belongs to a different professional', function () {
    $pro = createTenant('svcmc-update-foreign');
    $otherPro = createTenant('svcmc-update-other');
    $ownCatA = createServiceCategoryFor($pro);
    $ownCatB = createServiceCategoryFor($pro);
    $foreignCat = createServiceCategoryFor($otherPro);
    $service = createServiceFor($pro, ['category_id' => $ownCatA->id]);

    actingAsStaff(svcMultiCatTest_adminStaff())
        ->patchJson("/api/staff/professionals/{$pro->id}/services/{$service->id}", [
            // foreign id is second in the list — proves every id is checked, not just the first.
            'category_ids' => [$ownCatB->id, $foreignCat->id],
        ])
        ->assertStatus(422);

    // Membership must be untouched — the rejected write must not have synced.
    expect(svcMultiCatTest_categoryIds($service->fresh()))->toBe([(string) $ownCatA->id]);
});
