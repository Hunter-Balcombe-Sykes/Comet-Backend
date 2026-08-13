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
 * The ServiceCategory actor-gate cases below run through the Gate directly.
 * They were originally Gate-only because `{category}` route-model-binding was
 * broken: Laravel's scoped-binding convention resolved the parent relation as
 * `Str::plural(Str::camel('category'))` = `categories()`, but User's relation
 * is named `serviceCategories()`, so every staff route with a `{category}`
 * segment 500'd with "Call to undefined method User::categories()". That bug
 * is now fixed (DISC-5: the route param + controller arg were renamed to
 * `{serviceCategory}`, matching the relation); real HTTP-level binding
 * coverage lives in StaffServiceCategoryRouteBindingTest. The cases here
 * remain the actor-authorization coverage for these controllers.
 */

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupPartnaStaffTable();
    setupCustomersTable();
    setupServicesTable();
    // Slice 3b Task 11: the staff service routes read and write content.*
    // (items, source_items, sources) plus the services pool section in
    // site.sections, and re-evaluate the booking/services Block gates.
    setupIngestTables();
    setupContentTables();
    setupBlocksTable();
    shimPgAdvisoryLockForSqlite();
    // Every staff service write now fires the edge-purge lane.
    Queue::fake();

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

/**
 * A service for $pro that the staff routes can actually resolve, created
 * through the staff endpoint itself, returned as its content.items id.
 *
 * Slice 3b Task 11: `createServiceFor()` mints a `site.services` row with
 * `source IS NULL`. Post-cutover that row is the legacy shadow of a
 * content.* item, superseded by its projection and deliberately unreachable
 * through every staff service route (exactly as it already is through the
 * owner's own — UserServiceController scopes its fallback
 * `whereNotNull('source')` too). It therefore cannot serve as the fixture for
 * a POSITIVE control here: every such case would 404 regardless of the actor,
 * which is precisely the state that makes a "support staff is denied" case
 * pass while proving nothing.
 *
 * Created as ADMIN because the create route sits in the staff.admin group.
 * The caller's own actingAsStaff() re-binds the middleware afterwards, so the
 * role under test is unaffected by this setup call.
 */
function secTwoTest_service(User $pro): string
{
    return (string) actingAsStaff(secTwoTest_adminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services", [
            'title' => 'Actor gate fixture',
            'price_cents' => 1000,
        ])
        ->assertStatus(201)
        ->json('service.id');
}

/** Soft-delete a content.* service the way the staff route does — items.removed_at. */
function secTwoTest_removeService(User $pro, string $serviceId): void
{
    actingAsStaff(secTwoTest_adminStaff())
        ->deleteJson("/api/staff/professionals/{$pro->id}/services/{$serviceId}")
        ->assertStatus(200);
}

/** Whether a content.* service is currently removed. */
function secTwoTest_serviceIsRemoved(string $serviceId): bool
{
    return DB::connection('pgsql')->table('content.items')->where('id', $serviceId)->value('removed_at') !== null;
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
    $serviceId = secTwoTest_service($pro);
    secTwoTest_removeService($pro, $serviceId);

    actingAsStaff(secTwoTest_supportStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services/{$serviceId}/restore")
        ->assertStatus(403);

    // The EFFECT, not only the status: a refusal that still restored the row
    // would be the same bug with a nicer response code.
    expect(secTwoTest_serviceIsRemoved($serviceId))->toBeTrue();
});

it('admin staff can restore a service', function () {
    $pro = createTenant('sec2-svc-admin');
    $serviceId = secTwoTest_service($pro);
    secTwoTest_removeService($pro, $serviceId);

    actingAsStaff(secTwoTest_adminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services/{$serviceId}/restore")
        ->assertStatus(200);

    // The positive control has to actually SUCCEED, not merely answer 200 —
    // this is the half whose death makes the 403 case above vacuous.
    expect(secTwoTest_serviceIsRemoved($serviceId))->toBeFalse();
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
    $serviceId = secTwoTest_service($pro);

    actingAsStaff(secTwoTest_supportStaff())
        ->getJson("/api/staff/professionals/{$pro->id}/services/{$serviceId}")
        ->assertStatus(200)
        // A 200 carrying the wrong (or no) service would still pass a bare
        // status check — name the row that came back.
        ->assertJsonPath('service.id', $serviceId);
});

// #SEC-2 also covered: reorder()/reorderLayout() had zero authorization at
// all. Both sit in the staff.admin route group, so a support-role staffer
// is denied by middleware before reaching the controller — assert that
// boundary holds (proves the route wiring, not the new Policy call, since
// the Policy call is defence-in-depth for a bypass of that middleware).

it('support staff is denied 403 on the service reorder endpoint (staff.admin route group)', function () {
    $pro = createTenant('sec2-svc-reorder');
    $serviceId = secTwoTest_service($pro);

    actingAsStaff(secTwoTest_supportStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services/reorder", ['ids' => [$serviceId]])
        ->assertStatus(403);
});

it('admin staff can reorder services', function () {
    $pro = createTenant('sec2-svc-reorder-admin');
    $first = secTwoTest_service($pro);
    $second = secTwoTest_service($pro);

    actingAsStaff(secTwoTest_adminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services/reorder", ['ids' => [$second, $first]])
        ->assertStatus(200);

    // The positive control has to move something: two services, submitted
    // reversed, must come back reversed.
    $order = collect(actingAsStaff(secTwoTest_adminStaff())
        ->getJson("/api/staff/professionals/{$pro->id}/services")
        ->assertStatus(200)
        ->json('services'))->pluck('id')->all();

    expect($order)->toBe([$second, $first]);
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
