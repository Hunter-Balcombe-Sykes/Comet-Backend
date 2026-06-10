<?php

use App\Services\Platforms\SquarespaceScraper;
use App\Services\SmartLinks\SafeUrlFetcher;

afterEach(function () {
    Mockery::close();
});

// Build a SquarespaceScraper whose fetcher returns canned bodies per URL needle.
function sqspScraperWith(array $routes): SquarespaceScraper
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

    return new SquarespaceScraper($fetcher);
}

function sqspProductsJson(): string
{
    return json_encode([
        'website' => ['siteTitle' => 'Hester Store', 'logoImageUrl' => 'https://static1.squarespace.com/logo.png'],
        'collection' => ['typeName' => 'products'],
        'items' => [
            [
                'id' => 'item-1',
                'title' => 'Beni &amp; Shoga',
                'fullUrl' => '/shop/p/beni-shoga',
                'assetUrl' => 'https://static1.squarespace.com/beni.jpg',
                'structuredContent' => [
                    'variants' => [[
                        'id' => 'var-1',
                        'priceMoney' => ['currency' => 'usd', 'value' => '10.00'],
                        'onSale' => false,
                        'qtyInStock' => 4,
                        'unlimited' => false,
                    ]],
                ],
            ],
            [
                'id' => 'item-2',
                'title' => 'Sale Plate',
                'fullUrl' => '/shop/p/sale-plate',
                'assetUrl' => null,
                'structuredContent' => [
                    'variants' => [[
                        'id' => 'var-2',
                        'priceMoney' => ['currency' => 'USD', 'value' => '40.00'],
                        'salePriceMoney' => ['currency' => 'USD', 'value' => '25.00'],
                        'onSale' => true,
                        'qtyInStock' => 0,
                        'unlimited' => false,
                    ]],
                ],
            ],
        ],
    ]);
}

it('discovers the products collection from the pasted URL or shop fallbacks', function () {
    // Pasted URL is the products page itself.
    $direct = sqspScraperWith(['/shop?format=json' => ['status' => 200, 'body' => sqspProductsJson(), 'finalUrl' => 'x', 'contentType' => 'application/json']]);
    expect($direct->discoverProductsUrl('https://hester.example/shop'))->toBe('https://hester.example/shop');

    // Pasted homepage; /store answers.
    $fallback = sqspScraperWith([
        'hester.example/?format=json' => ['status' => 200, 'body' => '{"collection":{"typeName":"page"}}', 'finalUrl' => 'x', 'contentType' => 'application/json'],
        '/store?format=json' => ['status' => 200, 'body' => sqspProductsJson(), 'finalUrl' => 'x', 'contentType' => 'application/json'],
    ]);
    expect($fallback->discoverProductsUrl('https://hester.example/'))->toBe('https://hester.example/store');

    // Not a Squarespace site at all (HTML comes back) → null.
    $not = sqspScraperWith(['?format=json' => ['status' => 200, 'body' => '<html></html>', 'finalUrl' => 'x', 'contentType' => 'text/html']]);
    expect($not->discoverProductsUrl('https://blog.example/'))->toBeNull();
});

it('maps page-model items to the canonical product shape with sale pricing', function () {
    $scraper = sqspScraperWith(['/shop?format=json' => ['status' => 200, 'body' => sqspProductsJson(), 'finalUrl' => 'x', 'contentType' => 'application/json']]);

    $out = $scraper->fetchProducts('https://hester.example/shop');

    expect($out)->toHaveCount(2);
    expect($out[0])->toMatchArray([
        'productId' => 'item-1',
        'title' => 'Beni & Shoga',
        'handle' => 'beni-shoga',
        'image' => 'https://static1.squarespace.com/beni.jpg',
        'price' => '10.00',
        'currency' => 'USD',
        'variantId' => 'var-1',
        'available' => true,
        'url' => 'https://hester.example/shop/p/beni-shoga',
    ]);
    // On-sale variant: sale price wins; zero stock + not unlimited = unavailable.
    expect($out[1]['price'])->toBe('25.00');
    expect($out[1]['available'])->toBeFalse();
});

it('reads the brand identity from the page model website block', function () {
    $scraper = sqspScraperWith(['/shop?format=json' => ['status' => 200, 'body' => sqspProductsJson(), 'finalUrl' => 'x', 'contentType' => 'application/json']]);

    $brand = $scraper->fetchBrand('https://hester.example/shop');

    expect($brand['id'])->toBe('hester-example');
    expect($brand['name'])->toBe('Hester Store');
    expect($brand['logo'])->toBe('https://static1.squarespace.com/logo.png');
    expect($brand['currency'])->toBe('USD');
});
