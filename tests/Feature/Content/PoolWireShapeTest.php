<?php

use App\Models\Core\Site\Site;
use App\Site\Pools\PoolResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupSectionsTables();
    setupMediaTables();
    Queue::fake();
});

// The #API-1 equivalent for the pool wire. This fails on ADDITIONS as well as
// removals, which the legacy allowlist could not do — it only ever caught keys
// someone remembered to leave out.
it('emits exactly the declared item keys, no more and no fewer', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id);
    poolPin($siteId, 'shop', shopProduct($pro->id, $store, 'Hat'));

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');

    expect(array_keys($out['library'][0]))->toEqualCanonicalizing(PoolResolver::ITEM_KEYS);
});

it('emits exactly the declared store keys', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id);
    poolPin($siteId, 'shop', shopProduct($pro->id, $store, 'Hat'));

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');
    $card = $out['collections'][$store] ?? null;

    expect($card)->not->toBeNull()
        ->and(array_keys($card))->toEqualCanonicalizing(PoolResolver::STORE_KEYS);
});

it('emits exactly the declared variant keys', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id);
    $itemId = shopProduct($pro->id, $store, 'Tee');
    $sourceId = DB::table('content.source_items')->where('item_id', $itemId)->value('source_id');
    // content.item_variants carries no updated_at column (neither the real
    // migration nor the SQLite stand-in) — the brief's insert included one;
    // dropped here to match the actual schema.
    DB::table('content.item_variants')->insert([
        'id' => (string) Str::uuid(), 'item_id' => $itemId,
        'source_id' => $sourceId, 'label' => 'Small', 'sku' => 'sku-s',
        'position' => 0, 'image_url' => null,
    ]);

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');
    $item = collect($out['library'])->firstWhere('id', $itemId);

    expect(array_keys($item['variants'][0]))->toEqualCanonicalizing(PoolResolver::VARIANT_KEYS);
});

// The affiliate suffix must never be publicly readable again — composition is
// backend-side now, so referralQuery and linkMode have no reason to ship.
it('never publishes referralQuery or linkMode on a store card', function () {
    [$pro, $siteId] = poolTenant();
    $store = shopStore($pro->id, ['referral_query' => 'ref=secret']);
    poolPin($siteId, 'shop', shopProduct($pro->id, $store, 'Hat'));

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'shop');

    expect(json_encode($out['collections']))->not->toContain('ref=secret')
        ->and($out['collections'][$store])->not->toHaveKey('referralQuery')
        ->and($out['collections'][$store])->not->toHaveKey('linkMode');
});
