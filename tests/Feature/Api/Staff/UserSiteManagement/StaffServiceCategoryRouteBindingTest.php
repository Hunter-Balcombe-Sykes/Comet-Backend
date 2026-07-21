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
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupPartnaStaffTable();
    setupServiceCategoriesTable();

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
    $category = createServiceCategoryFor($pro, ['title' => 'Haircuts']);

    actingAsStaff($staff)
        ->getJson("/api/staff/professionals/{$pro->id}/service-categories/{$category->id}")
        ->assertOk()
        ->assertJsonPath('category.id', $category->id);
});

it('updates a category owned by the professional (PATCH update)', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    $pro = createTenant('cat-bind-update');
    $category = createServiceCategoryFor($pro, ['title' => 'Old Title']);

    actingAsStaff($staff)
        ->patchJson("/api/staff/professionals/{$pro->id}/service-categories/{$category->id}", ['title' => 'Renamed'])
        ->assertOk()
        ->assertJsonPath('category.title', 'Renamed');

    expect(DB::connection('pgsql')->table('site.service_categories')->where('id', $category->id)->value('title'))
        ->toBe('Renamed');
});

it('404s a category that belongs to a DIFFERENT professional (scoped-binding tenant isolation)', function () {
    $staff = PartnaStaff::factory()->admin()->create();
    $proA = createTenant('cat-bind-tenant-a');
    $proB = createTenant('cat-bind-tenant-b');
    $categoryB = createServiceCategoryFor($proB, ['title' => 'Belongs to B']);

    actingAsStaff($staff)
        ->getJson("/api/staff/professionals/{$proA->id}/service-categories/{$categoryB->id}")
        ->assertStatus(404);
});
