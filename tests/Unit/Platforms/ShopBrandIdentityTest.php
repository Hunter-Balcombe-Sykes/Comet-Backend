<?php

use App\Services\Platforms\ShopBrandIdentity;
use App\Services\Platforms\ShopBrandProfiler;
use App\Services\Platforms\ShopifyScraper;
use App\Services\Platforms\ShopProviderDetector;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// W9 Unit 2 — the load-bearing seam test. ShopBrandIdentity::for() must
// derive the EXACT SAME brand_id ShopBrandProfiler::forDetected() (which
// calls the real fetchBrand() on each scraper) produces, for every provider,
// without re-fetching the profile. Each case builds a $detected array in the
// shape ShopProviderDetector::detectDetailed() hands back and compares both
// services against it — never a hand-computed expectation for the HTTP-backed
// providers, so a reimplementation drift in either service fails loudly.
//
// Woo and Squarespace derive an id from the host with DIFFERENT expressions
// (WooCommerceScraper::brandIdFor() keeps a leading "www-"; SquarespaceScraper
// ::idFromOrigin() strips it first) — https://www.example.com is the case
// that pins the two apart (T8).
//
// LIMITATION (post-review, W9 Unit 4): for the Shopify cases below,
// Http::fake() serves the SAME canned meta.json body to both
// ShopBrandIdentity::for()'s call and ShopBrandProfiler::forDetected()'s
// call — so this file proves EXPRESSION parity (both derivations read the
// same $meta the same way), not FETCH atomicity. It cannot detect two
// real, independent /meta.json round-trips disagreeing (edge-cache drift, a
// myshopify_domain change mid-flight). That risk is why addBrand()'s
// SYNCHRONOUS branch derives $id from $brand['id'] (the single fetchBrand()
// call that also produced name/currency/favicon/logo) rather than from
// ShopBrandIdentity — see ShopController::addBrand()'s own comment. Identity
// is used for $id ONLY on the deferred branch, where there is no profile
// fetch to derive it from in the first place.

it('derives the same id as the profile for a bigcartel brand — no HTTP either side', function () {
    Http::fake();

    $detected = [
        'provider' => ShopProviderDetector::PROVIDER_BIGCARTEL,
        'origin' => 'https://acct.bigcartel.com',
        'sourceUrl' => 'https://acct.bigcartel.com',
        'page' => null,
        'store' => [
            'id' => 'bigcartel-acct', 'name' => 'Acct Store', 'currency' => 'AUD',
            'favicon' => null, 'logo' => null, 'origin' => 'https://acct.bigcartel.com',
        ],
        'meta' => null,
    ];

    $id = app(ShopBrandIdentity::class)->for($detected);
    [$brand] = app(ShopBrandProfiler::class)->forDetected($detected);

    expect($id)->toBe('bigcartel-acct')->toBe($brand['id']);
    Http::assertNothingSent();
});

it('derives the same id as the profile for a generic brand — no HTTP either side', function () {
    Http::fake();

    $detected = [
        'provider' => ShopProviderDetector::PROVIDER_GENERIC,
        'origin' => 'https://shop.example',
        'sourceUrl' => 'https://shop.example/collection',
        'page' => [
            'brand' => ['id' => 'shop-example', 'name' => 'Generic Shop', 'currency' => 'AUD', 'favicon' => null, 'logo' => null],
            'products' => [],
        ],
        'store' => null,
        'meta' => null,
    ];

    $id = app(ShopBrandIdentity::class)->for($detected);
    [$brand] = app(ShopBrandProfiler::class)->forDetected($detected);

    expect($id)->toBe('shop-example')->toBe($brand['id']);
    Http::assertNothingSent();
});

it('derives the same id as the profile for a client-assisted brand — no HTTP either side', function () {
    Http::fake();

    $detected = [
        'provider' => ShopProviderDetector::PROVIDER_WOOCOMMERCE,
        'origin' => 'https://client.example',
        'sourceUrl' => 'https://client.example',
        'page' => null,
        'store' => null,
        'meta' => null,
        'clientBrand' => ['id' => 'client-example', 'name' => 'Client Store', 'currency' => null, 'favicon' => 'https://client.example/favicon.ico', 'logo' => null],
        'clientProducts' => [],
    ];

    $id = app(ShopBrandIdentity::class)->for($detected);
    [$brand] = app(ShopBrandProfiler::class)->forDetected($detected);

    expect($id)->toBe('client-example')->toBe($brand['id']);
    Http::assertNothingSent();
});

// T8 — the divergence hazard: Woo keeps the "www-" prefix.
it('derives the same id as the profile for a woocommerce brand at a www. host', function () {
    Http::fake([
        'https://www.example.com/wp-json' => Http::response(json_encode(['name' => 'Example Store']), 200),
        'https://www.example.com/' => Http::response('<html></html>', 200),
    ]);

    $detected = [
        'provider' => ShopProviderDetector::PROVIDER_WOOCOMMERCE,
        'origin' => 'https://www.example.com',
        'sourceUrl' => 'https://www.example.com',
        'page' => null,
        'store' => null,
        'meta' => null,
    ];

    $id = app(ShopBrandIdentity::class)->for($detected);
    [$brand] = app(ShopBrandProfiler::class)->forDetected($detected);

    expect($id)->toBe('www-example-com')->toBe($brand['id']);
});

// T8 — same www. host as the case above, but Squarespace strips "www."
// first: 'example-com', not 'www-example-com'. Pins the two apart forever.
it('derives the same id as the profile for a squarespace brand at the SAME www. host, and it differs from woocommerce', function () {
    Http::fake([
        'https://www.example.com/shop?format=json' => Http::response(json_encode([
            'website' => ['siteTitle' => 'Example Squarespace', 'logoImageUrl' => null],
            'items' => [],
        ]), 200),
    ]);

    $detected = [
        'provider' => ShopProviderDetector::PROVIDER_SQUARESPACE,
        'origin' => 'https://www.example.com',
        'sourceUrl' => 'https://www.example.com/shop',
        'page' => null,
        'store' => null,
        'meta' => null,
    ];

    $id = app(ShopBrandIdentity::class)->for($detected);
    [$brand] = app(ShopBrandProfiler::class)->forDetected($detected);

    expect($id)->toBe('example-com')->toBe($brand['id'])
        ->and($id)->not->toBe('www-example-com'); // the woocommerce case above
});

it('derives the same id as the profile for a shopify brand with a meta.json id, using the CARRIED meta (no second /meta.json fetch)', function () {
    Http::fake([
        'https://example.com/meta.json' => Http::response(json_encode(['id' => 987654321, 'name' => 'Meta Shop', 'currency' => 'aud']), 200),
        'https://example.com/' => Http::response('<html></html>', 200),
    ]);

    $origin = 'https://example.com';
    // Mirrors exactly what ShopProviderDetector::detectDetailed() carries
    // forward on the detected array's 'meta' key.
    $meta = app(ShopifyScraper::class)->probeMeta($origin);

    $detected = [
        'provider' => ShopProviderDetector::PROVIDER_SHOPIFY,
        'origin' => $origin,
        'sourceUrl' => $origin,
        'page' => null,
        'store' => null,
        'meta' => $meta,
    ];

    $id = app(ShopBrandIdentity::class)->for($detected);
    [$brand] = app(ShopBrandProfiler::class)->forDetected($detected);

    expect($id)->toBe('987654321')->toBe($brand['id']);
});

// T8 — the host-slug fallback branch: no id in meta.json.
it('falls back to the host slug for a shopify brand whose meta.json has no id', function () {
    Http::fake([
        'https://example.com/meta.json' => Http::response(json_encode(['name' => 'Nameless Meta Shop']), 200),
        'https://example.com/' => Http::response('<html></html>', 200),
    ]);

    $origin = 'https://example.com';
    $meta = app(ShopifyScraper::class)->probeMeta($origin);

    $detected = [
        'provider' => ShopProviderDetector::PROVIDER_SHOPIFY,
        'origin' => $origin,
        'sourceUrl' => $origin,
        'page' => null,
        'store' => null,
        'meta' => $meta,
    ];

    $id = app(ShopBrandIdentity::class)->for($detected);
    [$brand] = app(ShopBrandProfiler::class)->forDetected($detected);

    expect($id)->toBe('example-com')->toBe($brand['id']);
});

// T9 — probe()/probeMeta() parity: a meta.json decoding to {} satisfies none
// of the isset() checks, so both must agree it's "not Shopify".
it('reports a meta.json decoding to {} as not-shopify on both probe() and probeMeta()', function () {
    Http::fake(['https://example.com/meta.json' => Http::response('{}', 200)]);

    $scraper = app(ShopifyScraper::class);

    expect($scraper->probe('https://example.com'))->toBeFalse();
    expect($scraper->probeMeta('https://example.com'))->toBeNull();
});
