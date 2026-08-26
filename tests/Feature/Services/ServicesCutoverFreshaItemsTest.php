<?php

use App\Models\Core\User\User;
use App\Services\Content\FreshaServiceItems;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupIngestTables();
    setupContentTables();
    Queue::fake();
});

/** One Fresha-landed service item: connection source + item + source_item. Returns [itemId, sourceId]. */
function svcCutItemsFresha(string $userId, string $title, string $recordKey): array
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
        'coord' => 'fresha:'.$recordKey, 'user_id' => $userId, 'item_id' => $itemId, 'bound_at' => now(),
    ]);

    return [$itemId, $sourceId];
}

function svcCutItemsUser(): User
{
    return createTenant('svccutitems-'.Str::lower(Str::random(8)));
}

it('findRow resolves a connection-sourced service item and managementRows lists it', function () {
    $pro = svcCutItemsUser();
    [$itemId] = svcCutItemsFresha($pro->id, 'Fade', 's:1');

    $items = app(FreshaServiceItems::class);
    $row = $items->findRow($pro->id, $itemId, null);

    expect($row)->not->toBeNull()
        ->and((string) $row->record_key)->toBe('s:1');
    expect($items->managementRows($pro->id, null)->pluck('id')->map(fn ($id) => (string) $id)->all())
        ->toBe([$itemId]);
});

it('toServiceModel stamps source, external_id, is_manual from overrides, and is_active from the hidden list', function () {
    $pro = svcCutItemsUser();
    [$itemId] = svcCutItemsFresha($pro->id, 'Fade', 's:1');
    DB::table('content.manual_overrides')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $itemId,
        'facet' => 'f_text', 'column_name' => 'body', 'value' => json_encode('Edited'),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $items = app(FreshaServiceItems::class);
    $model = $items->toServiceModel($pro->id, $items->findRow($pro->id, $itemId, null), hidden: ['s:1']);

    expect($model->source)->toBe('fresha')
        ->and((string) $model->external_id)->toBe('s:1')
        ->and($model->is_manual)->toBeTrue()
        ->and($model->is_active)->toBeFalse()
        ->and($model->description)->toBe('Edited');   // the override folds over the raw facet
});

it('selectionServices folds title, description and duration overrides over the vendor values', function () {
    $pro = svcCutItemsUser();
    [$itemId, $sourceId] = svcCutItemsFresha($pro->id, 'Vendor Name', 's:1');
    // Singleton facets are keyed (item_id, source_id) — no id, no created_at.
    DB::table('content.f_text')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId,
        'headline' => 'Vendor Name', 'body' => 'Vendor description', 'updated_at' => now(),
    ]);
    DB::table('content.f_duration')->insert([
        'item_id' => $itemId, 'source_id' => $sourceId, 'seconds' => 1800, 'updated_at' => now(),
    ]);
    foreach ([['f_text', 'headline', 'Owner Name'], ['f_text', 'body', 'Owner description'], ['f_duration', 'seconds', 3600]] as [$facet, $column, $value]) {
        DB::table('content.manual_overrides')->insert([
            'id' => (string) Str::uuid(), 'item_id' => $itemId,
            'facet' => $facet, 'column_name' => $column, 'value' => json_encode($value),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $entry = app(FreshaServiceItems::class)->selectionServices($pro->id)[0];

    expect($entry['name'])->toBe('Owner Name')
        ->and($entry['description'])->toBe('Owner description')
        ->and($entry['duration'])->toBe('1h');
});

it('findRow with liveOnly=false still matches an item whose source_item is removed', function () {
    $pro = svcCutItemsUser();
    [$itemId] = svcCutItemsFresha($pro->id, 'Departed', 's:9');
    DB::table('content.source_items')->where('item_id', $itemId)->update(['removed_at' => now()]);

    $items = app(FreshaServiceItems::class);
    expect($items->findRow($pro->id, $itemId, null))->toBeNull()
        ->and($items->findRow($pro->id, $itemId, null, liveOnly: false))->not->toBeNull();
});
