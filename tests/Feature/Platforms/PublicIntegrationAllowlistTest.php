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
        'first_name' => ucfirst($h),
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
    // `images` gallery, and each variant's `id` + `image` — inside the
    // allowlisted `products` array. Every key asserted below is on
    // SHOP_PRODUCT_ALLOWLIST (#API-1 added that per-product filter), and each
    // variant's sub-keys survive because the filter is top-level only.
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

// ── #API-1: the per-product allowlist on the public shop wire ─────────────
//
// ShopProduct.data is raw scraper output. Before SHOP_PRODUCT_ALLOWLIST the
// brand allowlist let `products` through WHOLE, so any key a fetcher chose to
// store reached unauthenticated visitors — and this response is CDN-cached for
// 15 minutes, so a leak is served to every visitor of that sitepage.

it('strips unlisted keys from each shop product on the public wire (#API-1)', function () {
    $user = allowlistUser('allowprodfilter');

    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $brand = ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'brand-filter', 'provider' => 'shopify',
        'url' => 'https://shop.example', 'position' => 0,
    ]);
    // The legitimate ShopifyScraper shape PLUS three keys a future fetcher (or a
    // careless merge) might store. None are on SHOP_PRODUCT_ALLOWLIST.
    ShopProduct::create([
        'brand_id' => $brand->id, 'product_id' => '111', 'position' => 0,
        'data' => [
            'productId' => '111', 'title' => 'Wool Runner', 'handle' => 'wool-runner',
            'vendor' => 'Allbirds', 'description' => 'Merino wool.',
            'image' => 'https://cdn.shopify.com/grey.jpg',
            'images' => ['https://cdn.shopify.com/grey.jpg'],
            'price' => '95.00', 'currency' => 'USD', 'variantId' => '201', 'available' => true,
            'url' => 'https://shop.example/products/wool-runner',
            'createdAt' => '2026-01-01T00:00:00Z',
            'variants' => [['id' => '201', 'title' => 'Grey', 'price' => '95.00', 'available' => true, 'image' => 'https://cdn.shopify.com/grey.jpg']],
            // Wholesale margin, supplier identity and a debug trace — commercially
            // sensitive and internal. Must never reach the public wire.
            'internalCostPrice' => '12.50',
            'supplierId' => 'SUP-9',
            '__debug' => 'trace',
        ],
    ]);

    $response = $this->getJson('/api/public/profiles/allowprodfilter/integrations')->assertOk();
    $product = $response->json('data.platforms.shop.0.payload.brand-filter.products.0');

    // The unvetted keys are gone.
    expect($product)->not->toHaveKey('internalCostPrice');
    expect($product)->not->toHaveKey('supplierId');
    expect($product)->not->toHaveKey('__debug');
    // ...and the real product still shipped, so this can't pass by returning [].
    expect($product['productId'])->toBe('111');
    expect($product['variantId'])->toBe('201');
    expect($product['url'])->toBe('https://shop.example/products/wool-runner');
    expect($product['images'])->toBe(['https://cdn.shopify.com/grey.jpg']);
    expect($product['variants'])->toHaveCount(1);

    // Belt-and-suspenders (the fresha teamMenu idiom): prove the stripped data
    // doesn't ride anywhere ELSE in the body, not merely inside this one key.
    expect($response->getContent())->not->toContain('internalCostPrice');
    expect($response->getContent())->not->toContain('supplierId');
    expect($response->getContent())->not->toContain('SUP-9');
    expect($response->getContent())->not->toContain('12.50');
    expect($response->getContent())->not->toContain('__debug');
});

it('keeps every SHOP_PRODUCT_ALLOWLIST key on the public wire (#API-1)', function () {
    // The other half of the filter: a typo or an accidental deletion in the
    // constant silently removes a field the sitepage renders. Store all 14
    // scraper-emitted keys with distinct non-null values and pin the exact key
    // list. `popularityRank` is 15th because toBrandArray() appends it AFTER the
    // stored data, and array_intersect_key preserves the stored order.
    $user = allowlistUser('allowprodkeys');

    $conn = IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shop', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $brand = ShopBrand::create([
        'connection_id' => $conn->id, 'brand_id' => 'brand-keys', 'provider' => 'shopify',
        'url' => 'https://shop.example', 'position' => 0,
    ]);
    ShopProduct::create([
        'brand_id' => $brand->id, 'product_id' => '111', 'position' => 0,
        'data' => [
            'productId' => '111',
            'title' => 'Wool Runner',
            'handle' => 'wool-runner',
            'vendor' => 'Allbirds',
            'description' => 'Merino wool sneaker.',
            'image' => 'https://cdn.shopify.com/grey.jpg',
            'images' => ['https://cdn.shopify.com/grey.jpg', 'https://cdn.shopify.com/navy.jpg'],
            'price' => '95.00',
            'currency' => 'USD',
            'variantId' => '201',
            'available' => true,
            'url' => 'https://shop.example/products/wool-runner',
            'createdAt' => '2026-01-01T00:00:00Z',
            'variants' => [
                ['id' => '201', 'title' => 'Grey', 'price' => '95.00', 'available' => true, 'image' => 'https://cdn.shopify.com/grey.jpg'],
                ['id' => '202', 'title' => 'Navy', 'price' => '99.00', 'available' => false, 'image' => 'https://cdn.shopify.com/navy.jpg'],
            ],
        ],
    ]);

    $product = $this->getJson('/api/public/profiles/allowprodkeys/integrations')
        ->assertOk()
        ->json('data.platforms.shop.0.payload.brand-keys.products.0');

    expect(array_keys($product))->toBe([
        'productId', 'title', 'handle', 'vendor', 'description',
        'image', 'images', 'price', 'currency', 'variantId',
        'available', 'url', 'createdAt', 'variants', 'popularityRank',
    ]);
    // Values survive intact, not just the keys.
    expect($product['vendor'])->toBe('Allbirds');
    expect($product['createdAt'])->toBe('2026-01-01T00:00:00Z');
    // The filter is TOP-LEVEL only: variant sub-objects pass through whole
    // (same residual as eventbrite's next/upcoming event objects).
    expect(array_keys($product['variants'][0]))->toBe(['id', 'title', 'price', 'available', 'image']);
    expect($product['variants'][1]['available'])->toBeFalse();
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

it('fails closed to an empty payload when a stored payload is a scalar, not an array (SEC-3)', function () {
    // Real code paths only ever write an array/JSONB object into `payload` —
    // this simulates a corrupt row (e.g. a bug once wrote a raw error string)
    // by inserting a JSON-encoded SCALAR directly via the query builder,
    // bypassing IntegrationConnection's array cast on write. On read, the
    // model's `payload` => 'array' cast json_decodes it back into a plain
    // PHP string (not an array) — exactly the shape filterPayload() must
    // reject before it ever reaches the per-platform allowlist.
    $user = allowlistUser('allowscalar');

    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'surface_key' => 'facebook.profile',
        'routing_class' => 'social',
        'resource_id' => 'facebook',
        'payload' => json_encode('leaked-scalar-value'),
        'is_active' => true,
        'last_refresh_status' => 'ok',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = $this->getJson('/api/public/profiles/allowscalar/integrations')
        ->assertOk()
        ->json('data.platforms.facebook.0.payload');

    // Fail-closed: never the raw scalar, regardless of platform allowlist.
    expect($payload)->toBe([]);
});

// Inverted 2026-07-25 (link classification consolidation, Decision 10). Booking
// and reservations used to be dashboard-only, excluded from the public query and
// given an empty allowlist. Now every non-Fresha/Square booking brand and every
// non-OpenTable/ResDiary/NowBookit reservation brand lands on these two SHARED
// keys, so a Booksy or Resy link IS a public "Book with {provider}" card. Both
// halves had to change together: the original change widened the Resource
// allowlist to ['url','provider'] but left PublicIntegrationController's
// whereNotIn in place, so the widening was silently inert and a Booksy row still
// rendered as nothing. This test now pins that exactly url + provider reach the
// wire — name/source/id stay private, so the allowlist is still the gate.
it('exposes shared-key booking/reservations publicly with ONLY url + provider', function () {
    $user = allowlistUser('allow10');

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

    // Both now reach the wire — the sitepage renders a card per provider.
    // Key order follows the stored payload, which lists provider first.
    expect($platforms['reservations'][0]['payload'])
        ->toBe(['provider' => 'custom', 'url' => 'https://example.com/book']);
    expect($platforms['booking'][0]['payload'])
        ->toBe(['provider' => 'custom', 'url' => 'https://example.com/appt']);
    // `name` and `source` were in both stored payloads and must NOT ship — the
    // allowlist, not the query, is the exposure gate now.
    expect($platforms['booking'][0]['payload'])->not->toHaveKey('name');
    expect($platforms['booking'][0]['payload'])->not->toHaveKey('source');
    // The public platform still ships untouched.
    expect($platforms['facebook'][0]['payload']['url'])->toBe('https://facebook.com/me');
});

it('exposes online-ordering entries publicly (2026-07-23 actions rebuild) with only url/name/favicon/logo — id/provider/source/data stay private', function () {
    $user = allowlistUser('allow-ordering');

    // One row per store link (OnlineOrderingController::addEntry's real
    // storage shape — resource_id 'order-<hash>', flat CardPayload).
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'online-ordering',
        'resource_id' => 'order-abc123',
        'payload' => [
            'id' => 'order-abc123',
            'provider' => 'custom',
            'url' => 'https://www.ubereats.com/store/maha-restaurant',
            'name' => 'Uber Eats',
            'favicon' => 'https://www.google.com/s2/favicons?domain=ubereats.com&sz=64',
            'logo' => null,
            'source' => 'manual',
            'data' => ['type' => 'delivery', 'fees' => '$4.99', 'time' => '30 min'],
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $payload = $this->getJson('/api/public/profiles/allow-ordering/integrations')
        ->assertOk()
        ->json('data.platforms.online-ordering.0.payload');

    // `provider` joined this allowlist on 2026-07-25 so the sitepage can label a
    // shared-key ordering card ("Order with {provider}") — same widening booking
    // and reservations got. id/source/data still stay private.
    expect($payload)->toBe([
        'provider' => 'custom',
        'url' => 'https://www.ubereats.com/store/maha-restaurant',
        'name' => 'Uber Eats',
        'favicon' => 'https://www.google.com/s2/favicons?domain=ubereats.com&sz=64',
        'logo' => null,
    ]);
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

it('allowlists the five 2026-07-23 link-only platforms (TEST-3) and strips internal keys', function () {
    $user = allowlistUser('allowlink5');

    // Each row seeded username-then-url (array_intersect_key preserves the
    // STORED order, and toBe() === is order-sensitive) plus a _scratch key
    // that's on NO allowlist, to prove it gets stripped.
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'snapchat', 'resource_id' => 'snapchat',
        'payload' => ['username' => 'snapper', 'url' => 'https://snapchat.com/add/snapper', '_scratch' => 'internal'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'discord', 'resource_id' => 'discord',
        'payload' => ['username' => 'abc123', 'url' => 'https://discord.gg/abc123', '_scratch' => 'internal'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'telegram', 'resource_id' => 'telegram',
        'payload' => ['username' => 'tguser', 'url' => 'https://t.me/tguser', '_scratch' => 'internal'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'kick', 'resource_id' => 'kick',
        'payload' => ['username' => 'kicker', 'url' => 'https://kick.com/kicker', '_scratch' => 'internal'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'medium', 'resource_id' => 'medium',
        'payload' => ['username' => 'writer', 'url' => 'https://medium.com/@writer', '_scratch' => 'internal'],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);

    $p = $this->getJson('/api/public/profiles/allowlink5/integrations')
        ->assertOk()
        ->json('data.platforms');

    expect($p['snapchat'][0]['payload'])->toBe(['username' => 'snapper', 'url' => 'https://snapchat.com/add/snapper']);
    expect($p['discord'][0]['payload'])->toBe(['username' => 'abc123', 'url' => 'https://discord.gg/abc123']);
    expect($p['telegram'][0]['payload'])->toBe(['username' => 'tguser', 'url' => 'https://t.me/tguser']);
    expect($p['kick'][0]['payload'])->toBe(['username' => 'kicker', 'url' => 'https://kick.com/kicker']);
    expect($p['medium'][0]['payload'])->toBe(['username' => 'writer', 'url' => 'https://medium.com/@writer']);
});

it('keeps a completed team-mode fresha connection\'s scraped teamMenu (staff data) off the public wire', function () {
    // R3 (2026-07-25): connectMode was stripped from a completed row, but
    // teamMenu deliberately stays — it's the row's only stored copy of the
    // scraped menu, rebuilt into the `ready` poll response on every re-poll,
    // with no schema change available to relocate it. That leaves the
    // ALLOWLIST below as the ONLY thing keeping scraped staff-member data off
    // this CDN-cached public wire ("one filter away from a leak" per the R3
    // brief). Prove today's ['url', 'selection'] entry actually holds the line.
    $user = allowlistUser('allowfreshateam');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'fresha',
        'resource_id' => 'fresha',
        'payload' => [
            'url' => 'https://www.fresha.com/a/ollies-salon',
            'selection' => null,
            'teamMenu' => [
                'storeName' => 'Ollies',
                'team' => [
                    ['employeeId' => 'e1', 'displayName' => 'Jo Smith', 'jobTitle' => 'Senior Stylist', 'avatarUrl' => 'https://images.fresha.com/e1.jpg', 'rating' => 4.9],
                ],
                'services' => [
                    ['serviceId' => 's:1', 'name' => 'Cut', 'duration' => '30min', 'description' => null, 'price' => '$50', 'priceValue' => null, 'currency' => null, 'category' => 'Cuts', 'hasVariants' => false],
                ],
            ],
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $response = $this->getJson('/api/public/profiles/allowfreshateam/integrations')->assertOk();

    expect($response->json('data.platforms.fresha.0.payload'))->toBe([
        'url' => 'https://www.fresha.com/a/ollies-salon',
        'selection' => null,
    ]);

    // Belt-and-suspenders: assert the scraped staff data doesn't ride anywhere
    // else in the response body either, not merely inside the extracted key.
    expect($response->getContent())->not->toContain('teamMenu');
    expect($response->getContent())->not->toContain('Jo Smith');
    expect($response->getContent())->not->toContain('Senior Stylist');
});

// 271-PRIV-2 (nested leg): `photos` is allowlisted for the sitepage background,
// but each photo carries `authors` — Google CONTRIBUTOR display names, i.e.
// personal data about people who never signed up to Partna. Both filters are a
// top-level array_intersect_key, so an allowlisted parent key drags its nested
// payload through untouched. `reviews` is deliberately NOT asserted here: that
// is a separate, still-open product decision (271-PRIV-2 public-wire leg).
it('strips nested Google photo-contributor names from the public wire (271-PRIV-2)', function () {
    $user = allowlistUser('allowgbphotos');

    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'google-business',
        'resource_id' => 'google-business',
        'payload' => [
            'name' => 'Ollies Coffee',
            'rating' => 4.7,
            'reviewCount' => 42,
            'photos' => [
                ['ref' => 'places/X/photos/1', 'widthPx' => 1200, 'heightPx' => 800, 'authors' => ['Ada Contributor', 'Grace Contributor']],
                ['ref' => 'places/X/photos/2', 'widthPx' => 640, 'heightPx' => 480, 'authors' => ['Ada Contributor']],
            ],
        ],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);

    $response = $this->getJson('/api/public/profiles/allowgbphotos/integrations')->assertOk();
    $photos = $response->json('data.platforms.google-business.0.payload.photos');

    // The photo itself still renders — only the contributor identity is dropped.
    expect($photos)->toBe([
        ['ref' => 'places/X/photos/1', 'widthPx' => 1200, 'heightPx' => 800],
        ['ref' => 'places/X/photos/2', 'widthPx' => 640, 'heightPx' => 480],
    ]);

    // Belt-and-suspenders: the names must not ride anywhere else in the body.
    expect($response->getContent())->not->toContain('authors');
    expect($response->getContent())->not->toContain('Ada Contributor');
    expect($response->getContent())->not->toContain('Grace Contributor');
});
