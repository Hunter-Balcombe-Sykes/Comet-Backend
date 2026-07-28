<?php

// The four probes that complete §11's "one connector, five probes" cascade
// (WAVE-2C item 1): WooCommerce, Squarespace, Big Cartel and the generic
// JSON-LD fallback, ported from the legacy ShopProviderDetector.
//
// What these pin is ORDER and EVIDENCE: each platform must be claimed by its
// own probe (never by the less specific one below it), and a hit must carry
// enough identity forward that the seeder never re-fetches.

use App\Routing\IriCanonicalizer;
use App\Routing\Probes\BigCartelStorefrontProbe;
use App\Routing\Probes\LinkProbeWorker;
use App\Routing\Probes\ProbeBudget;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    app(ProbeBudget::class)->startRun();
});

function cascadeIri(string $url)
{
    return app(IriCanonicalizer::class)->canonicalize($url);
}

function cascadeWorker(): LinkProbeWorker
{
    return app(LinkProbeWorker::class);
}

// ── WooCommerce ──────────────────────────────────────────────────────────────

it('identifies an own-domain WooCommerce store via the Store API', function () {
    Http::fake([
        '*/wp-json/wc/store/v1/products*' => Http::response([['id' => 11, 'name' => 'A Mug']], 200),
        '*/wp-json' => Http::response(['name' => 'Mug Emporium'], 200),
        '*' => Http::response('', 404),
    ]);

    $outcome = cascadeWorker()->probe(cascadeIri('https://example.com'), 'user-woo');

    expect($outcome->isMatch())->toBeTrue()
        ->and($outcome->surfaceKey)->toBe('woocommerce.store')
        ->and($outcome->probe)->toBe('woocommerce_store_api')
        ->and($outcome->identifier)->toBe('example-com')
        ->and($outcome->evidence['shop_name'])->toBe('Mug Emporium');
});

it('accepts the ?rest_route= Store API form plain-permalink sites serve', function () {
    Http::fake([
        '*rest_route=*' => Http::response([['id' => 3]], 200),
        '*' => Http::response('', 404),
    ]);

    $outcome = cascadeWorker()->probe(cascadeIri('https://example.com'), 'user-woo-plain');

    expect($outcome->isMatch())->toBeTrue()
        ->and($outcome->surfaceKey)->toBe('woocommerce.store');
});

// ── Squarespace ──────────────────────────────────────────────────────────────

it('identifies a Squarespace store via a products collection answering format=json', function () {
    $collection = [
        'collection' => ['typeName' => 'products'],
        'items' => [['structuredContent' => ['variants' => [['priceMoney' => ['currency' => 'AUD']]]]]],
        'website' => ['siteTitle' => 'The Studio Store', 'logoImageUrl' => 'https://images.example.com/logo.png'],
    ];
    Http::fake([
        'https://example.com/shop*' => Http::response($collection, 200),
        '*' => Http::response('', 404),
    ]);

    $outcome = cascadeWorker()->probe(cascadeIri('https://example.com/shop'), 'user-sqsp');

    expect($outcome->isMatch())->toBeTrue()
        ->and($outcome->surfaceKey)->toBe('squarespace.store')
        ->and($outcome->probe)->toBe('squarespace_format_json')
        ->and($outcome->identifier)->toBe('example-com')
        ->and($outcome->evidence['source_url'])->toBe('https://example.com/shop')
        ->and($outcome->evidence['shop_name'])->toBe('The Studio Store')
        ->and($outcome->evidence['currency'])->toBe('AUD');
});

// ── Big Cartel ───────────────────────────────────────────────────────────────

it('verifies a bigcartel tenant host through store.json when probed directly', function () {
    // A PASTED tenant URL never reaches the cascade (suffix override → the
    // detector places it; the worker refuses already_matched). The probe's
    // attempt() is exercised directly, as a seeder or importer would.
    Http::fake([
        'https://api.bigcartel.com/acme/store.json' => Http::response([
            'name' => 'Acme Prints', 'currency' => ['code' => 'usd'],
        ], 200),
        '*' => Http::response('', 404),
    ]);

    $hit = app(BigCartelStorefrontProbe::class)
        ->attempt(cascadeIri('https://acme.bigcartel.com'));

    expect($hit)->not->toBeNull()
        ->and($hit['identifier'])->toBe('bigcartel-acme')
        ->and($hit['evidence']['origin'])->toBe('https://acme.bigcartel.com')
        ->and($hit['evidence']['shop_name'])->toBe('Acme Prints')
        ->and($hit['evidence']['currency'])->toBe('USD');
});

it('treats a dead bigcartel subdomain as a miss, not a store', function () {
    Http::fake(['*' => Http::response('', 404)]);

    $hit = app(BigCartelStorefrontProbe::class)
        ->attempt(cascadeIri('https://gone.bigcartel.com'));

    expect($hit)->toBeNull();
});

it('spends nothing on a non-bigcartel host', function () {
    Http::fake();

    $hit = app(BigCartelStorefrontProbe::class)
        ->attempt(cascadeIri('https://example.com'));

    expect($hit)->toBeNull();
    Http::assertNothingSent();
});

// ── Generic JSON-LD ──────────────────────────────────────────────────────────

it('falls through to the generic probe for a custom storefront with Product JSON-LD', function () {
    $html = '<html><head><title>Handmade Things</title>'
        .'<script type="application/ld+json">'
        .json_encode(['@type' => 'Product', 'name' => 'A Vase', 'offers' => ['price' => '49.00', 'priceCurrency' => 'AUD']])
        .'</script></head><body></body></html>';

    Http::fake([
        // The canonicalised page URL carries a trailing slash; every platform
        // probe hitting its own endpoint under this host gets HTML back, which
        // each rejects as "not my JSON" — leaving the page for generic.
        'https://example.com*' => Http::response($html, 200, ['Content-Type' => 'text/html']),
        '*' => Http::response('', 404),
    ]);

    $outcome = cascadeWorker()->probe(cascadeIri('https://example.com'), 'user-generic');

    expect($outcome->isMatch())->toBeTrue()
        ->and($outcome->surfaceKey)->toBe('partna.storefront')
        ->and($outcome->probe)->toBe('generic_jsonld_products')
        ->and($outcome->identifier)->toBe('example-com')
        ->and($outcome->evidence['currency'])->toBe('AUD');
});

it('reports a miss for a plain page that is no kind of shop', function () {
    Http::fake([
        '*' => Http::response('<html><body>Just a blog.</body></html>', 200, ['Content-Type' => 'text/html']),
    ]);

    $outcome = cascadeWorker()->probe(cascadeIri('https://example.com'), 'user-blog');

    // Every probe ran; the page serves fine and declares nothing — an honest
    // miss (cached as one), never an error.
    expect($outcome->isMatch())->toBeFalse()
        ->and($outcome->outcome)->toBe('miss');
});

// ── Order: specific before generic ───────────────────────────────────────────

it('lets Shopify claim a store whose page also carries Product JSON-LD', function () {
    // A Shopify storefront's HTML often embeds Product JSON-LD too. The
    // platform probe must win — the generic probe is a fallback, not a race.
    $html = '<script type="application/ld+json">'
        .json_encode(['@type' => 'Product', 'name' => 'A Tee'])
        .'</script>';

    Http::fake([
        '*/meta.json' => Http::response(['id' => 77, 'name' => 'Tee Shop', 'currency' => 'AUD'], 200),
        'https://example.com' => Http::response($html, 200, ['Content-Type' => 'text/html']),
        '*' => Http::response('', 404),
    ]);

    $outcome = cascadeWorker()->probe(cascadeIri('https://example.com'), 'user-order');

    expect($outcome->surfaceKey)->toBe('shopify.store');
});

it('lets WooCommerce claim a store before Squarespace and generic get a look', function () {
    Http::fake([
        '*/wp-json/wc/store/v1/products*' => Http::response([['id' => 1]], 200),
        '*/wp-json' => Http::response(['name' => 'Woo Store'], 200),
        '*format=json*' => Http::response(['collection' => ['typeName' => 'products'], 'items' => []], 200),
        '*' => Http::response('', 404),
    ]);

    $outcome = cascadeWorker()->probe(cascadeIri('https://example.com'), 'user-order2');

    expect($outcome->surfaceKey)->toBe('woocommerce.store');
});
