<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\Site;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Services\PublicSite\SitepageDataResolverService;
use App\Services\Site\AdvisoryLockTimeoutException;
use App\Services\Site\ReorderService;
use App\Site\Documents\BuildState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Slice 3b Task 11b: StaffServiceCategoryManagementController's seven methods
// now read and write `content.collections` (kind='service_category') through
// ServiceCollections — the same store Task 9 moved the OWNER's own
// /service-categories routes onto — with the untouched legacy
// `site.service_categories` (Fresha) half still resolvable behind it.
//
// The defect this file exists to catch is a SILENT one. Task 9 moved the
// owner's category routes to content.collections and left this controller on
// site.service_categories, so a staff member could create a category for a
// professional, receive a 201, and the owner would never see it: not on their
// dashboard list, not on their public page, with no error to either party.
//
// Every assertion below is therefore taken from the OWNER's side (their
// /api/service-categories read, or the rendered public payload). Asserting the
// staff response is exactly the thing that already passes while the feature is
// broken.

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupIngestTables();
    setupContentTables();
    setupServicesTable();
    setupServiceCategoriesTable();
    setupBlocksTable();
    setupPartnaStaffTable();
    // store()/update()/reorder() take the same pg_advisory_xact_lock(hashtext(...))
    // the owner routes do — shim it under SQLite so the real locked code path
    // runs rather than being skipped.
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
 * File-local helpers, uniquely named. A helper defined in one test file is NOT
 * visible to another under a direct single-file `pest <path>` invocation, and
 * PHP has no per-file function scoping — so a name a sibling file already uses
 * would fatal with "Cannot redeclare" on a whole-directory run.
 */
function staffCatAdmin(): PartnaStaff
{
    return PartnaStaff::factory()->admin()->create();
}

/** @return array{0: User, 1: string} [$pro, $siteId] */
function staffCatTenant(): array
{
    $pro = createTenant('staffcat-'.Str::lower(Str::random(8)));

    return [$pro, (string) $pro->site->id];
}

/** POST the staff create endpoint and hand back the new category id. */
function staffCatCreate(PartnaStaff $staff, User $pro, string $title, array $extra = []): string
{
    return (string) actingAsStaff($staff)
        ->postJson("/api/staff/professionals/{$pro->id}/service-categories", $extra + ['title' => $title])
        ->assertCreated()
        ->json('category.id');
}

/** The OWNER's own dashboard list — the read that must agree with staff's write. */
function staffCatOwnerTitles(User $pro, string $query = ''): array
{
    return collect(
        actingAsUser($pro)->getJson('/api/service-categories'.$query)->assertOk()->json('categories')
    )->pluck('title')->all();
}

/** The OWNER's list as [id => title], for order- and identity-sensitive checks. */
function staffCatOwnerRows(User $pro, string $query = ''): array
{
    return collect(
        actingAsUser($pro)->getJson('/api/service-categories'.$query)->assertOk()->json('categories')
    )->all();
}

/** Create an owner-authored service through the OWNER's own endpoint. */
function staffCatOwnerService(User $pro, string $title): string
{
    return (string) actingAsUser($pro)
        ->postJson('/api/services', ['title' => $title, 'price_cents' => 5500, 'currency_code' => 'AUD'])
        ->assertCreated()
        ->json('service.id');
}

/** The public sitepage payload's services list — the read that actually renders. */
function staffCatPublicServices(string $siteId, string $userId): array
{
    $site = Site::query()->findOrFail($siteId);

    return app(SitepageDataResolverService::class)->buildServicesData($site, $userId)['services'];
}

/**
 * The three invalidation lanes, asserted as an EXACT revision delta.
 *
 * A ">0" check is worthless here: slice 3a shipped a three-lane test that
 * stayed green with the whole BuildState lane deleted, because a neighbouring
 * write already cleared that bar. Nothing in a category write bumps
 * content_revision except ManualServiceWriter::invalidate(), so the delta is
 * exactly 1 or the lane is gone.
 */
function staffCatAssertThreeLanes(string $siteId, Closure $act): void
{
    DB::table('site.sites')->where('id', $siteId)->update(['updated_at' => now()->subMinute()]);
    $beforeUpdatedAt = DB::table('site.sites')->where('id', $siteId)->value('updated_at');
    $beforeRevision = BuildState::read($siteId)['content_revision'];

    $act();

    expect(DB::table('site.site_build_state')->where('site_id', $siteId)->value('content_revision'))
        ->toBe($beforeRevision + 1);
    expect(DB::table('site.sites')->where('id', $siteId)->value('updated_at'))->not->toBe($beforeUpdatedAt);
    Queue::assertPushed(CloudflareCachePurgeJob::class);
}

// ── the defect, from the owner's side ───────────────────────────────────────

it('creates a category through the staff endpoint that the OWNER sees on their own list', function () {
    [$pro] = staffCatTenant();

    $id = staffCatCreate(staffCatAdmin(), $pro, 'Staff Made');

    // The staff 200/201 is NOT the evidence — it returned 201 while the owner
    // saw nothing. The owner's read is.
    expect(staffCatOwnerTitles($pro))->toContain('Staff Made');
    expect(collect(staffCatOwnerRows($pro))->pluck('id'))->toContain($id);

    // Landed in the store the owner and the page read, not the legacy table.
    expect(DB::table('content.collections')->where('id', $id)->where('kind', 'service_category')->exists())->toBeTrue();
    expect(DB::table('site.service_categories')->where('id', $id)->exists())->toBeFalse();
});

it('renders a staff-created category on the PUBLIC payload once a service is filed under it', function () {
    [$pro, $siteId] = staffCatTenant();

    $categoryId = staffCatCreate(staffCatAdmin(), $pro, 'Colour Work');
    $serviceId = staffCatOwnerService($pro, 'Balayage');

    actingAsUser($pro)
        ->patchJson("/api/services/{$serviceId}/category", ['category_id' => $categoryId])
        ->assertOk();

    $public = collect(staffCatPublicServices($siteId, $pro->id))->firstWhere('id', $serviceId);
    expect($public)->not->toBeNull();
    expect($public['category'])->toBe('Colour Work');
});

it('emits the owner-facing wire shape for a staff-created category', function () {
    [$pro] = staffCatTenant();

    actingAsStaff(staffCatAdmin())
        ->postJson("/api/staff/professionals/{$pro->id}/service-categories", ['title' => 'Shape Check'])
        ->assertCreated()
        ->assertJsonStructure(['category' => ['id', 'user_id', 'title', 'source', 'sort_order', 'created_at', 'updated_at', 'deleted_at']])
        ->assertJsonPath('category.title', 'Shape Check')
        // A staff-created category is owner-authored, not vendor-synced.
        ->assertJsonPath('category.source', null)
        ->assertJsonPath('category.user_id', $pro->id);
});

// ── index: both id spaces, as the grouped services list already merges them ──

it('lists BOTH the content.collections half and the legacy (Fresha) half', function () {
    [$pro] = staffCatTenant();

    $collectionId = staffCatCreate(staffCatAdmin(), $pro, 'Owner Cat');
    $legacy = createServiceCategoryFor($pro, ['title' => 'Fresha Cat', 'source' => 'fresha']);

    $rows = collect(
        actingAsStaff(staffCatAdmin())
            ->getJson("/api/staff/professionals/{$pro->id}/service-categories")
            ->assertOk()
            ->json('categories')
    );

    expect($rows->pluck('title'))->toContain('Owner Cat')->toContain('Fresha Cat');
    expect($rows->pluck('id'))->toContain($collectionId)->toContain((string) $legacy->id);
});

it('filters archived categories out of the content half by default and back in on request', function () {
    [$pro] = staffCatTenant();
    $staff = staffCatAdmin();

    $live = staffCatCreate($staff, $pro, 'Still Here');
    $gone = staffCatCreate($staff, $pro, 'Archived One');

    actingAsStaff($staff)->deleteJson("/api/staff/professionals/{$pro->id}/service-categories/{$gone}")->assertOk();

    $default = collect(actingAsStaff($staff)
        ->getJson("/api/staff/professionals/{$pro->id}/service-categories")->assertOk()->json('categories'))->pluck('id');
    expect($default)->toContain($live)->not->toContain($gone);

    $onlyArchived = collect(actingAsStaff($staff)
        ->getJson("/api/staff/professionals/{$pro->id}/service-categories?only_archived=1")->assertOk()->json('categories'))->pluck('id');
    expect($onlyArchived)->toContain($gone)->not->toContain($live);

    $includeArchived = collect(actingAsStaff($staff)
        ->getJson("/api/staff/professionals/{$pro->id}/service-categories?include_archived=1")->assertOk()->json('categories'))->pluck('id');
    expect($includeArchived)->toContain($gone)->toContain($live);
});

// ── show / update / destroy / restore / hard delete ─────────────────────────

it('shows a single content-backed category by id', function () {
    [$pro] = staffCatTenant();
    $staff = staffCatAdmin();
    $id = staffCatCreate($staff, $pro, 'Single Read');

    actingAsStaff($staff)
        ->getJson("/api/staff/professionals/{$pro->id}/service-categories/{$id}")
        ->assertOk()
        ->assertJsonPath('category.id', $id)
        ->assertJsonPath('category.title', 'Single Read');
});

it('lets staff rename a category and the OWNER sees the new title', function () {
    [$pro] = staffCatTenant();
    $staff = staffCatAdmin();
    $id = staffCatCreate($staff, $pro, 'Old Name');

    actingAsStaff($staff)
        ->patchJson("/api/staff/professionals/{$pro->id}/service-categories/{$id}", ['title' => 'New Name'])
        ->assertOk()
        ->assertJsonPath('category.title', 'New Name');

    expect(staffCatOwnerTitles($pro))->toContain('New Name')->not->toContain('Old Name');
});

it('lets staff rename a category and the PUBLIC payload follows', function () {
    [$pro, $siteId] = staffCatTenant();
    $staff = staffCatAdmin();

    $categoryId = staffCatCreate($staff, $pro, 'Before Rename');
    $serviceId = staffCatOwnerService($pro, 'Filed Service');
    actingAsUser($pro)->patchJson("/api/services/{$serviceId}/category", ['category_id' => $categoryId])->assertOk();

    actingAsStaff($staff)
        ->patchJson("/api/staff/professionals/{$pro->id}/service-categories/{$categoryId}", ['title' => 'After Rename'])
        ->assertOk();

    $public = collect(staffCatPublicServices($siteId, $pro->id))->firstWhere('id', $serviceId);
    expect($public['category'])->toBe('After Rename');
});

it('lets staff delete a category and the OWNER stops seeing it', function () {
    [$pro] = staffCatTenant();
    $staff = staffCatAdmin();
    $id = staffCatCreate($staff, $pro, 'Doomed');

    actingAsStaff($staff)
        ->deleteJson("/api/staff/professionals/{$pro->id}/service-categories/{$id}")
        ->assertOk()
        ->assertJson(['deleted' => true]);

    expect(staffCatOwnerTitles($pro))->not->toContain('Doomed');
    expect(DB::table('content.collections')->where('id', $id)->value('removed_at'))->not->toBeNull();
});

it('lets staff restore a category it deleted, and the OWNER gets it back', function () {
    [$pro] = staffCatTenant();
    $staff = staffCatAdmin();
    $id = staffCatCreate($staff, $pro, 'Back Again');

    actingAsStaff($staff)->deleteJson("/api/staff/professionals/{$pro->id}/service-categories/{$id}")->assertOk();
    actingAsStaff($staff)
        ->postJson("/api/staff/professionals/{$pro->id}/service-categories/{$id}/restore")
        ->assertOk()
        ->assertJson(['restored' => true]);

    expect(DB::table('content.collections')->where('id', $id)->value('removed_at'))->toBeNull();
    expect(staffCatOwnerTitles($pro))->toContain('Back Again');
});

it('lets staff hard-delete a content-backed category', function () {
    [$pro] = staffCatTenant();
    $staff = staffCatAdmin();

    $categoryId = staffCatCreate($staff, $pro, 'Hard Gone');
    $serviceId = staffCatOwnerService($pro, 'Member Service');
    actingAsUser($pro)->patchJson("/api/services/{$serviceId}/category", ['category_id' => $categoryId])->assertOk();

    actingAsStaff($staff)
        ->deleteJson("/api/staff/professionals/{$pro->id}/service-categories/{$categoryId}/hard")
        ->assertOk()
        ->assertJson(['deleted' => true, 'hard' => true]);

    // Hard means gone, not tombstoned — the row and its memberships both.
    expect(DB::table('content.collections')->where('id', $categoryId)->exists())->toBeFalse();
    expect(DB::table('content.collection_items')->where('collection_id', $categoryId)->exists())->toBeFalse();

    // The member SERVICE survives — a category hard-delete is not an item
    // deletion. content.items.removed_at is one-way and must not be written here.
    expect(DB::table('content.items')->where('id', $serviceId)->value('removed_at'))->toBeNull();
    expect(collect(staffCatPublicServices($pro->site->id, $pro->id))->pluck('id'))->toContain($serviceId);
});

it('reorders categories and the OWNER reads back the submitted order', function () {
    [$pro] = staffCatTenant();
    $staff = staffCatAdmin();

    $a = staffCatCreate($staff, $pro, 'Cat A');
    $b = staffCatCreate($staff, $pro, 'Cat B');
    $c = staffCatCreate($staff, $pro, 'Cat C');

    actingAsStaff($staff)
        ->postJson("/api/staff/professionals/{$pro->id}/service-categories/reorder", ['ids' => [$c, $a, $b]])
        ->assertOk()
        ->assertJson(['ok' => true]);

    expect(collect(staffCatOwnerRows($pro))->pluck('id')->all())->toBe([$c, $a, $b]);
});

// ── §19.8: both stores, ONE lock scope ──────────────────────────────────────

it('acquires the category lock exactly once for a reorder spanning both stores', function () {
    // reorder() writes content.collections AND site.service_categories. It used
    // to do so under two independent scopes — reposition() in its own
    // transaction, then ReorderService::reorder() opening a second one and
    // re-taking the same key — leaving a gap a competing writer could take the
    // key in, applying its whole reorder between this request's two halves.
    //
    // Counting the real `select pg_advisory_xact_lock(hashtext(?))` statements
    // is what distinguishes one scope from two. Asserting the resulting ORDER
    // cannot: the submitted order comes out right either way whenever nothing
    // happens to interleave, and comes out right with the lock deleted
    // altogether, because each store's own two-pass renumber is already
    // collision-safe. Two acquisitions here is the defect; one is the fix.
    [$pro] = staffCatTenant();
    $staff = staffCatAdmin();

    $collectionA = staffCatCreate($staff, $pro, 'Collection A');
    $collectionB = staffCatCreate($staff, $pro, 'Collection B');
    $legacyA = (string) createServiceCategoryFor($pro, ['title' => 'Legacy A', 'source' => 'fresha', 'sort_order' => 0])->id;
    $legacyB = (string) createServiceCategoryFor($pro, ['title' => 'Legacy B', 'source' => 'fresha', 'sort_order' => 1])->id;

    $acquisitions = [];
    DB::listen(function ($query) use (&$acquisitions) {
        if (str_contains($query->sql, 'pg_advisory_xact_lock')) {
            $acquisitions[] = (string) ($query->bindings[0] ?? '');
        }
    });

    actingAsStaff($staff)
        ->postJson("/api/staff/professionals/{$pro->id}/service-categories/reorder", [
            'ids' => [$collectionB, $collectionA, $legacyB, $legacyA],
        ])
        ->assertOk();

    expect($acquisitions)->toBe(["service-categories:{$pro->id}"]);

    // Positive control: the reorder really did write BOTH stores, so the count
    // above is one-scope-covering-two-writes, not one-write-happening.
    expect(DB::table('content.collections')->where('id', $collectionB)->value('position'))
        ->toBe(0)
        ->and(DB::table('site.service_categories')->where('id', $legacyB)->value('sort_order'))->toBe(0)
        ->and(DB::table('site.service_categories')->where('id', $legacyA)->value('sort_order'))->toBe(1);
});

it('rolls the collections half back when the legacy half fails', function () {
    // The other half of "one scope": one transaction. Under two scopes the
    // collections write had already committed by the time the legacy half ran,
    // so a failure there left the layout half-applied.
    [$pro] = staffCatTenant();
    $staff = staffCatAdmin();

    $collectionA = staffCatCreate($staff, $pro, 'Collection A');
    $collectionB = staffCatCreate($staff, $pro, 'Collection B');
    $legacy = (string) createServiceCategoryFor($pro, ['title' => 'Legacy', 'source' => 'fresha', 'sort_order' => 0])->id;

    $beforeA = DB::table('content.collections')->where('id', $collectionA)->value('position');
    $beforeB = DB::table('content.collections')->where('id', $collectionB)->value('position');

    // Throw from the legacy half only — the collections half has already run.
    //
    // BOTH entry points are stubbed on purpose. If only renumberLocked() were,
    // re-splitting the controller into two scopes would fail this case with an
    // unexpected-call 500 — red, but for the wrong reason, proving only that
    // the method name changed. Stubbing reorder() identically means a re-split
    // still returns 423, and the case then fails on the assertion that actually
    // matters: the collections half committed instead of rolling back.
    $this->mock(ReorderService::class, function ($m) {
        $m->shouldReceive('renumberLocked')->andThrow(new AdvisoryLockTimeoutException('service-categories:pending'));
        $m->shouldReceive('reorder')->andThrow(new AdvisoryLockTimeoutException('service-categories:pending'));
    });

    actingAsStaff($staff)
        ->postJson("/api/staff/professionals/{$pro->id}/service-categories/reorder", [
            'ids' => [$collectionB, $collectionA, $legacy],
        ])
        ->assertStatus(423)
        ->assertJsonPath('message', 'Another change is still saving — please retry in a moment.');

    expect(DB::table('content.collections')->where('id', $collectionA)->value('position'))->toBe($beforeA)
        ->and(DB::table('content.collections')->where('id', $collectionB)->value('position'))->toBe($beforeB);
});

it('honours an explicit sort_order on create, as the owner reads it', function () {
    [$pro] = staffCatTenant();
    $staff = staffCatAdmin();

    $first = staffCatCreate($staff, $pro, 'First');
    $second = staffCatCreate($staff, $pro, 'Second');
    $spliced = staffCatCreate($staff, $pro, 'Spliced', ['sort_order' => 0]);

    expect(collect(staffCatOwnerRows($pro))->pluck('id')->all())->toBe([$spliced, $first, $second]);
});

// ── tenancy: staff acting for A must not reach B ────────────────────────────

it('404s a content category that belongs to a DIFFERENT professional, and leaves it untouched', function () {
    [$proA] = staffCatTenant();
    [$proB] = staffCatTenant();
    $staff = staffCatAdmin();

    $bCategory = staffCatCreate($staff, $proB, 'Belongs To B');

    actingAsStaff($staff)
        ->getJson("/api/staff/professionals/{$proA->id}/service-categories/{$bCategory}")
        ->assertStatus(404);

    actingAsStaff($staff)
        ->patchJson("/api/staff/professionals/{$proA->id}/service-categories/{$bCategory}", ['title' => 'Hijacked'])
        ->assertStatus(404);

    actingAsStaff($staff)
        ->deleteJson("/api/staff/professionals/{$proA->id}/service-categories/{$bCategory}")
        ->assertStatus(404);

    actingAsStaff($staff)
        ->deleteJson("/api/staff/professionals/{$proA->id}/service-categories/{$bCategory}/hard")
        ->assertStatus(404);

    actingAsStaff($staff)
        ->postJson("/api/staff/professionals/{$proA->id}/service-categories/{$bCategory}/restore")
        ->assertStatus(404);

    // B's row is intact: not renamed, not removed, not deleted.
    $row = DB::table('content.collections')->where('id', $bCategory)->first();
    expect($row)->not->toBeNull();
    expect($row->label)->toBe('Belongs To B');
    expect($row->removed_at)->toBeNull();
    expect(staffCatOwnerTitles($proB))->toContain('Belongs To B');
});

it('does not let a reorder for professional A move professional B rows', function () {
    [$proA] = staffCatTenant();
    [$proB] = staffCatTenant();
    $staff = staffCatAdmin();

    $aOne = staffCatCreate($staff, $proA, 'A One');
    $aTwo = staffCatCreate($staff, $proA, 'A Two');
    $bOne = staffCatCreate($staff, $proB, 'B One');
    $bTwo = staffCatCreate($staff, $proB, 'B Two');

    // 422 is the pre-cutover contract, kept: ReorderService already aborted
    // 422 for an id outside the professional's own scope. A foreign id must
    // not silently consume a slot instead.
    actingAsStaff($staff)
        ->postJson("/api/staff/professionals/{$proA->id}/service-categories/reorder", ['ids' => [$bTwo, $bOne, $aTwo, $aOne]])
        ->assertStatus(422);

    // Neither side moved — A's rejected request is not half-applied, and B's
    // rows are exactly where B left them.
    expect(collect(staffCatOwnerRows($proA))->pluck('id')->all())->toBe([$aOne, $aTwo]);
    expect(collect(staffCatOwnerRows($proB))->pluck('id')->all())->toBe([$bOne, $bTwo]);
});

// ── the three cache lanes, one exact delta per write verb ───────────────────

it('busts all three cache lanes on create', function () {
    [$pro, $siteId] = staffCatTenant();
    $staff = staffCatAdmin();

    staffCatAssertThreeLanes($siteId, function () use ($staff, $pro) {
        actingAsStaff($staff)
            ->postJson("/api/staff/professionals/{$pro->id}/service-categories", ['title' => 'Lane Create'])
            ->assertCreated();
    });
});

it('busts all three cache lanes on update', function () {
    [$pro, $siteId] = staffCatTenant();
    $staff = staffCatAdmin();
    $id = staffCatCreate($staff, $pro, 'Lane Update');

    staffCatAssertThreeLanes($siteId, function () use ($staff, $pro, $id) {
        actingAsStaff($staff)
            ->patchJson("/api/staff/professionals/{$pro->id}/service-categories/{$id}", ['title' => 'Lane Updated'])
            ->assertOk();
    });
});

it('busts all three cache lanes on delete', function () {
    [$pro, $siteId] = staffCatTenant();
    $staff = staffCatAdmin();
    $id = staffCatCreate($staff, $pro, 'Lane Delete');

    staffCatAssertThreeLanes($siteId, function () use ($staff, $pro, $id) {
        actingAsStaff($staff)
            ->deleteJson("/api/staff/professionals/{$pro->id}/service-categories/{$id}")
            ->assertOk();
    });
});

it('busts all three cache lanes on restore', function () {
    [$pro, $siteId] = staffCatTenant();
    $staff = staffCatAdmin();
    $id = staffCatCreate($staff, $pro, 'Lane Restore');
    actingAsStaff($staff)->deleteJson("/api/staff/professionals/{$pro->id}/service-categories/{$id}")->assertOk();

    staffCatAssertThreeLanes($siteId, function () use ($staff, $pro, $id) {
        actingAsStaff($staff)
            ->postJson("/api/staff/professionals/{$pro->id}/service-categories/{$id}/restore")
            ->assertOk();
    });
});

it('busts all three cache lanes on reorder', function () {
    [$pro, $siteId] = staffCatTenant();
    $staff = staffCatAdmin();
    $a = staffCatCreate($staff, $pro, 'Lane A');
    $b = staffCatCreate($staff, $pro, 'Lane B');

    staffCatAssertThreeLanes($siteId, function () use ($staff, $pro, $a, $b) {
        actingAsStaff($staff)
            ->postJson("/api/staff/professionals/{$pro->id}/service-categories/reorder", ['ids' => [$b, $a]])
            ->assertOk();
    });
});

it('busts all three cache lanes on hard delete', function () {
    [$pro, $siteId] = staffCatTenant();
    $staff = staffCatAdmin();
    $id = staffCatCreate($staff, $pro, 'Lane Hard');

    staffCatAssertThreeLanes($siteId, function () use ($staff, $pro, $id) {
        actingAsStaff($staff)
            ->deleteJson("/api/staff/professionals/{$pro->id}/service-categories/{$id}/hard")
            ->assertOk();
    });
});

// ── the legacy (Fresha) half stays reachable ────────────────────────────────

it('still shows, renames and deletes a legacy site.service_categories row', function () {
    [$pro] = staffCatTenant();
    $staff = staffCatAdmin();
    $legacy = createServiceCategoryFor($pro, ['title' => 'Legacy Title', 'source' => 'fresha']);

    actingAsStaff($staff)
        ->getJson("/api/staff/professionals/{$pro->id}/service-categories/{$legacy->id}")
        ->assertOk()
        ->assertJsonPath('category.id', (string) $legacy->id);

    actingAsStaff($staff)
        ->patchJson("/api/staff/professionals/{$pro->id}/service-categories/{$legacy->id}", ['title' => 'Legacy Renamed'])
        ->assertOk()
        ->assertJsonPath('category.title', 'Legacy Renamed');

    expect(DB::table('site.service_categories')->where('id', $legacy->id)->value('title'))->toBe('Legacy Renamed');

    actingAsStaff($staff)
        ->deleteJson("/api/staff/professionals/{$pro->id}/service-categories/{$legacy->id}")
        ->assertOk();

    expect(DB::table('site.service_categories')->where('id', $legacy->id)->value('deleted_at'))->not->toBeNull();
});

it('404s a legacy category belonging to a different professional', function () {
    [$proA] = staffCatTenant();
    [$proB] = staffCatTenant();
    $legacyB = createServiceCategoryFor($proB, ['title' => 'Legacy B']);

    actingAsStaff(staffCatAdmin())
        ->getJson("/api/staff/professionals/{$proA->id}/service-categories/{$legacyB->id}")
        ->assertStatus(404);
});
