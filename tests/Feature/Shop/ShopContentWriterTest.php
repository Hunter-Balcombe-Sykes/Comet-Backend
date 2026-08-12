<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Services\Migration\ShopBackfiller;
use App\Services\Platforms\ShopCatalog;
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

// ── Task 6: ShopCatalog::syncLatest() repointed at content.* ──────────────

it('syncLatest writes content.* and leaves shop_products untouched', function () {
    // Two pre-existing legacy products, not one (deviates from the task-6
    // brief's literal test setup — see the Task 6 report): syncLatest()'s
    // count-preserving selection sizes $count from the brand's LIVE
    // content.collection_items count (this test's whole point is proving
    // that source), and after ShopBackfiller migrates these 2 rows that
    // count is 2 — matching this test's 2-item fetched catalog so both get
    // selected. A single pre-existing row would size $count at 1 and
    // legitimately cap the selection at 1, which the brief's original
    // setup — count=1 preserved, but asserting toBe(2) — did not account
    // for. 'stale' is deliberately absent from the fetched catalog so this
    // also exercises retireAbsent() alongside the storage-target change.
    [$user, $brand] = makeShopBrand();
    makeShopProduct($brand, ['url' => 'https://s.test/old', 'price' => '1.00']);
    makeShopProduct($brand, ['url' => 'https://s.test/stale', 'price' => '3.00']);
    app(ShopBackfiller::class)->run();

    fakeProviderCatalog($brand, [
        ['url' => 'https://s.test/old', 'title' => 'Old', 'price' => '1.00'],
        ['url' => 'https://s.test/new', 'title' => 'New', 'price' => '2.00'],
    ]);
    $legacyBefore = DB::table('site.shop_products')->count();

    expect(app(ShopCatalog::class)->syncLatest($brand))->toBe(2)
        ->and(DB::table('site.shop_products')->count())->toBe($legacyBefore)
        ->and(DB::table('content.items')->where('kind', 'product')
            ->whereNull('removed_at')->count())->toBe(2);
});

it('syncLatest still returns null for a reachable but empty store', function () {
    [$user, $brand] = makeShopBrand();
    fakeProviderCatalog($brand, []);

    expect(app(ShopCatalog::class)->syncLatest($brand))->toBeNull();
});

// ── Task 6: ShopContentWriter::isCurated() ─────────────────────────────────

it('isCurated reads the live ShopBrand column, not a content.storefronts snapshot', function () {
    // #SEM-1: a brand curated via ShopController::setProducts() (which sets
    // this column directly and does not touch content.storefronts) must read
    // as curated immediately — even before any sync/backfill has ever run for
    // it, i.e. before a content.collections/storefronts row even exists.
    [$user, $brand] = makeShopBrand(['products_curated_at' => now()]);

    expect(app(ShopContentWriter::class)->isCurated($brand))->toBeTrue();
});

it('isCurated is false for a brand with no curation on record', function () {
    [$user, $brand] = makeShopBrand();

    expect(app(ShopContentWriter::class)->isCurated($brand))->toBeFalse();
});

// ── Task 6 fix round 1, Finding 2: content.storefronts.products_curated_at
// is becoming the source of truth for #SEM-1 ────────────────────────────

it('upsertStore never clobbers an already-stamped content.storefronts.products_curated_at', function () {
    [$user, $brand] = makeShopBrand();
    $collectionId = app(ShopContentWriter::class)->upsertStore($brand, (string) $user->id);

    // Simulate Task 8 having stamped the content-side column directly,
    // independent of the (in this test, still-null) legacy column.
    DB::table('content.storefronts')->where('collection_id', $collectionId)
        ->update(['products_curated_at' => now()->subMinute()]);
    expect($brand->products_curated_at)->toBeNull();

    // A routine resync calls upsertStore() again for the same brand — this
    // must NOT reset the content-side stamp back to the frozen legacy null.
    app(ShopContentWriter::class)->upsertStore($brand, (string) $user->id);

    expect(DB::table('content.storefronts')->where('collection_id', $collectionId)->value('products_curated_at'))
        ->not->toBeNull();
});

it('isCurated returns true from the storefront value alone, with the legacy column null', function () {
    [$user, $brand] = makeShopBrand();
    $collectionId = app(ShopContentWriter::class)->upsertStore($brand, (string) $user->id);
    DB::table('content.storefronts')->where('collection_id', $collectionId)
        ->update(['products_curated_at' => now()]);

    expect($brand->products_curated_at)->toBeNull()
        ->and(app(ShopContentWriter::class)->isCurated($brand))->toBeTrue();
});
