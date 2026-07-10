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
    return (string) file_get_contents(dirname(__DIR__, 2).'/fixtures/shop/'.$name);
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
