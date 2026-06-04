<?php

use App\Services\Platforms\ShopifyScraper;
use App\Services\SmartLinks\SafeUrlFetcher;

afterEach(function () {
    Mockery::close();
});

// Build a ShopifyScraper whose fetcher returns $meta for /meta.json and $html
// for the homepage. Mirrors SafeUrlFetcher::fetch's {status, body} return shape.
function shopifyScraperWith(string $html, string $meta = '{"id":1,"name":"Shop","currency":"USD"}'): ShopifyScraper
{
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('fetch')->andReturnUsing(function (string $url, array $headers = []) use ($html, $meta) {
        return str_contains($url, '/meta.json')
            ? ['status' => 200, 'body' => $meta]
            : ['status' => 200, 'body' => $html];
    });

    return new ShopifyScraper($fetcher);
}

// Regression for motionlab.space: the header logo img has no class, lives under
// the /s/files/ CDN layout, and carries a ?v= cache-buster — and the store
// declares no <link rel="icon"> at all.
it('extracts a Shopify-CDN logo with a cache-buster and a class-less img', function () {
    $html = '<html><head>'
        .'<meta property="og:site_name" content="MOTION">'
        .'<meta property="og:image" content="https://cdn.shopify.com/s/files/1/0281/files/2-removebg.png?v=1738590890" />'
        .'</head><body>'
        .'<a href="/" class="logo"><img src="https://cdn.shopify.com/s/files/1/0281/files/motion_logo3.png?v=1614313159" width="50px"></a>'
        .'</body></html>';

    $brand = shopifyScraperWith($html, '{"id":28194898059,"name":"MOTION","currency":"AUD"}')
        ->fetchBrand('https://www.motionlab.space');

    expect($brand['name'])->toBe('MOTION')
        ->and($brand['logo'])->toContain('motion_logo3.png')
        ->and($brand['favicon'])->toBeNull();
});

it('returns a null favicon (not a /favicon.ico guess) when no icon link is declared', function () {
    $brand = shopifyScraperWith('<html><head></head><body></body></html>')->fetchBrand('https://shop.example.com');

    expect($brand['favicon'])->toBeNull();
});

it('uses a declared <link rel="icon"> as the favicon', function () {
    $html = '<head><link rel="icon" type="image/png" sizes="32x32" href="https://cdn.shopify.com/favicon32.png"></head>';

    $brand = shopifyScraperWith($html)->fetchBrand('https://shop.example.com');

    expect($brand['favicon'])->toBe('https://cdn.shopify.com/favicon32.png');
});

it('falls back to og:image for the logo when no logo signal is present', function () {
    $html = '<head><meta property="og:image" content="https://cdn.shopify.com/s/files/brandbanner.png?v=1"></head>';

    $brand = shopifyScraperWith($html)->fetchBrand('https://shop.example.com');

    expect($brand['logo'])->toBe('https://cdn.shopify.com/s/files/brandbanner.png?v=1');
});
