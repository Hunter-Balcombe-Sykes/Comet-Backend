<?php

use App\Services\Http\SafeUrlFetcher;
use App\Services\V5\Scraping\Platforms\FreshaScraper;
use App\Services\V5\Scraping\Platforms\OpenTableScraper;
use App\Services\V5\Scraping\Platforms\ShopifyScraper;
use App\Services\V5\Scraping\Platforms\SquareScraper;
use App\Services\V5\Scraping\Platforms\WooCommerceScraper;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// =========================================================================
// ShopifyScraper — graceful handling tests
// =========================================================================

it('returns empty items+profile when store is unreachable', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')
        ->andReturn(null); // simulate network failure

    $scraper = new ShopifyScraper($fetcher);

    $result = $scraper->fetch('https://nonexistent-shop-12345.myshopify.com');

    expect($result)->toHaveKey('items');
    expect($result)->toHaveKey('profile');
    expect($result['items'])->toBeArray();
    expect($result['profile'])->toBeArray();
});

it('returns products when /products.json returns data', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);

    // /meta.json response
    $fetcher->shouldReceive('tryFetch')
        ->withArgs(fn ($url) => str_contains($url, 'meta.json'))
        ->andReturn([
            'status' => 200,
            'body' => json_encode(['name' => 'Test Store', 'id' => 123]),
        ]);

    // /products.json response
    $fetcher->shouldReceive('tryFetch')
        ->withArgs(fn ($url) => str_contains($url, 'products.json'))
        ->andReturn([
            'status' => 200,
            'body' => json_encode([
                'products' => [
                    [
                        'id' => 1,
                        'title' => 'Test Product',
                        'handle' => 'test-product',
                        'body_html' => '<p>A great product</p>',
                        'variants' => [
                            ['id' => 10, 'price' => '29.99', 'available' => true],
                        ],
                    ],
                ],
            ]),
        ]);

    $scraper = new ShopifyScraper($fetcher);

    $result = $scraper->fetch('https://test-store.myshopify.com');

    expect($result['items'])->toHaveCount(1);
    expect($result['items'][0]['identifier'])->toBe('1');
    expect($result['items'][0]['name'])->toBe('Test Product');
    expect($result['items'][0]['item_type'])->toBe('product');
    expect($result['profile']['display_name'])->toBe('Test Store');

    // Check values
    $values = $result['items'][0]['values'];
    $titles = array_filter($values, fn ($v) => $v['field_name'] === 'title');
    expect($titles)->not->toBeEmpty();
    expect($titles[array_key_first($titles)]['value'])->toBe('Test Product');

    $prices = array_filter($values, fn ($v) => $v['field_name'] === 'price');
    expect($prices)->not->toBeEmpty();
    expect($prices[array_key_first($prices)]['value'])->toBe('29.99');
});

it('returns empty items when /products.json returns no products', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);

    // /meta.json response
    $fetcher->shouldReceive('tryFetch')
        ->withArgs(fn ($url) => str_contains($url, 'meta.json'))
        ->andReturn([
            'status' => 200,
            'body' => json_encode(['name' => 'Empty Store']),
        ]);

    // /products.json returns empty
    $fetcher->shouldReceive('tryFetch')
        ->withArgs(fn ($url) => str_contains($url, 'products.json'))
        ->andReturn([
            'status' => 200,
            'body' => json_encode(['products' => []]),
        ]);

    $scraper = new ShopifyScraper($fetcher);

    $result = $scraper->fetch('https://empty-store.myshopify.com');

    expect($result['items'])->toBeArray();
    expect($result['items'])->toBeEmpty();
});

// =========================================================================
// WooCommerceScraper — graceful handling tests
// =========================================================================

it('returns empty items+profile when WooCommerce Store API is unreachable', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')
        ->andReturn(null);

    $scraper = new WooCommerceScraper($fetcher);

    $result = $scraper->fetch('https://nonexistent-woocommerce-store.com');

    expect($result)->toHaveKey('items');
    expect($result)->toHaveKey('profile');
    expect($result['items'])->toBeArray();
    expect($result['profile'])->toBeArray();
});

it('returns products when WooCommerce Store API returns data', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);

    // /wp-json for site name
    $fetcher->shouldReceive('tryFetch')
        ->withArgs(fn ($url) => str_contains($url, 'wp-json') && ! str_contains($url, 'wc/store'))
        ->andReturn([
            'status' => 200,
            'body' => json_encode(['name' => 'Woo Test Store']),
        ]);

    // Store API products
    $fetcher->shouldReceive('tryFetch')
        ->withArgs(fn ($url) => str_contains($url, 'wc/store/v1/products'))
        ->andReturn([
            'status' => 200,
            'body' => json_encode([
                [
                    'id' => 1,
                    'name' => 'Test Product',
                    'slug' => 'test-product',
                    'permalink' => 'https://test-store.com/product/test-product',
                    'prices' => [
                        'price' => '1999',
                        'currency_minor_unit' => 2,
                        'currency_code' => 'USD',
                    ],
                    'short_description' => 'A short description',
                    'is_in_stock' => true,
                ],
            ]),
        ]);

    $scraper = new WooCommerceScraper($fetcher);

    $result = $scraper->fetch('https://test-store.com');

    expect($result['items'])->toHaveCount(1);
    expect($result['items'][0]['identifier'])->toBe('1');
    expect($result['items'][0]['name'])->toBe('Test Product');
    expect($result['items'][0]['item_type'])->toBe('product');
    expect($result['profile']['display_name'])->toBe('Woo Test Store');

    $values = $result['items'][0]['values'];
    $prices = array_filter($values, fn ($v) => $v['field_name'] === 'price');
    expect($prices)->not->toBeEmpty();
    expect($prices[array_key_first($prices)]['value'])->toBe('19.99');
});

it('tries ?rest_route= fallback when pretty URL fails', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);

    // Pretty URL fails (404)
    $fetcher->shouldReceive('tryFetch')
        ->withArgs(fn ($url) => str_contains($url, '/wp-json/wc/store/v1/products'))
        ->andReturn(['status' => 404, 'body' => '']);

    // ?rest_route= succeeds
    $fetcher->shouldReceive('tryFetch')
        ->withArgs(fn ($url) => str_contains($url, 'rest_route'))
        ->andReturn([
            'status' => 200,
            'body' => json_encode([
                ['id' => 2, 'name' => 'Fallback Product', 'slug' => 'fallback', 'permalink' => 'https://test.com/fallback', 'prices' => ['price' => '500', 'currency_minor_unit' => 2]],
            ]),
        ]);

    // /wp-json for site name (also fails? or succeeds)
    $fetcher->shouldReceive('tryFetch')
        ->withArgs(fn ($url) => $url === 'https://test-store.com/wp-json')
        ->andReturn(['status' => 200, 'body' => json_encode(['name' => null])]);

    $scraper = new WooCommerceScraper($fetcher);

    $result = $scraper->fetch('https://test-store.com');

    expect($result['items'])->toHaveCount(1);
    expect($result['items'][0]['name'])->toBe('Fallback Product');
});

// =========================================================================
// FreshaScraper — graceful handling tests
// =========================================================================

it('returns empty items+profile when Fresha page is unreachable', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')
        ->andReturn(null);

    $scraper = new FreshaScraper($fetcher);

    $result = $scraper->fetch('https://www.fresha.com/a/test-location');

    expect($result)->toHaveKey('items');
    expect($result)->toHaveKey('profile');
    expect($result['items'])->toBeArray();
    expect($result['profile'])->toBeArray();
});

it('returns empty items when Fresha page has no __NEXT_DATA__', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')
        ->andReturn([
            'status' => 200,
            'body' => '<html><body>No Next.js data here</body></html>',
        ]);

    $scraper = new FreshaScraper($fetcher);

    $result = $scraper->fetch('https://www.fresha.com/a/test-location');

    expect($result['items'])->toBeArray();
    expect($result['items'])->toBeEmpty();
});

it('parses services from Fresha __NEXT_DATA__', function () {
    $nextData = json_encode([
        'props' => [
            'pageProps' => [
                'data' => [
                    'location' => [
                        'name' => 'Test Salon',
                        'services' => [
                            [
                                'name' => 'Haircuts',
                                'items' => [
                                    [
                                        'id' => 's:123',
                                        'name' => 'Basic Haircut',
                                        'caption' => '30 min',
                                        'description' => 'A great haircut',
                                        'formattedRetailPrice' => '$50',
                                    ],
                                    [
                                        'id' => 's:456',
                                        'name' => 'Deluxe Haircut',
                                        'caption' => '45 min',
                                        'formattedRetailPrice' => '$80',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $html = '<html><head></head><body>'
        .'<script id="__NEXT_DATA__" type="application/json">'.$nextData.'</script>'
        .'</body></html>';

    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')
        ->andReturn(['status' => 200, 'body' => $html]);

    $scraper = new FreshaScraper($fetcher);

    $result = $scraper->fetch('https://www.fresha.com/en-au/a/test-location');

    expect($result['items'])->toHaveCount(2);
    expect($result['profile']['display_name'])->toBe('Test Salon');
    expect($result['items'][0]['identifier'])->toBe('s:123');
    expect($result['items'][0]['name'])->toBe('Basic Haircut');
    expect($result['items'][1]['name'])->toBe('Deluxe Haircut');

    // Check values on first item
    $values = $result['items'][0]['values'];
    $durations = array_filter($values, fn ($v) => $v['field_name'] === 'duration');
    expect($durations)->not->toBeEmpty();
    expect($durations[array_key_first($durations)]['value'])->toBe('30 min');
});

it('filters non-service items from Fresha data', function () {
    $nextData = json_encode([
        'props' => [
            'pageProps' => [
                'data' => [
                    'location' => [
                        'name' => 'Test Salon',
                        'services' => [
                            [
                                'name' => 'Category',
                                'items' => [
                                    ['id' => 's:1', 'name' => 'Real Service'],
                                    ['id' => 'p:2', 'name' => 'Product (should be skipped)'],
                                    ['id' => 'gift:3', 'name' => 'Gift Card (should be skipped)'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $html = '<script id="__NEXT_DATA__" type="application/json">'.$nextData.'</script>';

    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')
        ->andReturn(['status' => 200, 'body' => $html]);

    $scraper = new FreshaScraper($fetcher);

    $result = $scraper->fetch('https://www.fresha.com/a/test-location');

    expect($result['items'])->toHaveCount(1);
    expect($result['items'][0]['name'])->toBe('Real Service');
});

// =========================================================================
// SquareScraper — graceful handling tests
// =========================================================================

it('returns empty items+profile for Square (not implemented)', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $scraper = new SquareScraper($fetcher);

    $result = $scraper->fetch('https://order.fat-tuna.com');

    expect($result)->toHaveKey('items');
    expect($result)->toHaveKey('profile');
    expect($result['items'])->toBeArray();
    expect($result['items'])->toBeEmpty();
    expect($result['profile'])->toBeArray();
});

// =========================================================================
// OpenTableScraper — graceful handling tests
// =========================================================================

it('returns empty items+profile for unparseable OpenTable URL', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $scraper = new OpenTableScraper($fetcher);

    $result = $scraper->fetch('https://example.com/not-opentable');

    expect($result)->toHaveKey('items');
    expect($result)->toHaveKey('profile');
    expect($result['items'])->toBeArray();
    expect($result['items'])->toBeEmpty();
});

it('returns item with widget embed URL for profile link', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $scraper = new OpenTableScraper($fetcher);

    $result = $scraper->fetch('https://www.opentable.com/restaurant/profile/12345');

    expect($result['items'])->toHaveCount(1);
    expect($result['items'][0]['identifier'])->toBe('opentable_12345');
    expect($result['items'][0]['item_type'])->toBe('service');

    $values = $result['items'][0]['values'];
    $embedUrls = array_filter($values, fn ($v) => $v['field_name'] === 'embed_url');
    expect($embedUrls)->not->toBeEmpty();
    $embedUrl = $embedUrls[array_key_first($embedUrls)]['value'];
    expect($embedUrl)->toContain('widget/reservation/canvas');
    expect($embedUrl)->toContain('rid=12345');
});

it('returns item for slug link with note', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $scraper = new OpenTableScraper($fetcher);

    $result = $scraper->fetch('https://www.opentable.com/r/nobu-sydney');

    expect($result['items'])->toHaveCount(1);
    expect($result['items'][0]['name'])->toBe('Nobu Sydney');

    $values = $result['items'][0]['values'];
    $notes = array_filter($values, fn ($v) => $v['field_name'] === 'note');
    expect($notes)->not->toBeEmpty();
    expect($notes[array_key_first($notes)]['value'])->toContain('slug link');
});

it('handles OpenTable URLs with www.opentable.com.au', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $scraper = new OpenTableScraper($fetcher);

    $result = $scraper->fetch('https://www.opentable.com.au/restaurant/profile/9876');

    expect($result['items'])->toHaveCount(1);
    expect($result['items'][0]['identifier'])->toBe('opentable_9876');

    $values = $result['items'][0]['values'];
    $embedUrls = array_filter($values, fn ($v) => $v['field_name'] === 'embed_url');
    expect($embedUrls)->not->toBeEmpty();
    $embedUrl = $embedUrls[array_key_first($embedUrls)]['value'];
    expect($embedUrl)->toContain('rid=9876');
});

it('handles OpenTable URLs with ?rid= query param', function () {
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $scraper = new OpenTableScraper($fetcher);

    $result = $scraper->fetch('https://www.opentable.com/some-page?rid=5555');

    expect($result['items'])->toHaveCount(1);
    expect($result['items'][0]['identifier'])->toBe('opentable_5555');

    $values = $result['items'][0]['values'];
    $embedUrls = array_filter($values, fn ($v) => $v['field_name'] === 'embed_url');
    expect($embedUrls)->not->toBeEmpty();
    expect($embedUrls[array_key_first($embedUrls)]['value'])->toContain('rid=5555');
});
