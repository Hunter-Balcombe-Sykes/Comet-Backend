<?php

use App\Jobs\Cloudflare\CloudflareCachePurgeJob;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\Site\ShopProduct;
use App\Models\Core\User\User;
use App\Services\Platforms\GenericShopScraper;
use App\Services\Platforms\ShopifyScraper;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// FOUND-25: the shop brand+product map moved out of the single
// site.platform_connections.payload JSONB cell into site.shop_brands /
// site.shop_products child tables. These tests cover the storage shape itself
// (not just the API responses already covered elsewhere): the connection row's
// payload collapses to a static marker, brand/product rows are the source of
// truth, and lifecycle operations (remove/forget/re-add) don't leave orphans.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function shopStorageUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

/** shopStorageUser() + a site.sites row, so IntegrationConnectionObserver / the
 * shared refresher can resolve a subdomain to purge (mirrors PlatformLoopTest's
 * loopUser()). */
function shopStorageUserWithSite(string $h): User
{
    $user = shopStorageUser($h);
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subdomain' => $h,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $user;
}

it('addBrand + setProducts persist the relational marker and child rows, not a JSONB map', function () {
    $user = shopStorageUser('rel1');

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probe')->andReturn(true);
        $m->shouldReceive('fetchBrand')->andReturn([
            'id' => 'rel-brand', 'name' => 'Rel Store', 'currency' => 'AUD', 'favicon' => null, 'logo' => null,
        ]);
        $m->shouldReceive('fetchProducts')->andReturn([
            ['productId' => 'p1', 'title' => 'One', 'url' => 'https://rel1.example.com/p1'],
            ['productId' => 'p2', 'title' => 'Two', 'url' => 'https://rel1.example.com/p2'],
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://rel1.example.com'])
        ->assertOk();
    actingAsUser($user)->putJson('/api/platforms/shop/brands/rel-brand/selection', ['productIds' => ['p1', 'p2']])
        ->assertOk();

    $conn = IntegrationConnection::where('user_id', $user->id)->where('platform', 'shop')->firstOrFail();
    // The row itself is now just the lifecycle/authorization anchor.
    expect($conn->payload)->toBe(['storage' => 'relational']);

    $brand = ShopBrand::where('connection_id', $conn->id)->where('brand_id', 'rel-brand')->firstOrFail();
    expect($brand->provider)->toBe('shopify');
    expect(ShopProduct::where('brand_id', $brand->id)->count())->toBe(2);
    expect(ShopProduct::where('brand_id', $brand->id)->orderBy('position')->pluck('product_id')->all())
        ->toBe(['p1', 'p2']);
});

it('removeBrand hard-deletes the brand row and its products', function () {
    $user = shopStorageUser('rel2');
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $brand = ShopBrand::create(['connection_id' => $conn->id, 'brand_id' => 'brand-x', 'provider' => 'shopify', 'url' => 'https://x', 'position' => 0]);
    ShopProduct::create(['brand_id' => $brand->id, 'product_id' => 'p1', 'position' => 0, 'data' => ['productId' => 'p1']]);

    actingAsUser($user)->deleteJson('/api/platforms/shop/brands/brand-x')
        ->assertOk()
        ->assertJsonPath('brands', []);

    expect(ShopBrand::where('connection_id', $conn->id)->count())->toBe(0);
    expect(ShopProduct::where('brand_id', $brand->id)->count())->toBe(0);
});

it('forget deletes all shop child rows and soft-deletes the connection', function () {
    $user = shopStorageUser('rel3');
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $brand = ShopBrand::create(['connection_id' => $conn->id, 'brand_id' => 'b1', 'provider' => 'shopify', 'url' => 'https://b1', 'position' => 0]);
    ShopProduct::create(['brand_id' => $brand->id, 'product_id' => 'p1', 'position' => 0, 'data' => ['productId' => 'p1']]);

    actingAsUser($user)->deleteJson('/api/platforms/shop')
        ->assertOk()
        ->assertJsonPath('brands', []);

    expect(ShopBrand::where('connection_id', $conn->id)->count())->toBe(0);
    expect(ShopProduct::where('brand_id', $brand->id)->count())->toBe(0);
    expect(IntegrationConnection::find($conn->id))->toBeNull(); // soft-deleted
    expect(IntegrationConnection::withTrashed()->find($conn->id))->not->toBeNull();
});

it('re-adding a brand after forget creates a fresh row with no orphaned products', function () {
    $user = shopStorageUser('rel4');

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probe')->andReturn(true);
        $m->shouldReceive('fetchBrand')->andReturn([
            'id' => 'again-brand', 'name' => 'Again Store', 'currency' => 'AUD', 'favicon' => null, 'logo' => null,
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://again.example.com'])->assertOk();
    actingAsUser($user)->deleteJson('/api/platforms/shop')->assertOk();

    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://again.example.com'])
        ->assertOk()
        ->assertJsonPath('id', 'again-brand');

    $active = IntegrationConnection::where('user_id', $user->id)->where('platform', 'shop')->firstOrFail();
    $brand = ShopBrand::where('connection_id', $active->id)->where('brand_id', 'again-brand')->firstOrFail();
    expect(ShopProduct::where('brand_id', $brand->id)->count())->toBe(0);
});

it('addProduct dedupes by productId, keeps newest first, and caps at 20', function () {
    $user = shopStorageUser('ind1');

    $this->mock(GenericShopScraper::class, function ($m) {
        foreach (range(1, 21) as $n) {
            $m->shouldReceive('fetchSingleProduct')
                ->with("https://example.com/p{$n}")
                ->andReturn(['productId' => "p{$n}", 'title' => "Product {$n}", 'url' => "https://example.com/p{$n}"]);
        }
    });

    foreach (range(1, 21) as $n) {
        actingAsUser($user)->postJson('/api/platforms/shop/products', ['url' => "https://example.com/p{$n}"])
            ->assertOk();
    }

    $conn = IntegrationConnection::where('user_id', $user->id)->where('platform', 'shop')->firstOrFail();
    $individual = ShopBrand::where('connection_id', $conn->id)->where('brand_id', 'individual')->firstOrFail();
    expect($individual->is_individual)->toBeTrue();
    expect(ShopProduct::where('brand_id', $individual->id)->count())->toBe(20);

    $ids = ShopProduct::where('brand_id', $individual->id)->orderBy('position')->pluck('product_id')->all();
    expect($ids[0])->toBe('p21'); // newest first
    expect($ids)->not->toContain('p1'); // oldest evicted by the 20-cap

    // Re-adding an already-present product moves it to the front without duplicating.
    actingAsUser($user)->postJson('/api/platforms/shop/products', ['url' => 'https://example.com/p10'])
        ->assertOk();
    $idsAfter = ShopProduct::where('brand_id', $individual->id)->orderBy('position')->pluck('product_id')->all();
    expect($idsAfter[0])->toBe('p10');
    expect(array_count_values($idsAfter)['p10'])->toBe(1);
    expect(count($idsAfter))->toBe(20);
});

it('removeProduct drops the individual bucket once it has no products left', function () {
    $user = shopStorageUser('ind2');

    $this->mock(GenericShopScraper::class, fn ($m) => $m->shouldReceive('fetchSingleProduct')
        ->with('https://example.com/only')
        ->andReturn(['productId' => 'only', 'title' => 'Only', 'url' => 'https://example.com/only']));

    actingAsUser($user)->postJson('/api/platforms/shop/products', ['url' => 'https://example.com/only'])->assertOk();

    $conn = IntegrationConnection::where('user_id', $user->id)->where('platform', 'shop')->firstOrFail();
    $individual = ShopBrand::where('connection_id', $conn->id)->where('brand_id', 'individual')->firstOrFail();

    actingAsUser($user)->deleteJson('/api/platforms/shop/products/only')
        ->assertOk()
        ->assertJsonPath('brands', []);

    expect(ShopBrand::where('id', $individual->id)->exists())->toBeFalse();
    expect(ShopProduct::where('brand_id', $individual->id)->count())->toBe(0);
});

it('public platforms endpoint shop payload is value-identical to the pre-relational contract', function () {
    $user = shopStorageUser('pubshop');

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probe')->andReturn(true);
        $m->shouldReceive('fetchBrand')->andReturn([
            'id' => 'pub-brand', 'name' => 'Pub Store', 'currency' => 'AUD',
            'favicon' => 'https://pub.example.com/favicon.ico', 'logo' => 'https://pub.example.com/logo.png',
        ]);
        $m->shouldReceive('fetchProducts')->andReturn([
            ['productId' => 'p1', 'title' => 'Mug', 'url' => 'https://pub.example.com/p1', 'available' => true, 'price' => '10.00', 'currency' => 'AUD'],
        ]);
    });

    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://pub.example.com', 'discountCode' => 'SAVE10'])
        ->assertOk();
    actingAsUser($user)->putJson('/api/platforms/shop/brands/pub-brand/selection', ['productIds' => ['p1']])
        ->assertOk();

    $res = $this->getJson('/api/public/profiles/pubshop/platforms');
    $res->assertOk();

    // toEqual (not toBe): key ORDER inside the brand object shifts slightly
    // (id now precedes provider — see ShopBrand::toBrandArray()) but the value
    // set is exactly the pre-FOUND-25 contract; JSON object key order is not a
    // meaningful part of the wire contract for any real consumer.
    expect($res->json('data.platforms.shop.0.payload'))->toEqual([
        'pub-brand' => [
            'id' => 'pub-brand',
            'provider' => 'shopify',
            'url' => 'https://pub.example.com',
            'name' => 'Pub Store',
            'currency' => 'AUD',
            'favicon' => 'https://pub.example.com/favicon.ico',
            'logo' => 'https://pub.example.com/logo.png',
            'discountCode' => 'SAVE10',
            'products' => [
                ['productId' => 'p1', 'title' => 'Mug', 'url' => 'https://pub.example.com/p1', 'available' => true, 'price' => '10.00', 'currency' => 'AUD'],
            ],
        ],
    ]);
});

// ── FOUND-25 regression: edge-cache purge must survive past the first write ──
//
// IntegrationConnectionObserver only purges on wasRecentlyCreated / a
// payload change / an is_active flip. Every ShopController mutation now
// writes the SAME static marker ({"storage":"relational"}), so after the
// first connect the payload never changes again — without an explicit
// refresh, a second brand/product edit would silently stop purging the
// sitepage edge cache (stale shop data for up to the 24h TTL).

it('purges the edge cache on a SECOND shop mutation, not just the first connect', function () {
    Bus::fake();
    $user = shopStorageUserWithSite('purge2');

    $this->mock(ShopifyScraper::class, function ($m) {
        $m->shouldReceive('originOf')->andReturnUsing(fn ($url) => rtrim($url, '/'));
        $m->shouldReceive('probe')->andReturn(true);
        $m->shouldReceive('fetchBrand')->andReturn([
            'id' => 'purge-brand', 'name' => 'Purge Store', 'currency' => 'AUD', 'favicon' => null, 'logo' => null,
        ]);
    });

    // First connect always purges (new connection row, wasRecentlyCreated) —
    // not the behavior under test.
    actingAsUser($user)->postJson('/api/platforms/shop/brands', ['url' => 'https://purge2.example.com'])
        ->assertOk();

    // CloudflareCachePurgeJob is ShouldBeUnique: PendingDispatch::__destruct()
    // acquires its uniqueness lock BEFORE even reaching the (real or faked)
    // Dispatcher, so it's enforced regardless of Bus::fake() — and the array
    // cache store keeps locks in a SEPARATE `locks` property that plain
    // Cache::flush() does not touch (Illuminate\Cache\ArrayLock/ArrayStore).
    // Reset it directly to release the lock the first write took, so the
    // SECOND write's dispatch attempt isn't swallowed by the coalescing
    // window this test isn't exercising (that's covered separately by
    // PlatformLoopTest's connect+disconnect-coalesce assertion).
    Cache::getStore()->locks = [];

    // Re-fake to discard the first write's recorded dispatches — isolates the
    // assertion to whatever the SECOND mutation triggers.
    Bus::fake();

    // Second mutation on the SAME connection: the marker payload is
    // unchanged and is_active stays true, so the observer's own gate misses
    // entirely. Before the fix, this dispatched nothing.
    actingAsUser($user)->patchJson('/api/platforms/shop/brands/purge-brand', ['discountCode' => 'SAVE20'])
        ->assertOk();

    Bus::assertDispatched(CloudflareCachePurgeJob::class);
});
