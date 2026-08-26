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
/**
 * One Fresha-landed service content item — the id space every service verb
 * speaks after the services cutover, anchored exactly as a connector-landed
 * row is.
 */
function staffSvcFreshaItem(string $userId, string $title, string $recordKey): string
{
    $sourceId = (string) Str::uuid();
    $itemId = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $userId, 'kind' => 'connection',
        'priority' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $userId, 'kind' => 'service',
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
        'coord' => 'fresha:'.$recordKey, 'user_id' => $userId, 'item_id' => $itemId, 'bound_at' => now(),
    ]);

    return $itemId;
}

/** The services section's sort_key for one item, as a float (null = unpositioned). */
function staffSvcSortKey(string $itemId): ?float
{
    $key = DB::table('site.section_items')->where('item_id', $itemId)->value('sort_key');

    return $key === null ? null : (float) $key;
}

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

it('lists BOTH the owner-authored and the Fresha halves, now both from content.*', function () {
    [$pro] = staffSvcTenant();

    $manualId = staffSvcOwnerCreate($pro, ['title' => 'Owner Colour']);
    $freshaId = staffSvcFreshaItem($pro->id, 'Fresha Cut', 's:1');

    $rows = collect(
        actingAsStaff(staffSvcAdmin())
            ->getJson("/api/staff/professionals/{$pro->id}/services")
            ->assertOk()
            ->json('services')
    );

    // Both halves, through the same merge the owner's own list performs —
    // and both addressed by content.items ids (services cutover ruling 1).
    expect($rows->pluck('title'))->toContain('Fresha Cut')
        ->and($rows->pluck('title'))->toContain('Owner Colour');
    expect($rows->firstWhere('id', $freshaId)['source'])->toBe('fresha');
    expect($rows->firstWhere('id', $manualId)['source'])->toBeNull();
    expect(DB::table('content.items')->whereIn('id', [$freshaId, $manualId])->count())->toBe(2);
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

// ── ordering: ONE scale, site.section_items.sort_key (spec §3.4) ───────────

it('reorders across both halves onto one section_items scale', function () {
    // Was: "without colliding on services_user_sort_order_uq". That partial
    // UNIQUE (user_id, sort_order) is what made a half-only renumber a 500,
    // and it drops with site.services — services cutover Task 5 puts both
    // halves on sort_key, which carries no uniqueness constraint at all.
    [$pro, $siteId] = staffSvcTenant();

    $manualA = staffSvcOwnerCreate($pro, ['title' => 'Owner A']);
    $manualB = staffSvcOwnerCreate($pro, ['title' => 'Owner B']);
    $fresha = staffSvcFreshaItem($pro->id, 'Fresha Cut', 's:1');

    actingAsStaff(staffSvcAdmin())
        ->postJson("/api/staff/professionals/{$pro->id}/services/reorder", [
            'ids' => [$manualB, $fresha, $manualA],
        ])
        ->assertOk()
        ->assertJson(['ok' => true]);

    // The submitted interleaving IS the stored scale — one shared index
    // across both halves, never per-half (§NEW-I1).
    expect([staffSvcSortKey($manualB), staffSvcSortKey($fresha), staffSvcSortKey($manualA)])
        ->toBe([0.0, 1.0, 2.0]);

    // And the public page follows the manual half's new pin order.
    $publicTitles = array_column(staffSvcPublicServices($siteId, $pro->id), 'title');
    expect(array_search('Owner B', $publicTitles, true))
        ->toBeLessThan(array_search('Owner A', $publicTitles, true));
});

// The case above cannot detect a renumber that does NOTHING: its seeded
// sort_orders are already distinct, so the uniqueness assertion holds, and its
// single Fresha id cannot be out of order relative to another Fresha id. Both
// cases below were added when extracting LegacyServiceSortOrder: stubbing the
// shared helper to a no-op reddened three OWNER tests and not one staff test,
// which is a coverage hole on this surface rather than an incomplete
// extraction.

it('reorder moves a Fresha item past another Fresha item on the staff surface', function () {
    [$pro] = staffSvcTenant();

    // Seeded A-then-B; submitted B-then-A. Only an actual ordering write can
    // reverse them.
    $freshaA = staffSvcFreshaItem($pro->id, 'Fresha A', 's:1');
    $freshaB = staffSvcFreshaItem($pro->id, 'Fresha B', 's:2');

    actingAsStaff(staffSvcAdmin())
        ->postJson("/api/staff/professionals/{$pro->id}/services/reorder", ['ids' => [$freshaB, $freshaA]])
        ->assertOk();

    // The exact stored ranks, not merely "B before A": the submitted array
    // index IS the target rank, and that shared scale is what lets the merged
    // read interleave the two halves at all (§NEW-I1).
    expect([staffSvcSortKey($freshaB), staffSvcSortKey($freshaA)])->toBe([0.0, 1.0]);
});

it('positions an owner-authored manual item by its content id, interleaved with Fresha items', function () {
    // Two rewrites deep, and worth naming both. It began as "renumbers a
    // backfilled manual item's LEGACY ROW from the staff surface" — the staff
    // renumber had to reach site.services through the 'manual:{legacy_uuid}'
    // coord. Cutover Task 5 moved ordering onto the content item alone, so it
    // became "…and leaves its legacy row alone". The table is dropped now and
    // ServiceBackfiller with it, so there is no backfilled PAIR to observe:
    // what remains, and what this asserts, is that the manual half takes its
    // position on the same scale as the Fresha half.
    [$pro] = staffSvcTenant();

    $itemId = ownerServiceItem($pro->id, ['title' => 'Owner Manual']);

    $freshaA = staffSvcFreshaItem($pro->id, 'Fresha A', 's:1');
    $freshaB = staffSvcFreshaItem($pro->id, 'Fresha B', 's:2');

    actingAsStaff(staffSvcAdmin())
        ->postJson("/api/staff/professionals/{$pro->id}/services/reorder", ['ids' => [$itemId, $freshaB, $freshaA]])
        ->assertOk();

    expect([staffSvcSortKey($itemId), staffSvcSortKey($freshaB), staffSvcSortKey($freshaA)])
        ->toBe([0.0, 1.0, 2.0]);
});

it('reorderLayout orders both halves and repositions content collections', function () {
    // Was: "renumbers both halves and orders content collections, without
    // colliding". One category id space (content.collections) and one service
    // id space now — the legacy category blocks and the sort_order collision
    // they could cause are both gone.
    [$pro] = staffSvcTenant();

    $collectionA = (string) actingAsUser($pro)->postJson('/api/service-categories', ['title' => 'Owner Cat A'])
        ->assertCreated()->json('category.id');
    $collectionB = (string) actingAsUser($pro)->postJson('/api/service-categories', ['title' => 'Owner Cat B'])
        ->assertCreated()->json('category.id');

    $manual = staffSvcOwnerCreate($pro, ['title' => 'Owner One']);
    $manualTwo = staffSvcOwnerCreate($pro, ['title' => 'Owner Two']);
    $freshaA = staffSvcFreshaItem($pro->id, 'F A', 's:1');
    $freshaB = staffSvcFreshaItem($pro->id, 'F B', 's:2');

    actingAsStaff(staffSvcAdmin())
        ->postJson("/api/staff/professionals/{$pro->id}/services/reorder-layout", [
            'categories' => [
                ['id' => $collectionB, 'service_ids' => [$manual, $freshaB]],
                ['id' => $collectionA, 'service_ids' => [$manualTwo, $freshaA]],
            ],
        ])
        ->assertOk()
        ->assertJson(['ok' => true]);

    // Content collections repositioned in payload order (B before A).
    expect((int) DB::table('content.collections')->where('id', $collectionB)->value('position'))
        ->toBeLessThan((int) DB::table('content.collections')->where('id', $collectionA)->value('position'));

    // Both halves flattened onto one scale in first-occurrence order.
    expect([
        staffSvcSortKey($manual), staffSvcSortKey($freshaB),
        staffSvcSortKey($manualTwo), staffSvcSortKey($freshaA),
    ])->toBe([0.0, 1.0, 2.0, 3.0]);
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

// ── the empty-block contradiction (owner twin: ServiceResyncCutoverTest) ────
//
// StaffReorderServiceLayoutRequest declared service_ids `required`, and
// `required` rejects []. reorderLayout()'s coverage rule demands that EVERY
// category appear in the payload. For a professional holding ONE empty
// category the two rules contradict: name it and validation 422s, omit it and
// coverage 422s — no legal request exists, so staff could never save that
// professional's layout. Not hypothetical: ServiceCollections::list()
// deliberately keeps an empty user-created collection visible ("add your first
// service here"), and an all-categorised layout has an empty uncategorised
// bucket. `present` is the fix, and it is the same word the owner's request
// carries — the two gate the same payload and must not drift.

/** The grouped payload, reshaped into exactly what reorder-layout expects. */
function staffSvcLayoutFromGrouped(array $grouped): array
{
    $blocks = collect($grouped['categories'])->map(fn ($category) => [
        'id' => $category['id'],
        'service_ids' => collect($category['services'])->pluck('id')->all(),
    ])->all();

    $blocks[] = [
        'id' => null,
        'service_ids' => collect($grouped['uncategorised_services'])->pluck('id')->all(),
    ];

    return ['categories' => $blocks];
}

it('saves a layout for a professional who owns an empty category', function () {
    [$pro] = staffSvcTenant();
    $filled = (string) actingAsUser($pro)->postJson('/api/service-categories', ['title' => 'Colour'])
        ->assertCreated()->json('category.id');
    // Created and deliberately left empty — the state list() keeps visible.
    $empty = (string) actingAsUser($pro)->postJson('/api/service-categories', ['title' => 'Empty'])
        ->assertCreated()->json('category.id');
    $service = staffSvcOwnerCreate($pro, ['title' => 'Balayage']);
    actingAsUser($pro)->patchJson("/api/services/{$service}/category", ['category_id' => $filled])->assertOk();

    // Naming the empty category is the ONLY legal shape: omitting it trips the
    // coverage rule instead.
    actingAsStaff(staffSvcAdmin())
        ->postJson("/api/staff/professionals/{$pro->id}/services/reorder-layout", [
            'categories' => [
                ['id' => $filled, 'service_ids' => [$service]],
                ['id' => $empty, 'service_ids' => []],
                ['id' => null, 'service_ids' => []],
            ],
        ])
        ->assertOk()
        ->assertJson(['ok' => true]);

    // The empty category survives the save and still renders as its own block.
    $grouped = actingAsStaff(staffSvcAdmin())
        ->getJson("/api/staff/professionals/{$pro->id}/services?grouped=1")
        ->assertOk()->json();
    expect(collect($grouped['categories'])->pluck('id')->all())->toContain($empty);
});

it('accepts the exact layout its own grouped list just returned', function () {
    // The round trip, not a hand-built payload: the defect above lives in the
    // relationship between what the GET emits and what the POST accepts, so
    // only feeding one into the other exercises it.
    [$pro] = staffSvcTenant();
    $colour = (string) actingAsUser($pro)->postJson('/api/service-categories', ['title' => 'Colour'])
        ->assertCreated()->json('category.id');
    (string) actingAsUser($pro)->postJson('/api/service-categories', ['title' => 'Empty'])
        ->assertCreated()->json('category.id');
    $balayage = staffSvcOwnerCreate($pro, ['title' => 'Balayage']);
    actingAsUser($pro)->patchJson("/api/services/{$balayage}/category", ['category_id' => $colour])->assertOk();

    $grouped = actingAsStaff(staffSvcAdmin())
        ->getJson("/api/staff/professionals/{$pro->id}/services?grouped=1")
        ->assertOk()->json();
    // Every service is categorised, so the uncategorised bucket the reshape
    // appends is empty — the shape `required` rejected.
    expect($grouped['uncategorised_services'])->toBe([]);

    actingAsStaff(staffSvcAdmin())
        ->postJson("/api/staff/professionals/{$pro->id}/services/reorder-layout", staffSvcLayoutFromGrouped($grouped))
        ->assertOk()
        ->assertJson(['ok' => true]);

    $after = actingAsStaff(staffSvcAdmin())
        ->getJson("/api/staff/professionals/{$pro->id}/services?grouped=1")
        ->assertOk()->json();
    expect(staffSvcLayoutFromGrouped($after))->toBe(staffSvcLayoutFromGrouped($grouped));
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
