<?php

/**
 * B11 (SEC-1): Customer/Service/ServiceCategory no longer carry user_id in
 * $fillable — every create path must set it via the owning relation's
 * create() (FK set directly, bypassing $fillable) rather than mass-assignment.
 * Existence-only assertions ("a row exists") would pass even if user_id
 * silently dropped to NULL on SQLite's permissive schema — these assert the
 * PERSISTED value instead.
 */

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\Customer;
use App\Models\Core\User\Service;
use App\Models\Core\User\ServiceCategory;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupServicesTable(); // also creates site.service_categories
    setupCustomersTable();
    setupPartnaStaffTable();
    shimPgAdvisoryLockForSqlite(); // InsertWithSortOrder takes pg_advisory_xact_lock

    // staff.audit middleware writes here on every staff write request.
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

function massAssignTest_adminStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $staff->primary_email = 'admin@partna.au';

    return $staff;
}

it('persists user_id on a customer created via POST /api/customers', function () {
    $pro = createTenant('mass-cust-user');

    actingAsUser($pro)->postJson('/api/customers', [
        'full_name' => 'Jane Visitor',
        'email' => 'jane@example.test',
    ])->assertStatus(201);

    $customer = Customer::query()->where('user_id', $pro->id)->firstOrFail();
    expect($customer->fresh()->user_id)->toBe($pro->id);
});

it('persists user_id on a service created via POST /api/services', function () {
    $pro = createTenant('mass-svc-user');

    actingAsUser($pro)->postJson('/api/services', [
        'title' => 'Haircut',
        'price_cents' => 5000,
    ])->assertStatus(201);

    $service = Service::query()->where('user_id', $pro->id)->firstOrFail();
    expect($service->fresh()->user_id)->toBe($pro->id);
});

it('persists user_id on a service category created via POST /api/service-categories', function () {
    $pro = createTenant('mass-cat-user');

    actingAsUser($pro)->postJson('/api/service-categories', [
        'title' => 'Hair',
    ])->assertStatus(201);

    $category = ServiceCategory::query()->where('user_id', $pro->id)->firstOrFail();
    expect($category->fresh()->user_id)->toBe($pro->id);
});

it('persists user_id on a service created by staff via POST /api/staff/professionals/{professional}/services', function () {
    $pro = createTenant('mass-svc-staff');

    actingAsStaff(massAssignTest_adminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services", [
            'title' => 'Massage',
            'price_cents' => 8000,
        ])->assertStatus(201);

    $service = Service::query()->where('user_id', $pro->id)->firstOrFail();
    expect($service->fresh()->user_id)->toBe($pro->id);
});

it('persists user_id on a service category created by staff via POST /api/staff/professionals/{professional}/service-categories', function () {
    $pro = createTenant('mass-cat-staff');

    actingAsStaff(massAssignTest_adminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/service-categories", [
            'title' => 'Wellness',
        ])->assertStatus(201);

    $category = ServiceCategory::query()->where('user_id', $pro->id)->firstOrFail();
    expect($category->fresh()->user_id)->toBe($pro->id);
});
