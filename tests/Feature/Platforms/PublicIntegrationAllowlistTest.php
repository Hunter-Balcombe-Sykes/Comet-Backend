<?php

use App\Http\Resources\Platforms\PublicIntegrationConnectionResource;
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

it('returns an empty payload array (fail-closed) for a platform with no allowlist entry', function () {
    // SEC-2: the public resource must fail CLOSED — return [] — when a platform
    // has no ALLOWLIST entry, never leaking raw stored keys like _folder / source / sourceUrl.
    //
    // We exercise the Resource directly over an UNSAVED model so the SEC-1 saving
    // guard (which rejects unregistered platforms at persist time) does NOT fire.
    // new IntegrationConnection([...]) fills the model attributes without touching
    // the DB, giving us a valid carrier with an unregistered platform key.
    $carrier = new IntegrationConnection([
        'platform' => 'mystery',
        'resource_id' => 'x',
        'payload' => [
            'url' => 'https://example.com',
            '_folder' => 'secret',
            'source' => 'internal',
            'sourceUrl' => 'https://internal.example.com',
        ],
        'last_refreshed_at' => null,
    ]);

    $result = (new PublicIntegrationConnectionResource($carrier))->toArray(request());

    // Fail-closed: the payload field must be an empty array, not the raw stored data.
    expect($result['payload'])->toBe([]);
    // Prove no internal keys leaked.
    expect($result['payload'])->not->toHaveKey('_folder');
    expect($result['payload'])->not->toHaveKey('source');
    expect($result['payload'])->not->toHaveKey('sourceUrl');
});

it('never exposes the dashboard-only category platforms on the public endpoint', function () {
    $user = allowlistUser('allow10');

    // online-ordering carries sensitive internal data (fees, source tags) and is
    // dashboard-only — it must never reach the public, CDN-cached wire.
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'online-ordering',
        'resource_id' => 'online-ordering',
        'payload' => [
            'ubereats-abc' => [
                'id' => 'ubereats-abc',
                'provider' => 'custom',
                'url' => 'https://www.ubereats.com/store/x',
                'name' => 'Uber Eats',
                'source' => 'google-business',
                'data' => ['type' => 'delivery', 'fees' => '$4.99', 'time' => '30 min', 'sourcePlatform' => 'Uber Eats'],
            ],
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'reservations',
        'resource_id' => 'reservations',
        'payload' => ['provider' => 'custom', 'url' => 'https://example.com/book', 'name' => 'Book', 'source' => 'manual'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'booking',
        'resource_id' => 'booking',
        'payload' => ['provider' => 'custom', 'url' => 'https://example.com/appt', 'name' => 'Appointments', 'source' => 'manual'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
    // A genuinely public platform alongside, to prove the rest still ships.
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'facebook',
        'resource_id' => 'facebook',
        'payload' => ['username' => 'me', 'url' => 'https://facebook.com/me'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $platforms = $this->getJson('/api/public/profiles/allow10/integrations')
        ->assertOk()
        ->json('data.platforms');

    // Dashboard-only categories must not appear at all (whereNotIn guard).
    expect($platforms)->not->toHaveKey('online-ordering');
    expect($platforms)->not->toHaveKey('reservations');
    expect($platforms)->not->toHaveKey('booking');
    // The public platform still ships untouched.
    expect($platforms['facebook'][0]['payload']['url'])->toBe('https://facebook.com/me');
});

it('allowlists mixcloud using the MusicEmbed five-key contract and strips internal keys', function () {
    $user = allowlistUser('allow11');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'mixcloud',
        'resource_id' => 'mixcloud',
        'payload' => [
            'url' => 'https://www.mixcloud.com/djtest/set/my-mix/',
            'name' => 'My Mix',
            'thumbnail' => 'https://thumbnailer.mixcloud.com/unsafe/600x600/extaudio/1/2/3.jpg',
            'embedUrl' => 'https://www.mixcloud.com/widget/iframe/?feed=%2Fdjtest%2Fset%2Fmy-mix%2F',
            'link' => 'https://www.mixcloud.com/djtest/set/my-mix/',
            '_scratch' => 'internal', // not on the allowlist — must be stripped
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $payload = $this->getJson('/api/public/profiles/allow11/integrations')
        ->assertOk()
        ->json('data.platforms.mixcloud.0.payload');

    expect($payload)->toBe([
        'url' => 'https://www.mixcloud.com/djtest/set/my-mix/',
        'name' => 'My Mix',
        'thumbnail' => 'https://thumbnailer.mixcloud.com/unsafe/600x600/extaudio/1/2/3.jpg',
        'embedUrl' => 'https://www.mixcloud.com/widget/iframe/?feed=%2Fdjtest%2Fset%2Fmy-mix%2F',
        'link' => 'https://www.mixcloud.com/djtest/set/my-mix/',
    ]);
    expect($payload)->not->toHaveKey('_scratch');
});

it('allowlists tidal using the MusicEmbed five-key contract and strips internal keys', function () {
    $user = allowlistUser('allow12');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'tidal',
        'resource_id' => 'tidal',
        'payload' => [
            'url' => 'https://listen.tidal.com/album/123456',
            'name' => 'Test Album',
            'thumbnail' => 'https://resources.tidal.com/images/abc/640x640.jpg',
            'embedUrl' => 'https://embed.tidal.com/albums/123456',
            'link' => 'https://listen.tidal.com/album/123456',
            '_scratch' => 'internal', // not on the allowlist — must be stripped
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $payload = $this->getJson('/api/public/profiles/allow12/integrations')
        ->assertOk()
        ->json('data.platforms.tidal.0.payload');

    expect($payload)->toBe([
        'url' => 'https://listen.tidal.com/album/123456',
        'name' => 'Test Album',
        'thumbnail' => 'https://resources.tidal.com/images/abc/640x640.jpg',
        'embedUrl' => 'https://embed.tidal.com/albums/123456',
        'link' => 'https://listen.tidal.com/album/123456',
    ]);
    expect($payload)->not->toHaveKey('_scratch');
});

it('allowlists square to only the public booking url and strips any internal keys', function () {
    $user = allowlistUser('allow13');

    // Square stores only the user-pasted booking URL (no scraping). `source` is the
    // real internal origin tag SelectionPayload can carry; seed it to prove the
    // allowlist strips anything beyond `url`.
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'square',
        'resource_id' => 'square',
        'payload' => [
            'url' => 'https://book.squareup.com/appointments/abc123/location/xyz/services',
            'source' => 'manual', // not on the allowlist — must be stripped
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $payload = $this->getJson('/api/public/profiles/allow13/integrations')
        ->assertOk()
        ->json('data.platforms.square.0.payload');

    expect($payload)->toBe(['url' => 'https://book.squareup.com/appointments/abc123/location/xyz/services']);
    expect($payload)->not->toHaveKey('source');
});
