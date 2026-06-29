<?php

use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\BigCartelScraper;

afterEach(function () {
    Mockery::close();
});

function bigcartelScraperWith(array $routes): BigCartelScraper
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

    return new BigCartelScraper($fetcher);
}

it('extracts the account from *.bigcartel.com hosts only', function () {
    $scraper = bigcartelScraperWith([]);

    expect($scraper->accountFromUrl('https://atakontu.bigcartel.com/products'))->toBe('atakontu');
    expect($scraper->accountFromUrl('http://Some-Shop.bigcartel.com'))->toBe('some-shop');
    expect($scraper->accountFromUrl('https://www.bigcartel.com/product/examples'))->toBeNull();
    expect($scraper->accountFromUrl('https://example.com'))->toBeNull();
});

it('reads the store identity and currency from store.json', function () {
    $scraper = bigcartelScraperWith([
        '/atakontu/store.json' => ['status' => 200, 'body' => json_encode([
            'name' => 'Atakontu', 'subdomain' => 'atakontu',
            'currency' => ['code' => 'eur', 'sign' => '€'],
        ]), 'finalUrl' => 'x', 'contentType' => 'application/json'],
    ]);

    $store = $scraper->fetchStore('atakontu');

    expect($store['id'])->toBe('bigcartel-atakontu');
    expect($store['name'])->toBe('Atakontu');
    expect($store['currency'])->toBe('EUR');
    expect($store['origin'])->toBe('https://atakontu.bigcartel.com');
});

it('maps products.json to the canonical product shape', function () {
    $scraper = bigcartelScraperWith([
        '/atakontu/products.json' => ['status' => 200, 'body' => json_encode([
            [
                'id' => 119661900,
                'name' => 'Shopping Bag Rojo',
                'permalink' => 'shopping-bag-rojo',
                'price' => 39.0,
                'default_price' => 39.0,
                'status' => 'active',
                'url' => '/product/shopping-bag-rojo',
                'images' => [['url' => 'http://assets.bigcartel.com/1.jpg', 'secure_url' => 'https://assets.bigcartel.com/1.jpg']],
            ],
            [
                'id' => 2,
                'name' => 'Sold Out Tote',
                'permalink' => 'sold-out-tote',
                'price' => 12.5,
                'status' => 'sold-out',
                'url' => '/product/sold-out-tote',
                'images' => [],
            ],
        ]), 'finalUrl' => 'x', 'contentType' => 'application/json'],
    ]);

    $out = $scraper->fetchProducts('atakontu', 'EUR');

    expect($out)->toHaveCount(2);
    expect($out[0])->toMatchArray([
        'productId' => '119661900',
        'title' => 'Shopping Bag Rojo',
        'handle' => 'shopping-bag-rojo',
        'image' => 'https://assets.bigcartel.com/1.jpg',
        'price' => '39.00',
        'currency' => 'EUR',
        'available' => true,
        'url' => 'https://atakontu.bigcartel.com/product/shopping-bag-rojo',
    ]);
    expect($out[1]['available'])->toBeFalse();
    expect($out[1]['image'])->toBeNull();
    expect($out[1]['price'])->toBe('12.50');
});
