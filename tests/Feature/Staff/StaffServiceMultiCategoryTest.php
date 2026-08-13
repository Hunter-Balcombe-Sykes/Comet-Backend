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
 * ── The multi-id question, decided ────────────────────────────────────────
 *
 * `ServiceCollections::assign()` is single-collection PER SOURCE by design (its
 * rule 4 replaces the item's memberships for that source with at most one row),
 * so a two-id `category_ids` payload used to be accepted, fully validated, and
 * persisted as its FIRST entry alone — HTTP 200, no warning, the second id
 * dropped. Slice 3b pinned that collapse rather than changing it, and recorded
 * the 422 as an open product decision (§19.8, wire manifest item 5).
 *
 * The owner decided it on 2026-08-14: REJECT. A write carrying more than one
 * category_id returns 422 rather than discarding data the caller sent. The two
 * cases below therefore assert the refusal, not the collapse — the premise they
 * pinned has been retired, which is different from an assertion being weakened.
 * `max:1` in the request classes still admits [] (move to Uncategorized), and a
 * positive control below pins that the single-id path still works, so a
 * request class that 422'd everything could not pass this file.
 */

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Services\Content\ServiceCollections;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

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

it('staff creating a service with two category_ids gets a 422, not a silent collapse', function () {
    // PREMISE RETIRED, owner decision 2026-08-14. This case used to pin the
    // collapse — 201, with only $catA stored. That is no longer the contract:
    // discarding an id the caller sent, silently, is the defect. The assertion
    // is not weakened, it is inverted, and the write must not happen at all.
    $pro = createTenant('svcmc-store');
    $catA = svcMultiCatTest_category($pro);
    $catB = svcMultiCatTest_category($pro);

    actingAsStaff(svcMultiCatTest_adminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services", [
            'title' => 'Multi-cat service',
            'price_cents' => 5000,
            'category_ids' => [$catA, $catB],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('category_ids');

    // Nothing was created — a validation refusal, not a partial write.
    expect(DB::table('content.items')->count())->toBe(0);
});

it('staff updating a service to two category_ids gets a 422 and keeps its existing membership', function () {
    $pro = createTenant('svcmc-update');
    $catA = svcMultiCatTest_category($pro);
    $catB = svcMultiCatTest_category($pro);
    $catC = svcMultiCatTest_category($pro);
    $serviceId = svcMultiCatTest_service($pro, ['category_ids' => [$catA]]);

    actingAsStaff(svcMultiCatTest_adminStaff())
        ->patchJson("/api/staff/professionals/{$pro->id}/services/{$serviceId}", [
            'category_ids' => [$catB, $catC],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('category_ids');

    // The refusal leaves the prior membership intact — REPLACE semantics never
    // started, so $catA is still the one.
    expect(svcMultiCatTest_categoryIds($pro, $serviceId))->toBe([$catA]);
});

it('staff can still create and update with exactly one category_id', function () {
    // Positive control on the pair above: a 422-on-everything request class
    // would satisfy both, so pin that the single-id path is untouched.
    $pro = createTenant('svcmc-single');
    $catA = svcMultiCatTest_category($pro);
    $catB = svcMultiCatTest_category($pro);

    $serviceId = (string) actingAsStaff(svcMultiCatTest_adminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services", [
            'title' => 'Single-cat service',
            'price_cents' => 5000,
            'category_ids' => [$catA],
        ])
        ->assertStatus(201)
        ->json('service.id');

    expect(svcMultiCatTest_categoryIds($pro, $serviceId))->toBe([$catA]);

    actingAsStaff(svcMultiCatTest_adminStaff())
        ->patchJson("/api/staff/professionals/{$pro->id}/services/{$serviceId}", [
            'category_ids' => [$catB],
        ])
        ->assertStatus(200);

    expect(svcMultiCatTest_categoryIds($pro, $serviceId))->toBe([$catB]);
});

/*
 * The legacy SINGULAR `category_id` spelling, restored to what these two cases
 * were originally written to assert: it still works.
 *
 * Fix round 2 fixed the CAUSE rather than documenting it.
 * `StaffStoreServiceRequest` / `StaffUpdateServiceRequest` carried
 * `'exists:service_categories,id'` on this field — a rule pointed at the
 * LEGACY table — so once the staff routes cut over to content.*, a staff
 * member passing a perfectly valid category id got a 422 while the plural
 * `category_ids` spelling (which never carried the rule) worked. Both requests
 * now declare `['sometimes','nullable','uuid']`, byte-for-byte what the
 * owner-side StoreServiceRequest and UpdateServiceCategoryAssignmentRequest
 * already declare. Ownership stays where it always was — asserted in the
 * controller, owner-scoped, 422 — which `exists` never was.
 */

it('staff legacy single category_id still works on create (regression)', function () {
    $pro = createTenant('svcmc-legacy-store');
    $cat = svcMultiCatTest_category($pro);

    $response = actingAsStaff(svcMultiCatTest_adminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services", [
            'title' => 'Legacy single-cat service',
            'price_cents' => 3000,
            'category_id' => $cat,
        ])
        ->assertStatus(201);

    expect(svcMultiCatTest_categoryIds($pro, (string) $response->json('service.id')))->toBe([$cat]);
});

it('staff legacy single category_id still works on update and replaces the membership set (regression)', function () {
    $pro = createTenant('svcmc-legacy-update');
    $catA = svcMultiCatTest_category($pro);
    $catB = svcMultiCatTest_category($pro);
    $serviceId = svcMultiCatTest_service($pro, ['category_ids' => [$catA]]);

    actingAsStaff(svcMultiCatTest_adminStaff())
        ->patchJson("/api/staff/professionals/{$pro->id}/services/{$serviceId}", [
            'category_id' => $catB,
        ])
        ->assertStatus(200);

    expect(svcMultiCatTest_categoryIds($pro, $serviceId))->toBe([$catB]);
});

it('still rejects a single category_id belonging to a different professional (the check exists moved off, not away)', function () {
    // Dropping `exists:service_categories,id` must not have opened a hole: the
    // rule was never owner-scoped (it accepted ANY professional's category),
    // so the controller's own owner-scoped check is what has always done this
    // work — assert it still does, through the singular spelling.
    $pro = createTenant('svcmc-legacy-foreign');
    $otherPro = createTenant('svcmc-legacy-foreign-other');
    $foreignCat = svcMultiCatTest_category($otherPro);

    actingAsStaff(svcMultiCatTest_adminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services", [
            'title' => 'Should be rejected',
            'price_cents' => 3000,
            'category_id' => $foreignCat,
        ])
        ->assertStatus(422);

    expect(DB::connection('pgsql')->table('content.items')
        ->where('user_id', $pro->id)->where('kind', 'service')->count())->toBe(0);
});

it('still rejects a single category_id that does not exist at all', function () {
    // The other half of what `exists:` used to cover. A random uuid now
    // reaches the controller instead of the validator, and must still 422 —
    // ServiceCollections::find() returns null for it exactly as it does for a
    // foreign one.
    $pro = createTenant('svcmc-legacy-missing');

    actingAsStaff(svcMultiCatTest_adminStaff())
        ->postJson("/api/staff/professionals/{$pro->id}/services", [
            'title' => 'Should be rejected',
            'price_cents' => 3000,
            'category_id' => (string) Str::uuid(),
        ])
        ->assertStatus(422);

    expect(DB::connection('pgsql')->table('content.items')
        ->where('user_id', $pro->id)->where('kind', 'service')->count())->toBe(0);
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
