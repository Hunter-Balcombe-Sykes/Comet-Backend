<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Services\Migration\ShopBackfiller;
use App\Services\Platforms\ShopCatalog;
use App\Services\Shop\ShopContentWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

// ── Hotfix regression (2026-08-12/13): orphan recovery ──────────────────────
//
// Belt-and-braces, not load-bearing: since upsertStore()'s two writes now
// run in one transaction (see the class docblock's own incident note), a
// content.collections row with no content.storefronts partner can only
// exist from BEFORE that fix landed — this test simulates exactly that
// leftover state directly, because the writer itself can no longer produce
// it. Driver-agnostic query logic (a JOIN miss vs a LEFT JOIN match), unlike
// the rollback proof itself — see tests/Postgres/ShopUpsertStoreAtomicityTest
// .php for why THAT half needs a real Postgres server.

it('reuses an orphaned collection with no storefronts partner rather than minting a second one', function () {
    [$user, $brand] = makeShopBrand();

    // The incident's own leftover shape: content.collections committed,
    // content.storefronts never wrote. collectionIdFor()'s primary lookup
    // JOINs the two tables, so this row is invisible to it — only the
    // label-matched fallback can find it.
    $orphanId = (string) Str::uuid();
    DB::table('content.collections')->insert([
        'id' => $orphanId,
        'user_id' => (string) $user->id,
        'parent_id' => null,
        'label' => (string) ($brand->name ?? $brand->brand_id),
        'kind' => 'storefront',
        'position' => (int) $brand->position,
        'is_user_created' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $collectionId = app(ShopContentWriter::class)->upsertStore($brand, (string) $user->id);

    expect($collectionId)->toBe($orphanId)
        ->and(DB::table('content.collections')->count())->toBe(1)
        ->and(DB::table('content.storefronts')->where('collection_id', $orphanId)->exists())->toBeTrue();
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

// ── Fix round 3, Finding 1 (CRITICAL): createdAt must be reformatted, not
// passed through raw — content.f_published.published_from is a real
// Postgres timestamptz, and a raw pass-through only byte-matches because
// the SQLite test stand-in stores this column as bare TEXT and hands back
// the literal string untouched. Verified against a real Postgres container
// (partna-pg-test) that this is not a hypothetical: Postgres normalises the
// offset to UTC on write and returns '2026-01-05 00:00:00+00'-shaped text
// on read, never the original ISO-8601 string — see the Task 7 report for
// the full probe output. This test cannot reach that real-driver behaviour
// (SQLite stores the literal string), but it DOES pin the one thing under
// this task's control either way: the APPLICATION-layer transformation
// (Carbon::parse($stored)->utc()->toIso8601String()) must normalise a
// non-UTC offset to the UTC form, so that whatever a driver hands back —
// literal text (SQLite) or Postgres's own UTC-normalised text — the two
// converge on the identical output string for the same instant.

it('normalises a stored createdAt with a non-UTC offset to the UTC ISO-8601 form', function () {
    [$user, $collectionId] = makeStoreCollection();
    $blob = [
        'url' => 'https://s.test/tz', 'title' => 'Tee', 'price' => '10.00',
        'available' => true, 'createdAt' => '2026-01-05T10:30:00+10:00',
    ];

    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId, [$blob], 'AUD');
    $catalogue = app(ShopContentWriter::class)->currentCatalogue($collectionId);

    // 10:30 AEST (+10:00) is 00:30 UTC — the SAME instant, reformatted, not
    // the original offset preserved (timestamptz cannot preserve it; see
    // the report). 'Z' vs '+00:00' is Carbon::toIso8601String()'s own
    // rendering choice (always the latter), not something this task chose.
    expect($catalogue[0]['createdAt'])->toBe('2026-01-05T00:30:00+00:00');
});

it('normalises a stored createdAt already in UTC unchanged in effect (Z-equivalent, offset-notated)', function () {
    [$user, $collectionId] = makeStoreCollection();
    $blob = [
        'url' => 'https://s.test/tz2', 'title' => 'Mug', 'price' => '5.00',
        'available' => true, 'createdAt' => '2026-01-05T00:00:00Z',
    ];

    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId, [$blob], 'AUD');
    $catalogue = app(ShopContentWriter::class)->currentCatalogue($collectionId);

    expect($catalogue[0]['createdAt'])->toBe('2026-01-05T00:00:00+00:00');
});

// ── Fix round 3, Finding 3: urlless products are not silently dropped ──────
//
// Squarespace and BigCartel both legitimately emit a product with no url
// (BigCartelScraper's own return type is `url:?string`). syncStore() used to
// skip any blob with an empty url unconditionally — real, silent data loss.

it('keeps a urlless product identified by its productId alone, surviving a re-sync', function () {
    [$user, $collectionId] = makeStoreCollection();
    $blob = ['productId' => 'sq-1', 'title' => 'Handmade Mug', 'price' => '20.00', 'available' => true];

    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId, [$blob], 'AUD');
    $first = DB::table('content.items')->where('kind', 'product')->value('id');

    expect($first)->not->toBeNull();

    // Re-sync with the same urlless product (price changed) — must be
    // recognised as the SAME item via the pid: coord, not re-minted, and
    // must not be retired by retireAbsent() as "no longer in the catalogue".
    app(ShopContentWriter::class)->syncStore(
        (string) $user->id, $collectionId, [array_merge($blob, ['price' => '22.00'])], 'AUD');

    expect(DB::table('content.items')->where('kind', 'product')->value('id'))->toBe($first)
        ->and(DB::table('content.items')->where('id', $first)->value('removed_at'))->toBeNull()
        ->and(DB::table('content.offers')->where('item_id', $first)
            ->whereNull('variant_label')->value('amount_minor'))->toBe(2200);
});

it('round-trips a urlless product through currentCatalogue() with url: null on the wire', function () {
    [$user, $collectionId] = makeStoreCollection();
    $blob = ['productId' => 'sq-2', 'title' => 'Ceramic Bowl', 'price' => '15.00', 'available' => true];

    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId, [$blob], 'AUD');
    $catalogue = app(ShopContentWriter::class)->currentCatalogue($collectionId);

    expect($catalogue)->toHaveCount(1)
        ->and($catalogue[0]['productId'])->toBe('sq-2')
        ->and($catalogue[0]['url'])->toBeNull();
});

// ── Task 8 fix round 2, D1: the per-variant image round-trips ──────────────
//
// `variants[].image` used to come back unconditionally null: content
// .item_variants had no column for it and ShopProductProjection dropped the
// key, so the sitepage's per-variant photo swap (#84) lost its input the
// moment the public wire moved to content.* (fix round 1, C2). Migration
// 20260813100003 added image_url. This is the full write→read path the
// dashboard and the public payload both consume.

it('round-trips a variant image through syncStore() and back out of currentCatalogue()', function () {
    [$user, $collectionId] = makeStoreCollection();
    $blob = [
        'productId' => 'wr-1', 'title' => 'Wool Runner', 'url' => 'https://s.test/wool-runner',
        'price' => '95.00', 'currency' => 'AUD', 'available' => true,
        'image' => null, 'images' => [],
        'variants' => [
            ['id' => '201', 'title' => 'Grey', 'price' => '95.00', 'available' => true, 'image' => 'https://cdn.test/grey.jpg'],
            // A real variant whose source published no image — must come back
            // null, not absent and not the sibling's URL.
            ['id' => '202', 'title' => 'Navy', 'price' => '99.00', 'available' => false, 'image' => null],
        ],
    ];

    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId, [$blob], 'AUD');
    $variants = app(ShopContentWriter::class)->currentCatalogue($collectionId)[0]['variants'];

    expect($variants)->toHaveCount(2)
        ->and($variants[0]['title'])->toBe('Grey')
        ->and($variants[0]['image'])->toBe('https://cdn.test/grey.jpg')
        ->and($variants[1]['title'])->toBe('Navy')
        ->and($variants[1]['image'])->toBeNull();

    // And it survives a re-sync — the column is written on every projection,
    // not only on first insert (replaceCollections() deletes and re-inserts).
    app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId, [$blob], 'AUD');
    expect(app(ShopContentWriter::class)->currentCatalogue($collectionId)[0]['variants'][0]['image'])
        ->toBe('https://cdn.test/grey.jpg');
});

// ── Fix round 4, Finding 1: the urlless coord fallback is namespaced by
// COLLECTION ───────────────────────────────────────────────────────────────
//
// Coords resolve per (user_id, kind), not per collection. Round 3's
// unnamespaced 'pid:'.$productId therefore collapsed one user's two stores
// that each carry a urlless product with the same provider product id onto a
// single content.items row — and the later sync overwrote the earlier one's
// title, price and media. Namespacing is safe here precisely BECAUSE no
// canonical URL is involved: §1.7's one-coord-per-URL-per-user rule exists to
// stop a URL key being poisoned in the identity resolver, and a urlless
// product contributes no URL key at all. The url-derived coord stays
// unnamespaced for exactly that reason (pinned by the test above).

it('gives two stores urlless products that share a product id their own items, titles intact', function () {
    [$user, $brandA] = makeShopBrand(['brand_id' => 'store-a', 'position' => 0]);
    $brandB = ShopBrand::create([
        'connection_id' => $brandA->connection_id,
        'brand_id' => 'store-b',
        'provider' => 'bigcartel',
        'url' => 'https://storeb.test',
        'source_url' => 'https://storeb.test',
        'name' => 'Store B',
        'currency' => 'AUD',
        'discount_code' => '',
        'referral_query' => '',
        'is_individual' => false,
        'position' => 1,
    ]);

    $writer = app(ShopContentWriter::class);
    $collectionA = $writer->upsertStore($brandA, (string) $user->id);
    $collectionB = $writer->upsertStore($brandB, (string) $user->id);

    // Same provider product id, two different stores — the exact collision.
    $writer->syncStore((string) $user->id, $collectionA, [
        ['productId' => 'shared-1', 'title' => 'Store A Box', 'price' => '10.00', 'available' => true],
    ], 'AUD');
    $writer->syncStore((string) $user->id, $collectionB, [
        ['productId' => 'shared-1', 'title' => 'Store B Box', 'price' => '20.00', 'available' => true],
    ], 'AUD');

    expect(DB::table('content.items')->where('kind', 'product')->count())->toBe(2);

    // Each store still reads back its OWN product — the second sync did not
    // overwrite the first's title or price.
    $a = $writer->currentCatalogue($collectionA);
    $b = $writer->currentCatalogue($collectionB);

    expect($a)->toHaveCount(1)
        ->and($a[0]['title'])->toBe('Store A Box')
        ->and($a[0]['price'])->toBe('10.00')
        ->and($b)->toHaveCount(1)
        ->and($b[0]['title'])->toBe('Store B Box')
        ->and($b[0]['price'])->toBe('20.00');
});

it('skips and logs a product with neither a url nor a productId, rather than dropping it silently', function () {
    Log::shouldReceive('warning')->once()->with('shop.sync_store.unidentifiable_product', Mockery::type('array'));

    [$user, $collectionId] = makeStoreCollection();
    $blob = ['title' => 'Mystery Item', 'price' => '9.00', 'available' => true];

    $written = app(ShopContentWriter::class)->syncStore((string) $user->id, $collectionId, [$blob], 'AUD');

    expect($written)->toBe(0)
        ->and(DB::table('content.items')->where('kind', 'product')->count())->toBe(0);
});
