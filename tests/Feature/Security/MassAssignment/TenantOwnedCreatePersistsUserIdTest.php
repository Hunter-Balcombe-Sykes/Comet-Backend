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
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupServicesTable(); // also creates site.service_categories
    setupCustomersTable();
    setupPartnaStaffTable();
    // Slice 3a Task 5: POST /api/services now creates a content.* item, not
    // a site.services row.
    setupIngestTables();
    setupContentTables();
    setupBlocksTable();
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
    // Slice 3a Task 5: owner-authored services are created in content.* now
    // (ProjectionWriter::createItem() — a raw insert, not Eloquent mass
    // assignment) rather than site.services, so this asserts the id the
    // create response actually returned resolves to the right owner in the
    // new backing store, not a stray/spoofed one.
    $pro = createTenant('mass-svc-user');

    $response = actingAsUser($pro)->postJson('/api/services', [
        'title' => 'Haircut',
        'price_cents' => 5000,
    ])->assertStatus(201);

    $itemId = $response->json('service.id');
    expect(DB::table('content.items')->where('id', $itemId)->value('user_id'))->toBe($pro->id);
});

it('persists user_id on a service category created via POST /api/service-categories', function () {
    // Slice 3b Task 9: service categories are created in content.collections
    // now (ServiceCollections::create() — a raw insert, not Eloquent mass
    // assignment) rather than site.service_categories, so this asserts the id
    // the create response actually returned resolves to the right owner in the
    // new backing store, not a stray/spoofed one. Same shape as the
    // /api/services sibling above, which slice 3a moved for the same reason.
    $pro = createTenant('mass-cat-user');

    $response = actingAsUser($pro)->postJson('/api/service-categories', [
        'title' => 'Hair',
    ])->assertStatus(201);

    $collectionId = $response->json('category.id');
    expect(DB::table('content.collections')->where('id', $collectionId)->value('user_id'))->toBe($pro->id);
});

it('persists user_id on a service created by staff via POST /api/staff/professionals/{professional}/services', function () {
    // Slice 3b Task 11: the STAFF create moved to content.* too, for the same
    // reason and in the same shape as its /api/services sibling above — so
    // this asserts the id the create response actually returned resolves to
    // the right owner in the new backing store. The old
    // `Service::where('user_id', $pro->id)->firstOrFail()` was doubly wrong
    // now: it looks in the table staff no longer writes, AND it resolved the
    // row BY the owner it was about to assert, so it could never have caught a
    // create that landed under someone else's id.
    $pro = createTenant('mass-svc-staff');

    $response = actingAsStaff(massAssignTest_adminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services", [
            'title' => 'Massage',
            'price_cents' => 8000,
        ])->assertStatus(201);

    $itemId = $response->json('service.id');
    expect(DB::table('content.items')->where('id', $itemId)->value('user_id'))->toBe($pro->id);
});

it('persists user_id on a service category created by staff via POST /api/staff/professionals/{professional}/service-categories', function () {
    // Slice 3b Task 11b: the STAFF category create moved to content.collections
    // too, for the same reason and in the same shape as its /api/services
    // sibling above — so this asserts the id the create response actually
    // returned resolves to the right owner in the new backing store. The old
    // `ServiceCategory::where('user_id', $pro->id)->firstOrFail()` was doubly
    // wrong now: it looks in the table staff no longer writes, AND it resolved
    // the row BY the owner it was about to assert, so it could never have
    // caught a create that landed under someone else's id.
    $pro = createTenant('mass-cat-staff');

    $response = actingAsStaff(massAssignTest_adminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/service-categories", [
            'title' => 'Wellness',
        ])->assertStatus(201);

    $collectionId = $response->json('category.id');
    expect(DB::table('content.collections')->where('id', $collectionId)->value('user_id'))->toBe($pro->id);
});
