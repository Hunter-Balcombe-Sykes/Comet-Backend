<?php

use App\Services\Http\SafeUrlFetcher;
use App\Services\Platforms\ShopifyScraper;
use App\Services\Platforms\WooCommerceScraper;
use Symfony\Component\HttpKernel\Exception\HttpException;

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

// Same WS-B1 bug class as fetchBrand above, one method over: fetchProducts's
// abort message interpolated $response['status'] inside the very branch that
// fires when $response is NULL (transport failure), so the intended 502 became
// a 500 via the error handler's ErrorException. Reachable on any transport
// failure, and newly routine now that W1 opens a FetchBudget — an exhausted
// budget makes tryFetch() return null on exactly this path.
it('Shopify fetchProducts aborts 502, not a 500, when products.json fails at transport level', function () {
    try {
        (new ShopifyScraper(transportFailFetcher()))->fetchProducts('https://store.example');
        $this->fail('expected a 502 HttpException');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(502)
            ->and($e->getMessage())->toContain('no response');
    }
});
