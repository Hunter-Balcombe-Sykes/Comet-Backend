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

it('captures the full image gallery from products.json', function () {
    $scraper = bigcartelScraperWith([
        '/atakontu/products.json' => ['status' => 200, 'body' => json_encode([
            [
                'id' => 3,
                'name' => 'Gallery Tee',
                'permalink' => 'gallery-tee',
                'price' => 25.0,
                'status' => 'active',
                'url' => '/product/gallery-tee',
                'images' => [
                    ['url' => 'http://assets.bigcartel.com/a.jpg', 'secure_url' => 'https://assets.bigcartel.com/a.jpg'],
                    ['url' => 'http://assets.bigcartel.com/b.jpg'],
                ],
            ],
        ]), 'finalUrl' => 'x', 'contentType' => 'application/json'],
    ]);

    $out = $scraper->fetchProducts('atakontu', 'EUR')[0];

    expect($out['image'])->toBe('https://assets.bigcartel.com/a.jpg');   // hero unchanged (secure_url preferred)
    expect($out['images'])->toBe(['https://assets.bigcartel.com/a.jpg', 'http://assets.bigcartel.com/b.jpg']);
});

it('caps the image gallery at 25 and yields an empty array when there are none', function () {
    $images = array_map(fn ($i) => ['url' => "http://assets.bigcartel.com/{$i}.jpg"], range(1, 30));
    $scraper = bigcartelScraperWith([
        '/atakontu/products.json' => ['status' => 200, 'body' => json_encode([
            ['id' => 4, 'name' => 'Many', 'permalink' => 'many', 'price' => 10.0, 'status' => 'active', 'url' => '/p/many', 'images' => $images],
            ['id' => 5, 'name' => 'None', 'permalink' => 'none', 'price' => 5.0, 'status' => 'active', 'url' => '/p/none', 'images' => []],
        ]), 'finalUrl' => 'x', 'contentType' => 'application/json'],
    ]);

    $out = $scraper->fetchProducts('atakontu');

    expect($out[0]['images'])->toHaveCount(25);
    expect($out[1]['images'])->toBe([]);
});
