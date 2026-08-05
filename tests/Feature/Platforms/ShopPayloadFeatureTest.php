<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\Site\ShopBrand;
use App\Models\Core\Site\ShopProduct;
use App\Models\Core\User\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function shopPayloadUser(string $h): User
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

it('shop updateBrand preserves other brands fields verbatim', function () {
    $user = shopPayloadUser('shp1');
    // FOUND-25: two brands as separate site.shop_brands rows. brand-1 carries
    // internal fields (fetch_mode, source_url) the product dispatch depends on.
    // Updating brand-2's discount must not touch brand-1's row at all.
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $brand1 = ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'brand-1', 'provider' => 'woocommerce',
        'url' => 'https://b1', 'source_url' => 'https://b1/shop', 'fetch_mode' => 'client',
        'discount_code' => 'A', 'position' => 0,
    ]);
    ShopProduct::create(['brand_id' => $brand1->id, 'product_id' => 'p1', 'position' => 0, 'data' => ['productId' => 'p1', 'url' => 'https://b1/p1']]);
    ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'brand-2', 'provider' => 'shopify',
        'url' => 'https://b2', 'discount_code' => 'B', 'position' => 1,
    ]);

    actingAsUser($user)->patchJson('/api/platforms/shop/brands/brand-2', ['discountCode' => 'NEW'])
        ->assertOk()
        ->assertJsonPath('discountCode', 'NEW');

    // brand-1's fields + products survive verbatim; brand-2's discount updated.
    $brand1->refresh();
    expect($brand1->fetch_mode)->toBe('client');
    expect($brand1->source_url)->toBe('https://b1/shop');
    expect($brand1->products->map->data->all())->toBe([['productId' => 'p1', 'url' => 'https://b1/p1']]);
    expect(ShopBrand::where('connection_id', $conn->id)->where('brand_id', 'brand-2')->value('discount_code'))
        ->toBe('NEW');
});

it('shop selection returns the compat flat view of the first brand with products', function () {
    $user = shopPayloadUser('shp2');
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'empty', 'provider' => 'shopify',
        'url' => 'https://e', 'discount_code' => '', 'position' => 0,
    ]);
    $full = ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'full', 'provider' => 'shopify',
        'url' => 'https://f', 'discount_code' => 'SAVE', 'position' => 1,
    ]);
    ShopProduct::create(['brand_id' => $full->id, 'product_id' => 'p1', 'position' => 0, 'data' => ['productId' => 'p1', 'url' => 'https://f/p1']]);

    actingAsUser($user)->getJson('/api/platforms/shop/selection')
        ->assertOk()
        ->assertExactJson(['selection' => [
            'url' => 'https://f',
            'provider' => 'shopify',
            'discountCode' => 'SAVE',
            // popularityRank rides every dashboard product since 2026-08-04
            // (brandMap annotates from content_popularity_scores; null when
            // the site has no ranks) — the Smart order switch sorts on it.
            'products' => [['productId' => 'p1', 'url' => 'https://f/p1', 'popularityRank' => null]],
        ]]);
});

it('shop selection surfaces a seeded popularityRank keyed by product handle', function () {
    // Covers the dashboard route (shop_product ranks were ALREADY correctly
    // keyed by handle — ShopBrand::toBrandArray():135 — unlike custom/links'
    // resource_id mismatch, RANK-1). ShopRelationalStorageTest pins the same
    // keying on the PUBLIC wire only; this is the /api/platforms/shop/selection
    // half.
    setupContentPopularityScoresTable();
    $user = shopPayloadUser('shp4');
    $siteId = (string) Str::uuid();
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => $siteId,
        'user_id' => $user->id,
        'subdomain' => 'shp4',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $brand = ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'full', 'provider' => 'shopify',
        'url' => 'https://f', 'discount_code' => 'SAVE', 'position' => 0,
    ]);
    ShopProduct::create([
        'brand_id' => $brand->id, 'product_id' => 'p1', 'position' => 0,
        'data' => ['productId' => 'p1', 'handle' => 'mug', 'url' => 'https://f/p1'],
    ]);

    DB::connection('pgsql')->table('analytics.content_popularity_scores')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'content_type' => 'shop_product',
        'content_key' => 'mug',
        'score' => 8.0,
        'rank' => 1,
        'computed_at' => now()->toDateTimeString(),
    ]);

    actingAsUser($user)->getJson('/api/platforms/shop/selection')
        ->assertOk()
        ->assertJsonPath('selection.products.0.popularityRank', 1);
});

it('shop selection is null when no brand has products', function () {
    $user = shopPayloadUser('shp3');
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'b', 'provider' => 'shopify',
        'url' => 'https://b', 'position' => 0,
    ]);

    actingAsUser($user)->getJson('/api/platforms/shop/selection')
        ->assertOk()
        ->assertExactJson(['selection' => null]);
});
