<?php

use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\ShopifyScraper;
use App\Services\Platforms\WooCommerceScraper;

// WS-B1: a transport-level failure on the brand homepage fetch (SafeUrlFetcher::
// tryFetch → null) must yield a clean brand result, not a 500. Feature-bound on
// purpose — Laravel's error handler converts the pre-fix `$home['status']` array
// access on null into a thrown ErrorException, so this genuinely fails without the
// null-guard (a pure-unit context has no such handler and would pass regardless).

afterEach(fn () => Mockery::close());

// A fetcher whose WP-root / meta.json resolve (carrying a site name) but whose
// homepage fetch fails at transport level (tryFetch → null).
function transportFailFetcher(): SafeUrlFetcher
{
    $fetcher = Mockery::mock(SafeUrlFetcher::class);
    $fetcher->shouldReceive('tryFetch')->andReturnUsing(function (string $url) {
        if (str_contains($url, '/wp-json') || str_contains($url, '/meta.json')) {
            return ['status' => 200, 'body' => '{"name":"Store"}', 'finalUrl' => $url, 'contentType' => 'application/json'];
        }

        return null; // homepage → transport failure
    });

    return $fetcher;
}

it('WooCommerce fetchBrand returns cleanly when the homepage fetch fails at transport level', function () {
    $brand = (new WooCommerceScraper(transportFailFetcher()))->fetchBrand('https://store.example');

    expect($brand)->toHaveKeys(['id', 'name', 'currency', 'favicon', 'logo'])
        ->and($brand['id'])->toBe('store-example')
        ->and($brand['name'])->toBe('Store');
});

it('Shopify fetchBrand returns cleanly when the homepage fetch fails at transport level', function () {
    $brand = (new ShopifyScraper(transportFailFetcher()))->fetchBrand('https://store.example');

    // meta.json carries no id → the host slug is used; name comes from meta.json.
    expect($brand)->toHaveKeys(['id', 'name', 'currency', 'favicon', 'logo'])
        ->and($brand['id'])->toBe('store-example')
        ->and($brand['name'])->toBe('Store');
});
