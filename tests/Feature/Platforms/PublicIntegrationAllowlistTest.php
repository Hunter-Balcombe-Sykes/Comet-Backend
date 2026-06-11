<?php

use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
});

function allowlistUser(string $h): User
{
    return User::create([
        'handle' => $h,
        'handle_lc' => strtolower($h),
        'display_name' => ucfirst($h),
        'account_type' => 'individual',
        'auth_user_id' => (string) Str::uuid(),
        'primary_email' => "{$h}@example.com",
    ]);
}

it('strips the internal _folder key from the public Instagram payload', function () {
    $user = allowlistUser('allow1');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'instagram',
        'resource_id' => 'instagram',
        'payload' => [
            'username' => 'creator',
            'fullName' => 'Creator Name',
            'images' => ['https://media.partna.au/platforms/instagram/123/img-0.jpg'],
            'mode' => 'manual',
            '_folder' => 'platforms/instagram/123', // internal storage path — must never be public
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $payload = $this->getJson('/api/public/profiles/allow1/integrations')
        ->assertOk()
        ->json('data.platforms.instagram.0.payload');

    // Public content passes through unchanged...
    expect($payload['username'])->toBe('creator');
    expect($payload)->toHaveKey('images');
    expect($payload)->toHaveKey('fullName');
    // ...but the internal storage path is gone.
    expect($payload)->not->toHaveKey('_folder');
});

it('applies the per-brand allowlist to the Shopify brand map and strips unknown keys', function () {
    $user = allowlistUser('allow2');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'shop',
        'resource_id' => 'shop',
        'payload' => [
            'brand-123' => [
                'id' => 'brand-123',
                'url' => 'https://shop.example',
                'name' => 'Example Shop',
                'currency' => 'AUD',
                'favicon' => 'https://shop.example/favicon.ico',
                'logo' => 'https://shop.example/logo.png',
                'discountCode' => 'SAVE10',
                'products' => [],
                '_internalRef' => 'secret-xyz', // not on the allowlist — must be stripped
            ],
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $brand = $this->getJson('/api/public/profiles/allow2/integrations')
        ->assertOk()
        ->json('data.platforms.shop.0.payload.brand-123');

    expect($brand['name'])->toBe('Example Shop');
    expect($brand)->toHaveKey('discountCode'); // kept — current public contract is a pass-through
    expect($brand)->toHaveKey('products');
    expect($brand['provider'])->toBe('shopify'); // legacy brands default to shopify
    expect($brand)->not->toHaveKey('_internalRef');
});

it('allowlists the new v2 platforms on the public endpoint', function () {
    $user = allowlistUser('allow9');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'spotify',
        'resource_id' => 'spotify',
        'payload' => [
            'url' => 'https://open.spotify.com/artist/abc',
            'name' => 'Artist',
            'thumbnail' => 'https://i.scdn.co/t.jpg',
            'embedUrl' => 'https://open.spotify.com/embed/artist/abc',
            'link' => 'https://open.spotify.com/artist/abc',
            '_scratch' => 'internal', // not on the allowlist — must be stripped
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
    // v3 platforms carry private re-fetch inputs (vimeo apiPath) that must
    // never reach the public wire.
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'vimeo',
        'resource_id' => 'vimeo',
        'payload' => [
            'url' => 'https://vimeo.com/mockuser',
            'apiPath' => 'mockuser', // private
            'name' => 'Mock User',
            'thumbnail' => null,
            'link' => 'https://vimeo.com/mockuser',
            'latest' => null,
            'items' => [],
            'highlights' => [['itemId' => '1', 'name' => 'Pick', 'thumbnail' => null, 'link' => 'https://vimeo.com/1', 'embedUrl' => 'https://player.vimeo.com/video/1']],
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $platforms = $this->getJson('/api/public/profiles/allow9/integrations')
        ->assertOk()
        ->json('data.platforms');

    expect($platforms['spotify'][0]['payload'])->toBe([
        'url' => 'https://open.spotify.com/artist/abc',
        'name' => 'Artist',
        'thumbnail' => 'https://i.scdn.co/t.jpg',
        'embedUrl' => 'https://open.spotify.com/embed/artist/abc',
        'link' => 'https://open.spotify.com/artist/abc',
    ]);
    expect($platforms['vimeo'][0]['payload'])->not->toHaveKey('apiPath');
    expect($platforms['vimeo'][0]['payload']['url'])->toBe('https://vimeo.com/mockuser');
    expect($platforms['vimeo'][0]['payload']['highlights'])->toHaveCount(1);   // curated picks are public
});
