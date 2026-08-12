<?php

use App\Models\Core\Site\ShopBrand;
use App\Services\Migration\ShopBackfiller;
use App\Services\Platforms\ShopifyScraper;
use App\Services\Platforms\WooCommerceScraper;

// Slice 5a Task 7 §Step 1-2. A response-shape diff, not a shape review: the
// expected arrays below were CAPTURED by dumping the real JSON these five
// endpoints returned against the UNCHANGED (pre-Task-7) controllers — pasted
// here verbatim from that dump (see the Task 7 report for the raw output).
// They are never hand-derived from a docblock, and after the repoint they
// must never be edited to make a failure pass — see the Task 7 report if any
// of these fail.
//
// Scope: the FIVE read endpoints Task 7 repoints (brands, brandProducts,
// selection, settings, connectStatus). The nine write endpoints are Task 8's
// territory and untouched by this task, so their parity is out of this
// file's scope — re-deriving fixtures for endpoints nothing here changes
// buys nothing.
//
// Fixture: one user, two REAL (non-individual) stores, five products total
// (3 + 2), built the same way a real dev environment would have —
// site.shop_brands/site.shop_products seeded directly (what the pre-Task-7
// controllers read), then app(ShopBackfiller::class)->run() (the actual
// production migration path, not a hand-rolled content.* fixture) to land
// the SAME data in content.* (what the post-Task-7 controllers read). Every
// one of the 14 SHOP_PRODUCT_ALLOWLIST keys relevant to a stored product is
// populated on at least one product, including handle/vendor/description/
// variantId — deliberately, since those are exactly the fields Task 6's
// write path never carries into content.* (see ShopContentWriter::
// cataloguesFor()'s docblock) and a fixture that omitted them would hide
// that gap instead of proving it either way. brand-a also carries non-default
// selectionMode/linkMode (content.storefronts has no column for either — see
// ShopContentReader's docblock) so that gap shows up here too, not just in a
// comment.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
});

function parityFixture(): array
{
    [$user, $brandA, $site] = makeShopBrand([
        'brand_id' => 'brand-a',
        'provider' => 'shopify',
        'url' => 'https://storea.example.com',
        'source_url' => 'https://storea.example.com',
        'name' => 'Store A',
        'currency' => 'AUD',
        'discount_code' => 'SAVE10',
        'referral_query' => 'ref=abc123',
        'selection_mode' => 'latest',
        'link_mode' => 'checkout',
        'logo_mark_url' => 'https://cdn.example.com/a-mark.png',
        'logo_mark_svg_url' => 'https://cdn.example.com/a-mark.svg',
        'position' => 0,
    ], withSite: true);

    $brandB = ShopBrand::create([
        'connection_id' => $brandA->connection_id,
        'brand_id' => 'brand-b',
        'provider' => 'woocommerce',
        'url' => 'https://storeb.example.com',
        'source_url' => 'https://storeb.example.com',
        'name' => 'Store B',
        'currency' => 'USD',
        'discount_code' => '',
        'referral_query' => '',
        'is_individual' => false,
        'position' => 1,
    ]);

    makeShopProduct($brandA, [
        'productId' => 'p1',
        'title' => 'Classic Tee',
        'handle' => 'classic-tee',
        'vendor' => 'Acme Apparel',
        'description' => 'A classic tee, hand-stitched.',
        'image' => 'https://cdn.example.com/p1.jpg',
        'images' => ['https://cdn.example.com/p1.jpg', 'https://cdn.example.com/p1-alt.jpg'],
        'price' => '25.00',
        'currency' => 'AUD',
        'variantId' => 'v1',
        'available' => true,
        'url' => 'https://storea.example.com/products/classic-tee',
        'createdAt' => '2026-01-05T00:00:00Z',
        'variants' => [
            ['id' => 'v1', 'title' => 'Small', 'price' => '25.00', 'available' => true, 'image' => null],
            ['id' => 'v2', 'title' => 'Large', 'price' => '27.00', 'available' => false, 'image' => null],
        ],
    ]);
    makeShopProduct($brandA, [
        'productId' => 'p2',
        'title' => 'Canvas Tote',
        'handle' => 'canvas-tote',
        'vendor' => 'Acme Apparel',
        'description' => 'Sturdy canvas tote bag.',
        'image' => 'https://cdn.example.com/p2.jpg',
        'images' => ['https://cdn.example.com/p2.jpg'],
        'price' => '15.00',
        'currency' => 'AUD',
        'variantId' => 'p2',
        'available' => true,
        'url' => 'https://storea.example.com/products/canvas-tote',
        'createdAt' => '2026-02-10T00:00:00Z',
        'variants' => [],
    ]);
    makeShopProduct($brandA, [
        'productId' => 'p3',
        'title' => 'Wool Beanie',
        'handle' => 'wool-beanie',
        'vendor' => 'Acme Apparel',
        'description' => 'Warm wool beanie.',
        'image' => 'https://cdn.example.com/p3.jpg',
        'images' => [],
        'price' => '18.00',
        'currency' => 'AUD',
        'variantId' => 'p3',
        'available' => false,
        'url' => 'https://storea.example.com/products/wool-beanie',
        'createdAt' => '2026-03-01T00:00:00Z',
        'variants' => [],
    ]);

    makeShopProduct($brandB, [
        'productId' => 'q1',
        'title' => 'Ceramic Mug',
        'handle' => 'ceramic-mug',
        'vendor' => 'Store B Goods',
        'description' => 'Handmade ceramic mug.',
        'image' => 'https://cdn.example.com/q1.jpg',
        'images' => ['https://cdn.example.com/q1.jpg'],
        'price' => '12.00',
        'currency' => 'USD',
        'variantId' => 'q1',
        'available' => true,
        'url' => 'https://storeb.example.com/product/ceramic-mug',
        'createdAt' => '2026-01-20T00:00:00Z',
        'variants' => [],
    ]);
    makeShopProduct($brandB, [
        'productId' => 'q2',
        'title' => 'Linen Napkin Set',
        'handle' => 'linen-napkin-set',
        'vendor' => 'Store B Goods',
        'description' => 'Set of 4 linen napkins.',
        'image' => 'https://cdn.example.com/q2.jpg',
        'images' => [],
        'price' => '32.00',
        'currency' => 'USD',
        'variantId' => 'q2',
        'available' => true,
        'url' => 'https://storeb.example.com/product/linen-napkin-set',
        'createdAt' => '2026-01-25T00:00:00Z',
        'variants' => [],
    ]);

    // The actual production migration path — lands this exact seeded data in
    // content.* the same way ShopBackfiller does on real dev/prod data, so
    // the post-repoint reconstruction is exercised against a REAL write, not
    // a hand-rolled content.* fixture.
    app(ShopBackfiller::class)->run();

    return [$user, $brandA, $brandB, $site];
}

/**
 * brandProducts() always LIVE-scrapes via ShopCatalog::providerProducts() —
 * it never reads stored products — so both the baseline and the repointed
 * run need the SAME scraper mock. This is unaffected by Task 7: it proves
 * the picker's live path is untouched, which is the point of covering it
 * here at all.
 */
function mockParityScrapers(): void
{
    test()->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('fetchProducts')
            ->with('https://storea.example.com', 'AUD')
            ->andReturn([
                ['productId' => 'p1', 'title' => 'Classic Tee', 'url' => 'https://storea.example.com/products/classic-tee', 'price' => '25.00', 'currency' => 'AUD', 'available' => true],
            ]);
    });
    test()->mock(WooCommerceScraper::class, function ($m) {
        $m->shouldReceive('fetchProducts')
            ->with('https://storeb.example.com')
            ->andReturn([
                ['productId' => 'q1', 'title' => 'Ceramic Mug', 'url' => 'https://storeb.example.com/product/ceramic-mug', 'price' => '12.00', 'currency' => 'USD', 'available' => true],
            ]);
    });
}

// brand-a's three products, DASHBOARD shape (brands()/selection() go through
// ShopController's private brandMap(), which ALWAYS passes a (possibly
// empty) popularity-ranks array to toBrandArray() — never null — so
// popularityRank IS present here despite ShopBrand::toBrandArray()'s own
// docblock describing it as public-path-only. Captured from the dump, not
// the docblock — see this file's header.
function brandAProductsDashboardShape(): array
{
    return [
        [
            'productId' => 'p1', 'title' => 'Classic Tee',
            'url' => 'https://storea.example.com/products/classic-tee',
            'price' => '25.00', 'currency' => 'AUD', 'available' => true,
            'image' => 'https://cdn.example.com/p1.jpg',
            'images' => ['https://cdn.example.com/p1.jpg', 'https://cdn.example.com/p1-alt.jpg'],
            'variants' => [
                ['id' => 'v1', 'title' => 'Small', 'price' => '25.00', 'available' => true, 'image' => null],
                ['id' => 'v2', 'title' => 'Large', 'price' => '27.00', 'available' => false, 'image' => null],
            ],
            'handle' => 'classic-tee', 'vendor' => 'Acme Apparel',
            'description' => 'A classic tee, hand-stitched.', 'variantId' => 'v1',
            'createdAt' => '2026-01-05T00:00:00Z', 'popularityRank' => null,
        ],
        [
            'productId' => 'p2', 'title' => 'Canvas Tote',
            'url' => 'https://storea.example.com/products/canvas-tote',
            'price' => '15.00', 'currency' => 'AUD', 'available' => true,
            'image' => 'https://cdn.example.com/p2.jpg',
            'images' => ['https://cdn.example.com/p2.jpg'],
            'variants' => [],
            'handle' => 'canvas-tote', 'vendor' => 'Acme Apparel',
            'description' => 'Sturdy canvas tote bag.', 'variantId' => 'p2',
            'createdAt' => '2026-02-10T00:00:00Z', 'popularityRank' => null,
        ],
        [
            'productId' => 'p3', 'title' => 'Wool Beanie',
            'url' => 'https://storea.example.com/products/wool-beanie',
            'price' => '18.00', 'currency' => 'AUD', 'available' => false,
            'image' => 'https://cdn.example.com/p3.jpg',
            'images' => [],
            'variants' => [],
            'handle' => 'wool-beanie', 'vendor' => 'Acme Apparel',
            'description' => 'Warm wool beanie.', 'variantId' => 'p3',
            'createdAt' => '2026-03-01T00:00:00Z', 'popularityRank' => null,
        ],
    ];
}

function brandBProductsDashboardShape(): array
{
    return [
        [
            'productId' => 'q1', 'title' => 'Ceramic Mug',
            'url' => 'https://storeb.example.com/product/ceramic-mug',
            'price' => '12.00', 'currency' => 'USD', 'available' => true,
            'image' => 'https://cdn.example.com/q1.jpg',
            'images' => ['https://cdn.example.com/q1.jpg'],
            'variants' => [],
            'handle' => 'ceramic-mug', 'vendor' => 'Store B Goods',
            'description' => 'Handmade ceramic mug.', 'variantId' => 'q1',
            'createdAt' => '2026-01-20T00:00:00Z', 'popularityRank' => null,
        ],
        [
            'productId' => 'q2', 'title' => 'Linen Napkin Set',
            'url' => 'https://storeb.example.com/product/linen-napkin-set',
            'price' => '32.00', 'currency' => 'USD', 'available' => true,
            'image' => 'https://cdn.example.com/q2.jpg',
            'images' => [],
            'variants' => [],
            'handle' => 'linen-napkin-set', 'vendor' => 'Store B Goods',
            'description' => 'Set of 4 linen napkins.', 'variantId' => 'q2',
            'createdAt' => '2026-01-25T00:00:00Z', 'popularityRank' => null,
        ],
    ];
}

it('GET /brands — matches the pre-Task-7 dump', function () {
    [$user] = parityFixture();

    $res = actingAsUser($user)->getJson('/api/platforms/shop/brands')->assertOk();

    expect($res->json())->toEqual([
        'brands' => [
            [
                'id' => 'brand-a', 'provider' => 'shopify', 'url' => 'https://storea.example.com',
                'name' => 'Store A', 'currency' => 'AUD', 'favicon' => null, 'logo' => null,
                'discountCode' => 'SAVE10', 'selectionMode' => 'latest', 'linkMode' => 'checkout',
                'referralQuery' => 'ref=abc123', 'individual' => false,
                'products' => brandAProductsDashboardShape(),
                'logoMark' => 'https://cdn.example.com/a-mark.png',
                'logoMarkSvg' => 'https://cdn.example.com/a-mark.svg',
            ],
            [
                'id' => 'brand-b', 'provider' => 'woocommerce', 'url' => 'https://storeb.example.com',
                'name' => 'Store B', 'currency' => 'USD', 'favicon' => null, 'logo' => null,
                'discountCode' => '', 'selectionMode' => 'manual', 'linkMode' => 'product',
                'referralQuery' => '', 'individual' => false,
                'products' => brandBProductsDashboardShape(),
            ],
        ],
    ]);
});

it('GET /brands/{id}/products — matches the pre-Task-7 dump', function () {
    [$user] = parityFixture();
    mockParityScrapers();

    $res = actingAsUser($user)->getJson('/api/platforms/shop/brands/brand-a/products')->assertOk();

    expect($res->json())->toEqual([
        'products' => [
            [
                'productId' => 'p1', 'title' => 'Classic Tee',
                'url' => 'https://storea.example.com/products/classic-tee',
                'price' => '25.00', 'currency' => 'AUD', 'available' => true,
            ],
        ],
    ]);
});

it('GET /selection — matches the pre-Task-7 dump', function () {
    [$user] = parityFixture();

    $res = actingAsUser($user)->getJson('/api/platforms/shop/selection')->assertOk();

    expect($res->json())->toEqual([
        'selection' => [
            'url' => 'https://storea.example.com',
            'provider' => 'shopify',
            'discountCode' => 'SAVE10',
            'products' => brandAProductsDashboardShape(),
        ],
    ]);
});

it('GET /settings — matches the pre-Task-7 dump', function () {
    [$user] = parityFixture();

    $res = actingAsUser($user)->getJson('/api/platforms/shop/settings')->assertOk();

    expect($res->json())->toEqual([
        'linkMode' => 'checkout',
        'autoLatest' => true,
    ]);
});

it('GET /brands/{id}/connect/status — matches the pre-Task-7 dump', function () {
    [$user] = parityFixture();

    $res = actingAsUser($user)->getJson('/api/platforms/shop/brands/brand-a/connect/status')->assertOk();

    // No popularityRank here — connectStatus() calls toBrandArray() with no
    // args (default null), unlike brands()/selection() above.
    $productsNoRank = array_map(function (array $p) {
        unset($p['popularityRank']);

        return $p;
    }, brandAProductsDashboardShape());

    expect($res->json())->toEqual([
        'status' => 'ready',
        'id' => 'brand-a',
        'brand' => [
            'id' => 'brand-a', 'provider' => 'shopify', 'url' => 'https://storea.example.com',
            'name' => 'Store A', 'currency' => 'AUD', 'favicon' => null, 'logo' => null,
            'discountCode' => 'SAVE10', 'selectionMode' => 'latest', 'linkMode' => 'checkout',
            'referralQuery' => 'ref=abc123', 'individual' => false,
            'products' => $productsNoRank,
            'logoMark' => 'https://cdn.example.com/a-mark.png',
            'logoMarkSvg' => 'https://cdn.example.com/a-mark.svg',
        ],
    ]);
});
