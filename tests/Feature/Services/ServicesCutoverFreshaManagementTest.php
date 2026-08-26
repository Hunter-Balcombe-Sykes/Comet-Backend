<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Staff\PartnaStaff;
use App\Models\Core\User\User;
use App\Services\Cache\UserCacheService;
use App\Services\Content\ServiceCollections;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupIngestTables();
    setupContentTables();
    setupBlocksTable();
    setupPartnaStaffTable();
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

function svcCutMgmtStaffAdmin(): PartnaStaff
{
    $staff = new PartnaStaff;
    $staff->id = (string) Str::uuid();
    $staff->role = PartnaStaff::ROLE_ADMIN;
    $staff->primary_email = 'admin@partna.au';

    return $staff;
}

function svcCutMgmtUser(): User
{
    return createTenant('svccutmgmt-'.Str::lower(Str::random(8)));
}

/**
 * Fresha content item + a stored connection blob naming it. Returns itemId.
 *
 * Pass $sourceId to land a SECOND item on the same connection source: a user
 * has at most one live Fresha connection (unique on user+surface+resource), so
 * a second call without it would collide.
 */
function svcCutMgmtFresha(User $pro, string $title, string $recordKey, ?string $sourceId = null): string
{
    $existingSource = $sourceId !== null;
    $sourceId ??= (string) Str::uuid();
    $itemId = (string) Str::uuid();
    if (! $existingSource) {
        DB::table('content.sources')->insert([
            'id' => $sourceId, 'user_id' => $pro->id, 'kind' => 'connection',
            'priority' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $pro->id, 'kind' => 'service',
        'headline_cache' => $title, 'facets_cache' => '{}',
        'first_seen_at' => now(), 'last_seen_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    // source_items carries observation timestamps (first_seen_at/last_seen_at)
    // and a NOT NULL kind — it has no created_at/updated_at pair.
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
    if ($existingSource) {
        return $itemId;
    }

    // Through the model, not a raw insert: platform_connections.platform is a
    // GENERATED column off surface_key, and there is no resource_kind column.
    IntegrationConnection::create([
        'user_id' => $pro->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'is_active' => true,
        'payload' => [
            'url' => 'https://www.fresha.com/a/test',
            'selection' => ['mode' => 'employee', 'services' => [['serviceId' => $recordKey, 'name' => $title]], 'hiddenServiceIds' => []],
            'raw' => ['services' => [['serviceId' => $recordKey, 'name' => $title]]],
        ],
    ]);

    return $itemId;
}

it('shows a Fresha service by its content id and 404s an unknown uuid', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Fade', 's:1');

    actingAsUser($pro)->getJson("/api/services/{$itemId}")
        ->assertOk()
        ->assertJsonPath('service.source', 'fresha')
        ->assertJsonPath('service.id', $itemId);

    actingAsUser($pro)->getJson('/api/services/'.(string) Str::uuid())->assertNotFound();
});

it('an owner edit to a Fresha title lands as a manual override, not a facet write', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Vendor Name', 's:1');

    actingAsUser($pro)->patchJson("/api/services/{$itemId}", ['title' => 'Owner Name'])
        ->assertOk()
        ->assertJsonPath('service.title', 'Owner Name')
        ->assertJsonPath('service.is_manual', true);

    expect(DB::table('content.manual_overrides')->where('item_id', $itemId)
        ->where('facet', 'f_text')->where('column_name', 'headline')->count())->toBe(1);
});

it('rejects an edited price on a Fresha service and accepts an echo of the current one', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Fade', 's:1');

    actingAsUser($pro)->patchJson("/api/services/{$itemId}", ['price_cents' => 9900])
        ->assertStatus(422);
    // No offer row exists, so the current price is 0 — echoing it passes.
    actingAsUser($pro)->patchJson("/api/services/{$itemId}", ['price_cents' => 0, 'title' => 'Fade 2'])
        ->assertOk();
});

it('is_active=false on a Fresha service rides the blob hidden list, not a column', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Fade', 's:1');

    actingAsUser($pro)->patchJson("/api/services/{$itemId}", ['is_active' => false])->assertOk();

    $payload = json_decode((string) DB::table('site.platform_connections')
        ->where('user_id', $pro->id)->value('payload'), true);
    expect($payload['selection']['hiddenServiceIds'])->toBe(['s:1']);
});

it('deleting a Fresha service sets items.removed_at and drops it from the booking blob; restore brings it back', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Fade', 's:1');

    actingAsUser($pro)->deleteJson("/api/services/{$itemId}")->assertOk();

    expect(DB::table('content.items')->where('id', $itemId)->value('removed_at'))->not->toBeNull();
    $payload = json_decode((string) DB::table('site.platform_connections')
        ->where('user_id', $pro->id)->value('payload'), true);
    expect($payload['selection']['services'])->toBe([]);

    actingAsUser($pro)->postJson("/api/services/{$itemId}/restore")->assertOk();
    expect(DB::table('content.items')->where('id', $itemId)->value('removed_at'))->toBeNull();
});

it('files a Fresha service under an owner category via the owner membership lane', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Fade', 's:1');
    $collectionId = app(ServiceCollections::class)->create($pro->id, 'Cuts');

    // ServiceResource emits category_id/category_ids off the loaded relation —
    // it has no `categories` array on the wire.
    actingAsUser($pro)->patchJson("/api/services/{$itemId}/category", ['category_id' => $collectionId])
        ->assertOk()
        ->assertJsonPath('service.category_id', $collectionId)
        ->assertJsonPath('service.category_ids.0', $collectionId);

    expect(DB::table('content.collection_items')->where('item_id', $itemId)
        ->whereNull('source_id')->where('collection_id', $collectionId)->count())->toBe(1);
});

it('resync on a Fresha content item deletes its overrides and no legacy fallback remains', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Vendor Name', 's:1');
    actingAsUser($pro)->patchJson("/api/services/{$itemId}", ['title' => 'Owner Name'])->assertOk();

    actingAsUser($pro)->postJson("/api/services/{$itemId}/resync")
        ->assertOk()
        ->assertJsonPath('service.is_manual', false);

    expect(DB::table('content.manual_overrides')->where('item_id', $itemId)->count())->toBe(0);
    // An id that resolves in neither store is a plain 404 — no legacy branch left to fall into.
    actingAsUser($pro)->postJson('/api/services/'.(string) Str::uuid().'/resync')->assertNotFound();
});

it('never resurrects an owner-deleted Fresha service: removed_at survives a projection-style source_item touch', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Fade', 's:1');
    actingAsUser($pro)->deleteJson("/api/services/{$itemId}")->assertOk();

    // What a reappearing scrape does: clears source_items.removed_at. It must NOT touch items.removed_at.
    DB::table('content.source_items')->where('item_id', $itemId)->update(['removed_at' => null]);
    expect(DB::table('content.items')->where('id', $itemId)->value('removed_at'))->not->toBeNull();
});

it('the dashboard index lists Fresha services from content.* with content ids', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Fade', 's:1');

    actingAsUser($pro)->getJson('/api/services')
        ->assertOk()
        ->assertJsonPath('services.0.id', $itemId)
        ->assertJsonPath('services.0.source', 'fresha');
});

it('only_archived surfaces an owner-deleted Fresha service', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Fade', 's:1');
    actingAsUser($pro)->deleteJson("/api/services/{$itemId}")->assertOk();

    actingAsUser($pro)->getJson('/api/services?only_archived=1')
        ->assertOk()
        ->assertJsonPath('services.0.id', $itemId);
});

it('a hidden Fresha service is excluded from the active list but present on the dashboard list as inactive', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Fade', 's:1');
    actingAsUser($pro)->patchJson("/api/services/{$itemId}", ['is_active' => false])->assertOk();

    $active = app(UserCacheService::class)->getActiveServices($pro->id);
    expect(collect($active)->pluck('id')->all())->not->toContain($itemId);

    actingAsUser($pro)->getJson('/api/services')
        ->assertJsonPath('services.0.is_active', false);
});

// ── the staff twins (Task 7) ────────────────────────────────────────────────

it('staff show resolves a Fresha service by content id', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Fade', 's:1');

    actingAsStaff(svcCutMgmtStaffAdmin())->getJson("/api/staff/professionals/{$pro->id}/services/{$itemId}")
        ->assertOk()->assertJsonPath('service.source', 'fresha');
});

it('a staff edit to a Fresha title lands as an override; price edits 422', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Vendor Name', 's:1');
    $staff = svcCutMgmtStaffAdmin();

    actingAsStaff($staff)->patchJson("/api/staff/professionals/{$pro->id}/services/{$itemId}", ['title' => 'Staff Name'])
        ->assertOk()->assertJsonPath('service.is_manual', true);
    actingAsStaff($staff)->patchJson("/api/staff/professionals/{$pro->id}/services/{$itemId}", ['price_cents' => 500])
        ->assertStatus(422);
});

it('staff forceDestroy on a Fresha content item hard-deletes the item row', function () {
    $pro = svcCutMgmtUser();
    $itemId = svcCutMgmtFresha($pro, 'Fade', 's:1');

    actingAsStaff(svcCutMgmtStaffAdmin())->deleteJson("/api/staff/professionals/{$pro->id}/services/{$itemId}/hard")
        ->assertOk();
    expect(DB::table('content.items')->where('id', $itemId)->exists())->toBeFalse();
});

it('a legacy service-category uuid 404s on every staff by-id verb', function () {
    // Services cutover Task 8: the legacy site.service_categories rows served
    // only the by-id fall-backs, which are deleted. One id space now.
    $pro = svcCutMgmtUser();
    $staff = svcCutMgmtStaffAdmin();
    $legacyId = (string) Str::uuid();   // no collection row — the dead id space

    actingAsStaff($staff)->getJson("/api/staff/professionals/{$pro->id}/service-categories/{$legacyId}")->assertNotFound();
    actingAsStaff($staff)->patchJson("/api/staff/professionals/{$pro->id}/service-categories/{$legacyId}", ['title' => 'X'])->assertNotFound();
    actingAsStaff($staff)->deleteJson("/api/staff/professionals/{$pro->id}/service-categories/{$legacyId}")->assertNotFound();
    actingAsStaff($staff)->deleteJson("/api/staff/professionals/{$pro->id}/service-categories/{$legacyId}/hard")->assertNotFound();
    actingAsStaff($staff)->postJson("/api/staff/professionals/{$pro->id}/service-categories/{$legacyId}/restore")->assertNotFound();
    actingAsStaff($staff)->postJson("/api/staff/professionals/{$pro->id}/service-categories/reorder", ['ids' => [$legacyId]])->assertStatus(422);
});

it('disconnecting Fresha hides synced items via source_items.removed_at and spares overridden ones', function () {
    $pro = svcCutMgmtUser();
    $syncedId = svcCutMgmtFresha($pro, 'Synced', 's:1');
    // Same connection source — one live Fresha connection per user.
    $sourceId = (string) DB::table('content.sources')->where('user_id', $pro->id)
        ->where('kind', 'connection')->value('id');
    $editedId = svcCutMgmtFresha($pro, 'Edited', 's:2', $sourceId);
    DB::table('content.manual_overrides')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $editedId,
        'facet' => 'f_text', 'column_name' => 'headline', 'value' => json_encode('Mine'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    actingAsUser($pro)->deleteJson('/api/platforms/fresha')->assertOk();

    expect(DB::table('content.source_items')->where('item_id', $syncedId)->value('removed_at'))->not->toBeNull()
        ->and(DB::table('content.source_items')->where('item_id', $editedId)->value('removed_at'))->toBeNull()
        ->and(DB::table('content.items')->whereIn('id', [$syncedId, $editedId])->whereNotNull('removed_at')->count())->toBe(0);
});

it('the staff category index returns content.collections ids only', function () {
    // Moved here when ServiceCategoryAssignmentRetirementTest retired with its
    // subject table (services cutover Task 12). The legacy id space it used to
    // contrast against no longer exists, so what remains to pin is that the
    // index emits collection ids and nothing else.
    $pro = svcCutMgmtUser();
    $collectionId = app(ServiceCollections::class)->create($pro->id, 'Owner Cat');

    $rows = collect(
        actingAsStaff(svcCutMgmtStaffAdmin())
            ->getJson("/api/staff/professionals/{$pro->id}/service-categories")
            ->assertOk()
            ->json('categories')
    );

    expect($rows->pluck('id')->all())->toBe([$collectionId])
        ->and($rows->pluck('title')->all())->toBe(['Owner Cat']);
});
