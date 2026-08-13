<?php

/**
 * Fix C (whole-branch review pt.2) — unify the service-layout advisory key
 * onto the services:{user_id} key so every writer of the globally-unique
 * (user_id, sort_order) constraint (services_user_sort_order_uq) serialises
 * on ONE Postgres advisory lock: FreshaServiceProjector::sync(),
 * InsertWithSortOrder's manual-service create, ReorderService's flat reorder
 * (all already on services:{user_id}), plus the three sites this fix moves —
 * UserServiceController::updateCategory()/reorderLayout() and
 * StaffServiceManagementController::reorderLayout(). Before this fix those
 * three ran under a DIFFERENT key (service-layout:{user_id}), so a
 * reorderLayout() racing sync() under READ COMMITTED could both compute
 * max(sort_order)+1 from the same snapshot and collide on the unique index —
 * a raw Postgres 500, invisible under SQLite.
 *
 * WHAT THIS SUITE PROVES: (1) source-level — the three migrated call sites no
 * longer reference the service-layout key and DO reference AdvisoryLock's
 * services:{user_id} key + bound; the sites that only ever touched
 * category-assignment rows (never site.services.sort_order) stay OFF the
 * services:{user_id} key, confirming they were correctly left alone.
 * (2) behavioural — the migrated endpoints still function correctly
 * end-to-end under the SQLite shim (shimPgAdvisoryLockForSqlite() no-ops the
 * lock entirely, per AdvisoryLock's own docblock).
 *
 * Slice 3b Task 9 narrowed that second group from two controllers to one:
 * UserServiceCategoryController's categories moved to content.collections, so
 * its assignment detach (and with it the raw service-layout key) went away
 * entirely. It now serialises its own ordering on service-categories:{user_id}
 * — a third key, for a third uniqueness concern. StaffServiceCategoryManagement
 * Controller is the remaining raw service-layout site.
 *
 * WHAT THIS SUITE DOES NOT PROVE: that Postgres actually serialises the four
 * writers against each other, or that a real contended lock times out and
 * throws AdvisoryLockTimeoutException at these three new sites — SQLite has
 * neither pg_advisory_xact_lock nor SET LOCAL lock_timeout, and the shim is a
 * deliberate no-op (see AdvisoryLockTest.php's identical disclaimer for the
 * pre-existing three writers). That guarantee rests on AdvisoryLock::acquire()
 * being the ONLY code path all four now go through — verified here
 * structurally, not by exercising a real timeout.
 */

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\Service;
use App\Models\Core\User\ServiceCategory;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupServicesTable();
    setupPartnaStaffTable();
    shimPgAdvisoryLockForSqlite();

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

    // Mirror the production partial unique index so a real sort_order
    // collision fires under SQLite the way it does on Postgres — used by the
    // behavioural checks below (not the race itself, which is Postgres-only).
    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS site.services_layout_unify_sort_order_uq
         ON services (user_id, sort_order)
         WHERE deleted_at IS NULL'
    );
});

function serviceLayoutUnificationAdminStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $staff->primary_email = 'admin@partna.au';

    return $staff;
}

it('no longer keys the three sort_order-renumbering sites on service-layout', function () {
    $userSource = file_get_contents(app_path('Http/Controllers/Api/User/SiteManagement/UserServiceController.php'));
    $staffSource = file_get_contents(app_path('Http/Controllers/Api/Staff/UserSiteManagement/StaffServiceManagementController.php'));

    // Comments in these files legitimately mention the OLD key name for
    // documentation (see Fix C's own explanatory comments) — assert the
    // actual raw lock CALL is gone, not the bare substring.
    $rawLockCallNeedle = "DB::select('select pg_advisory_xact_lock(hashtext(?))', [\"service-layout:";
    expect($userSource)->not->toContain($rawLockCallNeedle);
    expect($staffSource)->not->toContain($rawLockCallNeedle);

    // updateCategory() + reorderLayout() in UserServiceController, plus
    // reorderLayout() in StaffServiceManagementController — three call sites,
    // three AdvisoryLock::acquire("services:...") occurrences beyond the
    // pre-existing store()/reorder() ones already on that key.
    //
    // Slice 3a Task 5 (owner-authored services → content.*) added TWO MORE
    // literal occurrences beyond Fix C's original three (updateCategory,
    // reorderLayout) + the two that used to live one level down
    // (InsertWithSortOrder inside store(), ReorderService inside reorder()):
    // store() and update() now write section_items directly through
    // ManualServiceWriter and lock "services:{user}" themselves rather than
    // delegating to InsertWithSortOrder, and reorder()'s manual (content.*)
    // half does the same instead of delegating entirely to ReorderService
    // (the Fresha half still does, so that one stays a call OUT rather than
    // a literal occurrence in this file). Five total: store, update,
    // updateCategory, reorder (manual half), reorderLayout — restore() needs
    // no lock at all (section_items.sort_key carries no uniqueness
    // constraint, unlike the legacy sort_order column restore() used to
    // renumber under). The invariant this test still proves — no code path
    // touching ordering references the old service-layout key — is
    // unaffected by which of these methods hold the lock directly vs. via a
    // helper.
    expect(substr_count($userSource, 'AdvisoryLock::acquire("services:'))->toBe(5);
    expect(substr_count($staffSource, 'AdvisoryLock::acquire("services:'))->toBe(1);

    // Each migrated site catches the timeout and returns the same 423 every
    // other services:{user} writer does.
    expect(substr_count($userSource, 'catch (AdvisoryLockTimeoutException)'))
        ->toBeGreaterThanOrEqual(4); // store, reorder, updateCategory, reorderLayout
    expect(substr_count($staffSource, 'catch (AdvisoryLockTimeoutException)'))
        ->toBeGreaterThanOrEqual(3); // store, reorder, reorderLayout
});

it('leaves the category-assignment-only service-layout site untouched, and keeps the migrated one off services:', function () {
    $userCategorySource = file_get_contents(app_path('Http/Controllers/Api/User/SiteManagement/UserServiceCategoryController.php'));
    $staffCategorySource = file_get_contents(app_path('Http/Controllers/Api/Staff/UserSiteManagement/StaffServiceCategoryManagementController.php'));

    // Confirmed by inspection (Fix C's report): destroy() in these controllers
    // only deleted site.service_category_assignments rows + the category row
    // itself — never touched site.services.sort_order — so unifying keys was
    // never applicable here. THAT is the invariant this case protects, and it
    // still holds for both: neither file references the services:{user} key.
    expect($userCategorySource)->not->toContain('AdvisoryLock::acquire("services:');
    expect($staffCategorySource)->not->toContain('AdvisoryLock::acquire("services:');

    // The staff controller is still on the untouched raw service-layout key.
    expect($staffCategorySource)->toContain('service-layout:');

    // Slice 3b Task 9 retired the user-facing half of this pair. Its
    // categories now live in content.collections, so the ids it handles can
    // never match service_category_assignments.service_category_id — the
    // detach that service-layout guarded became a guaranteed no-op and was
    // removed with it. It still renumbers an ordering of its own
    // (content.collections.position), so it must still hold A lock; the key
    // is service-categories:{user}, which is what the pre-cutover reorder()
    // already serialised on via ReorderService/InsertWithSortOrder.
    expect($userCategorySource)->not->toContain('service-layout:');
    expect($userCategorySource)->toContain('AdvisoryLock::acquire("service-categories:');
    expect($userCategorySource)->toContain('catch (AdvisoryLockTimeoutException)');
});

it('updateCategory() still appends at max(sort_order)+1 under the unified key', function () {
    $pro = createTenant('layout-unify-user-cat');
    $catA = createServiceCategoryFor($pro, ['sort_order' => 0]);
    $catB = createServiceCategoryFor($pro, ['sort_order' => 1]);
    // Fresha-sourced: category assignment is Fresha-only until 3b (Slice 3a).
    $mover = createServiceFor($pro, ['category_id' => $catA->id, 'sort_order' => 0, 'source' => 'fresha']);
    createServiceFor($pro, ['category_id' => $catA->id, 'sort_order' => 1]);

    actingAsUser($pro)->patchJson("/api/services/{$mover->id}/category", [
        'category_id' => $catB->id,
    ])->assertOk();

    $mover->refresh();
    expect($mover->sort_order)->toBe(2);
    $sortOrders = Service::query()->where('user_id', $pro->id)->pluck('sort_order');
    expect($sortOrders->unique())->toHaveCount($sortOrders->count());
});

it('staff reorderLayout() still renumbers services + categories under the unified key', function () {
    $pro = createTenant('layout-unify-staff');
    $catA = createServiceCategoryFor($pro, ['sort_order' => 0]);
    $catB = createServiceCategoryFor($pro, ['sort_order' => 1]);
    $serviceA = createServiceFor($pro, ['category_id' => $catA->id, 'sort_order' => 0]);
    $serviceB = createServiceFor($pro, ['category_id' => $catB->id, 'sort_order' => 1]);

    $response = actingAsStaff(serviceLayoutUnificationAdminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services/reorder-layout", [
            'categories' => [
                ['id' => $catB->id, 'service_ids' => [$serviceB->id]],
                ['id' => $catA->id, 'service_ids' => [$serviceA->id]],
            ],
        ]);

    $response->assertOk();

    $serviceA->refresh();
    $serviceB->refresh();
    // $catB's block came first in the payload, so $serviceB sorts ahead.
    expect($serviceB->sort_order)->toBeLessThan($serviceA->sort_order);
    expect(ServiceCategory::withoutGlobalScopes()->whereKey($catB->id)->value('sort_order'))->toBe(0);
    expect(ServiceCategory::withoutGlobalScopes()->whereKey($catA->id)->value('sort_order'))->toBe(1);

    $sortOrders = Service::query()->where('user_id', $pro->id)->pluck('sort_order');
    expect($sortOrders->unique())->toHaveCount(2);
});
