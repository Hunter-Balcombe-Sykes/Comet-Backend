<?php

use App\Http\Resources\Platforms\PublicIntegrationConnectionResource;
use App\Models\Core\Site\IntegrationConnection;
use App\Models\Core\User\User;
use App\Services\Shop\ShopContentWriter;
use App\Services\Shop\StoreRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    // Slice 5a Task 8, fix round 1 (C2): the public shop payload is rebuilt
    // from content.* now, so every shop fixture below lands through
    // ShopContentWriter — the same lane addBrand()/setProducts() use.
    setupIngestTables();
    setupContentTables();
});

/**
 * Land a store + its products in content.*, the way the endpoints do.
 *
 * Took a site.shop_brands row and mirrored it across until that table was
 * dropped (20260819000210). It takes the StoreRecord directly now — content.*
 * is the only storage a store has, so there is nothing left to mirror FROM.
 */
function allowlistStore(StoreRecord $store, string $userId, array $products = []): void
{
    $writer = app(ShopContentWriter::class);
    $collectionId = $writer->upsertStore($store, $userId);
    if ($products !== []) {
        $writer->syncStore($userId, $collectionId, $products, $store->currency);
    }
}

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

// ── Slice 5b Task 8 (2026-08-13): the shop keys are RETIRED ───────────────
//
// The five shop cases below used to pin the brand/product allowlists. Those
// allowlists are gone: `shop` is dashboard-only on this wire now, and products
// reach the sitepage through `profile.pools.shop` (ShopPoolPayloadTest for the
// payload, PoolWireShapeTest for the #API-1 enforcement point that replaced
// SHOP_PRODUCT_ALLOWLIST). Each case KEEPS its original fixture — so it can
// never pass by publishing nothing at all — and asserts the retirement instead
// of the old shape. Deleting them would have deleted the guard.

it('publishes an empty payload for a shop connection — no brand fields at all', function () {
    $user = allowlistUser('allow2');

    // A store is a content.collections + content.storefronts pair (it was a
    // relational site.shop_brands row under FOUND-25 until that table was
    // dropped). The fields below used to be split into public
    // (name/discountCode/provider) and private
    // (source_url/fetch_mode/is_individual/position). None reach the wire now.
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'shopify.store',
        'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
    allowlistStore(new StoreRecord(
        externalRef: 'brand-123',
        provider: 'shopify',
        name: 'Example Shop',
        url: 'https://shop.example',
        sourceUrl: 'https://shop.example/internal-rescrape-input', // must stay private
        currency: 'AUD',
        discountCode: 'SAVE10',
        logoUrl: 'https://shop.example/logo.png',
        faviconUrl: 'https://shop.example/favicon.ico',
    ), (string) $user->id);

    $response = $this->getJson('/api/public/profiles/allow2/integrations')->assertOk();

    // The envelope survives (a consumer iterating `platforms` sees no shape
    // change) — only the payload emptied.
    $row = $response->json('data.platforms.shopify.0');
    expect($row)->toHaveKeys(['resourceId', 'payload', 'lastRefreshedAt'])
        ->and($row['payload'])->toBe([]);

    // The brand really did land in content.* — so the empty payload above is a
    // retirement, not an empty fixture. And nothing about it rides anywhere
    // else in the body, public field or private.
    expect(DB::table('content.storefronts')->count())->toBe(1);
    expect($response->getContent())->not->toContain('Example Shop');
    expect($response->getContent())->not->toContain('internal-rescrape-input');
    expect($response->getContent())->not->toContain('SAVE10');
    expect($response->getContent())->not->toContain('linkMode');
});

it('keeps each product gallery, per-variant image and variantId OFF the /integrations wire', function () {
    // #84: the pages side builds /cart/<variantId>:1 checkout links + swaps the
    // photo per variant. It still gets that raw material — from
    // `profile.pools.shop` now, where PoolResolver serves variants (label, sku,
    // imageUrl, availability, price) and a backend-composed outbound URL. See
    // ShopPoolPayloadTest. What this case pins is the other half: none of it
    // reaches the legacy wire any more.
    $user = allowlistUser('allowvimg');

    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shopify.store', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $store = new StoreRecord(
        externalRef: 'brand-x', provider: 'shopify',
        url: 'https://shop.example', discountCode: 'SAVE10', referralQuery: 'ref=abc',
    );
    // The exact shape ShopifyScraper::fetchProducts() produces, landed the way
    // setProducts() lands it.
    allowlistStore($store, (string) $user->id, [[
        'productId' => '111', 'title' => 'Wool Runner', 'handle' => 'wool-runner',
        'vendor' => 'Allbirds', 'image' => 'https://cdn.shopify.com/grey.jpg',
        'images' => ['https://cdn.shopify.com/grey.jpg', 'https://cdn.shopify.com/navy.jpg'],
        'price' => '95.00', 'currency' => 'USD', 'variantId' => '201', 'available' => true,
        'url' => 'https://shop.example/products/wool-runner', 'createdAt' => '2026-01-01T00:00:00Z',
        'variants' => [
            ['id' => '201', 'title' => 'Grey', 'price' => '95.00', 'available' => true, 'image' => 'https://cdn.shopify.com/grey.jpg'],
            ['id' => '202', 'title' => 'Navy', 'price' => '95.00', 'available' => true, 'image' => 'https://cdn.shopify.com/navy.jpg'],
        ],
    ]]);

    $response = $this->getJson('/api/public/profiles/allowvimg/integrations')->assertOk();

    expect($response->json('data.platforms.shopify.0.payload'))->toBe([]);

    // The product landed in content.* (so this can't pass on an empty
    // fixture), and none of the render material rides the legacy wire.
    expect(DB::table('content.f_catalog')->where('sku', '111')->exists())->toBeTrue();
    expect($response->getContent())->not->toContain('Wool Runner');
    expect($response->getContent())->not->toContain('cdn.shopify.com');
    expect($response->getContent())->not->toContain('variants');
    expect($response->getContent())->not->toContain('variantId');
});

// ── #API-1 on this wire, after the retirement ─────────────────────────────
//
// ShopProduct.data is raw scraper output. SHOP_PRODUCT_ALLOWLIST existed
// because the brand allowlist let `products` through WHOLE, and this response
// is CDN-cached for 15 minutes, so a leak is served to every visitor of that
// sitepage. Slice 5b did not weaken that: the enforcement point MOVED to
// PoolResolver::ITEM_KEYS / STORE_KEYS / VARIANT_KEYS (PoolWireShapeTest),
// which is strictly stronger — it reaches inside variant objects, which the
// deleted top-level array_intersect_key never did. Here, nothing ships at all.

it('publishes no shop product at all on the public wire, unvetted keys included (#API-1)', function () {
    $user = allowlistUser('allowprodfilter');

    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shopify.store', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $store = new StoreRecord(
        externalRef: 'brand-filter', provider: 'shopify',
        position: 0, url: 'https://shop.example',
    );
    // The legitimate ShopifyScraper shape PLUS three keys a future fetcher (or a
    // careless merge) might store. None are on SHOP_PRODUCT_ALLOWLIST.
    allowlistStore($store, (string) $user->id, [[
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
    ]]);

    $response = $this->getJson('/api/public/profiles/allowprodfilter/integrations')->assertOk();

    expect($response->json('data.platforms.shopify.0.payload'))->toBe([]);

    // Belt-and-suspenders (the fresha teamMenu idiom): prove the sensitive data
    // doesn't ride anywhere ELSE in the body, not merely inside one key.
    expect($response->getContent())->not->toContain('internalCostPrice');
    expect($response->getContent())->not->toContain('supplierId');
    expect($response->getContent())->not->toContain('SUP-9');
    expect($response->getContent())->not->toContain('12.50');
    expect($response->getContent())->not->toContain('__debug');
    // ...and the legitimate product too, which is what changed in slice 5b.
    expect($response->getContent())->not->toContain('Wool Runner');
    // Belt-and-braces on the new source of truth: the product really did land
    // in content.* (so the assertions above can't pass by publishing nothing),
    // and the unvetted keys never reached it either — ShopProductProjection
    // reads only the keys it knows, which makes the wire filter defence in
    // depth rather than the only gate.
    expect(DB::table('content.f_catalog')->where('sku', '111')->exists())->toBeTrue();
    expect(DB::table('content.f_catalog')->where('sku', '111')->value('vendor'))->toBe('Allbirds');
});

it('publishes not one former SHOP_PRODUCT_ALLOWLIST key on this wire (#API-1)', function () {
    // The other half of the old filter pinned that every allowlisted key still
    // SHIPPED, so a typo in the constant couldn't silently blank a field the
    // sitepage renders. That guard moved with the contract: ShopPoolPayloadTest
    // + PoolWireShapeTest now pin what the sitepage receives. Its inverse is
    // what belongs here — store all 14 scraper-emitted keys with distinct
    // non-null values and prove NONE of them reaches /integrations.
    $user = allowlistUser('allowprodkeys');

    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shopify.store', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $store = new StoreRecord(
        externalRef: 'brand-keys', provider: 'shopify',
        position: 0, url: 'https://shop.example',
    );
    allowlistStore($store, (string) $user->id, [[
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
    ]]);

    $response = $this->getJson('/api/public/profiles/allowprodkeys/integrations')->assertOk();

    expect($response->json('data.platforms.shopify.0.payload'))->toBe([]);

    // Every one of the 15 keys the old allowlist named is absent from the whole
    // body — asserted against the body text, not one JSON path, so a key
    // re-appearing under a differently-shaped payload would still fail.
    $body = $response->getContent();
    foreach ([
        'productId', 'title', 'handle', 'vendor', 'description',
        'images', 'currency', 'variantId', 'available',
        'createdAt', 'variants', 'popularityRank',
    ] as $retired) {
        expect($body)->not->toContain($retired);
    }
    // `price`, `image` and `url` are deliberately NOT in that loop: they are
    // ordinary key names on OTHER platforms' public payloads (google-business
    // priceLevel, the link platforms' url), so a substring check on them would
    // be a guard that fires for the wrong reason. Their VALUES cover them here.
    expect($body)->not->toContain('Wool Runner');
    expect($body)->not->toContain('Allbirds');
    expect($body)->not->toContain('95.00');
    expect($body)->not->toContain('shop.example');
    // The fixture is real: content.* holds the full product, so the assertions
    // above are proving a retirement rather than an empty database.
    expect(DB::table('content.f_catalog')->where('sku', '111')->value('vendor'))->toBe('Allbirds');
});

it('keeps the GLOBAL shop link mode off this wire — the pool composes the URL instead', function () {
    $user = allowlistUser('allowshop');
    // The global lives on site.sites.shop_link_mode (2026-07-08). It used to be
    // stamped onto every brand's public `linkMode` for the sitepage to build a
    // checkout deep link from. Slice 5b moved that job backend-side:
    // ShopOutboundUrl::compose() reads the same column and emits a finished
    // `url` on the pool item, so no link mode reaches any public consumer.
    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $user->id,
        'subdomain' => 'allowshop',
        'shop_link_mode' => 'checkout',
        // Explicit: a raw insert takes the SQLite DDL default (0), and the
        // public read paths now 404 a claimed owner's unpublished site.
        'is_published' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id, 'platform' => 'shopify.store', 'resource_id' => 'shop',
        'payload' => ['storage' => 'relational'], 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    // Two stores under that global. They carried a per-brand `link_mode` column
    // when this case was written, set to 'product' so the global overriding it
    // to 'checkout' was visible; content.storefronts has no such column (slice
    // 5a fix round 1, Finding 4 — link_mode was already one global setting in
    // practice), so the site-level 'checkout' above is the only link mode in
    // play. It is still the one that must not reach this wire: brandMap()
    // stamps it onto every DASHBOARD brand from site.sites.shop_link_mode, so
    // a resource that published the brand map here would leak it.
    allowlistStore(new StoreRecord(
        externalRef: 'b1', provider: 'shopify', position: 0, url: 'https://b1.example',
    ), (string) $user->id);
    allowlistStore(new StoreRecord(
        externalRef: 'b2', provider: 'woocommerce', position: 1, url: 'https://b2.example',
    ), (string) $user->id);

    $response = $this->getJson('/api/public/profiles/allowshop/integrations')->assertOk();

    expect($response->json('data.platforms.shopify.0.payload'))->toBe([]);
    expect($response->getContent())->not->toContain('linkMode');
    expect($response->getContent())->not->toContain('checkout');
    // Both stores really exist — the empty payload is the retirement, not an
    // unwritten fixture.
    expect(DB::table('content.storefronts')->count())->toBe(2);
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
            'highlights' => [['itemId' => '1', 'name' => 'Stale Featured Leftover']],
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
    // Featured is gone (2026-08-06): a stale stored highlights key never
    // reaches the public wire.
    expect($platforms['vimeo'][0]['payload'])->not->toHaveKey('highlights');
});

it('publishes nothing for a retired events row, ACCOUNT or STANDALONE', function () {
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

    // Slice 2 Task 9 retired the ACCOUNT half of this lane; slice 7 Phase 4
    // retired the STANDALONE half. Both kinds now publish `[]`, and every
    // event reaches the wire through profile.pools.events instead — the
    // standalone ones because StandaloneEventBackfiller carried them onto
    // content.* and the standalone-event lane lands new ones there.
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

    expect($platforms['eventbrite'][0]['payload'])->toBe([]);
    // Was private before the retirement and stays private after it — the two
    // are different mechanisms and only one is doing the work now.
    expect($platforms['eventbrite'][0]['payload'])->not->toHaveKey('hiddenEventIds');

    // The standalone row publishes nothing either, as of slice 7 Phase 4 —
    // every one of its seventeen enriched top-level keys is gone from this
    // surface, not a filtered subset of them.
    expect($platforms['humanitix'][0]['payload'])->toBe([]);
});

it('keeps the stored bandcamp releases grid and stale highlights off the public wire', function () {
    // Which releases appear publicly is the Listen pool's selection now
    // (platforms-as-sources P4): the stored scrape keeps `releases` for
    // internal use, and older rows may still carry a `highlights` leftover —
    // neither is served, with or without any display_settings stored.
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

    $plain = allowlistUser('allowbc1');
    IntegrationConnection::create([
        'user_id' => $plain->id, 'platform' => 'bandcamp', 'resource_id' => 'bandcamp',
        'payload' => $payload, 'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $served = $this->getJson('/api/public/profiles/allowbc1/integrations')->assertOk()->json('data.platforms.bandcamp.0.payload');
    expect($served)->not->toHaveKey('releases');
    expect($served)->not->toHaveKey('highlights');
    expect($served['latest']['name'])->toBe('Latest Album');

    // A legacy stored opt-in changes nothing — the toggle is gone.
    $legacy = allowlistUser('allowbc2');
    IntegrationConnection::create([
        'user_id' => $legacy->id, 'platform' => 'bandcamp', 'resource_id' => 'bandcamp',
        'payload' => $payload, 'display_settings' => ['show_all_releases' => true],
        'is_active' => true, 'last_refresh_status' => 'ok',
    ]);
    $servedLegacy = $this->getJson('/api/public/profiles/allowbc2/integrations')->assertOk()->json('data.platforms.bandcamp.0.payload');
    expect($servedLegacy)->not->toHaveKey('releases');
    expect($servedLegacy)->not->toHaveKey('highlights');
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
        'platform' => 'sevenrooms.reserve',
        'resource_id' => 'reservations',
        'payload' => ['provider' => 'custom', 'url' => 'https://example.com/book', 'name' => 'Book', 'source' => 'manual'],
        'is_active' => true,
        'last_refresh_status' => 'ok',
    ]);
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'booksy.book',
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
    //
    // Convergence Phase 6: these cards sit on their BRAND surfaces, so they are
    // grouped under the brand key and take the brand allowlist — which carries
    // `name`, where the retired shared keys did not. That is the point of the
    // brand entries: the sitepage can label the card with the provider's name.
    expect($platforms['sevenrooms'][0]['payload'])
        ->toBe(['provider' => 'custom', 'url' => 'https://example.com/book', 'name' => 'Book']);
    expect($platforms['booksy'][0]['payload'])
        ->toBe(['provider' => 'custom', 'url' => 'https://example.com/appt', 'name' => 'Appointments']);
    // `source` was in both stored payloads and must NOT ship — the allowlist,
    // not the query, is the exposure gate. `name` DOES ship now: these cards
    // moved onto brand surfaces, whose allowlist entries carry it so the
    // sitepage can label the card with the provider's name (see above).
    expect($platforms['booksy'][0]['payload'])->not->toHaveKey('source');
    expect($platforms['sevenrooms'][0]['payload'])->not->toHaveKey('source');
    // The public platform still ships untouched.
    expect($platforms['facebook'][0]['payload']['url'])->toBe('https://facebook.com/me');
});

it('exposes ordering entries publicly under their BRAND key with only url/name/favicon/logo/provider — id/source/data stay private', function () {
    $user = allowlistUser('allow-ordering');

    // One row per store link (OnlineOrderingController::addEntry's real
    // storage shape — resource_id 'order-<hash>', flat CardPayload).
    IntegrationConnection::create([
        'user_id' => $user->id,
        'platform' => 'uber_eats.order',
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
        // Convergence Phase 6: an ordering row is grouped under its brand
        // ('uber_eats'), not the retired 'online-ordering' pseudo-key. The
        // key SET is unchanged — the brand entries were added to ALLOWLIST
        // with exactly the shape the shared key had.
        ->json('data.platforms.uber_eats.0.payload');

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
    // brief). Prove today's ['url'] entry actually holds the line.
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
