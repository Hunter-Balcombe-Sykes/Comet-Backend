<?php

use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;

// Slice 3a Task 5 (§C2/I4 review fix): restore()'s sort_order-recompute
// legacy branch is Fresha-only now (source IS NOT NULL) — owner-authored
// (source IS NULL) services moved to content.*, where restore() only clears
// items.removed_at (section_items.sort_key carries no uniqueness constraint,
// so there is nothing to recompute). Fixtures below are explicitly
// 'source' => 'fresha' to keep exercising the legacy code path this
// regression guard was written against (byte-for-byte restored post-cutover
// per the review), and go through the HTTP layer rather than a direct
// controller call: restore() no longer binds an implicit Service model (it
// resolves a raw string id, content.* first then the Fresha fallback), so a
// direct call can no longer hand it a pre-resolved Eloquent model.
beforeEach(function () {
    tenantHelpersEnsureTables();
    setupServicesTable();
    setupServiceCategoriesTable();
    setupIngestTables();
    setupContentTables();
    setupBlocksTable();
    shimPgAdvisoryLockForSqlite();

    // Mirror the production partial unique index so the constraint fires during tests.
    // SQLite (used in test env) requires schema prefix on the index name, not the table name.
    DB::connection('pgsql')->statement(
        'CREATE UNIQUE INDEX IF NOT EXISTS site.services_pro_sort_order_uq
         ON services (user_id, sort_order)
         WHERE deleted_at IS NULL'
    );
});

it('does not restore a legacy site.services row at all — the id is unaddressable (services cutover, ruling 1)', function () {
    // Was: "restores a Fresha service without 500 when another service claimed
    // its freed sort_order" — a regression guard on restore()'s legacy
    // sort_order recompute. Services-cutover ruling 1 retires that branch:
    // restore() resolves content.items ids only, so this row is unreachable
    // and the recompute it guarded no longer exists (the index it served,
    // services_user_sort_order_uq, drops with the table). The content-side
    // restore semantics are pinned by the second case here and by
    // ServicesCutoverFreshaManagementTest.
    $owner = createTenant('svc-restore-sort-order-cr004');

    $catA = createServiceCategoryFor($owner, ['sort_order' => 0]);
    $serviceA = createServiceFor($owner, ['category_id' => $catA->id, 'sort_order' => 0, 'source' => 'fresha']);
    $serviceA->delete();

    // Another row claims the freed slot — the collision the old recompute existed for.
    $catB = createServiceCategoryFor($owner, ['sort_order' => 1]);
    createServiceFor($owner, ['category_id' => $catB->id, 'sort_order' => 0, 'source' => 'fresha']);

    actingAsUser($owner)->postJson("/api/services/{$serviceA->id}/restore")->assertNotFound();

    $serviceA->refresh();
    expect($serviceA->deleted_at)->not->toBeNull();
});

it('restores a manual (content.*) service by clearing removed_at, no sort_order recompute', function () {
    [$userId, $siteId] = seedUserWithSite();
    $pro = User::query()->with('site')->findOrFail($userId);

    $id = actingAsUser($pro)->postJson('/api/services', ['title' => 'Manual', 'price_cents' => 1000])
        ->assertCreated()->json('service.id');
    actingAsUser($pro)->deleteJson("/api/services/{$id}")->assertOk();

    $response = actingAsUser($pro)->postJson("/api/services/{$id}/restore");

    $response->assertOk();
    expect(DB::table('content.items')->where('id', $id)->value('removed_at'))->toBeNull();
});
