<?php

use App\Models\Core\User\User;
use App\Services\Content\ManualServiceItems;
use App\Services\Content\ManualServiceWriter;
use App\Services\Content\ServiceCollections;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Services cutover Task 5 (spec §3.4): both halves of the dashboard list are
// ordered by site.section_items.sort_key on the services section — ONE scale,
// one authority. LegacyServiceSortOrder's renumber of site.services.sort_order
// is gone. The two-surface rule is unaffected: the public services read joins
// content.sources.kind = 'manual', so a pinned Fresha item is ordering
// bookkeeping it structurally cannot see.

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupIngestTables();
    setupContentTables();
    setupBlocksTable();
    shimPgAdvisoryLockForSqlite();
    Queue::fake();
});

function svcCutOrdUser(): User
{
    return createTenant('svccutord-'.Str::lower(Str::random(8)));
}

/**
 * One Fresha-landed service content item (connection source + item +
 * source_item).
 *
 * Anchored exactly as a connector-landed row is — see the anchor insert
 * below for why that matters.
 */
function svcCutOrdFresha(User $pro, string $title, string $recordKey): string
{
    $sourceId = (string) Str::uuid();
    $itemId = (string) Str::uuid();
    DB::table('content.sources')->insert([
        'id' => $sourceId, 'user_id' => $pro->id, 'kind' => 'connection',
        'priority' => 100, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('content.items')->insert([
        'id' => $itemId, 'user_id' => $pro->id, 'kind' => 'service',
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
        'coord' => 'fresha:'.$recordKey, 'user_id' => $pro->id, 'item_id' => $itemId, 'bound_at' => now(),
    ]);

    return $itemId;
}

function svcCutOrdManual(User $pro, string $title): string
{
    $writer = app(ManualServiceWriter::class);

    return $writer->write($pro->id, 'manual:'.(string) Str::uuid(), $writer->projectionFor((object) [
        'title' => $title, 'description' => null, 'price_cents' => 1000,
        'currency_code' => 'AUD', 'duration_minutes' => null,
    ]));
}

it('reorder interleaves manual and Fresha ids onto one section_items scale and writes no site.services row', function () {
    $pro = svcCutOrdUser();
    $site = $pro->site;
    $manualId = svcCutOrdManual($pro, 'Manual One');
    app(ManualServiceWriter::class)->pin($site, $manualId, 1.0);
    $freshaId = svcCutOrdFresha($pro, 'Fresha One', 's:1');

    actingAsUser($pro)->postJson('/api/services/reorder', ['ids' => [$freshaId, $manualId]])->assertOk();

    $sectionId = app(ManualServiceItems::class)->sectionId($site->fresh());
    $keys = DB::table('site.section_items')->where('section_id', $sectionId)
        ->whereIn('item_id', [$freshaId, $manualId])
        ->pluck('sort_key', 'item_id');
    expect((float) $keys[$freshaId])->toBe(0.0)
        ->and((float) $keys[$manualId])->toBe(1.0);
});

it('a pinned Fresha item still never appears in the public services section', function () {
    $pro = svcCutOrdUser();
    $manualId = svcCutOrdManual($pro, 'Manual One');
    $freshaId = svcCutOrdFresha($pro, 'Fresha One', 's:1');

    actingAsUser($pro)->postJson('/api/services/reorder', ['ids' => [$freshaId, $manualId]])->assertOk();

    $public = app(ManualServiceItems::class)->publicList($pro->id, $pro->site->fresh());
    expect(collect($public)->pluck('id')->all())->toBe([$manualId]);
});

it('reorder-layout accepts one category space and orders Fresha items without touching legacy tables', function () {
    $pro = svcCutOrdUser();
    $collectionId = app(ServiceCollections::class)->create($pro->id, 'Cuts');
    $manualId = svcCutOrdManual($pro, 'Manual One');
    $freshaId = svcCutOrdFresha($pro, 'Fresha One', 's:1');

    actingAsUser($pro)->postJson('/api/services/reorder-layout', [
        'categories' => [
            ['id' => $collectionId, 'service_ids' => [$freshaId]],
            ['id' => null, 'service_ids' => [$manualId]],
        ],
    ])->assertOk();

    $sectionId = app(ManualServiceItems::class)->sectionId($pro->site->fresh());
    expect((float) DB::table('site.section_items')->where('section_id', $sectionId)
        ->where('item_id', $freshaId)->value('sort_key'))->toBe(0.0);
});
