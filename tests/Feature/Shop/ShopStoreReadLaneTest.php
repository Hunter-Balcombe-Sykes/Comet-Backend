<?php

use App\Services\Migration\ShopBackfiller;
use App\Services\Platforms\WooCommerceScraper;
use App\Services\Shop\ShopContentReader;
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
