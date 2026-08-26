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
use App\Models\Core\User\User;
use App\Services\Content\ManualServiceItems;
use App\Services\Content\ServiceCollections;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupServicesTable();
    setupPartnaStaffTable();
    // Slice 3b Task 11: the staff reorder/reorderLayout routes now read
    // content.collections + the services pool section in site.sections, and
    // re-evaluate the booking/services Block gates.
    setupIngestTables();
    setupContentTables();
    setupBlocksTable();
    shimPgAdvisoryLockForSqlite();
    // Every staff service write now fires the edge-purge lane.
    Queue::fake();

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

/** One Fresha-landed service content item — the id space the layout verbs speak. */
function layoutUnifyFreshaItem(User $pro, string $title, string $recordKey): string
{
    $sourceId = (string) Str::uuid();
    $itemId = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $pro->id, 'kind' => 'connection',
        'priority' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $pro->id, 'kind' => 'service',
        'headline_cache' => $title, 'facets_cache' => '{}',
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
        'coord' => 'fresha:'.$recordKey, 'record_key' => $recordKey, 'kind' => 'service',
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
    // The anchor is what makes this stable: ProjectionWriter::resolveItems()
    // binds a coord to its item through content.item_anchors, and it re-runs
    // over EVERY live source item for the (user, kind) pair on any manual
    // write. Without an anchor row this coord resolves as an unrelated
    // singleton, gets a freshly minted item, and the id returned here is
    // orphaned — which is exactly what a connector-landed row never does.
    DB::table('content.item_anchors')->insert([
        'coord' => 'fresha:'.$recordKey, 'user_id' => $pro->id, 'item_id' => $itemId, 'bound_at' => now(),
    ]);

    return $itemId;
}

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
    // Slice 3b Task 11 raised the STAFF count from 1 to 4 for exactly the
    // reason slice 3a raised the user count from 3 to 5: store(), update() and
    // reorder()'s manual half now write site.section_items directly through
    // ManualServiceWriter and take "services:{user}" themselves, instead of
    // delegating to InsertWithSortOrder / ReorderService which took it one
    // level down. Four literal occurrences: store, update, reorder,
    // reorderLayout. restore() needs no lock at all — section_items.sort_key
    // carries no uniqueness constraint, unlike the legacy sort_order column.
    //
    // WHAT THESE TWO NUMBERS ARE, AND ARE NOT. The property this case exists
    // to protect is the one asserted just above: no code path that renumbers
    // ordering still references the old service-layout key. The counts are a
    // structural proxy for "and both controllers still take the unified one" —
    // they are deliberately EXACT rather than `>= 1`, because a bare "at least
    // one" would stay green if a refactor quietly dropped the lock from three
    // of the four methods. The cost is that they also move on a change that
    // breaks no property at all (splitting or inlining a method). If you are
    // reading this because the number moved: confirm every ordering write in
    // the file still holds the key, then update the number and this comment —
    // do NOT relax the assertion to an inequality, which is what would make it
    // stop catching the case it is here for.
    // Services cutover Task 4 lowered the USER count from 5 to 4, and the
    // property above is why it is safe: updateCategory() held the key solely
    // to serialise its max(sort_order)+1 append against the global
    // services_user_sort_order_uq. Both halves file into content.collections
    // now, that append is gone (assignOwnerServiceCategory()'s docblock: a
    // re-file must not move a service in the owner's chosen order), and an
    // endpoint that renumbers nothing must not hold an ordering lock.
    // Remaining four, each still renumbering: store, update, reorder (manual
    // half), reorderLayout.
    expect(substr_count($userSource, 'AdvisoryLock::acquire("services:'))->toBe(4);
    expect(substr_count($staffSource, 'AdvisoryLock::acquire("services:'))->toBe(4);

    // Each migrated site catches the timeout and returns the same 423 every
    // other services:{user} writer does.
    expect(substr_count($userSource, 'catch (AdvisoryLockTimeoutException)'))
        ->toBeGreaterThanOrEqual(4); // store, update, reorder, reorderLayout
    expect(substr_count($staffSource, 'catch (AdvisoryLockTimeoutException)'))
        ->toBeGreaterThanOrEqual(4); // store, update, reorder, reorderLayout
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

    // Services cutover Task 8: the staff twin is off the raw service-layout
    // key too. It held that key ONLY inside destroyLegacy(), whose whole job
    // was detaching site.service_category_assignments rows before soft-
    // deleting a legacy category — a branch deleted with the id space it
    // served. Like the user twin it now renumbers content.collections.position
    // alone, under service-categories:{user}, through AdvisoryLock.
    expect($staffCategorySource)->not->toContain('service-layout:');
    expect($staffCategorySource)->toContain('AdvisoryLock::acquire("service-categories:');
    expect($staffCategorySource)->toContain('catch (AdvisoryLockTimeoutException)');

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

it('updateCategory() no longer renumbers sort_order at all — the legacy row is unaddressable', function () {
    // Was: "updateCategory() still appends at max(sort_order)+1 under the
    // unified key". Services cutover Task 4 retires that append with the
    // legacy branch it belonged to: both halves file into content.collections,
    // ordering lives on site.section_items.sort_key, and re-filing a service
    // deliberately does not move it in the owner's chosen order. What is left
    // to pin is that nothing renumbers — the legacy row is untouched because
    // the id resolves nowhere.
    $pro = createTenant('layout-unify-user-cat');
    $catA = createServiceCategoryFor($pro, ['sort_order' => 0]);
    $catB = createServiceCategoryFor($pro, ['sort_order' => 1]);
    $mover = createServiceFor($pro, ['category_id' => $catA->id, 'sort_order' => 0, 'source' => 'fresha']);
    createServiceFor($pro, ['category_id' => $catA->id, 'sort_order' => 1]);

    actingAsUser($pro)->patchJson("/api/services/{$mover->id}/category", [
        'category_id' => $catB->id,
    ])->assertNotFound();

    $mover->refresh();
    expect($mover->sort_order)->toBe(0);
    $sortOrders = Service::query()->where('user_id', $pro->id)->pluck('sort_order');
    expect($sortOrders->unique())->toHaveCount($sortOrders->count());
});

it('staff reorderLayout() still holds the unified key while it orders content items', function () {
    // Was: "still renumbers services + categories under the unified key".
    // Services cutover Task 5 moved BOTH halves onto site.section_items
    // .sort_key and dissolved the legacy category space, so there is no
    // site.services.sort_order or site.service_categories.sort_order renumber
    // left to observe. The lock is still held (the exact-count assertion
    // above covers that structurally); what this case now proves behaviourally
    // is that the endpoint orders content items and leaves the legacy rows
    // exactly as it found them.
    $pro = createTenant('layout-unify-staff');
    $legacyCat = createServiceCategoryFor($pro, ['sort_order' => 0]);
    $legacyService = createServiceFor($pro, ['category_id' => $legacyCat->id, 'sort_order' => 7, 'source' => 'fresha', 'external_id' => 's:a']);

    $collectionId = app(ServiceCollections::class)->create($pro->id, 'Cuts');
    $itemA = layoutUnifyFreshaItem($pro, 'Fresha A', 's:1');
    $itemB = layoutUnifyFreshaItem($pro, 'Fresha B', 's:2');

    actingAsStaff(serviceLayoutUnificationAdminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services/reorder-layout", [
            'categories' => [
                ['id' => $collectionId, 'service_ids' => [$itemB, $itemA]],
            ],
        ])->assertOk();

    $sectionId = app(ManualServiceItems::class)->sectionId($pro->site->fresh());
    $keys = DB::table('site.section_items')->where('section_id', $sectionId)
        ->whereIn('item_id', [$itemA, $itemB])->pluck('sort_key', 'item_id');
    expect((float) $keys[$itemB])->toBeLessThan((float) $keys[$itemA]);

    // The legacy rows are untouched — nothing renumbers them any more.
    $legacyService->refresh();
    expect($legacyService->sort_order)->toBe(7);
    expect(ServiceCategory::withoutGlobalScopes()->whereKey($legacyCat->id)->value('sort_order'))->toBe(0);
});
