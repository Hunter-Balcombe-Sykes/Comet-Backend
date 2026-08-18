<?php

use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\WooCommerceScraper;

afterEach(function () {
    Mockery::close();
});

// Build a WooCommerceScraper whose fetcher returns canned bodies per path.
function wooScraperWith(array $routes): WooCommerceScraper
{
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->andReturnUsing(function (string $url) use ($routes) {
        foreach ($routes as $needle => $response) {
            if (str_contains($url, $needle)) {
                return $response;
            }
        }

        return ['status' => 404, 'body' => '', 'finalUrl' => $url, 'contentType' => ''];
    });

    return new WooCommerceScraper($fetcher);
}

it('converts Store-API minor units to decimal strings', function () {
    $scraper = wooScraperWith([]);

    expect($scraper->minorToDecimal('1900', 2))->toBe('19.00');
    expect($scraper->minorToDecimal('5', 2))->toBe('0.05');
    expect($scraper->minorToDecimal('190', 1))->toBe('19.0');
    expect($scraper->minorToDecimal('1900', 0))->toBe('1900');
    expect($scraper->minorToDecimal(null, 2))->toBeNull();
    expect($scraper->minorToDecimal('abc', 2))->toBeNull();
});

it('probes positive on a Store API JSON list and negative otherwise', function () {
    $live = wooScraperWith(['/wp-json/wc/store/v1/products' => ['status' => 200, 'body' => '[]', 'finalUrl' => 'x', 'contentType' => 'application/json']]);
    expect($live->probe('https://store.example'))->toBeTrue();

    $object = wooScraperWith(['/wp-json/wc/store/v1/products' => ['status' => 200, 'body' => '{"code":"rest_no_route"}', 'finalUrl' => 'x', 'contentType' => 'application/json']]);
    expect($object->probe('https://store.example'))->toBeFalse();

    $missing = wooScraperWith([]);
    expect($missing->probe('https://store.example'))->toBeFalse();
});

it('maps Store-API products to the canonical product shape with permalink urls', function () {
    $products = json_encode([[
        'id' => 245,
        'name' => 'Handpicked Red Chillies &amp; Co',
        'slug' => 'handpicked-red-chillies',
        'permalink' => 'https://store.example/product/handpicked-red-chillies/',
        'prices' => ['price' => '1900', 'currency_code' => 'gbp', 'currency_minor_unit' => 2],
        'images' => [['src' => 'https://store.example/img/chillies.jpg']],
        'is_in_stock' => true,
    ], [
        'id' => 246,
        'name' => 'Sold Out Thing',
        'slug' => 'sold-out-thing',
        'permalink' => 'https://store.example/product/sold-out-thing/',
        'prices' => ['price' => '500', 'currency_code' => 'GBP', 'currency_minor_unit' => 2],
        'images' => [],
        'is_in_stock' => false,
    ]]);

    $scraper = wooScraperWith([
        '/wp-json/wc/store/v1/products' => ['status' => 200, 'body' => $products, 'finalUrl' => 'x', 'contentType' => 'application/json'],
    ]);

    $out = $scraper->fetchProducts('https://store.example');

    expect($out)->toHaveCount(2);
    expect($out[0])->toMatchArray([
        'productId' => '245',
        'title' => 'Handpicked Red Chillies & Co',
        'handle' => 'handpicked-red-chillies',
        'image' => 'https://store.example/img/chillies.jpg',
        'price' => '19.00',
        'currency' => 'GBP',
        'variantId' => '245',
        'available' => true,
        'url' => 'https://store.example/product/handpicked-red-chillies/',
    ]);
    expect($out[1]['available'])->toBeFalse();
    expect($out[1]['image'])->toBeNull();
});

// ── Live-site fixtures (WS-B1.3: bluelane.co + fearnoevil.com.au) ─────────────
//
// Real Store-API payloads saved from the two stores that were the WooCommerce
// repro (SiteGround's WAF 403'd our browser-spoof UA; SafeUrlFetcher now
// retries with an honest bot UA — see SafeUrlFetcherTest). Parsing them here
// pins the mapping path against genuine WooCommerce output, no network.

/** @see tests/Unit/Platforms/GenericShopScraperTest.php shopFixture() — duplicated to keep unit files standalone. */
function wooFixture(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/fixtures/recorded/shop/'.$name);
}

it('probes positive on the real bluelane.co Store API payload', function () {
    $scraper = wooScraperWith([
        '/wp-json/wc/store/v1/products' => ['status' => 200, 'body' => wooFixture('bluelane-store-api.json'), 'finalUrl' => 'x', 'contentType' => 'application/json'],
    ]);

    expect($scraper->probe('https://bluelane.co'))->toBeTrue();
});

it('maps the real bluelane.co Store API catalog to the canonical shape', function () {
    $scraper = wooScraperWith([
        '/wp-json/wc/store/v1/products' => ['status' => 200, 'body' => wooFixture('bluelane-store-api.json'), 'finalUrl' => 'x', 'contentType' => 'application/json'],
    ]);

    $out = $scraper->fetchProducts('https://bluelane.co');

    expect($out)->toHaveCount(2);
    expect($out[0])->toMatchArray([
        'productId' => '3539',
        'title' => 'Pink Lobster Swim Short',
        'handle' => 'lobster-swim-short-pink',
        'price' => '100.00',
        'currency' => 'AUD',
        'available' => true,
        'url' => 'https://bluelane.co/product/lobster-swim-short-pink/',
    ]);
    expect($out[0]['image'])->toBe('https://bluelane.co/wp-content/uploads/2025/12/Blue-Lane_Dec25_0313-scaled.jpeg');
    expect($out[0]['variants'])->toHaveCount(4);
    expect($out[0]['variants'][0])->toMatchArray(['id' => '3540', 'title' => 'small']);
});

it('maps the real fearnoevil.com.au catalog including out-of-stock and unicode titles', function () {
    $scraper = wooScraperWith([
        '/wp-json/wc/store/v1/products' => ['status' => 200, 'body' => wooFixture('fearnoevil-store-api.json'), 'finalUrl' => 'x', 'contentType' => 'application/json'],
    ]);

    $out = $scraper->fetchProducts('https://fearnoevil.com.au');

    expect($out)->toHaveCount(2);
    expect($out[0])->toMatchArray([
        'productId' => '2964',
        'title' => 'Bulwark Jacket',
        'price' => '280.00',
        'currency' => 'AUD',
        'available' => true,
        'url' => 'https://fearnoevil.com.au/product/bulwark-jacket/',
    ]);
    expect($out[1])->toMatchArray([
        'productId' => '2595',
        'title' => 'Kōji Pants',
        'price' => '180.00',
        'available' => false,
    ]);
});

it('reads the brand off the real bluelane.co homepage with the WP-root site name', function () {
    $scraper = wooScraperWith([
        '/wp-json/wc' => ['status' => 404, 'body' => '', 'finalUrl' => 'x', 'contentType' => ''],
        // Real WP-root `name` verified live (the homepage og:site_name says
        // "My WordPress" — the WP-root name wins, as fetchBrand prefers it).
        '/wp-json' => ['status' => 200, 'body' => '{"name":"Blue Lane"}', 'finalUrl' => 'x', 'contentType' => 'application/json'],
        'https://bluelane.co/' => ['status' => 200, 'body' => wooFixture('bluelane-homepage-head.html'), 'finalUrl' => 'https://bluelane.co/', 'contentType' => 'text/html'],
    ]);

    $brand = $scraper->fetchBrand('https://bluelane.co');

    expect($brand['id'])->toBe('bluelane-co');
    expect($brand['name'])->toBe('Blue Lane');
});

it('reads the site name from the WP REST root when fetching the brand', function () {
    $scraper = wooScraperWith([
        '/wp-json/wc' => ['status' => 404, 'body' => '', 'finalUrl' => 'x', 'contentType' => ''],
        '/wp-json' => ['status' => 200, 'body' => '{"name":"Organic Shop"}', 'finalUrl' => 'x', 'contentType' => 'application/json'],
        'https://store.example/' => ['status' => 200, 'body' => '<html><head><link rel="icon" href="/fav.png" type="image/png"></head></html>', 'finalUrl' => 'https://store.example/', 'contentType' => 'text/html'],
    ]);

    $brand = $scraper->fetchBrand('https://store.example');

    expect($brand['id'])->toBe('store-example');
    expect($brand['name'])->toBe('Organic Shop');
    expect($brand['favicon'])->toBe('https://store.example/fav.png');
});

// ── images[] gallery + description sanitization ─────────────────────────

it('captures the full image gallery and a sanitized short_description', function () {
    $products = json_encode([[
        'id' => 900,
        'name' => 'Gallery Item',
        'slug' => 'gallery-item',
        'permalink' => 'https://store.example/product/gallery-item/',
        'prices' => ['price' => '1000', 'currency_code' => 'AUD', 'currency_minor_unit' => 2],
        'images' => [
            ['src' => 'https://store.example/img/1.jpg'],
            ['src' => 'https://store.example/img/2.jpg'],
        ],
        'short_description' => "<p>Hand &amp; <em>machine</em>\nstitched.</p>",
        'description' => '<p>Full description, unused when short is present.</p>',
        'is_in_stock' => true,
    ]]);

    $scraper = wooScraperWith([
        '/wp-json/wc/store/v1/products' => ['status' => 200, 'body' => $products, 'finalUrl' => 'x', 'contentType' => 'application/json'],
    ]);

    $out = $scraper->fetchProducts('https://store.example')[0];

    expect($out['images'])->toBe(['https://store.example/img/1.jpg', 'https://store.example/img/2.jpg']);
    expect($out['image'])->toBe('https://store.example/img/1.jpg'); // hero unchanged
    expect($out['description'])->toBe('Hand & machine stitched.');
});

it('inserts a space at former block-element boundaries instead of gluing adjacent blocks together (B4)', function () {
    // Shared PlatformScraper::sanitizeDescription() fix — strip_tags() alone
    // glues "<p>Hello</p><p>world</p>" into "Helloworld" with no boundary space.
    $products = json_encode([[
        'id' => 902, 'name' => 'Glued Text Check', 'slug' => 'glued-text-check',
        'permalink' => 'https://store.example/product/glued-text-check/',
        'prices' => ['price' => '1000', 'currency_code' => 'AUD', 'currency_minor_unit' => 2],
        'images' => [], 'short_description' => '<p>Hello</p><p>world</p><ul><li>One</li><li>Two</li></ul>',
        'is_in_stock' => true,
    ]]);

    $scraper = wooScraperWith([
        '/wp-json/wc/store/v1/products' => ['status' => 200, 'body' => $products, 'finalUrl' => 'x', 'contentType' => 'application/json'],
    ]);

    $description = $scraper->fetchProducts('https://store.example')[0]['description'];

    expect($description)->toBe('Hello world One Two');
});

it('falls back to description when short_description is blank', function () {
    $products = json_encode([[
        'id' => 901, 'name' => 'No Short', 'slug' => 'no-short',
        'permalink' => 'https://store.example/product/no-short/',
        'prices' => ['price' => '500', 'currency_code' => 'AUD', 'currency_minor_unit' => 2],
        'images' => [], 'short_description' => '', 'description' => '<p>Full desc only.</p>',
        'is_in_stock' => true,
    ]]);

    $scraper = wooScraperWith([
        '/wp-json/wc/store/v1/products' => ['status' => 200, 'body' => $products, 'finalUrl' => 'x', 'contentType' => 'application/json'],
    ]);

    $out = $scraper->fetchProducts('https://store.example')[0];

    expect($out['description'])->toBe('Full desc only.');
    expect($out['images'])->toBe([]);
});

it('caps the image gallery at 25', function () {
    $images = array_map(fn ($i) => ['src' => "https://store.example/img/{$i}.jpg"], range(1, 30));
    $products = json_encode([[
        'id' => 902, 'name' => 'Many Images', 'slug' => 'many-images',
        'permalink' => 'https://store.example/product/many-images/',
        'prices' => ['price' => '500', 'currency_code' => 'AUD', 'currency_minor_unit' => 2],
        'images' => $images, 'is_in_stock' => true,
    ]]);

    $scraper = wooScraperWith([
        '/wp-json/wc/store/v1/products' => ['status' => 200, 'body' => $products, 'finalUrl' => 'x', 'contentType' => 'application/json'],
    ]);

    expect($scraper->fetchProducts('https://store.example')[0]['images'])->toHaveCount(25);
});

it('strips non-http(s) image URLs from the client-posted images array', function () {
    // productsFromClient() is the browser-assisted path — a hostile client
    // could post javascript:/data: entries; the hero `image` is already
    // filtered this way, `images[]` must be too (same documented invariant).
    $raw = [[
        'id' => 903,
        'name' => 'Client Item',
        'slug' => 'client-item',
        'permalink' => 'https://store.example/product/client-item/',
        'prices' => ['price' => '500', 'currency_code' => 'AUD', 'currency_minor_unit' => 2],
        'images' => [
            ['src' => 'https://store.example/img/ok.jpg'],
            ['src' => 'javascript:alert(1)'],
            ['src' => 'data:text/html,evil'],
        ],
        'is_in_stock' => true,
    ]];

    $scraper = wooScraperWith([]);
    $out = $scraper->productsFromClient('https://store.example', $raw)[0];

    expect($out['images'])->toBe(['https://store.example/img/ok.jpg']);
});
