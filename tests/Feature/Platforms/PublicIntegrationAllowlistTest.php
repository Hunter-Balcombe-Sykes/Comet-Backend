<?php

use App\Http\Resources\Platforms\PublicIntegrationConnectionResource;
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

function allowlistUser(string $h): User
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

    // FOUND-25: brands are relational site.shop_brands rows — fixed columns
    // mean there's no stray key to leak at the storage layer, but the public
    // resource must still only expose SHOP_BRAND_ALLOWLIST fields (source_url,
    // fetch_mode, is_individual, position never reach the public wire).
    $conn = IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'shop',
        'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
    ShopBrand::create([
        'connection_id' => $conn->id,
        'brand_id' => 'brand-123',
        'provider' => 'shopify',
        'url' => 'https://shop.example',
        'source_url' => 'https://shop.example/internal-rescrape-input', // must stay private
        'name' => 'Example Shop',
        'currency' => 'AUD',
        'favicon' => 'https://shop.example/favicon.ico',
        'logo' => 'https://shop.example/logo.png',
        'discount_code' => 'SAVE10',
    ]);

    $brand = $this->getJson('/api/public/profiles/allow2/integrations')
        ->assertOk()
        ->json('data.platforms.shop.0.payload.brand-123');

    expect($brand['name'])->toBe('Example Shop');
    expect($brand)->toHaveKey('discountCode'); // kept — current public contract is a pass-through
    expect($brand)->toHaveKey('products');
    expect($brand['provider'])->toBe('shopify');
    expect($brand)->not->toHaveKey('sourceUrl');
    // No site row for allow2 → the global stamp is skipped, so the brand keeps
    // its stored per-brand linkMode default ('product' from toBrandArray()).
    expect($brand['linkMode'])->toBe('product');
});

it('passes each product gallery + per-variant image + variantId through to the public payload', function () {
    // #84: the pages side builds /cart/<variantId>:1 checkout links + swaps the
    // photo per variant. Prove the backend exposes the raw material — full
    // `images` gallery, and each variant's `id` + `image` — verbatim inside the
    // allowlisted `products` array (products are NOT per-key filtered).
    $user = allowlistUser('allowvimg');

    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $brand = ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'brand-x', 'provider' => 'shopify',
        'url' => 'https://shop.example', 'discount_code' => 'SAVE10', 'referral_query' => 'ref=abc',
    ]);
    // Store the exact shape ShopifyScraper::fetchProducts() now produces.
    ShopProduct::create([
        'brand_id' => $brand->id, 'product_id' => '111', 'position' => 0,
        'data' => [
            'productId' => '111', 'title' => 'Wool Runner', 'handle' => 'wool-runner',
            'vendor' => 'Allbirds', 'image' => 'https://cdn.shopify.com/grey.jpg',
            'images' => ['https://cdn.shopify.com/grey.jpg', 'https://cdn.shopify.com/navy.jpg'],
            'price' => '95.00', 'currency' => 'USD', 'variantId' => '201', 'available' => true,
            'url' => 'https://shop.example/products/wool-runner', 'createdAt' => '2026-01-01T00:00:00Z',
            'variants' => [
                ['id' => '201', 'title' => 'Grey', 'price' => '95.00', 'available' => true, 'image' => 'https://cdn.shopify.com/grey.jpg'],
                ['id' => '202', 'title' => 'Navy', 'price' => '95.00', 'available' => true, 'image' => 'https://cdn.shopify.com/navy.jpg'],
            ],
        ],
    ]);

    $product = $this->getJson('/api/public/profiles/allowvimg/integrations')
        ->assertOk()
        ->json('data.platforms.shop.0.payload.brand-x.products.0');

    // Full gallery reaches the wire.
    expect($product['images'])->toBe(['https://cdn.shopify.com/grey.jpg', 'https://cdn.shopify.com/navy.jpg']);
    // Every variant carries its id (for the checkout URL) + its own image (for the swap).
    expect($product['variants'])->toHaveCount(2);
    expect($product['variants'][0]['id'])->toBe('201');
    expect($product['variants'][0]['image'])->toBe('https://cdn.shopify.com/grey.jpg');
    expect($product['variants'][1]['id'])->toBe('202');
    expect($product['variants'][1]['image'])->toBe('https://cdn.shopify.com/navy.jpg');
    // Brand-level fields the pages side needs to assemble the checkout URL ride along.
    expect($product['url'])->toBe('https://shop.example/products/wool-runner');
});

it('stamps every shop brand linkMode from the GLOBAL site setting', function () {
    $user = allowlistUser('allowshop');
    // The global lives on site.sites.shop_link_mode (2026-07-08). Set it to
    // 'checkout' and prove EVERY brand's public linkMode is stamped from it,
    // regardless of the per-brand link_mode column (dormant under the global).
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subdomain' => 'allowshop',
        'shop_link_mode' => 'checkout',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    // Two brands whose STORED per-brand link_mode is 'product' — the global must
    // override both to 'checkout'.
    ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'b1', 'provider' => 'shopify',
        'url' => 'https://b1.example', 'link_mode' => 'product', 'position' => 0,
    ]);
    ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'b2', 'provider' => 'woocommerce',
        'url' => 'https://b2.example', 'link_mode' => 'product', 'position' => 1,
    ]);

    $shop = $this->getJson('/api/public/profiles/allowshop/integrations')
        ->assertOk()
        ->json('data.platforms.shop.0.payload');

    expect($shop['b1']['linkMode'])->toBe('checkout');
    expect($shop['b2']['linkMode'])->toBe('checkout');
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

it('passes the enriched event fields to the public wire and keeps hiddenEventIds private', function () {
    $user = allowlistUser('allowevents');

    $enriched = [
        'id' => 'ev1',
        'name' => 'Warehouse Rave',
        'venue' => 'The Depot',
        'location' => 'Brunswick',
        'startDate' => '2026-09-05T21:00:00+10:00',
        'endDate' => '2026-09-06T05:00:00+10:00',
        'description' => 'All-night warehouse party.',
        'startsAt' => '2026-09-05T21:00:00+10:00',
        'endsAt' => '2026-09-06T05:00:00+10:00',
        'price' => 'AUD 20 – 55.5',
        'priceMin' => 20.0,
        'currency' => 'AUD',
        'availability' => 'available',
        'soldOut' => false,
        'image' => 'https://img.evbuc.com/cover.jpg',
        'link' => 'https://www.eventbrite.com/e/warehouse-rave-tickets-123',
    ];

    // Account row: upcoming[] event objects pass through WHOLE (top-level filter
    // only), so the enriched keys ride inside; hiddenEventIds must never leak.
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'eventbrite',
        'resource_id' => 'acct-abc',
        'payload' => [
            'url' => 'https://www.eventbrite.com/o/acme-1',
            'organiser' => 'Acme',
            'next' => $enriched,
            'upcoming' => [$enriched],
            'hiddenEventIds' => ['secret-hide'],
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
    // Standalone event row: the enriched keys are TOP-level, so they must be on
    // the platform allowlist to survive.
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'humanitix',
        'resource_id' => 'event-ev1',
        'payload' => ['kind' => 'event', ...$enriched],
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'resource_kind' => 'event',
    ]);

    $platforms = $this->getJson('/api/public/profiles/allowevents/integrations')
        ->assertOk()
        ->json('data.platforms');

    $account = $platforms['eventbrite'][0]['payload'];
    expect($account)->not->toHaveKey('hiddenEventIds');
    expect($account['upcoming'][0])->toMatchArray([
        'description' => 'All-night warehouse party.',
        'startsAt' => '2026-09-05T21:00:00+10:00',
        'endsAt' => '2026-09-06T05:00:00+10:00',
        'priceMin' => 20.0,
        'currency' => 'AUD',
        'soldOut' => false,
    ]);

    $standalone = $platforms['humanitix'][0]['payload'];
    expect($standalone)->toMatchArray([
        'kind' => 'event',
        'description' => 'All-night warehouse party.',
        'startsAt' => '2026-09-05T21:00:00+10:00',
        'endsAt' => '2026-09-06T05:00:00+10:00',
        'priceMin' => 20.0,
        'currency' => 'AUD',
        'soldOut' => false,
        'venue' => 'The Depot',
    ]);
});

it('serves the bandcamp releases list only when show_all_releases is enabled', function () {
    $payload = [
        'url' => 'https://artist.bandcamp.com',
        'artist' => 'Artist',
        'name' => 'Latest Album',
        'thumbnail' => 'https://f4.bcbits.com/img/latest.jpg',
        'link' => 'https://artist.bandcamp.com/album/latest',
        'latest' => ['itemId' => 'album-1', 'name' => 'Latest Album'],
        'highlights' => [['itemId' => 'album-2', 'name' => 'Curated Pick']],
        'releases' => [
            ['itemId' => 'album-1', 'name' => 'Latest Album', 'thumbnail' => null, 'link' => 'https://artist.bandcamp.com/album/latest'],
            ['itemId' => 'album-2', 'name' => 'Curated Pick', 'thumbnail' => null, 'link' => 'https://artist.bandcamp.com/album/pick'],
        ],
    ];

    // Default (nothing stored): show_all_releases is OFF → releases suppressed,
    // the capped latest+highlights selection is all the public wire carries.
    $off = allowlistUser('allowbc1');
    IntegrationConnection::create([
        'user_id' => $off->id, 'platform' => 'bandcamp', 'resource_id' => 'bandcamp',
        'payload' => $payload, 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $served = $this->getJson('/api/public/profiles/allowbc1/integrations')->assertOk()->json('data.platforms.bandcamp.0.payload');
    expect($served)->not->toHaveKey('releases');
    expect($served['latest']['name'])->toBe('Latest Album');
    expect($served['highlights'])->toHaveCount(1);

    // Owner opted in (stored true) → the full grid is served.
    $on = allowlistUser('allowbc2');
    IntegrationConnection::create([
        'user_id' => $on->id, 'platform' => 'bandcamp', 'resource_id' => 'bandcamp',
        'payload' => $payload, 'display_settings' => ['show_all_releases' => true],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $served = $this->getJson('/api/public/profiles/allowbc2/integrations')->assertOk()->json('data.platforms.bandcamp.0.payload');
    expect($served['releases'])->toHaveCount(2);
    expect($served['releases'][1]['name'])->toBe('Curated Pick');
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
