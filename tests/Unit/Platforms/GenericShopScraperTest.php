<?php

use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\GenericShopScraper;

afterEach(function () {
    Mockery::close();
});

function genericScraperWith(string $html, int $status = 200): GenericShopScraper
{
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->andReturn([
        'status' => $status, 'body' => $html, 'finalUrl' => 'https://shop.example/store', 'contentType' => 'text/html',
    ]);

    return new GenericShopScraper($fetcher);
}

it('extracts products from an ItemList of Product JSON-LD nodes', function () {
    $ld = json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'ItemList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'item' => [
                '@type' => 'Product',
                'name' => 'Ceramic Mug',
                'sku' => 'MUG-1',
                'url' => '/products/ceramic-mug',
                'image' => ['https://shop.example/img/mug.jpg'],
                'offers' => ['@type' => 'Offer', 'price' => '29.00', 'priceCurrency' => 'aud', 'availability' => 'https://schema.org/InStock'],
            ]],
            ['@type' => 'ListItem', 'position' => 2, 'item' => [
                '@type' => 'Product',
                'name' => 'Sold Out Vase',
                'url' => 'https://shop.example/products/vase',
                'image' => ['@type' => 'ImageObject', 'url' => 'https://shop.example/img/vase.jpg'],
                'offers' => [['price' => '120', 'priceCurrency' => 'AUD', 'availability' => 'https://schema.org/OutOfStock']],
            ]],
        ],
    ]);
    $html = '<html><head><meta property="og:site_name" content="Example Ceramics">'
        .'<script type="application/ld+json">'.$ld.'</script></head><body></body></html>';

    $page = genericScraperWith($html)->fetchPage('https://shop.example/store');

    expect($page)->not->toBeNull();
    expect($page['brand']['name'])->toBe('Example Ceramics');
    expect($page['brand']['id'])->toBe('shop-example');
    expect($page['brand']['currency'])->toBe('AUD');

    expect($page['products'])->toHaveCount(2);
    expect($page['products'][0])->toMatchArray([
        'productId' => 'MUG-1',
        'title' => 'Ceramic Mug',
        'image' => 'https://shop.example/img/mug.jpg',
        'price' => '29.00',
        'currency' => 'AUD',
        'available' => true,
        'url' => 'https://shop.example/products/ceramic-mug',
    ]);
    expect($page['products'][1]['available'])->toBeFalse();
    expect($page['products'][1]['image'])->toBe('https://shop.example/img/vase.jpg');
});

it('returns null when the page has no Product JSON-LD', function () {
    $html = '<html><head><script type="application/ld+json">{"@type":"Organization","name":"X"}</script></head></html>';

    expect(genericScraperWith($html)->fetchPage('https://shop.example/store'))->toBeNull();
});

it('returns null on a non-200 page', function () {
    expect(genericScraperWith('', 404)->fetchPage('https://shop.example/store'))->toBeNull();
});
