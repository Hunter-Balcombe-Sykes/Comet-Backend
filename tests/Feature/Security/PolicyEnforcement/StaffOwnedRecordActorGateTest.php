<?php

/**
 * #SEC-2 — StaffCustomerManagementController / StaffServiceManagementController /
 * StaffServiceCategoryManagementController previously authorized the
 * PROFESSIONAL who owns the record, not the STAFF ACTOR making the request
 * (`authorizeForUser($professional, 'view'|'update', $resource)`). Because a
 * professional always owns their own customer/service/category rows, that
 * check was tautologically true — it authorized nothing.
 *
 * restore() is the clearest before/after case: it lives in the NON-admin
 * staff route group (any staff role reaches the controller), so before the
 * fix a support-role staffer's request would have passed the tautological
 * check and restored the record. After the fix, restore() is gated by
 * UserSelfPolicy::staffManage (admin-only) — the same policy-is-the-actual-
 * enforcement-point precedent StaffUserController::destroy/restore already
 * established for the User model itself.
 *
 * ServiceCategory routes are tested through the Gate directly rather than
 * HTTP: `{category}` implicit route-model-binding is broken independent of
 * this fix — Laravel's scoped-binding convention resolves the parent
 * relation as `Str::plural(Str::camel('category'))` = `categories()`, but
 * User's relation is named `serviceCategories()`, so EVERY staff route with
 * a `{category}` segment currently 500s with "Call to undefined method
 * User::categories()" (confirmed pre-existing on unmodified
 * routes/api/staff.php + User.php — out of this bundle's scope; flagged
 * separately, not fixed here).
 */

use App\Models\Core\Staff\PartnaStaff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    setupPartnaStaffTable();
    setupCustomersTable();
    setupServicesTable();
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

function secTwoTest_supportStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->role = PartnaStaff::ROLE_SUPPORT;
    $staff->primary_email = 'support@partna.au';

    return $staff;
}

function secTwoTest_adminStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $staff->primary_email = 'admin@partna.au';

    return $staff;
}

/** Soft-delete a row after creation (not at insert time — Eloquent's default
 * SoftDeletes scope would exclude it from the immediate findOrFail() the
 * create*For() helpers do). */
function secTwoTest_softDelete(string $table, string $id): void
{
    DB::connection('pgsql')->table($table)->where('id', $id)
        ->update(['deleted_at' => now()->toDateTimeString()]);
}

// ── Customer restore ────────────────────────────────────────────────────

it('support staff is denied 403 restoring a customer (staffManage is admin-only)', function () {
    $pro = createTenant('sec2-cust-support');
    $customer = createCustomerFor($pro);
    secTwoTest_softDelete('site.customers', $customer->id);

    actingAsStaff(secTwoTest_supportStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/customers/{$customer->id}/restore")
        ->assertStatus(403);
});

it('admin staff can restore a customer', function () {
    $pro = createTenant('sec2-cust-admin');
    $customer = createCustomerFor($pro);
    secTwoTest_softDelete('site.customers', $customer->id);

    actingAsStaff(secTwoTest_adminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/customers/{$customer->id}/restore")
        ->assertStatus(200);

    expect(DB::connection('pgsql')->table('site.customers')->where('id', $customer->id)->first()->deleted_at)
        ->toBeNull();
});

it('a non-staff user is rejected before reaching the controller at all', function () {
    $pro = createTenant('sec2-cust-intruder');
    $customer = createCustomerFor($pro);
    secTwoTest_softDelete('site.customers', $customer->id);

    actingAsUser($pro)
        ->postJson("/api/staff/professionals/{$pro->id}/customers/{$customer->id}/restore")
        ->assertStatus(403);
});

// ── Service restore ─────────────────────────────────────────────────────

it('support staff is denied 403 restoring a service', function () {
    $pro = createTenant('sec2-svc-support');
    $service = createServiceFor($pro);
    secTwoTest_softDelete('site.services', $service->id);

    actingAsStaff(secTwoTest_supportStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services/{$service->id}/restore")
        ->assertStatus(403);
});

it('admin staff can restore a service', function () {
    $pro = createTenant('sec2-svc-admin');
    $service = createServiceFor($pro);
    secTwoTest_softDelete('site.services', $service->id);

    actingAsStaff(secTwoTest_adminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services/{$service->id}/restore")
        ->assertStatus(200);
});

// ── show() stays any-staff (staffView) — non-regression check ──────────
// Unlike restore(), show() is a plain read: it should still be reachable by
// any staff role, matching the read-surface convention used everywhere else
// in this route group (#SEC-5). Only the ACTOR changed (staff, not the
// professional) — not who is allowed.

it('support staff can still view a customer (staffView, any role)', function () {
    $pro = createTenant('sec2-cust-show-support');
    $customer = createCustomerFor($pro);

    actingAsStaff(secTwoTest_supportStaff())
        ->getJson("/api/staff/professionals/{$pro->id}/customers/{$customer->id}")
        ->assertStatus(200);
});

it('support staff can still view a service', function () {
    $pro = createTenant('sec2-svc-show-support');
    $service = createServiceFor($pro);

    actingAsStaff(secTwoTest_supportStaff())
        ->getJson("/api/staff/professionals/{$pro->id}/services/{$service->id}")
        ->assertStatus(200);
});

// #SEC-2 also covered: reorder()/reorderLayout() had zero authorization at
// all. Both sit in the staff.admin route group, so a support-role staffer
// is denied by middleware before reaching the controller — assert that
// boundary holds (proves the route wiring, not the new Policy call, since
// the Policy call is defence-in-depth for a bypass of that middleware).

it('support staff is denied 403 on the service reorder endpoint (staff.admin route group)', function () {
    $pro = createTenant('sec2-svc-reorder');
    $service = createServiceFor($pro);

    actingAsStaff(secTwoTest_supportStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services/reorder", ['ids' => [$service->id]])
        ->assertStatus(403);
});

it('admin staff can reorder services', function () {
    $pro = createTenant('sec2-svc-reorder-admin');
    $service = createServiceFor($pro);

    actingAsStaff(secTwoTest_adminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services/reorder", ['ids' => [$service->id]])
        ->assertStatus(200);
});

// ── ServiceCategory: Gate-level proof (HTTP blocked by the pre-existing
//    {category} binding bug documented in the file header) ────────────────

it('staffManage denies support staff and allows admin staff for ServiceCategory operations (professional target)', function () {
    $pro = createTenant('sec2-cat-gate');

    expect(Gate::forUser(secTwoTest_supportStaff())->allows('staffManage', $pro))->toBeFalse();
    expect(Gate::forUser(secTwoTest_adminStaff())->allows('staffManage', $pro))->toBeTrue();
});

it('staffView allows any staff role for ServiceCategory reads (professional target)', function () {
    $pro = createTenant('sec2-cat-view-gate');

    expect(Gate::forUser(secTwoTest_supportStaff())->allows('staffView', $pro))->toBeTrue();
    expect(Gate::forUser(secTwoTest_adminStaff())->allows('staffView', $pro))->toBeTrue();
});

it('a request with no staff actor at all is rejected by the Gate outright', function () {
    $pro = createTenant('sec2-cat-nonstaff-gate');

    // Mirrors the real failure mode: $request->attributes->get('partna_staff')
    // returns null for any caller the staff middleware didn't authenticate.
    expect(Gate::forUser(null)->allows('staffManage', $pro))->toBeFalse();
});
