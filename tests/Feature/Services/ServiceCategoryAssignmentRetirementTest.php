<?php

use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Services\Content\ServiceCollections;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Slice 7 Task 12: the last hand-maintained writes to
// `site.service_category_assignments` and the last read of
// `site.service_categories`. Phase 6 DROPs all three tables, so anything still
// touching them is a 500 the moment that migration lands.
//
// The Fresha lane was cut over first: FreshaServiceProjector no longer writes
// site.services, Fresha services are content.* items under a
// content.sources.kind='connection' source, and their categories are
// content.collections rows of kind='service_category'. So the replace-set both
// reorderLayout() twins maintained wrote a pivot NOTHING reads back, and the
// staff category index merged a second id space that no longer has members.
//
// Asserting "the response was 200" is exactly what already passes while these
// blocks are still there, so every case below reads the TABLE (or the id space
// the index emits), never the status code alone.

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupIngestTables();
    setupContentTables();
    setupServicesTable();
    setupServiceCategoriesTable();
    setupBlocksTable();
    setupPartnaStaffTable();
    // Both reorderLayout() twins take pg_advisory_xact_lock(hashtext(...)) —
    // shim it so the real locked path runs rather than being skipped.
    shimPgAdvisoryLockForSqlite();
    Queue::fake();

    // staff.audit middleware writes here on every staff request.
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

/**
 * File-local helpers, uniquely prefixed. A helper defined in one test file is
 * NOT visible to another under a single-file `pest <path>` run, and PHP has no
 * per-file function scoping — a name a sibling already uses fatals with
 * "Cannot redeclare" on a whole-directory or --parallel run.
 */
function svcAsgnRetireAdmin(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $staff->primary_email = 'admin@partna.au';

    return $staff;
}

function svcAsgnRetireTenant(): User
{
    return createTenant('svcasgn-'.Str::lower(Str::random(8)));
}

/** The legacy pivot rows for one service, as a sorted list of category ids. */
function svcAsgnRetireRows(string $serviceId): array
{
    return DB::table('site.service_category_assignments')
        ->where('service_id', $serviceId)
        ->pluck('service_category_id')
        ->map(fn ($id) => (string) $id)
        ->sort()
        ->values()
        ->all();
}

// ── the owner's layout reorder ──────────────────────────────────────────────

it('writes no legacy assignment row when the OWNER layout reorder files a Fresha service under a category', function () {
    $pro = svcAsgnRetireTenant();
    $category = createServiceCategoryFor($pro, ['title' => 'Fresha Cat', 'sort_order' => 0]);
    $fresha = createServiceFor($pro, ['title' => 'Fresha Cut', 'source' => 'fresha', 'sort_order' => 0]);

    expect(svcAsgnRetireRows((string) $fresha->id))->toBe([]);

    actingAsUser($pro)->postJson('/api/services/reorder-layout', [
        'categories' => [
            ['id' => (string) $category->id, 'service_ids' => [(string) $fresha->id]],
        ],
    ])->assertOk();

    expect(svcAsgnRetireRows((string) $fresha->id))->toBe([]);
});

it('leaves an existing legacy assignment row untouched when the OWNER layout reorder re-files that service', function () {
    $pro = svcAsgnRetireTenant();
    $catA = createServiceCategoryFor($pro, ['title' => 'Cat A', 'sort_order' => 0]);
    $catB = createServiceCategoryFor($pro, ['title' => 'Cat B', 'sort_order' => 1]);
    // createServiceFor() seeds the pivot row for category_id.
    $fresha = createServiceFor($pro, ['title' => 'Fresha Cut', 'source' => 'fresha', 'sort_order' => 0, 'category_id' => (string) $catA->id]);

    expect(svcAsgnRetireRows((string) $fresha->id))->toBe([(string) $catA->id]);

    actingAsUser($pro)->postJson('/api/services/reorder-layout', [
        'categories' => [
            ['id' => (string) $catB->id, 'service_ids' => [(string) $fresha->id]],
            ['id' => (string) $catA->id, 'service_ids' => []],
        ],
    ])->assertOk();

    // The replace-set is gone: no detach of catA, no attach of catB.
    expect(svcAsgnRetireRows((string) $fresha->id))->toBe([(string) $catA->id]);
});

// ── the staff twin ──────────────────────────────────────────────────────────

it('writes no legacy assignment row when the STAFF layout reorder files a Fresha service under a category', function () {
    $pro = svcAsgnRetireTenant();
    $category = createServiceCategoryFor($pro, ['title' => 'Fresha Cat', 'sort_order' => 0]);
    $freshaId = ownerService($pro->id, ['title' => 'Fresha Cut', 'source' => 'fresha', 'external_id' => 's:1', 'sort_order' => 0]);

    expect(svcAsgnRetireRows($freshaId))->toBe([]);

    actingAsStaff(svcAsgnRetireAdmin())
        ->postJson("/api/staff/professionals/{$pro->id}/services/reorder-layout", [
            'categories' => [
                ['id' => (string) $category->id, 'service_ids' => [$freshaId]],
            ],
        ])->assertOk();

    expect(svcAsgnRetireRows($freshaId))->toBe([]);
});

it('leaves an existing legacy assignment row untouched when the STAFF layout reorder re-files that service', function () {
    $pro = svcAsgnRetireTenant();
    $catA = createServiceCategoryFor($pro, ['title' => 'Cat A', 'sort_order' => 0]);
    $catB = createServiceCategoryFor($pro, ['title' => 'Cat B', 'sort_order' => 1]);
    $freshaId = ownerService($pro->id, ['title' => 'Fresha Cut', 'source' => 'fresha', 'external_id' => 's:1', 'sort_order' => 0]);
    DB::table('site.service_category_assignments')->insert([
        'service_id' => $freshaId,
        'service_category_id' => (string) $catA->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    actingAsStaff(svcAsgnRetireAdmin())
        ->postJson("/api/staff/professionals/{$pro->id}/services/reorder-layout", [
            'categories' => [
                ['id' => (string) $catB->id, 'service_ids' => [$freshaId]],
                ['id' => (string) $catA->id, 'service_ids' => []],
            ],
        ])->assertOk();

    expect(svcAsgnRetireRows($freshaId))->toBe([(string) $catA->id]);
});

// ── the staff category index: one id space, not two ─────────────────────────

it('returns only content.collections ids from the staff category index', function () {
    $pro = svcAsgnRetireTenant();
    $collectionId = app(ServiceCollections::class)->create($pro->id, 'Owner Cat');
    $legacy = createServiceCategoryFor($pro, ['title' => 'Fresha Cat', 'source' => 'fresha']);

    $rows = collect(
        actingAsStaff(svcAsgnRetireAdmin())
            ->getJson("/api/staff/professionals/{$pro->id}/service-categories")
            ->assertOk()
            ->json('categories')
    );

    expect($rows->pluck('id')->all())->toBe([$collectionId]);
    expect($rows->pluck('title')->all())->toBe(['Owner Cat']);
    // The legacy row still exists — the index simply stopped reading it.
    expect(DB::table('site.service_categories')->where('id', $legacy->id)->exists())->toBeTrue();
});
