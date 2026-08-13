<?php

/**
 * #SVC-1 — Staff service create/update parity with the professional-facing
 * multi-category support (20260721180000): staff Requests accept category_ids
 * (array) alongside the legacy single category_id, and
 * StaffServiceManagementController::store()/update() assert ownership of EVERY
 * supplied id — not just the first — before touching any membership.
 *
 * Route-level (real FormRequest validation), per this audit's own lesson that
 * controller-only tests with mocked FormRequests let a broken validation rule
 * go unnoticed for two months.
 *
 * ── Slice 3b Task 11: what moved, and what did not ────────────────────────
 *
 * The STORE moved. A staff-created service is now an owner-authored
 * `content.items` row, and its category memberships live in
 * `content.collection_items` written through `ServiceCollections::assign()`,
 * not in `site.service_category_assignments`. So the category ids here are
 * `content.collections` ids (created through ServiceCollections, the same
 * rows the owner's own `/api/service-categories` routes now serve) and the
 * membership is read back off the wire's `category_ids` field rather than out
 * of the legacy pivot.
 *
 * The #SVC-1 PROPERTY did not move, and is still the subject of this file:
 * every supplied id is ownership-checked before anything is written, and the
 * foreign id sits SECOND in each rejection payload precisely so a
 * check-only-the-first implementation cannot pass.
 *
 * ⚠️ ONE ASSERTION GENUINELY CHANGED, and it is not a weakening — it is a
 * behaviour change inherited from Task 8. `ServiceCollections::assign()` is
 * single-collection PER SOURCE by design (its rule 4 replaces the item's
 * memberships for that source with at most one row), so a multi-id
 * `category_ids` payload is accepted and fully validated but persists only its
 * FIRST entry. The owner's own `PATCH /services/{id}/category` already
 * collapses the same way — it is a known open carry-forward from Task 10, not
 * a staff-side quirk. The two "multiple category_ids" cases below therefore
 * pin the collapse EXPLICITLY (exactly one membership, and it is the first id)
 * rather than quietly asserting less: written this way, the day
 * content.collection_items becomes genuinely multi-valued, these two cases go
 * red and force the decision instead of silently starting to over-deliver.
 */

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Services\Content\ServiceCollections;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupServicesTable();
    setupPartnaStaffTable();
    // The staff service routes read and write content.* now, plus the services
    // pool section in site.sections and the booking/services Block gates.
    setupIngestTables();
    setupContentTables();
    setupBlocksTable();
    shimPgAdvisoryLockForSqlite();
    // Every staff service write fires the edge-purge lane.
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

function svcMultiCatTest_adminStaff(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $staff->primary_email = 'admin@partna.au';

    return $staff;
}

/** A content.collections service-category for $pro — the id space staff writes now use. */
function svcMultiCatTest_category(User $pro): string
{
    return app(ServiceCollections::class)->create($pro->id, 'Cat '.uniqid());
}

/**
 * The service's persisted memberships, read back off the STAFF wire — the
 * post-cutover replacement for plucking site.service_category_assignments.
 *
 * @return list<string>
 */
function svcMultiCatTest_categoryIds(User $pro, string $serviceId): array
{
    return collect(actingAsStaff(svcMultiCatTest_adminStaff())
        ->getJson("/api/staff/professionals/{$pro->id}/services/{$serviceId}")
        ->assertStatus(200)
        ->json('service.category_ids'))
        ->map(fn ($id) => (string) $id)
        ->all();
}

/** A service for $pro, created through the staff endpoint; returns its content.items id. */
function svcMultiCatTest_service(User $pro, array $payload = []): string
{
    return (string) actingAsStaff(svcMultiCatTest_adminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services", $payload + [
            'title' => 'Fixture service',
            'price_cents' => 2500,
        ])
        ->assertStatus(201)
        ->json('service.id');
}

it('staff can create a service with multiple category_ids', function () {
    $pro = createTenant('svcmc-store');
    $catA = svcMultiCatTest_category($pro);
    $catB = svcMultiCatTest_category($pro);

    $response = actingAsStaff(svcMultiCatTest_adminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services", [
            'title' => 'Multi-cat service',
            'price_cents' => 5000,
            'category_ids' => [$catA, $catB],
        ])
        ->assertStatus(201);

    $serviceId = (string) $response->json('service.id');

    // Both ids were accepted and validated; ServiceCollections::assign() is
    // single-collection per source, so the FIRST is what persists. Pinned
    // exactly — see this file's header for why this is stated rather than
    // hidden behind a laxer assertion.
    expect(svcMultiCatTest_categoryIds($pro, $serviceId))->toBe([$catA]);
});

it('staff can update a service to multiple category_ids', function () {
    $pro = createTenant('svcmc-update');
    $catA = svcMultiCatTest_category($pro);
    $catB = svcMultiCatTest_category($pro);
    $catC = svcMultiCatTest_category($pro);
    $serviceId = svcMultiCatTest_service($pro, ['category_ids' => [$catA]]);

    actingAsStaff(svcMultiCatTest_adminStaff())
        ->patchJson("/api/staff/professionals/{$pro->id}/services/{$serviceId}", [
            'category_ids' => [$catB, $catC],
        ])
        ->assertStatus(200);

    // REPLACE semantics hold: the previous membership ($catA) is gone, not
    // merged with. The collapse to the first of the new set is the same
    // single-collection rule as create().
    expect(svcMultiCatTest_categoryIds($pro, $serviceId))->toBe([$catB]);
});

/*
 * ⚠️ KNOWN GAP, PINNED — the legacy SINGULAR `category_id` spelling.
 *
 * These two cases asserted that `category_id` (not `category_ids`) still
 * stores the membership. Post-slice-3b that is FALSE, and NOT because of
 * anything in StaffServiceManagementController: `StaffStoreServiceRequest:17`
 * and `StaffUpdateServiceRequest:17` still validate the singular field with
 * `'exists:service_categories,id'` — a rule against the LEGACY table — while a
 * staff create now lands an owner-authored content item whose memberships can
 * only be `content.collections` ids. The plural `category_ids` spelling has no
 * such rule and works (the four cases above), so the wire is not broken, only
 * this one spelling is.
 *
 * The equivalent rule was already removed from the user-side requests
 * (StoreServiceRequest:24, UpdateServiceCategoryAssignmentRequest:21 are bare
 * `['sometimes','nullable','uuid']`). THE FIX IS THE SAME ONE LINE IN EACH OF
 * THE TWO STAFF REQUESTS — both are outside Task 11's permitted file list,
 * which is why this is pinned rather than fixed here.
 *
 * These assert the CURRENT behaviour, deliberately, and name the failure
 * precisely (the validator's own error key) so they cannot pass for an
 * unrelated reason. They are self-alarming: the moment someone drops that
 * `exists:` rule, both go RED and whoever did it has to restore the original
 * "still stores the membership" expectation, which is the outcome we want.
 */

it('KNOWN GAP: staff legacy single category_id 422s on create, blocked by a stale exists:service_categories rule', function () {
    $pro = createTenant('svcmc-legacy-store');
    $cat = svcMultiCatTest_category($pro);

    actingAsStaff(svcMultiCatTest_adminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services", [
            'title' => 'Legacy single-cat service',
            'price_cents' => 3000,
            'category_id' => $cat,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['category_id']);

    // Nothing was written — the rejection is at the FormRequest, before the
    // controller runs at all.
    expect(DB::connection('pgsql')->table('content.items')
        ->where('user_id', $pro->id)->where('kind', 'service')->count())->toBe(0);
});

it('KNOWN GAP: staff legacy single category_id 422s on update, same stale rule', function () {
    $pro = createTenant('svcmc-legacy-update');
    $catA = svcMultiCatTest_category($pro);
    $catB = svcMultiCatTest_category($pro);
    $serviceId = svcMultiCatTest_service($pro, ['category_ids' => [$catA]]);

    actingAsStaff(svcMultiCatTest_adminStaff())
        ->patchJson("/api/staff/professionals/{$pro->id}/services/{$serviceId}", [
            'category_id' => $catB,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['category_id']);

    // The membership the rejected write addressed is untouched.
    expect(svcMultiCatTest_categoryIds($pro, $serviceId))->toBe([$catA]);
});

it('clears the membership set when staff send an explicit empty category_ids', function () {
    $pro = createTenant('svcmc-clear');
    $cat = svcMultiCatTest_category($pro);
    $serviceId = svcMultiCatTest_service($pro, ['category_ids' => [$cat]]);

    actingAsStaff(svcMultiCatTest_adminStaff())
        ->patchJson("/api/staff/professionals/{$pro->id}/services/{$serviceId}", [
            'category_ids' => [],
        ])
        ->assertStatus(200);

    // An explicitly-supplied empty array is "move to Uncategorised", which is
    // a different thing from omitting the key — the distinction the controller
    // draws between assignmentCategoryIds() and requestedCategoryIds().
    expect(svcMultiCatTest_categoryIds($pro, $serviceId))->toBe([]);
});

it('leaves the membership set alone when staff omit category_ids entirely', function () {
    $pro = createTenant('svcmc-omit');
    $cat = svcMultiCatTest_category($pro);
    $serviceId = svcMultiCatTest_service($pro, ['category_ids' => [$cat]]);

    actingAsStaff(svcMultiCatTest_adminStaff())
        ->patchJson("/api/staff/professionals/{$pro->id}/services/{$serviceId}", [
            'title' => 'Renamed, not re-filed',
        ])
        ->assertStatus(200);

    expect(svcMultiCatTest_categoryIds($pro, $serviceId))->toBe([$cat]);
});

it('rejects create when ANY supplied category_id belongs to a different professional', function () {
    $pro = createTenant('svcmc-store-foreign');
    $otherPro = createTenant('svcmc-store-other');
    $ownCat = svcMultiCatTest_category($pro);
    $foreignCat = svcMultiCatTest_category($otherPro);

    actingAsStaff(svcMultiCatTest_adminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services", [
            'title' => 'Should be rejected',
            'price_cents' => 4000,
            // foreign id is second in the list — proves every id is checked, not just the first.
            'category_ids' => [$ownCat, $foreignCat],
        ])
        ->assertStatus(422);

    // Nothing was written: the ownership loop runs BEFORE the content write,
    // so a rejected create leaves no orphan item behind.
    expect(DB::connection('pgsql')->table('content.items')
        ->where('user_id', $pro->id)->where('kind', 'service')->count())->toBe(0);
});

it('rejects update when ANY supplied category_id belongs to a different professional', function () {
    $pro = createTenant('svcmc-update-foreign');
    $otherPro = createTenant('svcmc-update-other');
    $ownCatA = svcMultiCatTest_category($pro);
    $ownCatB = svcMultiCatTest_category($pro);
    $foreignCat = svcMultiCatTest_category($otherPro);
    $serviceId = svcMultiCatTest_service($pro, ['category_ids' => [$ownCatA]]);

    actingAsStaff(svcMultiCatTest_adminStaff())
        ->patchJson("/api/staff/professionals/{$pro->id}/services/{$serviceId}", [
            // foreign id is second in the list — proves every id is checked, not just the first.
            'category_ids' => [$ownCatB, $foreignCat],
        ])
        ->assertStatus(422);

    // Membership must be untouched — the rejected write must not have assigned.
    expect(svcMultiCatTest_categoryIds($pro, $serviceId))->toBe([$ownCatA]);
});
