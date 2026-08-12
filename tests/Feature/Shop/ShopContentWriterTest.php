<?php

use App\Services\Migration\ShopBackfiller;
use App\Services\Shop\ShopContentWriter;
use Illuminate\Support\Facades\DB;

// Slice 5a §3.5: syncStore() is what Tasks 6/7/8 call on every re-fetch. The
// legacy ShopCatalog::syncLatest() deletes every row for a brand and
// re-inserts — ported literally into content.* that re-mints item ids every
// sync, breaking analytics.item_views references and any curation pin. These
// tests pin the reconcile-by-coord replacement: same id across a re-sync,
// removed_at (never source_items.removed_at) on drop, and never a hard delete.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupIngestTables();
    setupContentTables();
});

it('keeps the same item id when a product is re-synced', function () {
    [$user, $collectionId] = makeStoreCollection();
    $blob = ['url' => 'https://s.test/a', 'title' => 'Tee', 'price' => '10.00', 'available' => true];

    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId, [$blob], 'AUD');
    $first = DB::table('content.items')->where('kind', 'product')->value('id');

    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId,
        [array_merge($blob, ['price' => '12.00'])], 'AUD');

    expect(DB::table('content.items')->where('kind', 'product')->value('id'))->toBe($first)
        ->and(DB::table('content.offers')->where('item_id', $first)
            ->whereNull('variant_label')->value('amount_minor'))->toBe(1200);
});

it('retires a product dropped from the catalogue via items.removed_at', function () {
    [$user, $collectionId] = makeStoreCollection();
    $a = ['url' => 'https://s.test/a', 'title' => 'A', 'price' => '1.00'];
    $b = ['url' => 'https://s.test/b', 'title' => 'B', 'price' => '2.00'];

    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId, [$a, $b], 'AUD');
    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId, [$a], 'AUD');

    $gone = DB::table('content.items as i')
        ->join('content.f_link as l', 'l.item_id', '=', 'i.id')
        ->where('l.url', 'https://s.test/b')->first(['i.id', 'i.removed_at']);

    expect($gone->removed_at)->not->toBeNull();
});

it('never writes source_items.removed_at — that would resurrect on reappearance', function () {
    [$user, $collectionId] = makeStoreCollection();
    $a = ['url' => 'https://s.test/a', 'title' => 'A', 'price' => '1.00'];

    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId, [$a], 'AUD');
    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId, [], 'AUD');

    expect(DB::table('content.source_items')->whereNotNull('removed_at')->count())->toBe(0);
});

it('hard-deletes nothing', function () {
    [$user, $collectionId] = makeStoreCollection();
    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId,
        [['url' => 'https://s.test/a', 'title' => 'A', 'price' => '1.00']], 'AUD');

    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId, [], 'AUD');

    expect(DB::table('content.items')->count())->toBe(1);
});

it('survives a sync run immediately after the backfill — parent 8.3', function () {
    // The kickoff asks for "a real connector run after backfill". No connector
    // emits kind=product (there is no gumroad ingest source), so the honest
    // equivalent is the thing that actually rewrites this data: a sync.
    [$user, $brand] = makeShopBrand();
    makeShopProduct($brand, ['url' => 'https://s.test/a', 'price' => '9.00']);

    app(ShopBackfiller::class)->run();
    $before = DB::table('content.items')->where('kind', 'product')->pluck('id')->sort()->values();

    $collectionId = DB::table('content.collections')->value('id');
    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId,
        [['url' => 'https://s.test/a', 'title' => 'A', 'price' => '9.00']], 'AUD');

    $after = DB::table('content.items')->where('kind', 'product')
        ->whereNull('removed_at')->pluck('id')->sort()->values();

    expect($after->all())->toBe($before->all());
});
