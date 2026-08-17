<?php

use App\Http\Controllers\Api\User\SiteManagement\UserServiceController;
use App\Http\Requests\Api\User\Services\ReorderServiceLayoutRequest;
use App\Http\Requests\Api\User\Services\ReorderServiceRequest;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupIngestTables();
    setupContentTables();
    setupBlocksTable();
    shimPgAdvisoryLockForSqlite();

    // The shared mirror (services + categories + the multi-category pivot) —
    // ServiceResource reads memberships now, so the hand-rolled minimal table
    // no longer suffices.
    setupServicesTable();
});

/**
 * One Fresha-landed service content item. Services cutover: the Fresha half is
 * content.* under a kind='connection' source, anchored exactly as a
 * connector-landed row is.
 */
function svcIsolationFreshaItem(string $userId, string $title, string $recordKey): string
{
    $sourceId = (string) Str::uuid();
    $itemId = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $userId, 'kind' => 'connection',
        'priority' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $userId, 'kind' => 'service',
        'headline_cache' => $title, 'facets_cache' => '{}', 'eligible_cache' => '{}',
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

// Slice 3a Task 5: destroy() and show()/update()/restore() no longer bind an
// implicit Service model (they resolve a raw string id against content.*
// first, then fall back to site.services WHERE source IS NOT NULL) — a
// direct controller call can no longer hand them a pre-resolved, wrong-tenant
// Eloquent model the way this test used to (Model::__toString() would
// silently coerce it to JSON instead of TypeErroring). Rewritten onto the
// HTTP layer, which is the only path that exercises the real string-id
// lookup these methods now do. Covers BOTH halves — content.* (owner-
// authored) and the untouched Fresha fallback — since both are reachable
// through the same endpoint post-cutover (§C2).
it('destroy refuses a manual (content.*) service belonging to another professional', function () {
    [$a, $b] = createTwoTenants();

    $id = actingAsUser($a)->postJson('/api/services', ['title' => 'Secret Cut', 'price_cents' => 5000])
        ->assertCreated()->json('service.id');

    actingAsUser($b)->deleteJson("/api/services/{$id}")->assertNotFound();

    expect(DB::table('content.items')->where('id', $id)->value('removed_at'))->toBeNull();
});

it('destroy refuses a Fresha-sourced service belonging to another professional', function () {
    [$a, $b] = createTwoTenants();
    $id = svcIsolationFreshaItem($a->id, 'Secret Cut', 's:1');

    actingAsUser($b)->deleteJson("/api/services/{$id}")->assertNotFound();

    expect(DB::table('content.items')->where('id', $id)->value('removed_at'))->toBeNull();
});

it('index only returns services belonging to the authenticated professional, across both halves', function () {
    [$a, $b] = createTwoTenants();

    actingAsUser($a)->postJson('/api/services', ['title' => 'A Manual', 'price_cents' => 1000])->assertCreated();
    actingAsUser($b)->postJson('/api/services', ['title' => 'B Manual', 'price_cents' => 2000])->assertCreated();
    // Services cutover: the Fresha half is content.* under a connection
    // source. Built after the manual writes — see svcIsolationFreshaItem().
    svcIsolationFreshaItem($a->id, 'A Fresha', 's:1');
    svcIsolationFreshaItem($b->id, 'B Fresha', 's:2');

    $response = actingAsUser($b)->getJson('/api/services?include_archived=1')->assertOk();

    $titles = collect($response->json('services'))->pluck('title')->all();
    expect($titles)->toContain('B Manual', 'B Fresha');
    expect($titles)->not->toContain('A Manual', 'A Fresha');
});

// The three isolation cases above already go over HTTP. The pending-deletion
// pair below is split ACROSS both layers on purpose (2026-08-14).
//
// EnforcePendingDeletionReadOnly answers a write request with its own 423
// before the controller runs, so an HTTP-only version of these would assert the
// middleware twice and never reach ServicePolicy::update — it would stay green
// with the policy gate deleted. Converting them to HTTP would therefore delete
// a probe, not fix a limitation. Each layer gets its own case instead, and the
// two are told apart by the body: the middleware sends
// error=account_pending_deletion, the policy does not.

it('blocks a pending-deletion professional from reordering services over HTTP (423)', function () {
    $pro = createTenant('svc-reorder-pending-http');
    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->update([
        'status' => 'pending_deletion',
    ]);
    $pro = $pro->fresh()->load('site');

    actingAsUser($pro)
        ->postJson('/api/services/reorder', ['ids' => [(string) Str::uuid()]])
        ->assertStatus(423)
        ->assertJsonPath('error', 'account_pending_deletion');
});

it('blocks a pending-deletion professional from reordering the full layout over HTTP (423)', function () {
    $pro = createTenant('svc-layout-pending-http');
    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->update([
        'status' => 'pending_deletion',
    ]);
    $pro = $pro->fresh()->load('site');

    actingAsUser($pro)
        ->postJson('/api/services/reorder-layout', [
            'categories' => [['id' => null, 'service_ids' => [(string) Str::uuid()]]],
        ])
        ->assertStatus(423)
        ->assertJsonPath('error', 'account_pending_deletion');
});

// SEC-6: reorder()/reorderLayout() previously never called authorizeForUser,
// relying solely on the HTTP-layer EnforcePendingDeletionReadOnly middleware.
// Direct controller invocation bypasses that middleware so these actually
// exercise the new ServicePolicy::update gate — see the note above for why
// that is deliberate rather than a leftover.
it('blocks a pending-deletion professional from reordering services (423, policy gate itself)', function () {
    $pro = createTenant('svc-reorder-pending');
    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->update([
        'status' => 'pending_deletion',
    ]);
    $pro = $pro->fresh()->load('site');

    $req = tenantRequestAs($pro, ['ids' => [(string) Str::uuid()]], 'POST');
    $formReq = ReorderServiceRequest::createFrom($req);
    $formReq->setContainer(app());
    $formReq->validateResolved();

    try {
        app(UserServiceController::class)->reorder($formReq);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
    }
});

it('blocks a pending-deletion professional from reordering the full service layout (423, policy gate itself)', function () {
    $pro = createTenant('svc-layout-pending');
    DB::connection('pgsql')->table('core.users')->where('id', $pro->id)->update([
        'status' => 'pending_deletion',
    ]);
    $pro = $pro->fresh()->load('site');

    $req = tenantRequestAs($pro, [
        'categories' => [
            ['id' => null, 'service_ids' => [(string) Str::uuid()]],
        ],
    ], 'POST');
    $formReq = ReorderServiceLayoutRequest::createFrom($req);
    $formReq->setContainer(app());
    $formReq->validateResolved();

    try {
        app(UserServiceController::class)->reorderLayout($formReq);
        expect(false)->toBeTrue('Expected AuthorizationException');
    } catch (AuthorizationException $e) {
        expect($e->status())->toBe(423);
    }
});
