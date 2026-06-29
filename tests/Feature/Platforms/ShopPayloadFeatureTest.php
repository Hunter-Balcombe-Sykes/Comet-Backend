<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
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
        'account_type' => 'partna',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

it('shop updateBrand preserves other brands internal keys verbatim', function () {
    $user = shopPayloadUser('shp1');
    // Two brands; brand-1 carries internal keys (fetchMode, sourceUrl) the product
    // dispatch depends on. Updating brand-2's discount must not strip brand-1's keys.
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => [
            'brand-1' => [
                'id' => 'brand-1', 'provider' => 'woocommerce', 'url' => 'https://b1',
                'sourceUrl' => 'https://b1/shop', 'fetchMode' => 'client',
                'discountCode' => 'A', 'products' => [['productId' => 'p1', 'url' => 'https://b1/p1']],
            ],
            'brand-2' => [
                'id' => 'brand-2', 'provider' => 'shopify', 'url' => 'https://b2',
                'discountCode' => 'B', 'products' => [],
            ],
        ],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->patchJson('/api/platforms/shop/brands/brand-2', ['discountCode' => 'NEW'])
        ->assertOk()
        ->assertJsonPath('discountCode', 'NEW');

    $stored = IntegrationConnection::where('user_id', $user->id)->where('platform', 'shop')->firstOrFail()->payload;
    // brand-1 internal keys + products survive verbatim; brand-2 discount updated.
    expect($stored['brand-1']['fetchMode'])->toBe('client');
    expect($stored['brand-1']['sourceUrl'])->toBe('https://b1/shop');
    expect($stored['brand-1']['products'])->toBe([['productId' => 'p1', 'url' => 'https://b1/p1']]);
    expect($stored['brand-2']['discountCode'])->toBe('NEW');
});

it('shop selection returns the compat flat view of the first brand with products', function () {
    $user = shopPayloadUser('shp2');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => [
            'empty' => ['id' => 'empty', 'url' => 'https://e', 'discountCode' => '', 'products' => []],
            // No provider key — must default to 'shopify' in the compat view.
            'full' => ['id' => 'full', 'url' => 'https://f', 'discountCode' => 'SAVE', 'products' => [['productId' => 'p1', 'url' => 'https://f/p1']]],
        ],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->getJson('/api/platforms/shop/selection')
        ->assertOk()
        ->assertExactJson(['selection' => [
            'url' => 'https://f',
            'provider' => 'shopify',
            'discountCode' => 'SAVE',
            'products' => [['productId' => 'p1', 'url' => 'https://f/p1']],
        ]]);
});

it('shop selection is null when no brand has products', function () {
    $user = shopPayloadUser('shp3');
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => ['b' => ['id' => 'b', 'url' => 'https://b', 'products' => []]],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    actingAsUser($user)->getJson('/api/platforms/shop/selection')
        ->assertOk()
        ->assertExactJson(['selection' => null]);
});
