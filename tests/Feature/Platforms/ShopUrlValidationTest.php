<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\User\User;
use App\Services\Http\SafeUrlFetcher;
use App\Services\Shop\ShopContentWriter;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// WS-B1: the add-brand and add-product endpoints return DISTINCT, coded 422s
// the dashboard can key UI off:
//
//   POST /api/platforms/shop/products → `store_homepage` (+ storeUrl) | `no_product_found`
//   POST /api/platforms/shop/brands   → `unsupported_store` | `store_unreachable`
//
// Only SafeUrlFetcher is mocked (per-path canned responses, incl. fixtures
// saved from the live repro sites) — the real detector + scraper stack runs
// end-to-end, no network.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Task 7: brandProducts() now reads content.storefronts (ShopContentReader)
    // with a fallback to the legacy site.shop_brands map — attach the
    // stand-in schema so the content.* half of that read doesn't 500 on
    // SQLite's real absence of the table.
    setupContentTables();
});

function shopValidationUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'first_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

/** Live-site fixture saved under tests/fixtures/shop (WS-B1). */
function shopValidationFixture(string $name): string
{
    return (string) file_get_contents(base_path('tests/fixtures/shop/'.$name));
}

/**
 * Container-mock SafeUrlFetcher with canned per-path responses. Routes match
 * by str_contains in insertion order — put specific paths before catch-alls.
 * Values: [status, body]. Anything unmatched 404s.
 */
function fakeShopFetcher(array $routes): void
{
    $mock = Mockery::mock(SafeUrlFetcher::class);
    $mock->shouldReceive('tryFetch')->andReturnUsing(function (string $url) use ($routes) {
        foreach ($routes as $needle => [$status, $body]) {
            if (str_contains($url, $needle)) {
                return ['status' => $status, 'body' => $body, 'finalUrl' => $url, 'contentType' => 'text/html'];
            }
        }

        return ['status' => 404, 'body' => '', 'finalUrl' => $url, 'contentType' => ''];
    });
    app()->instance(SafeUrlFetcher::class, $mock);
}

// ── POST /platforms/shop/products (WS-B1.1) ───────────────────────────────────

it('returns the distinct store_homepage code when a brand homepage is pasted as a product', function () {
    // Live repro: abovetheground.co (Shopify brand homepage, og:type=website,
    // no Product markup) was accepted as a "product". Now → 422 + code +
    // the origin so the dashboard can offer "connect as a brand" prefilled.
    fakeShopFetcher([
        'abovetheground.co' => [200, shopValidationFixture('abovetheground-homepage.html')],
    ]);

    actingAsUser(shopValidationUser('b11home'))
        ->postJson('/api/platforms/shop/products', ['url' => 'https://abovetheground.co'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'store_homepage')
        ->assertJsonPath('storeUrl', 'https://abovetheground.co')
        ->assertJsonStructure(['code', 'storeUrl', 'message']);
});

it('returns no_product_found for a page with no product markup', function () {
    fakeShopFetcher([
        'brand.example' => [200, '<html><head><meta property="og:type" content="website"><meta property="og:title" content="About us"></head><body></body></html>'],
    ]);

    actingAsUser(shopValidationUser('b11none'))
        ->postJson('/api/platforms/shop/products', ['url' => 'https://brand.example/about'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'no_product_found');
});

it('still adds a real product page (OpenGraph product markup)', function () {
    fakeShopFetcher([
        '/product/bulwark-jacket' => [200, '<html><head><meta property="og:type" content="product">'
            .'<meta property="og:title" content="Bulwark Jacket">'
            .'<meta property="product:price:amount" content="280.00">'
            .'<meta property="product:price:currency" content="AUD"></head></html>'],
    ]);

    actingAsUser(shopValidationUser('b11ok'))
        ->postJson('/api/platforms/shop/products', ['url' => 'https://fearnoevil.com.au/product/bulwark-jacket'])
        ->assertOk()
        ->assertJsonPath('products.0.title', 'Bulwark Jacket')
        ->assertJsonPath('products.0.price', '280.00');
});

// ── POST /platforms/shop/brands (WS-B1.2) ─────────────────────────────────────

it('returns the distinct unsupported_store code for a reachable site that is not a store platform', function () {
    // Every platform probe misses; the page itself serves fine but carries no
    // storefront tech and no product markup → genuinely unsupported.
    fakeShopFetcher([
        'portfolio.example' => [200, '<html><head><title>My Portfolio</title></head><body><p>Hi, I paint.</p></body></html>'],
    ]);

    actingAsUser(shopValidationUser('b12unsup'))
        ->postJson('/api/platforms/shop/brands', ['url' => 'https://portfolio.example'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'unsupported_store');
});

it('returns store_unreachable when every request is blocked', function () {
    fakeShopFetcher([
        'blocked.example' => [403, '<html><title>403 - Forbidden</title></html>'],
    ]);

    actingAsUser(shopValidationUser('b12block'))
        ->postJson('/api/platforms/shop/brands', ['url' => 'https://blocked.example'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'store_unreachable');
});

it('reports a marker-bearing WooCommerce site with a blocked Store API as unreachable, NOT unsupported', function () {
    // The platform exists (WooCommerce markers on the homepage — live
    // fearnoevil fixture) but its API is blocked → the honest signal is
    // "couldn't get in" (the dashboard's client-assisted retry still applies),
    // never "unsupported store type".
    fakeShopFetcher([
        '/wp-json/wc/store/v1/products' => [403, ''],
        'rest_route' => [403, ''],
        '/meta.json' => [403, ''],
        'format=json' => [403, ''],
        '/wp-json' => [403, ''],
        'fearnoevil.com.au' => [200, shopValidationFixture('fearnoevil-homepage-head.html')],
    ]);

    actingAsUser(shopValidationUser('b12waf'))
        ->postJson('/api/platforms/shop/brands', ['url' => 'https://fearnoevil.com.au'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'store_unreachable');
});

it('returns the coded store_catalog_blocked 422 when a connected store 429s its catalog (GET .../products)', function () {
    // Live repro: Culture Kings — a connected Shopify brand whose upstream WAF
    // answers /products.json with 429. ShopifyScraper abort(502)s, which used
    // to surface as a raw 502 in the picker; the dashboard needs the same
    // coded-422 contract as the add flows to render it inline.
    $user = shopValidationUser('b14blocked');
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shopify.store', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $brand = ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'blockedstore-example', 'provider' => 'shopify',
        'url' => 'https://blockedstore.example', 'discount_code' => '', 'position' => 0,
    ]);
    // Task 8: brandProducts() now reads ShopContentReader with no legacy
    // fallback (hybridBrandMap() is gone) — land this fixture in content.*
    // the same way a real addBrand() connect would.
    app(ShopContentWriter::class)->upsertStore($brand, (string) $user->id);

    fakeShopFetcher([
        '/products.json' => [429, 'Too Many Requests'],
    ]);

    actingAsUser($user)
        ->getJson('/api/platforms/shop/brands/blockedstore-example/products')
        ->assertStatus(422)
        ->assertJsonPath('code', 'store_catalog_blocked')
        ->assertJsonStructure(['code', 'message']);
});

it('connects a WooCommerce store end-to-end off the real bluelane.co payloads', function () {
    // WS-B1.3 store-level import proof at the API layer: real Store API JSON +
    // homepage + WP-root name captured from the live site.
    fakeShopFetcher([
        '/wp-json/wc/store/v1/products' => [200, shopValidationFixture('bluelane-store-api.json')],
        '/meta.json' => [404, ''],
        'format=json' => [404, ''],
        '/wp-json' => [200, '{"name":"Blue Lane"}'],
        'bluelane.co' => [200, shopValidationFixture('bluelane-homepage-head.html')],
    ]);

    $user = shopValidationUser('b13woo');

    actingAsUser($user)
        ->postJson('/api/platforms/shop/brands', ['url' => 'https://bluelane.co'])
        ->assertOk()
        ->assertJsonPath('provider', 'woocommerce')
        ->assertJsonPath('name', 'Blue Lane')
        ->assertJsonPath('id', 'bluelane-co');

    // The picker catalog then lists the store's real products.
    actingAsUser($user)
        ->getJson('/api/platforms/shop/brands/bluelane-co/products')
        ->assertOk()
        ->assertJsonPath('products.0.productId', '3539')
        ->assertJsonPath('products.0.title', 'Pink Lobster Swim Short')
        ->assertJsonPath('products.0.price', '100.00')
        ->assertJsonPath('products.0.currency', 'AUD');
});

// ── SSRF boundary on these endpoints (TEST-30) ────────────────────────────────
//
// Everything above mocks SafeUrlFetcher away, so none of it can show that the
// SSRF boundary is actually WIRED INTO the shop endpoints. These three use the
// REAL fetcher. They deliberately do NOT call fakeShopFetcher().
//
// Http::assertNothingSent() is the real assertion; the 422 is scaffolding.
// Under a bare Http::fake() every unmatched request answers an empty 200, so a
// shop URL that got past the guard still finds no storefront markers and still
// ends in a 422 — measured: https://example.com/ passes assertSafe(), fires 8
// requests, and returns 422 `unsupported_store`. Against a real unreachable
// internal host it would return 422 `store_unreachable`, i.e. exactly what
// these tests assert. Only "zero requests left the process" distinguishes
// "assertSafe() refused it" from "the guard broke and the probe simply found
// nothing". Do not simplify these to a status check.
//
// All three inputs are DNS-free by construction, which is why they work where
// the audit's suggested RFC 2606 domains would not: assertSafe() does a real
// gethostbynamel()/dns_get_record() that Http::fake() cannot intercept, and
// .test/.invalid/*.example do not resolve. Literal IPs skip resolveHost()
// entirely (filter_var FILTER_VALIDATE_IP short-circuits), and the
// denied-host-suffix check runs before resolution.

it('refuses a loopback store URL at the SSRF boundary, before any HTTP request is made', function () {
    Http::fake();

    actingAsUser(shopValidationUser('ssrfloop'))
        ->postJson('/api/platforms/shop/brands', ['url' => 'http://127.0.0.1/'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'store_unreachable')
        ->assertJsonStructure(['code', 'message']);

    Http::assertNothingSent();
});

it('refuses the cloud-metadata endpoint as a store URL', function () {
    // The SSRF payload that actually matters on Laravel Cloud: 169.254.169.254
    // is instance credentials. Blocked by FILTER_FLAG_NO_RES_RANGE.
    Http::fake();

    actingAsUser(shopValidationUser('ssrfmeta'))
        ->postJson('/api/platforms/shop/brands', ['url' => 'http://169.254.169.254/latest/meta-data/'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'store_unreachable');

    Http::assertNothingSent();
});

it('refuses an own-infrastructure host as a store URL', function () {
    // Exercises SafeUrlFetcher::deniedHostSuffixes() — the one guard that exists
    // precisely because our own API/storage/DB hosts resolve to PUBLIC IPs, so
    // the address-range checks can never catch them.
    Http::fake();

    actingAsUser(shopValidationUser('ssrfown'))
        ->postJson('/api/platforms/shop/brands', ['url' => 'https://api.partna.au/'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'store_unreachable');

    Http::assertNothingSent();
});
