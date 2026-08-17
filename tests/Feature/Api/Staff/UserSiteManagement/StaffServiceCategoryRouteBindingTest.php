<?php

// DISC-5 (P2): staff service-category routes bind `{category}` inside a
// ->scopeBindings() group. Laravel resolves a scoped child through the
// parent's relation named Str::plural(Str::camel('category')) = categories()
// — but User only defines serviceCategories(), so show/update/destroy/
// forceDestroy/restore 500 with "Call to undefined method
// App\Models\Core\User\User::categories()" in production. index/store/reorder
// have no {category} segment and are unaffected.
//
// Fix: rename the route param + controller arg to {serviceCategory}, matching
// the User relation name. This test drives the real HTTP pipeline (scoped
// binding + staff middleware) rather than the Gate — StaffOwnedRecordActorGateTest
// documents the pre-fix Gate-only workaround this replaces with real coverage.

use App\Models\Core\Staff\PartnaStaff;
use App\Services\Content\ServiceCollections;
use Illuminate\Support\Facades\DB;

// Slice 3b Task 11b: the controller resolves {serviceCategory} through
// ServiceCollections (content.collections).
//
// Services cutover Task 8: the legacy site.service_categories fall-backs are
// deleted, so the fixtures below are collections. The subject is unchanged and
// is not about either store — it is that the scoped binding resolves at all
// rather than 500ing on User::categories().

beforeEach(function () {
    setupPartnaStaffTable();
    setupServiceCategoriesTable();
    setupIngestTables();
    setupContentTables();
    // The collections rename/reposition path takes
    // pg_advisory_xact_lock(hashtext(...)) — the legacy branch it replaced
    // took none, so this shim is new here.
    shimPgAdvisoryLockForSqlite();

    // staff.audit middleware (RecordStaffAuditEntry) writes here in terminate()
    // on every write request — mirrors StaffOwnedRecordActorGateTest's setup.
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

it('resolves and returns a category owned by the professional (GET show)', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('cat-bind-show');
    $categoryId = app(ServiceCollections::class)->create($pro->id, 'Haircuts');

    actingAsStaff($staff)
        ->getJson("/api/staff/professionals/{$pro->id}/service-categories/{$categoryId}")
        ->assertOk()
        ->assertJsonPath('category.id', $categoryId);
});

it('updates a category owned by the professional (PATCH update)', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('cat-bind-update');
    $categoryId = app(ServiceCollections::class)->create($pro->id, 'Old Title');

    actingAsStaff($staff)
        ->patchJson("/api/staff/professionals/{$pro->id}/service-categories/{$categoryId}", ['title' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('category.title', 'Renamed');

    expect(DB::connection('pgsql')->table('content.collections')->where('id', $categoryId)->value('label'))
        ->toBe('Renamed');
});

it('404s a category that belongs to a DIFFERENT professional (scoped-binding tenant isolation)', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    $proA = createTenant('cat-bind-tenant-a');
    $proB = createTenant('cat-bind-tenant-b');
    $categoryB = app(ServiceCollections::class)->create($proB->id, 'Belongs to B');

    actingAsStaff($staff)
        ->getJson("/api/staff/professionals/{$proA->id}/service-categories/{$categoryB}")
        ->assertStatus(404);
});
