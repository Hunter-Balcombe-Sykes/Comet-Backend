<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\Site;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Services\PublicSite\SitepageDataResolverService;
use App\Site\Documents\BuildState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Slice 3b Task 11: StaffServiceManagementController's nine methods now read
// and write content.* through the SAME collaborators the owner-facing routes
// use (ManualServiceItems / ManualServiceWriter / ServiceCollections), with
// the Fresha half still merged in from the untouched site.services rows.
//
// The defect this file exists to catch is not "staff sees stale data": post-3a
// an owner-authored service has NO site.services row at all, so staff could
// not see, edit or delete it. And a staff edit to a row that DOES still exist
// returned 200 while writing a lane nothing public reads — a silent success,
// which is worse than an error. Every edit case below therefore asserts the
// PUBLIC read moved, never merely that the response was 200.

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupIngestTables();
    setupContentTables();
    setupServicesTable();
    setupBlocksTable();
    setupPartnaStaffTable();
    // store()/update()/reorder()/reorderLayout() take the same
    // pg_advisory_xact_lock(hashtext(...)) the owner routes do — shim it under
    // SQLite so the real locked code path runs rather than being skipped.
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
 * visible to another under a direct single-file `pest <path>` invocation —
 * only whole-suite/--filter runs parse sibling files — so nothing here leans
 * on a neighbour's definition.
 */
function staffSvcAdmin(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $staff->primary_email = 'admin@partna.au';

    return $staff;
}

/** @return array{0: User, 1: string} [$pro, $siteId] */
function staffSvcTenant(): array
{
    $pro = createTenant('staffsvc-'.Str::lower(Str::random(8)));

    return [$pro, (string) $pro->site->id];
}

/** Create an owner-authored service THROUGH THE OWNER'S OWN ENDPOINT and return its id. */
function staffSvcOwnerCreate(User $pro, array $payload = []): string
{
    return (string) actingAsUser($pro)
        ->postJson('/api/services', $payload + [
            'title' => 'Created via dashboard',
            'price_cents' => 5000,
            'currency_code' => 'AUD',
        ])
        ->assertCreated()
        ->json('service.id');
}

/** The public sitepage payload's services list — the read that actually renders. */
function staffSvcPublicServices(string $siteId, string $userId): array
{
    $site = Site::query()->findOrFail($siteId);

    return app(SitepageDataResolverService::class)->buildServicesData($site, $userId)['services'];
}

/**
 * The three invalidation lanes, asserted as an EXACT revision delta.
 *
 * A ">0" check is worthless here: slice 3a shipped a three-lane test that
 * stayed green with the whole BuildState lane deleted, because a neighbouring
 * write already cleared that bar. $expectedDelta is 2 where the request also
 * goes through ProjectionWriter::writeManualItem() (which bumps once on its
 * own) and 1 where invalidate() is the only bumper — either way, deleting
 * invalidate()'s bump changes the number.
 */
function staffSvcAssertThreeLanes(string $siteId, int $expectedDelta, Closure $act): void
{
    DB::table('site.sites')->where('id', $siteId)->update(['updated_at' => now()->subMinute()]);
    $beforeUpdatedAt = DB::table('site.sites')->where('id', $siteId)->value('updated_at');
    $beforeRevision = BuildState::read($siteId)['content_revision'];

    $act();

    expect(DB::table('site.site_build_state')->where('site_id', $siteId)->value('content_revision'))
        ->toBe($beforeRevision + $expectedDelta);
    expect(DB::table('site.sites')->where('id', $siteId)->value('updated_at'))->not->toBe($beforeUpdatedAt);
    Queue::assertPushed(CloudflareCachePurgeJob::class);
}

// ── the defect, from the outside ────────────────────────────────────────────

it('shows staff a service created through the user endpoints', function () {
    [$pro] = staffSvcTenant();
    staffSvcOwnerCreate($pro);

    $response = actingAsStaff(staffSvcAdmin())
        ->getJson("/api/staff/professionals/{$pro->id}/services")
        ->assertOk();

    expect(collect($response->json('services'))->pluck('title'))
        ->toContain('Created via dashboard');
});

it('shows staff a single owner-authored service by id', function () {
    [$pro] = staffSvcTenant();
    $id = staffSvcOwnerCreate($pro);

    actingAsStaff(staffSvcAdmin())
        ->getJson("/api/staff/professionals/{$pro->id}/services/{$id}")
        ->assertOk()
        ->assertJsonPath('service.title', 'Created via dashboard');
});

it('lets staff edit that service and the change reaches the public payload', function () {
    [$pro, $siteId] = staffSvcTenant();
    $id = staffSvcOwnerCreate($pro);

    actingAsStaff(staffSvcAdmin())
        ->patchJson("/api/staff/professionals/{$pro->id}/services/{$id}", [
            'title' => 'Edited by staff',
            'price_cents' => 9900,
        ])
        ->assertOk();

    // The silent-200 defect: the response body is NOT the evidence. What the
    // customer's page renders is.
    $public = staffSvcPublicServices($siteId, $pro->id);
    expect(array_column($public, 'title'))->toContain('Edited by staff');
    expect(array_column($public, 'title'))->not->toContain('Created via dashboard');
    expect(array_column($public, 'price_cents'))->toContain(9900);

    // And the OWNER's own dashboard read agrees — one store, not two.
    $owner = actingAsUser($pro)->getJson('/api/services')->assertOk()->json('services');
    expect(collect($owner)->firstWhere('id', $id)['title'])->toBe('Edited by staff');
});

it('lets staff hide that service, and the public payload drops it', function () {
    [$pro, $siteId] = staffSvcTenant();
    $id = staffSvcOwnerCreate($pro);

    actingAsStaff(staffSvcAdmin())
        ->patchJson("/api/staff/professionals/{$pro->id}/services/{$id}", ['is_active' => false])
        ->assertOk();

    expect(array_column(staffSvcPublicServices($siteId, $pro->id), 'id'))->not->toContain($id);
    expect(DB::table('site.section_items')->where('item_id', $id)->value('state'))->toBe('excluded');
});

it('lets staff delete that service', function () {
    [$pro, $siteId] = staffSvcTenant();
    $id = staffSvcOwnerCreate($pro);

    actingAsStaff(staffSvcAdmin())
        ->deleteJson("/api/staff/professionals/{$pro->id}/services/{$id}")
        ->assertOk()
        ->assertJson(['deleted' => true]);

    // items.removed_at is the ONE-WAY home for a user deletion.
    expect(DB::table('content.items')->where('id', $id)->value('removed_at'))->not->toBeNull();
    // NEVER source_items.removed_at — that column is cleared on reappearance
    // and would resurrect a service its owner deleted.
    expect(DB::table('content.source_items')->where('item_id', $id)->whereNotNull('removed_at')->count())->toBe(0);

    expect(array_column(staffSvcPublicServices($siteId, $pro->id), 'id'))->not->toContain($id);
});

it('lets staff restore a service it deleted, and the public payload gets it back', function () {
    [$pro, $siteId] = staffSvcTenant();
    $id = staffSvcOwnerCreate($pro);

    actingAsStaff(staffSvcAdmin())->deleteJson("/api/staff/professionals/{$pro->id}/services/{$id}")->assertOk();
    actingAsStaff(staffSvcAdmin())->postJson("/api/staff/professionals/{$pro->id}/services/{$id}/restore")
        ->assertOk()
        ->assertJson(['restored' => true]);

    expect(DB::table('content.items')->where('id', $id)->value('removed_at'))->toBeNull();
    expect(array_column(staffSvcPublicServices($siteId, $pro->id), 'id'))->toContain($id);
});

it('lets staff hard-delete an owner-authored service', function () {
    [$pro, $siteId] = staffSvcTenant();
    $id = staffSvcOwnerCreate($pro);

    actingAsStaff(staffSvcAdmin())
        ->deleteJson("/api/staff/professionals/{$pro->id}/services/{$id}/hard")
        ->assertOk()
        ->assertJson(['deleted' => true, 'hard' => true]);

    // Hard means gone, not tombstoned — the row itself is removed, along with
    // the curation and source_item rows that pointed at it.
    expect(DB::table('content.items')->where('id', $id)->exists())->toBeFalse();
    expect(DB::table('content.source_items')->where('item_id', $id)->exists())->toBeFalse();
    expect(DB::table('site.section_items')->where('item_id', $id)->exists())->toBeFalse();
    expect(array_column(staffSvcPublicServices($siteId, $pro->id), 'id'))->not->toContain($id);
});

it('creates a service through the staff endpoint that the owner and the public read both see', function () {
    [$pro, $siteId] = staffSvcTenant();

    $id = (string) actingAsStaff(staffSvcAdmin())
        ->postJson("/api/staff/professionals/{$pro->id}/services", [
            'title' => 'Created by staff',
            'price_cents' => 7700,
            'currency_code' => 'AUD',
        ])
        ->assertCreated()
        ->json('service.id');

    // Landed in content.*, not site.services — the store staff writes must be
    // the store the owner and the page read.
    expect(DB::table('content.items')->where('id', $id)->where('kind', 'service')->exists())->toBeTrue();

    $owner = actingAsUser($pro)->getJson('/api/services')->assertOk()->json('services');
    expect(collect($owner)->pluck('id'))->toContain($id);
    expect(array_column(staffSvcPublicServices($siteId, $pro->id), 'title'))->toContain('Created by staff');
});

// ── the merged list: manual half AND Fresha half ────────────────────────────

it('lists BOTH the owner-authored (content.*) and the Fresha (site.services) halves', function () {
    [$pro] = staffSvcTenant();

    $freshaId = ownerService($pro->id, [
        'title' => 'Fresha Cut', 'source' => 'fresha', 'external_id' => 's:1', 'sort_order' => 0,
    ]);
    $manualId = staffSvcOwnerCreate($pro, ['title' => 'Owner Colour']);

    $titles = collect(
        actingAsStaff(staffSvcAdmin())
            ->getJson("/api/staff/professionals/{$pro->id}/services")
            ->assertOk()
            ->json('services')
    )->pluck('title');

    // Both halves, through the same merge the owner's own list performs.
    expect($titles)->toContain('Fresha Cut');
    expect($titles)->toContain('Owner Colour');
    expect(DB::table('site.services')->where('id', $freshaId)->exists())->toBeTrue();
    expect(DB::table('content.items')->where('id', $manualId)->exists())->toBeTrue();
});

it('archives filters read the content.* half, not site.services', function () {
    [$pro] = staffSvcTenant();
    $live = staffSvcOwnerCreate($pro, ['title' => 'Still Here']);
    $gone = staffSvcOwnerCreate($pro, ['title' => 'Archived One']);

    actingAsStaff(staffSvcAdmin())->deleteJson("/api/staff/professionals/{$pro->id}/services/{$gone}")->assertOk();

    $default = collect(actingAsStaff(staffSvcAdmin())
        ->getJson("/api/staff/professionals/{$pro->id}/services")->assertOk()->json('services'))->pluck('id');
    expect($default)->toContain($live)->not->toContain($gone);

    $onlyArchived = collect(actingAsStaff(staffSvcAdmin())
        ->getJson("/api/staff/professionals/{$pro->id}/services?only_archived=1")->assertOk()->json('services'))->pluck('id');
    expect($onlyArchived)->toContain($gone)->not->toContain($live);

    $includeArchived = collect(actingAsStaff(staffSvcAdmin())
        ->getJson("/api/staff/professionals/{$pro->id}/services?include_archived=1")->assertOk()->json('services'))->pluck('id');
    expect($includeArchived)->toContain($gone)->toContain($live);
});

// ── categories: one id space the owner and staff both see ───────────────────

it('shows a category the OWNER created, with the owner service filed under it', function () {
    [$pro] = staffSvcTenant();

    $categoryId = (string) actingAsUser($pro)
        ->postJson('/api/service-categories', ['title' => 'Colour Work'])
        ->assertCreated()->json('category.id');
    $serviceId = staffSvcOwnerCreate($pro, ['title' => 'Balayage']);

    actingAsUser($pro)->patchJson("/api/services/{$serviceId}/category", ['category_id' => $categoryId])->assertOk();

    $grouped = actingAsStaff(staffSvcAdmin())
        ->getJson("/api/staff/professionals/{$pro->id}/services?grouped=1")
        ->assertOk()->json();

    $block = collect($grouped['categories'])->firstWhere('id', $categoryId);
    expect($block)->not->toBeNull();
    expect($block['title'])->toBe('Colour Work');
    expect(collect($block['services'])->pluck('id'))->toContain($serviceId);
    expect(collect($grouped['uncategorised_services'])->pluck('id'))->not->toContain($serviceId);
});

it('lets staff file an owner service into a content collection, and the owner sees the membership', function () {
    [$pro] = staffSvcTenant();

    $categoryId = (string) actingAsUser($pro)
        ->postJson('/api/service-categories', ['title' => 'Staff Filed'])
        ->assertCreated()->json('category.id');
    $serviceId = staffSvcOwnerCreate($pro, ['title' => 'Needs Filing']);

    actingAsStaff(staffSvcAdmin())
        ->patchJson("/api/staff/professionals/{$pro->id}/services/{$serviceId}", [
            'category_ids' => [$categoryId],
        ])
        ->assertOk();

    // The OWNER's read is the evidence, not the staff response.
    $owner = collect(actingAsUser($pro)->getJson('/api/services')->assertOk()->json('services'))
        ->firstWhere('id', $serviceId);
    expect($owner['category_ids'])->toBe([$categoryId]);
});

// ── ordering: services_user_sort_order_uq is GLOBAL per user ────────────────

it('reorders across both halves without colliding on services_user_sort_order_uq', function () {
    [$pro, $siteId] = staffSvcTenant();

    // A legacy owner-authored site.services row (ServiceBackfiller never
    // deletes these) sits alongside the Fresha row — renumbering only the
    // Fresha subset to a dense 0..N-1 lands on a slot this row holds.
    ownerService($pro->id, ['title' => 'Legacy Manual', 'source' => null, 'sort_order' => 0]);
    $fresha = ownerService($pro->id, ['title' => 'Fresha Cut', 'source' => 'fresha', 'external_id' => 's:1', 'sort_order' => 1]);
    $manualA = staffSvcOwnerCreate($pro, ['title' => 'Owner A']);
    $manualB = staffSvcOwnerCreate($pro, ['title' => 'Owner B']);

    actingAsStaff(staffSvcAdmin())
        ->postJson("/api/staff/professionals/{$pro->id}/services/reorder", [
            'ids' => [$manualB, $fresha, $manualA],
        ])
        ->assertOk()
        ->assertJson(['ok' => true]);

    // No duplicate live sort_order for this user — the constraint is not
    // scoped by source, so a half-only renumber is a 500 waiting to happen.
    $sortOrders = DB::table('site.services')->where('user_id', $pro->id)->whereNull('deleted_at')->pluck('sort_order');
    expect($sortOrders->unique()->count())->toBe($sortOrders->count());

    // The merged staff list reconstructs the submitted interleaving — both
    // halves are numbered on ONE shared index, never per-half.
    $order = collect(actingAsStaff(staffSvcAdmin())
        ->getJson("/api/staff/professionals/{$pro->id}/services")->assertOk()->json('services'))
        ->pluck('id')
        ->filter(fn ($id) => in_array($id, [$manualA, $manualB, $fresha], true))
        ->values()
        ->all();
    expect($order)->toBe([$manualB, $fresha, $manualA]);

    // And the public page follows the manual half's new pin order.
    $publicTitles = array_column(staffSvcPublicServices($siteId, $pro->id), 'title');
    expect(array_search('Owner B', $publicTitles, true))
        ->toBeLessThan(array_search('Owner A', $publicTitles, true));
});

it('reorderLayout renumbers both halves and orders content collections, without colliding', function () {
    [$pro] = staffSvcTenant();

    $catA = createServiceCategoryFor($pro, ['title' => 'Fresha Cat A', 'sort_order' => 0]);
    $catB = createServiceCategoryFor($pro, ['title' => 'Fresha Cat B', 'sort_order' => 1]);
    $collectionA = (string) actingAsUser($pro)->postJson('/api/service-categories', ['title' => 'Owner Cat A'])
        ->assertCreated()->json('category.id');
    $collectionB = (string) actingAsUser($pro)->postJson('/api/service-categories', ['title' => 'Owner Cat B'])
        ->assertCreated()->json('category.id');

    $freshaA = ownerService($pro->id, ['title' => 'F A', 'source' => 'fresha', 'external_id' => 's:1', 'sort_order' => 0]);
    $freshaB = ownerService($pro->id, ['title' => 'F B', 'source' => 'fresha', 'external_id' => 's:2', 'sort_order' => 1]);
    DB::table('site.service_category_assignments')->insert([
        ['service_id' => $freshaA, 'service_category_id' => (string) $catA->id, 'created_at' => now(), 'updated_at' => now()],
        ['service_id' => $freshaB, 'service_category_id' => (string) $catB->id, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $manual = staffSvcOwnerCreate($pro, ['title' => 'Owner One']);
    $manualTwo = staffSvcOwnerCreate($pro, ['title' => 'Owner Two']);

    // StaffReorderServiceLayoutRequest declares service_ids `required`, which
    // rejects an empty array — every block carries at least one id.
    actingAsStaff(staffSvcAdmin())
        ->postJson("/api/staff/professionals/{$pro->id}/services/reorder-layout", [
            'categories' => [
                ['id' => $collectionB, 'service_ids' => [$manual]],
                ['id' => (string) $catB->id, 'service_ids' => [$freshaB]],
                ['id' => (string) $catA->id, 'service_ids' => [$freshaA]],
                ['id' => $collectionA, 'service_ids' => [$manualTwo]],
            ],
        ])
        ->assertOk()
        ->assertJson(['ok' => true]);

    // Legacy categories renumbered in payload order (B before A).
    expect((int) DB::table('site.service_categories')->where('id', $catB->id)->value('sort_order'))
        ->toBeLessThan((int) DB::table('site.service_categories')->where('id', $catA->id)->value('sort_order'));

    // Content collections renumbered in payload order too (B before A).
    expect((int) DB::table('content.collections')->where('id', $collectionB)->value('position'))
        ->toBeLessThan((int) DB::table('content.collections')->where('id', $collectionA)->value('position'));

    // No duplicate live sort_order for this user.
    $sortOrders = DB::table('site.services')->where('user_id', $pro->id)->whereNull('deleted_at')->pluck('sort_order');
    expect($sortOrders->unique()->count())->toBe($sortOrders->count());

    // The manual service came first in the payload, so it leads the merged list.
    $order = collect(actingAsStaff(staffSvcAdmin())
        ->getJson("/api/staff/professionals/{$pro->id}/services")->assertOk()->json('services'))->pluck('id')->all();
    expect(array_search($manual, $order, true))->toBe(0);
});

// ── the three cache lanes, per write route ──────────────────────────────────
//
// ServiceCollections and ManualServiceWriter's private mutators deliberately
// do not self-invalidate — they hold no site context — so invalidation lives
// with this controller and NO CI check will report a missing call.

it('fires all three invalidation lanes on store', function () {
    [$pro, $siteId] = staffSvcTenant();

    staffSvcAssertThreeLanes($siteId, 2, function () use ($pro) {
        actingAsStaff(staffSvcAdmin())
            ->postJson("/api/staff/professionals/{$pro->id}/services", ['title' => 'Lanes', 'price_cents' => 1000])
            ->assertCreated();
    });
});

it('fires all three invalidation lanes on update', function () {
    [$pro, $siteId] = staffSvcTenant();
    $id = staffSvcOwnerCreate($pro);

    staffSvcAssertThreeLanes($siteId, 2, function () use ($pro, $id) {
        actingAsStaff(staffSvcAdmin())
            ->patchJson("/api/staff/professionals/{$pro->id}/services/{$id}", ['title' => 'Lanes'])
            ->assertOk();
    });
});

it('fires all three invalidation lanes on destroy', function () {
    [$pro, $siteId] = staffSvcTenant();
    $id = staffSvcOwnerCreate($pro);

    staffSvcAssertThreeLanes($siteId, 1, function () use ($pro, $id) {
        actingAsStaff(staffSvcAdmin())
            ->deleteJson("/api/staff/professionals/{$pro->id}/services/{$id}")
            ->assertOk();
    });
});

it('fires all three invalidation lanes on restore', function () {
    [$pro, $siteId] = staffSvcTenant();
    $id = staffSvcOwnerCreate($pro);
    actingAsStaff(staffSvcAdmin())->deleteJson("/api/staff/professionals/{$pro->id}/services/{$id}")->assertOk();

    staffSvcAssertThreeLanes($siteId, 1, function () use ($pro, $id) {
        actingAsStaff(staffSvcAdmin())
            ->postJson("/api/staff/professionals/{$pro->id}/services/{$id}/restore")
            ->assertOk();
    });
});

it('fires all three invalidation lanes on forceDestroy', function () {
    [$pro, $siteId] = staffSvcTenant();
    $id = staffSvcOwnerCreate($pro);

    staffSvcAssertThreeLanes($siteId, 1, function () use ($pro, $id) {
        actingAsStaff(staffSvcAdmin())
            ->deleteJson("/api/staff/professionals/{$pro->id}/services/{$id}/hard")
            ->assertOk();
    });
});

it('fires all three invalidation lanes on reorder', function () {
    [$pro, $siteId] = staffSvcTenant();
    $a = staffSvcOwnerCreate($pro, ['title' => 'A']);
    $b = staffSvcOwnerCreate($pro, ['title' => 'B']);

    staffSvcAssertThreeLanes($siteId, 1, function () use ($pro, $a, $b) {
        actingAsStaff(staffSvcAdmin())
            ->postJson("/api/staff/professionals/{$pro->id}/services/reorder", ['ids' => [$b, $a]])
            ->assertOk();
    });
});

it('fires all three invalidation lanes on reorderLayout', function () {
    [$pro, $siteId] = staffSvcTenant();
    $a = staffSvcOwnerCreate($pro, ['title' => 'A']);
    $b = staffSvcOwnerCreate($pro, ['title' => 'B']);

    staffSvcAssertThreeLanes($siteId, 1, function () use ($pro, $a, $b) {
        actingAsStaff(staffSvcAdmin())
            ->postJson("/api/staff/professionals/{$pro->id}/services/reorder-layout", [
                'categories' => [['id' => null, 'service_ids' => [$b, $a]]],
            ])
            ->assertOk();
    });
});

// ── tenancy ─────────────────────────────────────────────────────────────────

it('never resolves another professional\'s owner-authored service', function () {
    [$pro] = staffSvcTenant();
    [$other] = staffSvcTenant();
    $id = staffSvcOwnerCreate($other, ['title' => 'Not Yours']);

    actingAsStaff(staffSvcAdmin())->getJson("/api/staff/professionals/{$pro->id}/services/{$id}")->assertNotFound();
    actingAsStaff(staffSvcAdmin())->patchJson("/api/staff/professionals/{$pro->id}/services/{$id}", ['title' => 'X'])->assertNotFound();
    actingAsStaff(staffSvcAdmin())->deleteJson("/api/staff/professionals/{$pro->id}/services/{$id}")->assertNotFound();

    expect(DB::table('content.items')->where('id', $id)->value('removed_at'))->toBeNull();
});
