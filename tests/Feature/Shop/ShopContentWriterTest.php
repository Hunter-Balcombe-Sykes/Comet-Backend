<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
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

it('hard-deletes nothing, but does retire via the empty-catalogue branch', function () {
    [$user, $collectionId] = makeStoreCollection();
    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId,
        [['url' => 'https://s.test/a', 'title' => 'A', 'price' => '1.00']], 'AUD');

    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId, [], 'AUD');

    // Fix round 1, Finding 3: an empty $liveCoords list takes a different
    // branch in retireAbsent() (the whereNotIn is skipped entirely rather
    // than matching "not in []"). Pin that this branch actually retires,
    // not just that it doesn't delete — a no-op branch would also pass the
    // count()===1 assertion below.
    expect(DB::table('content.items')->count())->toBe(1)
        ->and(DB::table('content.items')->whereNotNull('removed_at')->count())->toBe(1);
});

it('does not retire an item still live in another storefront of the same user', function () {
    // Fix round 1, Finding 2: coordFor() is URL-only, not store-scoped, so
    // two of this user's stores listing the same URL resolve to ONE
    // content.items row. Store A dropping it must not remove it from B.
    [$user, $brandA] = makeShopBrand();
    $collectionA = app(ShopContentWriter::class)->upsertStore($brandA, (string) $user->id);

    $connectionB = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'shop',
        'resource_id' => 'shop-b',
        'payload' => ['storage' => 'relational'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
    $brandB = ShopBrand::create([
        'connection_id' => $connectionB->id,
        'brand_id' => 'brand-b',
        'provider' => 'shopify',
        'url' => 'https://store-b.test',
        'source_url' => 'https://store-b.test',
        'name' => 'Store B',
        'currency' => 'AUD',
        'discount_code' => '',
        'referral_query' => '',
        'is_individual' => false,
        'position' => 0,
    ]);
    $collectionB = app(ShopContentWriter::class)->upsertStore($brandB, (string) $user->id);

    $shared = ['url' => 'https://s.test/shared', 'title' => 'Shared', 'price' => '5.00'];
    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionA, [$shared], 'AUD');
    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionB, [$shared], 'AUD');

    $itemId = DB::table('content.items')->where('kind', 'product')->value('id');
    expect(DB::table('content.items')->where('kind', 'product')->count())->toBe(1);

    // Store A drops the shared URL — B's catalogue still lists it.
    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionA, [], 'AUD');

    expect(DB::table('content.items')->where('id', $itemId)->value('removed_at'))->toBeNull()
        ->and(DB::table('content.collection_items')
            ->where('collection_id', $collectionA)->where('item_id', $itemId)->exists())->toBeFalse()
        ->and(DB::table('content.collection_items')
            ->where('collection_id', $collectionB)->where('item_id', $itemId)->exists())->toBeTrue();

    // Now B drops it too — no live catalogue carries it anywhere, so it retires.
    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionB, [], 'AUD');

    expect(DB::table('content.items')->where('id', $itemId)->value('removed_at'))->not->toBeNull();
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
