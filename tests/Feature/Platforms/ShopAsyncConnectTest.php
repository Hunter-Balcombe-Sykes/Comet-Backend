<?php

use App\Jobs\Platforms\ProcessShopBrandLogoJob;
use App\Jobs\Platforms\ShopBrandConnectJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\Site\ShopProduct;
use App\Models\Core\User\User;
use App\Services\Accounts\AccountCapabilities;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\BigCartelScraper;
use App\Services\Platforms\GenericShopScraper;
use App\Services\Platforms\ShopifyScraper;
use App\Services\Platforms\SquarespaceScraper;
use App\Services\Platforms\WooCommerceScraper;
use App\Services\PublicSite\SitepageDataResolverService;
use App\Services\Shop\ShopContentWriter;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

// W9 §4 Unit 4 — the deferred addBrand() branch, the connectStatus() poll
// action, and the two conditional resource keys. Test IDs (T-n) below map 1:1
// to `.superpowers/sdd/PLAN-2026-07-24-connect-shop.md` §5's test plan table.
//
// Every deferred-branch test calls Bus::fake() BEFORE the mutating POST —
// QUEUE_CONNECTION=sync in tests and this suite runs with no surrounding DB
// transaction, so ShopBrandConnectJob::dispatch()->afterCommit() would
// otherwise execute for real, synchronously, inline in the HTTP request
// (transactionLevel() === 0 at dispatch time — the whole point of firing
// AFTER the lock releases). Bus::fake() is what actually keeps a row pending
// long enough to observe.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Task 7: brands()/connectStatus() now read content.storefronts
    // (ShopContentReader), even for a brand that has never synced (they hit
    // the table with zero matching rows, not a missing table) — attach the
    // stand-in schema so those queries don't 500 on SQLite's real absence.
    setupContentTables();
});

function shopAsyncUser(string $h): User
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

/** shopAsyncUser() + a site.sites row, so the public profile endpoint resolves. */
function shopAsyncUserWithSite(string $h): User
{
    $user = shopAsyncUser($h);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subdomain' => $h,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

/**
 * Stub ShopifyScraper::currencyFrom() with the SAME expression the real
 * method uses (mirrors this file's brandIdFrom stubs) — needed on every
 * ShopifyScraper double that reaches ShopBrandProfiler::syncCurrencyFor() on
 * the deferred branch, else the strict Mockery double throws.
 */
function stubShopifyCurrencyFrom($m): void
{
    $m->shouldReceive('currencyFrom')->andReturnUsing(function ($meta) {
        $currency = data_get($meta, 'currency');

        return is_string($currency) && trim($currency) !== '' ? strtoupper(trim($currency)) : null;
    });
}

// ── T1 — dark-merge proof ────────────────────────────────────────────────────

it('T1: with the deferred flag empty, addBrand returns the pre-change 200 shape and dispatches nothing', function () {
    config(['partna.connect.deferred' => []]);
    $user = shopAsyncUser('t1dark');

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probeMeta')->andReturn(['id' => 't1-brand', 'name' => 'T1 Store']);
        $m->shouldReceive('brandIdFrom')->andReturnUsing(fn ($meta, $origin) => (string) ($meta['id'] ?? $origin));
        $m->shouldReceive('fetchBrand')->andReturn([
            'id' => 't1-brand', 'name' => 'T1 Store', 'currency' => 'AUD',
            'favicon' => 'https://t1.example.com/favicon.ico', 'logo' => 'https://t1.example.com/logo.png',
        ]);
    });

    // shopAsyncUser() has no site row, so IntegrationConnectionCacheRefresher's
    // purge no-ops (no subdomain to resolve) — this makes the "dispatches
    // nothing" assertion meaningful rather than tripping on the pre-existing
    // CloudflareCachePurgeJob dispatch every connect already fires.
    Bus::fake();

    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://t1.example.com'])
        ->assertOk()
        ->assertExactJson([
            'id' => 't1-brand',
            'provider' => 'shopify',
            'url' => 'https://t1.example.com',
            'name' => 'T1 Store',
            'currency' => 'AUD',
            'favicon' => 'https://t1.example.com/favicon.ico',
            'logo' => 'https://t1.example.com/logo.png',
            'discountCode' => '',
            'selectionMode' => 'manual',
            // Task 8: addBrand()'s response is now built from ShopContentReader
            // (matching GET /brands, which Task 7 already repointed) instead of
            // the legacy ShopBrand::toBrandArray() — linkMode is derived from
            // site.sites.shop_link_mode, and shopAsyncUser() has no site row,
            // so it falls to Site::DEFAULT_SHOP_LINK_MODE ('checkout') rather
            // than ShopBrand's own per-brand column default ('product'). Same
            // documented divergence ShopEndpointParityTest's GET /brands test
            // already accepted for brand-b. A real dashboard user always has a
            // site by the time they can connect a shop, so this never surfaces
            // in production.
            'linkMode' => 'checkout',
            'referralQuery' => '',
            'individual' => false,
            'products' => [],
        ]);

    // The synchronous settle legitimately kicks the best-effort logo mark
    // processing; the point of this assertion is that no async CONNECT job
    // (ShopBrandConnectJob) fired on the non-deferred path.
    Bus::assertNotDispatched(ShopBrandConnectJob::class);
    Bus::assertDispatched(ProcessShopBrandLogoJob::class);
});

// ── T25 — non-deferrable providers stay synchronous 200s even when the flag is on ──

it('T25: bigcartel still returns 200 with a complete brand when shop is deferred, and dispatches nothing', function () {
    config(['partna.connect.deferred' => ['shop']]);
    $user = shopAsyncUser('t25bc');

    $this->mock(ShopifyScraper::class, fn ($m) => $m->shouldReceive('originOf')->andReturn('https://acct.bigcartel.com'));
    $this->mock(BigCartelScraper::class, function ($m) {
        $m->shouldReceive('accountFromUrl')->andReturn('acct');
        $m->shouldReceive('fetchStore')->andReturn([
            'id' => 'bigcartel-acct', 'name' => 'Acct Store', 'currency' => 'EUR',
            'favicon' => null, 'logo' => null, 'origin' => 'https://acct.bigcartel.com',
        ]);
    });

    Bus::fake();

    $res = actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://acct.bigcartel.com/products'])
        ->assertOk()
        ->assertJsonPath('provider', 'bigcartel')
        ->assertJsonPath('name', 'Acct Store');

    expect($res->json())->not->toHaveKey('status');
    expect($res->json())->not->toHaveKey('statusUrl');
    Bus::assertNotDispatched(ShopBrandConnectJob::class);
});

it('T25: generic still returns 200 with a complete brand when shop is deferred, and dispatches nothing', function () {
    config(['partna.connect.deferred' => ['shop']]);
    $user = shopAsyncUser('t25gen');

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => 'https://shop.example');
        $m->shouldReceive('probeMeta')->andReturn(null);
    });
    $this->mock(WooCommerceScraper::class, fn ($m) => $m->shouldReceive('probe')->andReturn(false));
    $this->mock(GenericShopScraper::class, function ($m) {
        $m->shouldReceive('fetchPageDetailed')->andReturn([
            'page' => [
                'brand' => ['id' => 'shop-example', 'name' => 'Example Ceramics', 'currency' => 'AUD', 'favicon' => null, 'logo' => null],
                'products' => [
                    ['productId' => 'MUG-1', 'title' => 'Ceramic Mug', 'handle' => 'MUG-1', 'vendor' => null, 'image' => null, 'price' => '29.00', 'currency' => 'AUD', 'variantId' => 'MUG-1', 'available' => true, 'url' => 'https://shop.example/products/mug'],
                ],
            ],
            'reachable' => true,
            'storefrontMarkers' => true,
        ]);
    });

    Bus::fake();

    $res = actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://shop.example/store'])
        ->assertOk()
        ->assertJsonPath('provider', 'generic')
        ->assertJsonPath('name', 'Example Ceramics');

    expect($res->json())->not->toHaveKey('status');
    Bus::assertNotDispatched(ShopBrandConnectJob::class);
});

it('T25: client-assisted woocommerce still returns 200 with a complete brand when shop is deferred, and dispatches nothing', function () {
    config(['partna.connect.deferred' => ['shop']]);
    // Every server-side probe blocked — the WAF case detectFromClientPayload() exists for.
    Http::fake(['*' => Http::response('Forbidden', 403)]);
    $user = shopAsyncUser('t25client');

    Bus::fake();

    $res = actingAsUser($user)->postJson('/api/platforms/shop/brands', [
        'url' => 'https://client25.example.com',
        'storeApi' => [
            'root' => ['name' => 'Client Store', 'url' => 'https://client25.example.com'],
            'products' => [
                [
                    'id' => 1, 'name' => 'Widget', 'slug' => 'widget',
                    'permalink' => 'https://client25.example.com/product/widget/',
                    'images' => [], 'prices' => ['price' => '1000', 'currency_code' => 'AUD', 'currency_minor_unit' => 2],
                    'is_in_stock' => true,
                ],
            ],
        ],
    ])->assertOk()
        ->assertJsonPath('provider', 'woocommerce')
        ->assertJsonPath('name', 'Client Store');

    expect($res->json())->not->toHaveKey('status');
    Bus::assertNotDispatched(ShopBrandConnectJob::class);
});

// ── T2/T3 — content-proxy: NOT NULL columns + CHECK vocabulary ──────────────

it('T2/T3: the pending row satisfies every NOT NULL column with correct values, and every CHECK vocabulary', function () {
    config(['partna.connect.deferred' => ['shop']]);
    $user = shopAsyncUser('t2t3');

    // Deliberately NO fetchBrand stub — a strict Mockery double throws if
    // addBrand() ever calls the profiler on the deferred branch, which
    // doubles as a live regression guard for "only call the profiler on the
    // synchronous branch".
    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probeMeta')->andReturn(['id' => 't2-brand', 'name' => null]);
        $m->shouldReceive('brandIdFrom')->andReturnUsing(fn ($meta, $origin) => (string) ($meta['id'] ?? $origin));
        stubShopifyCurrencyFrom($m);
    });

    Bus::fake();

    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://t2.example.com'])
        ->assertStatus(202);

    $brand = ShopBrand::where('brand_id', 't2-brand')->firstOrFail();

    // NOT NULL columns, with the right values (content-proxy — SQLite enforces
    // no NOT NULL/CHECK here; see CheckConstraintsTest for the Postgres real thing).
    expect($brand->brand_id)->toBe('t2-brand');
    expect($brand->provider)->toBe('shopify');
    expect($brand->is_individual)->toBeFalse();
    expect($brand->position)->toBe(0);
    expect($brand->selection_mode)->toBe('manual');
    expect($brand->link_mode)->toBe('product');
    expect($brand->referral_query)->toBe('');

    // CHECK vocabularies.
    expect(in_array($brand->selection_mode, ['manual', 'latest'], true))->toBeTrue();
    expect(in_array($brand->link_mode, ['product', 'checkout'], true))->toBeTrue();
    expect(in_array($brand->connect_status, ShopBrand::CONNECT_STATUSES, true))->toBeTrue();
    expect($brand->connect_status)->toBe('pending');
    expect($brand->connect_error)->toBeNull();
});

// ── T4 — GET /brands/{id}/products succeeds during the pending window ───────

it('T4: GET /brands/{id}/products succeeds during the pending window — shopify', function () {
    config(['partna.connect.deferred' => ['shop']]);
    $user = shopAsyncUser('t4shopify');

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probeMeta')->andReturn(['id' => 't4-shopify', 'name' => null]);
        $m->shouldReceive('brandIdFrom')->andReturnUsing(fn ($meta, $origin) => (string) ($meta['id'] ?? $origin));
        stubShopifyCurrencyFrom($m);
        $m->shouldReceive('fetchProducts')->andReturn([
            ['productId' => 'p1', 'title' => 'One', 'url' => 'https://t4shopify.example.com/p1'],
        ]);
    });

    Bus::fake();

    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://t4shopify.example.com'])
        ->assertStatus(202);

    actingAsUser($user)->getJson('/api/platforms/shop/brands/t4-shopify/products')
        ->assertOk()
        ->assertJsonPath('products.0.productId', 'p1');
});

it('T4: GET /brands/{id}/products succeeds during the pending window — woocommerce', function () {
    config(['partna.connect.deferred' => ['shop']]);
    $user = shopAsyncUser('t4woo');

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probeMeta')->andReturn(null);
    });
    $this->mock(WooCommerceScraper::class, function ($m) {
        $m->shouldReceive('probe')->andReturn(true);
        $m->shouldReceive('brandIdFor')->andReturn('t4-woo');
        $m->shouldReceive('fetchProducts')->andReturn([
            ['productId' => 'p1', 'title' => 'One', 'url' => 'https://t4woo.example.com/p1'],
        ]);
    });

    Bus::fake();

    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://t4woo.example.com'])
        ->assertStatus(202);

    actingAsUser($user)->getJson('/api/platforms/shop/brands/t4-woo/products')
        ->assertOk()
        ->assertJsonPath('products.0.productId', 'p1');
});

it('T4: GET /brands/{id}/products succeeds during the pending window — squarespace', function () {
    config(['partna.connect.deferred' => ['shop']]);
    $user = shopAsyncUser('t4sq');

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probeMeta')->andReturn(null);
    });
    $this->mock(WooCommerceScraper::class, fn ($m) => $m->shouldReceive('probe')->andReturn(false));
    $this->mock(SquarespaceScraper::class, function ($m) {
        $m->shouldReceive('discoverProductsUrl')->andReturn('https://t4sq.example.com/shop');
        $m->shouldReceive('originOf')->andReturn('https://t4sq.example.com');
        $m->shouldReceive('idFromOrigin')->andReturn('t4-sq');
        $m->shouldReceive('fetchProducts')->andReturn([
            ['productId' => 'p1', 'title' => 'One', 'url' => 'https://t4sq.example.com/p1'],
        ]);
    });

    Bus::fake();

    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://t4sq.example.com'])
        ->assertStatus(202);

    actingAsUser($user)->getJson('/api/platforms/shop/brands/t4-sq/products')
        ->assertOk()
        ->assertJsonPath('products.0.productId', 'p1');
});

// ── Currency fix (post-review) — Shopify's currency IS truthfully derivable
// at 202 time from the carried meta.json (ShopifyScraper::currencyFrom()),
// and ShopCatalog::providerProducts() passes it as the per-product fallback,
// so omitting it (like name/favicon/logo) would degrade the picker during
// the pending window for exactly the provider path (c) exists to keep fast. ─

it('a deferred Shopify connect writes a truthful currency on the pending row', function () {
    config(['partna.connect.deferred' => ['shop']]);
    $user = shopAsyncUser('currshopify');

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        // Lowercase on the wire — proves the real uppercasing expression ran,
        // not a passthrough.
        $m->shouldReceive('probeMeta')->andReturn(['id' => 'curr-shopify', 'name' => null, 'currency' => 'aud']);
        $m->shouldReceive('brandIdFrom')->andReturnUsing(fn ($meta, $origin) => (string) ($meta['id'] ?? $origin));
        stubShopifyCurrencyFrom($m);
    });

    Bus::fake();

    $res = actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://currshopify.example.com'])
        ->assertStatus(202);

    expect($res->json('currency'))->toBe('AUD');
    expect(ShopBrand::where('brand_id', 'curr-shopify')->firstOrFail()->currency)->toBe('AUD');
});

it('a deferred Squarespace connect leaves currency untouched, and re-adding a settled Squarespace brand does not blank its existing currency', function () {
    $user = shopAsyncUser('currsq');

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probeMeta')->andReturn(null);
    });
    $this->mock(WooCommerceScraper::class, fn ($m) => $m->shouldReceive('probe')->andReturn(false));
    $this->mock(SquarespaceScraper::class, function ($m) {
        $m->shouldReceive('discoverProductsUrl')->andReturn('https://currsq.example.com/shop');
        $m->shouldReceive('originOf')->andReturn('https://currsq.example.com');
        $m->shouldReceive('idFromOrigin')->andReturn('curr-sq');
        $m->shouldReceive('fetchBrand')->andReturn([
            'id' => 'curr-sq', 'name' => 'Curr SQ Store', 'currency' => 'USD', 'favicon' => null, 'logo' => null,
        ]);
    });

    // Settle it fully first — flag off, today's synchronous behaviour.
    config(['partna.connect.deferred' => []]);
    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://currsq.example.com'])
        ->assertOk()
        ->assertJsonPath('currency', 'USD');

    Bus::fake();

    // Now defer shop and re-POST the SAME url — a Squarespace connect always
    // takes the deferred branch when shop is named (no clientBrand, provider
    // is in DEFERRABLE_PROVIDERS), and ShopBrandProfiler::syncCurrencyFor()
    // returns null for every non-Shopify provider, so `currency` is omitted
    // from THIS write entirely — proving the omit-don't-null discipline still
    // protects Squarespace's already-settled currency.
    config(['partna.connect.deferred' => ['shop']]);
    $res = actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://currsq.example.com'])
        ->assertStatus(202);

    expect($res->json('currency'))->toBe('USD');
    expect(ShopBrand::where('brand_id', 'curr-sq')->firstOrFail()->currency)->toBe('USD');
});

it('GET /brands/{id}/products during the pending window returns Shopify products with a non-null currency fallback (real scraper, no doubles)', function () {
    config(['partna.connect.deferred' => ['shop']]);
    $user = shopAsyncUser('currprod');

    // No ShopifyScraper mock at all here — this exercises the REAL scraper
    // (Http::fake() only), to prove the user-visible wire outcome end-to-end:
    // a picker GET during the pending window must not show a blank currency.
    // example.com (not a made-up subdomain) — SafeUrlFetcher::assertSafe()
    // does a real gethostbynamel() DNS resolution before Http::fake() ever
    // sees the request, and only the bare reserved domain is guaranteed to
    // resolve (mirrors ShopBrandIdentityTest's own real-HTTP shopify cases).
    Http::fake([
        'https://example.com/meta.json' => Http::response(json_encode([
            'id' => 'curr-prod', 'name' => 'Curr Prod Store', 'currency' => 'aud',
        ]), 200),
        'https://example.com/products.json*' => Http::response(json_encode([
            'products' => [[
                'id' => 900, 'title' => 'No-Price-Currency Tee', 'handle' => 'tee', 'images' => [],
                // Deliberately no presentment_prices on the variant — the
                // exact shape ShopifyScraper::fetchProducts():189 falls back
                // to $defaultCurrency for.
                'variants' => [['id' => 9001, 'price' => '25.00', 'available' => true]],
            ]],
        ]), 200),
        'https://example.com/' => Http::response('<html></html>', 200),
    ]);

    Bus::fake();

    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://example.com'])
        ->assertStatus(202)
        ->assertJsonPath('currency', 'AUD');

    actingAsUser($user)->getJson('/api/platforms/shop/brands/curr-prod/products')
        ->assertOk()
        ->assertJsonPath('products.0.currency', 'AUD');
});

// ── T5 — pending → job → poll reports ready with the full brand ─────────────

it('T5: a pending brand settles to ready after the job runs, and the poll reports the full brand', function () {
    config(['partna.connect.deferred' => ['shop']]);
    $user = shopAsyncUser('t5ready');

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probeMeta')->andReturn(['id' => 't5-brand', 'name' => null]);
        $m->shouldReceive('brandIdFrom')->andReturnUsing(fn ($meta, $origin) => (string) ($meta['id'] ?? $origin));
        stubShopifyCurrencyFrom($m);
        $m->shouldReceive('fetchBrand')->andReturn([
            'id' => 't5-brand', 'name' => 'T5 Store', 'currency' => 'AUD',
            'favicon' => 'https://t5.example.com/favicon.ico', 'logo' => 'https://t5.example.com/logo.png',
        ]);
    });

    Bus::fake();

    $res = actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://t5.example.com'])
        ->assertStatus(202);
    expect($res->json('status'))->toBe('pending');

    $poll = actingAsUser($user)->getJson('/api/platforms/shop/brands/t5-brand/connect/status')->assertOk();
    expect($poll->json('status'))->toBe('pending');

    $brand = ShopBrand::where('brand_id', 't5-brand')->firstOrFail();

    // Run the job directly — mirrors ShopBrandConnectJobTest's own established
    // idiom (see its header comment): never rely on the sync queue driver to
    // prove queued behaviour, since afterCommit() dispatches fire immediately
    // outside a wrapping transaction, which this suite deliberately avoided
    // above via Bus::fake().
    app()->call([new ShopBrandConnectJob($brand->id), 'handle']);

    actingAsUser($user)->getJson('/api/platforms/shop/brands/t5-brand/connect/status')
        ->assertOk()
        ->assertExactJson([
            'status' => 'ready',
            'id' => 't5-brand',
            'brand' => [
                'id' => 't5-brand',
                'provider' => 'shopify',
                'url' => 'https://t5.example.com',
                'name' => 'T5 Store',
                'currency' => 'AUD',
                'favicon' => 'https://t5.example.com/favicon.ico',
                'logo' => 'https://t5.example.com/logo.png',
                'discountCode' => '',
                'selectionMode' => 'manual',
                // Task 8: ShopBrandConnectJob's success write now also
                // upserts content.storefronts, so connectStatus()'s
                // brandPayload() finds a content.* row here (no legacy
                // fallback needed) — same site-default divergence as T1
                // above ('checkout' vs the legacy per-brand 'product'
                // default), shopAsyncUser() has no site row.
                'linkMode' => 'checkout',
                'referralQuery' => '',
                'individual' => false,
                'products' => [],
            ],
        ]);
});

// ── T7 — duplicate paste updates the one row ─────────────────────────────────

it('T7: pasting the same store URL twice while deferred updates the ONE row, not a second', function () {
    config(['partna.connect.deferred' => ['shop']]);
    $user = shopAsyncUser('t7dup');

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probeMeta')->andReturn(['id' => 't7-brand', 'name' => null]);
        $m->shouldReceive('brandIdFrom')->andReturnUsing(fn ($meta, $origin) => (string) ($meta['id'] ?? $origin));
        stubShopifyCurrencyFrom($m);
    });

    Bus::fake();

    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://t7.example.com'])->assertStatus(202);
    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://t7.example.com'])->assertStatus(202);

    $conn = IntegrationConnection::where('user_id', $user->id)->where('platform', 'shop')->firstOrFail();
    expect(ShopBrand::where('connection_id', $conn->id)->count())->toBe(1);
});

// ── T10 — the 5-brand cap does not move ──────────────────────────────────────

it('T10: the 6th store still 422s synchronously, and dispatches nothing new', function () {
    config(['partna.connect.deferred' => ['shop']]);
    $user = shopAsyncUser('t10cap');

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probeMeta')->andReturnUsing(fn ($origin) => ['id' => md5($origin), 'name' => null]);
        $m->shouldReceive('brandIdFrom')->andReturnUsing(fn ($meta, $origin) => (string) ($meta['id'] ?? $origin));
        stubShopifyCurrencyFrom($m);
    });

    Bus::fake();

    foreach (['a', 'b', 'c', 'd', 'e'] as $s) {
        actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => "https://{$s}.t10cap.example.com"])
            ->assertStatus(202);
    }
    Bus::assertDispatchedTimes(ShopBrandConnectJob::class, 5);

    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://f.t10cap.example.com'])
        ->assertStatus(422)
        ->assertJsonPath('message', 'You can connect up to 5 stores.');

    Bus::assertDispatchedTimes(ShopBrandConnectJob::class, 5);
});

// ── T11 — re-adding a settled brand while deferred is non-destructive ───────

it('T11: re-adding an already-settled brand while deferred does not blank its profile, and preserves position/discount/selectionMode/products', function () {
    $user = shopAsyncUser('t11readd');

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probeMeta')->andReturn(['id' => 't11-brand', 'name' => 'T11 Store']);
        $m->shouldReceive('brandIdFrom')->andReturnUsing(fn ($meta, $origin) => (string) ($meta['id'] ?? $origin));
        stubShopifyCurrencyFrom($m);
        $m->shouldReceive('fetchBrand')->andReturn([
            'id' => 't11-brand', 'name' => 'T11 Store', 'currency' => 'AUD',
            'favicon' => 'https://t11.example.com/favicon.ico', 'logo' => 'https://t11.example.com/logo.png',
        ]);
    });

    // Settle it fully first — flag off, today's synchronous behaviour.
    config(['partna.connect.deferred' => []]);
    actingAsUser($user)->postJson('/api/platforms/shop/brands', [
        'url' => 'https://t11.example.com', 'discountCode' => 'SAVE15',
    ])->assertOk();

    $conn = IntegrationConnection::where('user_id', $user->id)->where('platform', 'shop')->firstOrFail();
    $brand = ShopBrand::where('connection_id', $conn->id)->where('brand_id', 't11-brand')->firstOrFail();
    $brand->update(['selection_mode' => 'latest']);
    ShopProduct::create(['brand_id' => $brand->id, 'product_id' => 'p1', 'position' => 0, 'data' => ['productId' => 'p1', 'title' => 'Existing']]);

    Bus::fake();

    // Now defer shop and re-POST the SAME url.
    config(['partna.connect.deferred' => ['shop']]);
    $res = actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://t11.example.com'])
        ->assertStatus(202);

    expect($res->json('name'))->toBe('T11 Store');
    expect($res->json('currency'))->toBe('AUD');
    expect($res->json('favicon'))->toBe('https://t11.example.com/favicon.ico');
    expect($res->json('logo'))->toBe('https://t11.example.com/logo.png');
    expect($res->json('discountCode'))->toBe('SAVE15');
    expect($res->json('connectStatus'))->toBe('pending');

    $brand->refresh();
    expect($brand->name)->toBe('T11 Store');
    expect($brand->currency)->toBe('AUD');
    expect($brand->favicon)->toBe('https://t11.example.com/favicon.ico');
    expect($brand->logo)->toBe('https://t11.example.com/logo.png');
    expect($brand->position)->toBe(0);
    expect($brand->discount_code)->toBe('SAVE15');
    expect($brand->selection_mode)->toBe('latest');
    expect($brand->connect_status)->toBe('pending');
    expect(ShopProduct::where('brand_id', $brand->id)->count())->toBe(1);
});

// ── T12 — a settled brand's dashboard body stays byte-identical ─────────────

it('T12: a settled brands GET /brands body carries no connectStatus/connectError key', function () {
    config(['partna.connect.deferred' => []]);
    $user = shopAsyncUser('t12settled');

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probeMeta')->andReturn(['id' => 't12-brand', 'name' => 'T12 Store']);
        $m->shouldReceive('brandIdFrom')->andReturnUsing(fn ($meta, $origin) => (string) ($meta['id'] ?? $origin));
        $m->shouldReceive('fetchBrand')->andReturn([
            'id' => 't12-brand', 'name' => 'T12 Store', 'currency' => 'AUD', 'favicon' => null, 'logo' => null,
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://t12.example.com'])->assertOk();

    $body = actingAsUser($user)->getJson('/api/platforms/shop/brands')->assertOk()->json('brands.0');
    expect($body)->not->toHaveKey('connectStatus');
    expect($body)->not->toHaveKey('connectError');
});

// ── T13 — public payload omits pending, includes failed, never leaks connectStatus ──

it('T13: the public payload omits a pending brand, includes a failed one, and never exposes connectStatus', function () {
    $user = shopAsyncUserWithSite('t13pub');
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'pend-brand', 'provider' => 'shopify',
        'url' => 'https://pend.example.com', 'source_url' => 'https://pend.example.com',
        'position' => 0, 'connect_status' => 'pending',
    ]);
    $failed = ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'fail-brand', 'provider' => 'shopify',
        'url' => 'https://fail.example.com', 'source_url' => 'https://fail.example.com',
        'position' => 1, 'connect_status' => 'failed',
        'connect_error' => 'We could not load that account. Please try again.',
    ]);
    ShopProduct::create(['brand_id' => $failed->id, 'product_id' => 'p1', 'position' => 0, 'data' => ['productId' => 'p1', 'title' => 'Still usable', 'url' => 'https://fail.example.com/p1']]);

    $payload = $this->getJson('/api/public/profiles/t13pub/platforms')
        ->assertOk()
        ->json('data.platforms.shop.0.payload');

    expect($payload)->not->toHaveKey('pend-brand');
    expect($payload)->toHaveKey('fail-brand');
    expect($payload['fail-brand'])->not->toHaveKey('connectStatus');
    expect($payload['fail-brand'])->not->toHaveKey('connectError');
});

// ── T14 — presentPageIds() regression guard on the deferred path ────────────

it('T14: presentPageIds still excludes Shop when the only brand is pending (zero products)', function () {
    $pro = createTenant('t14-pending-page');
    $conn = IntegrationConnection::create([
        'user_id' => $pro->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'pending-page-brand', 'provider' => 'shopify',
        'url' => 'https://p.example.com', 'position' => 0, 'connect_status' => 'pending',
    ]);

    $pages = app(SitepageDataResolverService::class)
        ->presentPageIds($pro->site, AccountCapabilities::for($pro), collect());

    expect($pages)->not->toContain('shop');
});

// ── T19 — stale-pending backstop is synthetic ────────────────────────────────

it('T19: a pending row stale for 6 minutes polls failed synthetically, without writing the row', function () {
    $user = shopAsyncUser('t19stale');
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $brand = ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'stale-brand', 'provider' => 'shopify',
        'url' => 'https://stale.example.com', 'position' => 0, 'connect_status' => 'pending',
    ]);
    // Deliberately a query-builder mass update, NOT $brand->save(): Eloquent's
    // save() re-touches updated_at to now(), silently discarding a manually
    // forceFill()'d value (mirrors DefersBespokeConnectTest's own idiom).
    ShopBrand::where('id', $brand->id)->update(['updated_at' => now()->subMinutes(6)]);

    $res = actingAsUser($user)->getJson('/api/platforms/shop/brands/stale-brand/connect/status')
        ->assertOk();

    expect($res->json())->toBe([
        'status' => 'failed',
        'error' => "We couldn't save your connection just then — please try again.",
    ]);

    $brand->refresh();
    expect($brand->connect_status)->toBe('pending'); // never written — synthetic.
    expect($brand->connect_error)->toBeNull();
});

// ── T20 — a failed brand is retained and retry re-uses the same row ─────────

it('T20: a failed brand is retained, still returns products, and re-POSTing its URL retries onto the same row', function () {
    $user = shopAsyncUser('t20retry');
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $brand = ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 't20-brand', 'provider' => 'shopify',
        'url' => 'https://t20.example.com', 'source_url' => 'https://t20.example.com',
        'position' => 0, 'connect_status' => 'failed',
        'connect_error' => 'We could not load that account. Please try again.',
    ]);
    // Task 8: brandProducts() now reads ShopContentReader with no legacy
    // fallback (hybridBrandMap() is gone) — a brand this test builds by hand
    // needs the content.* row a real deferred connect would already have
    // (ShopBrandConnectJob::markTerminal() upserts one on every 'failed'
    // transition; see that job's own docblock).
    app(ShopContentWriter::class)->upsertStore($brand, (string) $user->id);

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probeMeta')->andReturn(['id' => 't20-brand', 'name' => 'T20 Store']);
        $m->shouldReceive('brandIdFrom')->andReturnUsing(fn ($meta, $origin) => (string) ($meta['id'] ?? $origin));
        stubShopifyCurrencyFrom($m);
        $m->shouldReceive('fetchProducts')->andReturn([
            ['productId' => 'p1', 'title' => 'One', 'url' => 'https://t20.example.com/p1'],
        ]);
    });

    // Still usable while failed: the live catalog read works (§3g).
    actingAsUser($user)->getJson('/api/platforms/shop/brands/t20-brand/products')
        ->assertOk()
        ->assertJsonPath('products.0.productId', 'p1');

    Bus::fake();

    config(['partna.connect.deferred' => ['shop']]);
    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://t20.example.com'])
        ->assertStatus(202);

    expect(ShopBrand::where('connection_id', $conn->id)->count())->toBe(1);
    $brand = ShopBrand::where('connection_id', $conn->id)->where('brand_id', 't20-brand')->firstOrFail();
    expect($brand->connect_status)->toBe('pending');
});

// ── P1 review fix — a retry of an ALREADY-pending brand refreshes updated_at ──
//
// Before this fix, re-POSTing a still-pending brand wrote byte-identical
// values via updateOrCreate() (provider/url/source_url/discount/fetch_mode/
// position/currency/connect_status/connect_error all unchanged) — nothing
// dirty, so updated_at never advanced. A worker that died left the row
// 'pending' forever; the user's retry got a fresh 202 + a fresh job, but the
// STALE updated_at meant the very next poll still tripped the 5-minute
// stale-pending backstop and reported 'failed' for a retry that was
// genuinely in flight.

it('P1: a re-POST of an already-pending brand refreshes updated_at, so an immediate poll reports pending not failed', function () {
    config(['partna.connect.deferred' => ['shop']]);
    $user = shopAsyncUser('retrypending');

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probeMeta')->andReturn(['id' => 'retry-brand', 'name' => null]);
        $m->shouldReceive('brandIdFrom')->andReturnUsing(fn ($meta, $origin) => (string) ($meta['id'] ?? $origin));
        stubShopifyCurrencyFrom($m);
    });

    Bus::fake();

    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://retrypending.example.com'])
        ->assertStatus(202);

    $brand = ShopBrand::where('brand_id', 'retry-brand')->firstOrFail();
    // Simulate a dead worker: age the row past the 5-minute stale-pending
    // threshold — a query-builder mass update, not $brand->save() (T19's own
    // idiom), since save() would re-touch updated_at and defeat the setup.
    ShopBrand::where('id', $brand->id)->update(['updated_at' => now()->subMinutes(6)]);

    actingAsUser($user)->getJson('/api/platforms/shop/brands/retry-brand/connect/status')
        ->assertOk()
        ->assertJsonPath('status', 'failed');

    // The retry: the row is still genuinely 'pending' in the DB (only the
    // poll's SYNTHETIC backstop reported 'failed' above — T19 pins that it
    // never writes the row) — this re-POST takes the SAME deferred branch and
    // writes byte-identical values to what's already stored.
    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://retrypending.example.com'])
        ->assertStatus(202);

    $brand->refresh();
    expect($brand->updated_at->gt(now()->subMinute()))->toBeTrue();

    actingAsUser($user)->getJson('/api/platforms/shop/brands/retry-brand/connect/status')
        ->assertOk()
        ->assertJsonPath('status', 'pending');
});

// ── P3 review fix — the response is built INSIDE the lock, not after ───────
//
// Pre-fix, ShopController::addBrand() read $brandRow->fresh('products')
// AFTER withConnectionLock() had already returned — Illuminate\Cache\Lock::
// block() releases the lock in its own `finally`, synchronously, before
// control returns to the caller, so there's no code between "lock released"
// and "response read" to hook a mock into. A concurrent removeBrand()/
// forget() from the SAME user landing in that exact gap could delete the row
// and turn the response build into "Call to a member function toBrandArray()
// on null". The decorator below wraps the real per-user platform lock (the
// SAME key withConnectionLock() acquires) so the instant its block() call
// returns — i.e. the instant the lock has released — it performs a REAL
// delete, reproducing the timing a genuinely concurrent second request would
// have. Every other cache key passes straight through to the real store.

it('P3: a brand deleted the instant the connection lock releases does not crash the addBrand response', function () {
    config(['partna.connect.deferred' => ['shop']]);
    $user = shopAsyncUser('race3');

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probeMeta')->andReturn(['id' => 'race-brand', 'name' => null]);
        $m->shouldReceive('brandIdFrom')->andReturnUsing(fn ($meta, $origin) => (string) ($meta['id'] ?? $origin));
        stubShopifyCurrencyFrom($m);
    });

    Bus::fake();

    $key = CacheKeyGenerator::platformConnectionLock('shop', (string) $user->id);
    $real = Cache::getFacadeRoot();

    Cache::swap(new class($real, $key)
    {
        public function __construct(private $real, private string $target) {}

        public function lock($name, $seconds = 0, $owner = null)
        {
            $realLock = $this->real->lock($name, $seconds, $owner);
            if ($name !== $this->target) {
                return $realLock;
            }

            return new class($realLock)
            {
                public function __construct(private $inner) {}

                public function __call($method, $args)
                {
                    $result = $this->inner->{$method}(...$args);
                    if ($method === 'block') {
                        // Fires the instant the lock has released — see the
                        // header comment above.
                        ShopProduct::whereHas('brand', fn ($q) => $q->where('brand_id', 'race-brand'))->delete();
                        ShopBrand::where('brand_id', 'race-brand')->delete();
                    }

                    return $result;
                }
            };
        }

        public function __call($method, $args)
        {
            return $this->real->{$method}(...$args);
        }
    });

    $res = actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://race3.example.com']);

    $res->assertStatus(202);
    expect($res->json('id'))->toBe('race-brand');
    expect($res->json('status'))->toBe('pending');

    // The delete DID happen (proves the decorator actually fired) — but only
    // AFTER the response above was already fully built and returned.
    expect(ShopBrand::where('brand_id', 'race-brand')->exists())->toBeFalse();
});

// ── T21 — poll 404s, never 403 ───────────────────────────────────────────────

it('T21: poll 404s for an unknown brand id and for another users brand, never 403', function () {
    $owner = shopAsyncUser('t21owner');
    $stranger = shopAsyncUser('t21stranger');
    $conn = IntegrationConnection::create([
        'user_id' => $owner->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    ShopBrand::create(['connection_id' => $conn->id, 'brand_id' => 'owner-brand', 'provider' => 'shopify', 'url' => 'https://o.example', 'position' => 0]);

    actingAsUser($owner)->getJson('/api/platforms/shop/brands/no-such-brand/connect/status')
        ->assertStatus(404)
        ->assertJsonPath('message', 'Brand not found.');

    actingAsUser($stranger)->getJson('/api/platforms/shop/brands/owner-brand/connect/status')
        ->assertStatus(404)
        ->assertJsonPath('message', 'Brand not found.');
});

// ── T22 — the poll route lives under /shop only (alias removed 2026-08-05) ──

it('T22: the poll route answers under /shop and the old /shopify alias 404s', function () {
    $user = shopAsyncUser('t22');
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    ShopBrand::create(['connection_id' => $conn->id, 'brand_id' => 'dual-brand', 'provider' => 'shopify', 'url' => 'https://d.example', 'position' => 0]);

    actingAsUser($user)->getJson('/api/platforms/shop/brands/dual-brand/connect/status')
        ->assertOk()->assertJsonPath('status', 'ready');
    actingAsUser($user)->getJson('/api/platforms/shopify/brands/dual-brand/connect/status')
        ->assertNotFound();
});
