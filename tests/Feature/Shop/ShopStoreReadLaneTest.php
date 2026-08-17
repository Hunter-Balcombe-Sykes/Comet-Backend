<?php

use App\Models\Core\Site\ShopBrand;
use App\Models\Core\User\User;
use App\Services\Migration\ShopBackfiller;
use App\Services\Platforms\WooCommerceScraper;
use App\Services\Shop\ShopConnections;
use App\Services\Shop\ShopContentReader;
use App\Services\Shop\StoreRecord;
use Illuminate\Support\Facades\DB;

// The shop re-home's read lane: every dashboard read resolves its store from
// content.* and never from the legacy site.shop_brands / site.shop_products
// child tables.
//
// The assertions here are QUERY assertions, not shape assertions. Shape is
// already pinned byte-for-byte by ShopEndpointParityTest — if a repoint below
// changed a response body, that file fails, and this one is free to ask the
// narrower question these tasks actually turn on: which table was read.
//
// ShopBackfiller is the fixture's route into content.* deliberately — it is
// the real migration path every dev store already took, so a store built this
// way carries exactly the content.* state production carries.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
});

it('re-warms a store catalog without reading site.shop_products', function (): void {
    [$user, $brand] = makeShopBrand([
        'brand_id' => 'read-lane-store',
        'provider' => 'woocommerce',
        'url' => 'https://store.test',
        'source_url' => 'https://store.test',
        'fetch_mode' => 'client',
    ], withSite: true);
    makeShopProduct($brand, ['productId' => 'p1', 'url' => 'https://store.test/p1']);

    app(ShopBackfiller::class)->run();

    mockShopService(WooCommerceScraper::class, function ($m) {
        $m->shouldReceive('productsFromClient')->andReturn([
            ['productId' => 'c1', 'title' => 'Catalog Product', 'url' => 'https://store.test/c1'],
        ]);
    });

    $queries = [];
    DB::listen(function ($q) use (&$queries): void {
        $queries[] = $q->sql;
    });

    actingAsUser($user)
        ->postJson("/api/platforms/shop/brands/{$brand->brand_id}/catalog", [
            'products' => [['id' => 'c1', 'title' => 'Catalog Product']],
        ])
        ->assertOk();

    expect(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'shop_products')))
        ->toBeEmpty();
});

it('curates a store selection without reading site.shop_products', function (): void {
    [$user, $brand] = makeShopBrand([
        'brand_id' => 'curate-lane-store',
        'url' => 'https://store.test',
        'source_url' => 'https://store.test',
    ], withSite: true);
    makeShopProduct($brand, ['productId' => 'p1', 'url' => 'https://store.test/p1']);

    app(ShopBackfiller::class)->run();

    // setProducts() re-scrapes on a cold picker cache — stub the provider so
    // this test measures the read lane, not egress.
    fakeProviderCatalog($brand, []);

    $queries = [];
    DB::listen(function ($q) use (&$queries): void {
        $queries[] = $q->sql;
    });

    actingAsUser($user)
        ->putJson("/api/platforms/shop/brands/{$brand->brand_id}/selection", ['productIds' => []])
        ->assertOk();

    expect(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'shop_products')))
        ->toBeEmpty();
});

it('removes a store without reading site.shop_products', function (): void {
    [$user, $brand] = makeShopBrand([
        'brand_id' => 'remove-lane-store',
        'url' => 'https://store.test',
        'source_url' => 'https://store.test',
    ], withSite: true);
    makeShopProduct($brand, ['productId' => 'p1', 'url' => 'https://store.test/p1']);

    app(ShopBackfiller::class)->run();

    $queries = [];
    DB::listen(function ($q) use (&$queries): void {
        $queries[] = $q->sql;
    });

    actingAsUser($user)
        ->deleteJson("/api/platforms/shop/brands/{$brand->brand_id}")
        ->assertOk();

    expect(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'shop_products')))
        ->toBeEmpty();
});

// Ported from ShopRelationalStorageTest, which pinned this on
// ShopBrand::toBrandArray() — deleted by re-home Task 2. It was that rule's
// ONLY guard anywhere in the suite (ShopContentReaderTest has no
// popularityRank coverage at all), so it moves rather than going with the
// method.
it('keys popularityRank by product HANDLE, matching the scoring pipeline', function (): void {
    // content_popularity_scores keys shop_product rows by the product's handle
    // slug (what beacons and click signals carry) — NEVER by productId. A map
    // keyed by productId must not match; a handle-keyed map must.
    [$user, $brand] = makeShopBrand([
        'brand_id' => 'rank-lane-store',
        'url' => 'https://store.test',
        'source_url' => 'https://store.test',
    ], withSite: true);
    makeShopProduct($brand, [
        'productId' => 'p1',
        'handle' => 'mug',
        'title' => 'Mug',
        'url' => 'https://store.test/products/mug',
    ]);

    app(ShopBackfiller::class)->run();

    $reader = app(ShopContentReader::class);

    $handleKeyed = $reader->brandMap($user, ['mug' => 3])['rank-lane-store'];
    expect($handleKeyed['products'][0]['popularityRank'])->toBe(3);

    $productIdKeyed = $reader->brandMap($user, ['p1' => 3])['rank-lane-store'];
    expect($productIdKeyed['products'][0]['popularityRank'])->toBeNull();
});

// ── Task 3: StoreRecord carries the collection id it was read back from ──
//
// The legacy site.shop_brands uuid PK is what the two async jobs key on today
// (spec §5). It has no content.* twin, so the collection id replaces it — and
// the only place that id is known is the read.

it('carries the collection id when rebuilt from a storefront row', function (): void {
    $row = (object) [
        'collection_id' => '11111111-1111-4111-8111-111111111111',
        'external_ref' => '75102060779',
        'provider' => 'shopify',
        'label' => 'Fear No Evil',
        'position' => 2,
        'url' => 'https://fearnoevil.com.au',
        'source_url' => null,
        'currency' => 'AUD',
        'discount_code' => null,
        'referral_query' => '',
        'is_individual' => false,
        'fetch_mode' => null,
        'connect_status' => null,
        'connect_error' => null,
        'logo_url' => null,
        'favicon_url' => null,
        'logo_mark_url' => null,
        'logo_mark_svg_url' => null,
        'products_curated_at' => null,
    ];

    $record = StoreRecord::fromStorefrontRow($row);

    expect($record->collectionId)->toBe('11111111-1111-4111-8111-111111111111')
        ->and($record->externalRef)->toBe('75102060779')
        ->and($record->name)->toBe('Fear No Evil');
});

// The write direction must NOT carry one: upsertStore() RETURNS the collection
// id, so a record built for a write has nothing to put there yet. A default of
// null is what keeps those two directions from being confusable.
it('defaults the collection id to null for a record built to be written', function (): void {
    $record = new StoreRecord(externalRef: '75102060779', provider: 'shopify');

    expect($record->collectionId)->toBeNull();
});

// ── Task 4: ShopConnections::stores() / store() read content.* ───────────
//
// The old brands()/brand() pair returned a query Builder, so callers chained
// ->where(), ->max('position') and ->count(). A keyed Collection supports all
// three and costs one query for the whole family instead of one per call.

it('lists every store the user owns, ordered, off content.*', function (): void {
    [$user, $brandA] = makeShopBrand([
        'brand_id' => 'alpha', 'url' => 'https://a.test', 'source_url' => 'https://a.test', 'position' => 0,
    ], withSite: true);
    ShopBrand::create([
        'connection_id' => $brandA->connection_id,
        'brand_id' => 'beta', 'provider' => 'shopify',
        'url' => 'https://b.test', 'source_url' => 'https://b.test',
        'is_individual' => false, 'position' => 1,
    ]);

    app(ShopBackfiller::class)->run();

    $stores = app(ShopConnections::class)->stores($user);

    expect($stores->keys()->all())->toBe(['alpha', 'beta'])
        ->and($stores->get('alpha'))->toBeInstanceOf(StoreRecord::class)
        ->and($stores->get('alpha')->collectionId)->not->toBeNull()
        ->and($stores->get('beta')->position)->toBe(1);
});

it('returns an empty collection for a user with no shop at all', function (): void {
    $handle = 'sbrnoshop';
    $user = User::factory()->create(['handle' => $handle, 'handle_lc' => $handle]);

    expect(app(ShopConnections::class)->stores($user))->toBeEmpty();
});

it('issues no query naming shop_brands when listing stores', function (): void {
    [$user] = makeShopBrand([
        'brand_id' => 'alpha', 'url' => 'https://a.test', 'source_url' => 'https://a.test',
    ], withSite: true);
    app(ShopBackfiller::class)->run();

    $queries = [];
    DB::listen(function ($q) use (&$queries): void {
        $queries[] = $q->sql;
    });

    // Non-empty is asserted first: a stores() that returned nothing would
    // satisfy the query assertion below for the wrong reason.
    expect(app(ShopConnections::class)->stores($user))->toHaveCount(1)
        ->and(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'shop_brands')))
        ->toBeEmpty();
});

it('resolves one store by its provider store id', function (): void {
    [$user] = makeShopBrand([
        'brand_id' => 'alpha', 'url' => 'https://a.test', 'source_url' => 'https://a.test',
    ], withSite: true);
    app(ShopBackfiller::class)->run();

    $shop = app(ShopConnections::class);

    expect($shop->store($user, 'alpha'))->toBeInstanceOf(StoreRecord::class)
        ->and($shop->store($user, 'nope'))->toBeNull();
});

// stores() and ShopContentReader::brandMap() MUST agree on which store is
// position 0 — they are two reads of the same rows, and a dashboard that
// ordered them differently from the picker would be a real user-visible bug.
it('orders stores identically to ShopContentReader::brandMap()', function (): void {
    [$user, $brandA] = makeShopBrand([
        'brand_id' => 'zeta', 'url' => 'https://z.test', 'source_url' => 'https://z.test', 'position' => 0,
    ], withSite: true);
    foreach ([['alpha', 1], ['mid', 1]] as [$id, $position]) {
        ShopBrand::create([
            'connection_id' => $brandA->connection_id,
            'brand_id' => $id, 'provider' => 'shopify',
            'url' => "https://{$id}.test", 'source_url' => "https://{$id}.test",
            'is_individual' => false, 'position' => $position,
        ]);
    }

    app(ShopBackfiller::class)->run();

    expect(app(ShopConnections::class)->stores($user)->keys()->all())
        ->toBe(array_keys(app(ShopContentReader::class)->brandMap($user)));
});
