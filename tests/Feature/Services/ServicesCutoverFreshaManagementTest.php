<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Content\ServiceCollections;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupIngestTables();
    setupContentTables();
    setupBlocksTable();
    shimPgAdvisoryLockForSqlite();
    Queue::fake();
});

function svcCutMgmtUser(): User
{
    return createTenant('svccutmgmt-'.Str::lower(Str::random(8)));
}

/** Fresha content item + a stored connection blob naming it. Returns itemId. */
function svcCutMgmtFresha(User $pro, string $title, string $recordKey): string
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
    // source_items carries observation timestamps (first_seen_at/last_seen_at)
    // and a NOT NULL kind — it has no created_at/updated_at pair.
    DB::table('content.source_items')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $itemId, 'source_id' => $sourceId,
        'coord' => 'fresha:'.$recordKey, 'record_key' => $recordKey, 'kind' => 'service',
        'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);
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
