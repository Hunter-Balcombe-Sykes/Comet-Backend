<?php

use App\Models\Core\User\User;
use App\Services\Content\ManualServiceItems;
use App\Services\Content\ServiceCollections;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// POST /api/services/reorder-layout (UserServiceController::reorderLayout) —
// full layout save (categories + the services within each).
//
// This file began as regression coverage for a 500 (Postgres 23505) whenever a
// layout had 2+ categories each holding a service: the per-category loop
// restarted sort_order at 0 per bucket, while services_user_sort_order_uq was a
// partial UNIQUE on (user_id, sort_order) — GLOBAL per professional.
//
// Services cutover Task 5 retires that failure mode outright: both halves are
// ordered by site.section_items.sort_key, which carries no uniqueness
// constraint, and the index that made a repeated position fatal drops with
// site.services. What survives, and is what these cases now assert, is the
// property the fix was really protecting — ONE global running position across
// buckets in payload order, never a per-bucket restart — plus the two stances
// the endpoint has held since: it writes ORDER only, never membership, and
// both halves land on the same scale in one request.
beforeEach(function () {
    tenantHelpersEnsureTables();
    setupIngestTables();
    setupContentTables();
    setupBlocksTable();
    // reorderLayout() takes pg_advisory_xact_lock(hashtext(...)) — Postgres-only;
    // shim it as a SQLite UDF so the real production code path runs under test.
    shimPgAdvisoryLockForSqlite();
});

/** One Fresha-landed service content item — the id space the layout verb speaks. */
function svcLayoutReorderFreshaItem(User $pro, string $title, string $recordKey): string
{
    $sourceId = (string) Str::uuid();
    $itemId = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $pro->id, 'kind' => 'connection',
        'priority' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $pro->id, 'kind' => 'service',
        'headline_cache' => $title, 'facets_cache' => '{}', 'eligible_cache' => '{}',
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
        'coord' => 'fresha:'.$recordKey, 'record_key' => $recordKey, 'kind' => 'service',
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    return $itemId;
}

/** The services section's sort_key for one item, as a float (null when unpositioned). */
function svcLayoutReorderKey(User $pro, string $itemId): ?float
{
    $sectionId = app(ManualServiceItems::class)->sectionId($pro->site->fresh());
    $key = DB::table('site.section_items')->where('section_id', $sectionId)
        ->where('item_id', $itemId)->value('sort_key');

    return $key === null ? null : (float) $key;
}

it('reorders a layout across two categories that each hold a service without a 500', function () {
    $pro = createTenant('svc-layout-two-cats');

    $collections = app(ServiceCollections::class);
    $catA = $collections->create($pro->id, 'Cat A');
    $catB = $collections->create($pro->id, 'Cat B');
    $serviceA = svcLayoutReorderFreshaItem($pro, 'Service A', 's:a');
    $serviceB = svcLayoutReorderFreshaItem($pro, 'Service B', 's:b');

    actingAsUser($pro)->postJson('/api/services/reorder-layout', [
        'categories' => [
            ['id' => $catA, 'service_ids' => [$serviceA]],
            ['id' => $catB, 'service_ids' => [$serviceB]],
        ],
    ])->assertOk();

    // ONE global running counter across buckets in payload order, not a
    // per-bucket restart — the two services must not share a position.
    expect([svcLayoutReorderKey($pro, $serviceA), svcLayoutReorderKey($pro, $serviceB)])
        ->toEqualCanonicalizing([0.0, 1.0]);
});

it('does NOT persist a re-filed category, and still orders globally', function () {
    $pro = createTenant('svc-layout-move');

    $collections = app(ServiceCollections::class);
    $catA = $collections->create($pro->id, 'Cat A');
    $catB = $collections->create($pro->id, 'Cat B');
    $stays = svcLayoutReorderFreshaItem($pro, 'Stays', 's:1');
    $moves = svcLayoutReorderFreshaItem($pro, 'Moves', 's:2');
    $resident = svcLayoutReorderFreshaItem($pro, 'Resident', 's:3');
    $collections->assign($pro->id, $moves, $catA, null);
    $collections->assign($pro->id, $resident, $catB, null);

    // $moves is asked to leave category A for category B, landing after
    // $resident. Only the landing is honoured.
    actingAsUser($pro)->postJson('/api/services/reorder-layout', [
        'categories' => [
            ['id' => $catA, 'service_ids' => [$stays]],
            ['id' => $catB, 'service_ids' => [$resident, $moves]],
        ],
    ])->assertOk();

    // Membership unmoved: still catA, and catB gained nothing.
    expect(DB::table('content.collection_items')->where('item_id', $moves)->pluck('collection_id')->all())
        ->toBe([$catA]);
    expect(DB::table('content.collection_items')->where('collection_id', $catB)->pluck('item_id')->all())
        ->toBe([$resident]);

    // Order IS applied: $moves lands after $resident.
    expect(svcLayoutReorderKey($pro, $moves))->toBeGreaterThan(svcLayoutReorderKey($pro, $resident));
});

it('swaps the order of two services within a category and keeps the collection order intact', function () {
    $pro = createTenant('svc-layout-swap');

    $collections = app(ServiceCollections::class);
    $catA = $collections->create($pro->id, 'Cat A');
    $catB = $collections->create($pro->id, 'Cat B');
    $first = svcLayoutReorderFreshaItem($pro, 'First', 's:1');
    $second = svcLayoutReorderFreshaItem($pro, 'Second', 's:2');
    $other = svcLayoutReorderFreshaItem($pro, 'Other', 's:3');

    // Swap $first/$second's relative order within category A; category B unchanged.
    actingAsUser($pro)->postJson('/api/services/reorder-layout', [
        'categories' => [
            ['id' => $catA, 'service_ids' => [$second, $first]],
            ['id' => $catB, 'service_ids' => [$other]],
        ],
    ])->assertOk();

    expect(svcLayoutReorderKey($pro, $second))->toBeLessThan(svcLayoutReorderKey($pro, $first));
    // Distinct positions across all three, on one scale.
    expect(collect([$first, $second, $other])->map(fn ($id) => svcLayoutReorderKey($pro, $id))->unique())
        ->toHaveCount(3);

    // Collection order follows the payload's block order.
    expect($collections->list($pro->id)->map(fn ($row) => (string) $row->id)->all())->toBe([$catA, $catB]);
});

it('reorders a manual service by sort_key alongside a Fresha layout in one request', function () {
    [$userId] = seedUserWithSite();
    $pro = User::query()->with('site')->findOrFail($userId);

    $catA = app(ServiceCollections::class)->create($pro->id, 'Cat A');
    $manualId = actingAsUser($pro)->postJson('/api/services', ['title' => 'Manual', 'price_cents' => 1000])
        ->assertCreated()->json('service.id');
    // After the manual write: a hand-built source_item carries no identity
    // keys, and writeManualItem() re-resolves the whole (user, kind) set.
    $freshaId = svcLayoutReorderFreshaItem($pro, 'Fresha', 's:1');

    actingAsUser($pro)->postJson('/api/services/reorder-layout', [
        'categories' => [
            ['id' => $catA, 'service_ids' => [$freshaId]],
            ['id' => null, 'service_ids' => [$manualId]],
        ],
    ])->assertOk();

    // Both halves land on the one scale, the Fresha block first.
    expect(svcLayoutReorderKey($pro, $freshaId))->toBe(0.0)
        ->and(svcLayoutReorderKey($pro, $manualId))->toBe(1.0);
});
