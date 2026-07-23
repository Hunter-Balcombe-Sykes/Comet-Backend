<?php

use App\Services\Cache\ApifyBudget;
use App\Services\Cache\CacheKeyGenerator;
use App\Services\Platforms\MenuApifyScraper;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

// Fixtures mirror the REAL actor output:
//   Uber Eats → memo23~uber-eats-scraper (flattened menuItems with name /
//               description / section / price / imageUrl).
//   DoorDash  → dz_omar~doordash-scraper (menu_categories[]→items[] with
//               item_id / price_cents / image_url / rating_pct / badges).
// fetch() returns the normalized { store, categories:[{ name, items }] } shape
// that MenuMerger consumes.

beforeEach(fn () => config(['services.apify.token' => 'apify-test-token']));

it('maps a memo23 Uber Eats store into the normalized shape', function () {
    Http::fake(['api.apify.com/*' => Http::response([[
        'title' => 'Guzman y Gomez',
        'shopName' => 'guzman-y-gomez',
        'ratingValue' => 4.6,
        'reviewCount' => 1200,
        'currencyCode' => 'AUD',
        'image' => 'https://tb-static.uber.com/logo.png',
        'supportedDiningModes' => [['mode' => 'DELIVERY', 'isAvailable' => true], ['mode' => 'PICKUP', 'isAvailable' => true]],
        // memo23 returns a flattened list; each item names its own section.
        'menuItems' => [
            ['name' => 'Burritos', 'description' => 'Rice, jack cheese, beans.', 'section' => 'Featured items', 'price' => 17.8, 'currency' => 'AUD', 'imageUrl' => 'https://tb-static.uber.com/x.jpeg'],
            ['name' => 'Nachos', 'description' => 'Corn chips + cheese.', 'section' => 'Sides', 'price' => 9.0, 'currency' => 'AUD', 'imageUrl' => null],
        ],
    ]], 201)]);

    $menu = app(MenuApifyScraper::class)->fetch('https://www.ubereats.com/au/store/x/abc', 'uber-eats', 'u1');

    expect($menu)->not->toBeNull();
    expect($menu['store']['name'])->toBe('Guzman y Gomez');               // from title
    expect($menu['store']['rating'])->toBe(4.6);                          // ratingValue
    expect($menu['store']['reviewCount'])->toBe(1200);
    expect($menu['store']['currency'])->toBe('AUD');
    expect($menu['store']['logo'])->toBe('https://tb-static.uber.com/logo.png'); // image
    // Items grouped by section into categories, first-seen order.
    expect($menu['categories'])->toHaveCount(2);
    expect($menu['categories'][0]['name'])->toBe('Featured items');
    expect($menu['categories'][1]['name'])->toBe('Sides');
    $item = $menu['categories'][0]['items'][0];
    expect($item['externalId'])->toBeNull();                              // flattened list has no id
    expect($item['name'])->toBe('Burritos');
    expect($item['price'])->toBe(17.8);                                   // already dollars
    expect($item['currency'])->toBe('AUD');                               // per-item currency (UE only)
    expect($item['image'])->toBe('https://tb-static.uber.com/x.jpeg');
    expect($item['rating'])->toBeNull();                                  // UE has no per-item rating
    // Store-level supported dining modes, normalized from [{mode, isAvailable}]
    // to the available mode-name strings only.
    expect($menu['store']['diningModes'])->toBe(['DELIVERY', 'PICKUP']);
});

it('normalizes supportedDiningModes to only the available mode names', function () {
    Http::fake(['api.apify.com/*' => Http::response([[
        'title' => 'X',
        'currencyCode' => 'AUD',
        'supportedDiningModes' => [
            ['mode' => 'DELIVERY', 'isAvailable' => true],
            ['mode' => 'DINE_IN', 'isAvailable' => false],
        ],
        'menuItems' => [['name' => 'Item', 'section' => 'Cat', 'price' => 5.0]],
    ]], 201)]);

    $menu = app(MenuApifyScraper::class)->fetch('https://www.ubereats.com/au/store/x', 'uber-eats', 'u1');

    expect($menu['store']['diningModes'])->toBe(['DELIVERY']);
});

it('yields a null diningModes when supportedDiningModes is absent', function () {
    Http::fake(['api.apify.com/*' => Http::response([[
        'currencyCode' => 'AUD',
        'menuItems' => [['name' => 'Item', 'section' => 'Cat', 'price' => 5.0]],
    ]], 201)]);

    $menu = app(MenuApifyScraper::class)->fetch('https://www.ubereats.com/au/store/x', 'uber-eats', 'u1');

    expect($menu['store']['diningModes'])->toBeNull();
});

it('maps a dz_omar DoorDash store into the normalized shape', function () {
    Http::fake(['api.apify.com/*' => Http::response([[
        'name' => 'Diamond Indian Cuisine',
        'rating' => 3.7,
        'num_ratings' => '38',
        'currency' => 'AUD',
        'cover_square_image' => 'https://img.cdn4dd.com/cover.jpg',
        'menu_categories' => [[
            'category_name' => 'Mains',
            'items' => [[
                'item_id' => '94474277',
                'name' => 'Plain Rice',
                'description' => 'Steamed basmati rice.',
                'price_cents' => 400,
                'price_display' => 'A$4.00',
                'image_url' => 'https://img.cdn4dd.com/rice.jpg',
                'rating_pct' => 95,
                'rating_count' => '213',
                'badges' => [['text' => '#1 Most liked', 'type' => 'popular']],
            ]],
        ]],
        // featured_items re-lists category items — must be ignored, not duplicated.
        'featured_items' => ['items' => [['item_id' => 'z', 'name' => 'DUP', 'price_cents' => 100]]],
    ]], 201)]);

    $menu = app(MenuApifyScraper::class)->fetch('https://www.doordash.com/store/x', 'doordash', 'u1', 'Melbourne VIC, Australia');

    expect($menu)->not->toBeNull();
    expect($menu['store']['name'])->toBe('Diamond Indian Cuisine');
    expect($menu['store']['rating'])->toBe(3.7);
    expect($menu['store']['reviewCount'])->toBe(38);                      // num_ratings "38"
    expect($menu['store']['logo'])->toBe('https://img.cdn4dd.com/cover.jpg');
    expect($menu['categories'])->toHaveCount(1);                          // featured_items ignored
    expect($menu['categories'][0]['name'])->toBe('Mains');
    $item = $menu['categories'][0]['items'][0];
    expect($item['externalId'])->toBe('94474277');
    expect($item['name'])->toBe('Plain Rice');
    expect($item['price'])->toBe(4.0);                                    // price_cents / 100
    expect($item['image'])->toBe('https://img.cdn4dd.com/rice.jpg');
    expect($item['rating'])->toBe(95.0);
    expect($item['ratingCount'])->toBe(213);
    expect($item['badges'][0])->toMatchArray(['text' => '#1 Most liked', 'type' => 'popular']);
    // DoorDash's actor carries no per-item currency or store dining-mode field.
    expect($item['currency'] ?? null)->toBeNull();
    expect($menu['store']['diningModes'])->toBeNull();
});

it('sends DoorDash the startUrls + address input shape', function () {
    Http::fake(['api.apify.com/*' => Http::response([[
        'name' => 'X', 'currency' => 'AUD',
        'menu_categories' => [['category_name' => 'C', 'items' => [['item_id' => '1', 'name' => 'I', 'price_cents' => 500]]]],
    ]], 201)]);

    app(MenuApifyScraper::class)->fetch('https://www.doordash.com/store/x', 'doordash', 'u1', '5 King St, Sydney');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'dz_omar~doordash-scraper')
        && $request['startUrls'] === [['url' => 'https://www.doordash.com/store/x']]
        && $request['address'] === '5 King St, Sydney');
});

it('retries an empty Apify result, then succeeds', function () {
    Http::fake(['api.apify.com/*' => Http::sequence()
        ->push([], 201)
        ->push([[
            'currencyCode' => 'AUD',
            'menuItems' => [['name' => 'Item', 'section' => 'Cat', 'price' => 5.0]],
        ]], 201),
    ]);

    $menu = app(MenuApifyScraper::class)->fetch('https://www.ubereats.com/au/store/x', 'uber-eats', 'u1');

    expect($menu)->not->toBeNull();
    expect($menu['categories'][0]['items'][0]['name'])->toBe('Item');
    Http::assertSentCount(2);
});

it('does not retry a 4xx hard error', function () {
    Http::fake(['api.apify.com/*' => Http::response('Actor not rented', 403)]);

    $menu = app(MenuApifyScraper::class)->fetch('https://www.ubereats.com/au/store/x', 'uber-eats', 'u1');

    expect($menu)->toBeNull();
    Http::assertSentCount(1);
});

// ── fetchStores (concurrent per-mode + per-platform fusion) ────────────

it('fuses the concurrent pickup and delivery scrapes into per-mode prices', function () {
    // Same store, different price per mode (delivery marked up). The pool fires both
    // mode URLs at once; the closure returns each mode's price by its diningMode.
    Http::fake(function ($request) {
        $url = (string) data_get($request->data(), 'startUrls.0.url', '');
        $price = str_contains($url, 'PICKUP') ? 15.0 : 17.0;

        return Http::response([['currencyCode' => 'AUD', 'menuItems' => [['name' => 'Burrito', 'section' => 'Cat', 'price' => $price]]]], 201);
    });

    $links = ['uber-eats' => [
        'pickupUrl' => 'https://www.ubereats.com/au/store/x?diningMode=PICKUP',
        'deliveryUrl' => 'https://www.ubereats.com/au/store/x?diningMode=DELIVERY',
        'storeUrl' => 'https://www.ubereats.com/au/store/x',
        'modes' => ['pickup', 'delivery'],
    ]];
    $item = app(MenuApifyScraper::class)->fetchStores($links, 'u1')['uber-eats']['categories'][0]['items'][0];

    expect($item['name'])->toBe('Burrito');
    expect($item['pickupPrice'])->toBe(15.0);
    expect($item['deliveryPrice'])->toBe(17.0);
    expect($item)->not->toHaveKey('price');   // raw single price dropped
    Http::assertSentCount(2);
});

it('applies a single scraped price to both modes for an untyped store', function () {
    Http::fake(['api.apify.com/*' => Http::response([['currencyCode' => 'AUD', 'menuItems' => [
        ['name' => 'Combo', 'section' => 'Cat', 'price' => 12.0],
    ]]], 201)]);

    $links = ['uber-eats' => ['pickupUrl' => null, 'deliveryUrl' => null, 'storeUrl' => 'https://www.ubereats.com/au/store/x', 'modes' => ['pickup', 'delivery']]];
    $item = app(MenuApifyScraper::class)->fetchStores($links, 'u1')['uber-eats']['categories'][0]['items'][0];

    expect($item['pickupPrice'])->toBe(12.0);
    expect($item['deliveryPrice'])->toBe(12.0);
    Http::assertSentCount(1);   // one scrape, reused for both modes
});

it('falls back to a sequential retry when the concurrent attempt for a mode is empty', function () {
    // Pickup always empty (pool miss → sequential fallback also empty); delivery ok.
    Http::fake(function ($request) {
        $url = (string) data_get($request->data(), 'startUrls.0.url', '');
        if (str_contains($url, 'PICKUP')) {
            return Http::response([], 201);
        }

        return Http::response([['currencyCode' => 'AUD', 'menuItems' => [['name' => 'Combo', 'section' => 'Cat', 'price' => 20.0]]]], 201);
    });

    $links = ['uber-eats' => [
        'pickupUrl' => 'https://www.ubereats.com/au/store/x?diningMode=PICKUP',
        'deliveryUrl' => 'https://www.ubereats.com/au/store/x?diningMode=DELIVERY',
        'storeUrl' => 'https://www.ubereats.com/au/store/x',
        'modes' => ['pickup', 'delivery'],
    ]];
    $item = app(MenuApifyScraper::class)->fetchStores($links, 'u1')['uber-eats']['categories'][0]['items'][0];

    expect($item['pickupPrice'])->toBeNull();     // pickup never resolved
    expect($item['deliveryPrice'])->toBe(20.0);
    // R4-RES-1 regression pin: 2 pooled + FALLBACK_ATTEMPTS(2) fallback calls for
    // pickup = 4. Pre-fix this fell back to fetch()'s own MAX_ATTEMPTS=4 loop and
    // sent 6 — this is the exact assertion that was missing when the bug shipped.
    Http::assertSentCount(4);
});

it('scrapes both platforms concurrently and returns a fused menu per platform', function () {
    // memo23 (UE) and dz_omar (DD) fire in the same pool; route by actor in the URL.
    Http::fake(function ($request) {
        $isUe = str_contains($request->url(), 'memo23');

        return Http::response($isUe
            ? [['currencyCode' => 'AUD', 'menuItems' => [['name' => 'Burrito', 'section' => 'Mains', 'price' => 17.0]]]]
            : [['name' => 'Ollies', 'currency' => 'AUD', 'menu_categories' => [['category_name' => 'Sweets', 'items' => [['item_id' => 'd1', 'name' => 'Churros', 'price_cents' => 800]]]]]],
            201);
    });

    $links = [
        'uber-eats' => ['pickupUrl' => null, 'deliveryUrl' => 'https://ue/s?diningMode=DELIVERY', 'storeUrl' => 'https://ue/s', 'modes' => ['delivery']],
        'doordash' => ['pickupUrl' => 'https://dd/s?pickup=true', 'deliveryUrl' => null, 'storeUrl' => 'https://dd/s', 'modes' => ['pickup']],
    ];
    $menus = app(MenuApifyScraper::class)->fetchStores($links, 'u1', 'Melbourne VIC');

    expect($menus)->toHaveKeys(['uber-eats', 'doordash']);
    expect($menus['uber-eats']['categories'][0]['items'][0]['deliveryPrice'])->toBe(17.0);
    expect($menus['doordash']['categories'][0]['items'][0]['pickupPrice'])->toBe(8.0);   // price_cents / 100
});

// ── ApifyBudget gate (SCALE-2) ──────────────────────────────────────────────

it('fetch() skips and sends no HTTP when the menu budget is exhausted', function () {
    config()->set('services.apify.token', 'test-token');
    config()->set('partna.limits.apify.actors.menu', 0);
    Http::fake();

    $result = app(MenuApifyScraper::class)->fetch('https://ubereats.com/store/x', 'uber-eats', 'user-1');

    expect($result)->toBeNull();
    Http::assertNothingSent();
});

it('fetchStores() scrapes no target when the menu budget is exhausted', function () {
    config()->set('services.apify.token', 'test-token');
    config()->set('partna.limits.apify.actors.menu', 0);
    Http::fake();

    $result = app(MenuApifyScraper::class)->fetchStores(
        ['uber-eats' => ['pickupUrl' => 'https://ubereats.com/store/x', 'deliveryUrl' => null, 'storeUrl' => null, 'modes' => ['pickup']]],
        'user-1',
    );

    expect($result)->toBe([]);
    Http::assertNothingSent();
});

// ── R4-RES-1: claims == calls invariant ─────────────────────────────────────

it('fetch() claims exactly one budget slot per billed call, at the cap', function () {
    config()->set('services.apify.token', 'test-token');
    config()->set('partna.limits.apify.actors.menu', 4); // == MAX_ATTEMPTS
    Http::fake(['api.apify.com/*' => Http::response([], 201)]); // always empty => always retryable

    $menu = app(MenuApifyScraper::class)->fetch('https://ubereats.com/store/x', 'uber-eats', 'user-1');

    expect($menu)->toBeNull();
    Http::assertSentCount(4);
    expect(app(ApifyBudget::class)->remaining('menu'))->toBe(0);
});

it('fetch() stops issuing calls the moment the cap is one short of MAX_ATTEMPTS', function () {
    config()->set('services.apify.token', 'test-token');
    config()->set('partna.limits.apify.actors.menu', 3); // MAX_ATTEMPTS - 1
    Http::fake(['api.apify.com/*' => Http::response([], 201)]);

    $menu = app(MenuApifyScraper::class)->fetch('https://ubereats.com/store/x', 'uber-eats', 'user-1');

    expect($menu)->toBeNull();
    // Proves the claim binds at the CALL, not the logical operation: under the
    // pre-fix code the cap could sit at 3 and still see 4 calls go out.
    Http::assertSentCount(3);
    expect(app(ApifyBudget::class)->remaining('menu'))->toBe(0);
});

it('fetchStores() 4-target worst case claims exactly 12 for 12 real calls', function () {
    config()->set('services.apify.token', 'test-token');
    config()->set('partna.limits.apify.actors.menu', 12); // 4 targets × (1 pooled + FALLBACK_ATTEMPTS)
    Http::fake(['api.apify.com/*' => Http::response([], 201)]); // always empty => always retryable, never mapped

    $links = [
        'uber-eats' => ['pickupUrl' => 'https://ue/p', 'deliveryUrl' => 'https://ue/d', 'storeUrl' => null, 'modes' => ['pickup', 'delivery']],
        'doordash' => ['pickupUrl' => 'https://dd/p', 'deliveryUrl' => 'https://dd/d', 'storeUrl' => null, 'modes' => ['pickup', 'delivery']],
    ];

    $result = app(MenuApifyScraper::class)->fetchStores($links, 'user-1');

    expect($result)->toBe(['uber-eats' => null, 'doordash' => null]);
    Http::assertSentCount(12);
    expect(app(ApifyBudget::class)->remaining('menu'))->toBe(0);
});

it('exhausts the budget gracefully mid-ladder — no fallback call, no exception', function () {
    config()->set('services.apify.token', 'test-token');
    config()->set('partna.limits.apify.actors.menu', 2); // exactly enough for the 2 pooled calls, none left for fallback
    Http::fake(['api.apify.com/*' => Http::response([], 201)]); // always empty => always retryable

    $links = [
        'uber-eats' => ['pickupUrl' => null, 'deliveryUrl' => null, 'storeUrl' => 'https://ue/s', 'modes' => ['pickup', 'delivery']],
        'doordash' => ['pickupUrl' => null, 'deliveryUrl' => null, 'storeUrl' => 'https://dd/s', 'modes' => ['pickup', 'delivery']],
    ];

    $result = app(MenuApifyScraper::class)->fetchStores($links, 'user-1');

    // Normal per-platform shape preserved — null for each unresolved platform,
    // not an exception and not a partial/malformed structure.
    expect($result)->toBe(['uber-eats' => null, 'doordash' => null]);
    Http::assertSentCount(2);
});

// ── R4-RES-1: per-target negative cache ─────────────────────────────────────

it('writes a negative-cache marker for a target that never resolves', function () {
    config()->set('services.apify.token', 'test-token');
    Http::fake(['api.apify.com/*' => Http::response([], 201)]);

    $url = 'https://ubereats.com/store/x';
    app(MenuApifyScraper::class)->fetchStores(
        ['uber-eats' => ['pickupUrl' => null, 'deliveryUrl' => null, 'storeUrl' => $url, 'modes' => ['pickup', 'delivery']]],
        'user-1',
    );

    $key = CacheKeyGenerator::menuScrapeBlocked('uber-eats', $url);
    expect(Cache::has($key))->toBeTrue();
    expect(Cache::get($key)['reason'])->toBe('blocked');
});

it('a pre-seeded negative-cache marker suppresses the call AND the claim', function () {
    config()->set('services.apify.token', 'test-token');
    $url = 'https://ubereats.com/store/x';
    Cache::put(CacheKeyGenerator::menuScrapeBlocked('uber-eats', $url), ['at' => now()->toIso8601String(), 'reason' => 'blocked'], 600);
    Http::fake();

    $before = app(ApifyBudget::class)->remaining('menu');
    $result = app(MenuApifyScraper::class)->fetchStores(
        ['uber-eats' => ['pickupUrl' => null, 'deliveryUrl' => null, 'storeUrl' => $url, 'modes' => ['pickup', 'delivery']]],
        'user-1',
    );
    $after = app(ApifyBudget::class)->remaining('menu');

    // Matches the existing budget-exhausted contract (MenuApifyScraperTest's
    // "scrapes no target" case): every target suppressed => [] , not a null map.
    expect($result)->toBe([]);
    Http::assertNothingSent();
    // The negative-cache check runs BEFORE the budget claim, so a suppressed
    // target must not move the counter either.
    expect($after)->toBe($before);
});

it('a block on the pickup URL does not suppress the delivery URL of the same store', function () {
    config()->set('services.apify.token', 'test-token');
    $pickupUrl = 'https://ubereats.com/store/x?diningMode=PICKUP';
    $deliveryUrl = 'https://ubereats.com/store/x?diningMode=DELIVERY';
    Cache::put(CacheKeyGenerator::menuScrapeBlocked('uber-eats', $pickupUrl), ['at' => now()->toIso8601String(), 'reason' => 'blocked'], 600);
    Http::fake(['api.apify.com/*' => Http::response([['currencyCode' => 'AUD', 'menuItems' => [['name' => 'Burrito', 'section' => 'Cat', 'price' => 17.0]]]], 201)]);

    $item = app(MenuApifyScraper::class)->fetchStores(
        ['uber-eats' => ['pickupUrl' => $pickupUrl, 'deliveryUrl' => $deliveryUrl, 'storeUrl' => null, 'modes' => ['pickup', 'delivery']]],
        'user-1',
    )['uber-eats']['categories'][0]['items'][0];

    expect($item['pickupPrice'])->toBeNull();
    expect($item['deliveryPrice'])->toBe(17.0);
    Http::assertSentCount(1); // only delivery — pickup suppressed by its own key
});

it('a target recovers once the negative-cache TTL has passed, leaving no stale marker', function () {
    config()->set('services.apify.token', 'test-token');
    $url = 'https://ubereats.com/store/x';
    $key = CacheKeyGenerator::menuScrapeBlocked('uber-eats', $url);
    Cache::put($key, ['at' => now()->toIso8601String(), 'reason' => 'blocked'], 600);

    $this->travel(601)->seconds(); // past the TTL — the read gate no longer suppresses this target
    Http::fake(['api.apify.com/*' => Http::response([['currencyCode' => 'AUD', 'menuItems' => [['name' => 'Combo', 'section' => 'Cat', 'price' => 5.0]]]], 201)]);

    $result = app(MenuApifyScraper::class)->fetchStores(
        ['uber-eats' => ['pickupUrl' => null, 'deliveryUrl' => null, 'storeUrl' => $url, 'modes' => ['pickup', 'delivery']]],
        'user-1',
    );

    expect($result['uber-eats'])->not->toBeNull();
    Http::assertSentCount(1);
    expect(Cache::has($key))->toBeFalse(); // recovered scrape clears the marker rather than leaving it stale
});

it('honours partna.menu.blocked_ttl_seconds from config', function () {
    config()->set('services.apify.token', 'test-token');
    config()->set('partna.menu.blocked_ttl_seconds', 30);
    Http::fake(['api.apify.com/*' => Http::response([], 201)]);

    app(MenuApifyScraper::class)->fetchStores(
        ['uber-eats' => ['pickupUrl' => null, 'deliveryUrl' => null, 'storeUrl' => 'https://ubereats.com/store/x', 'modes' => ['pickup', 'delivery']]],
        'user-1',
    );

    $key = CacheKeyGenerator::menuScrapeBlocked('uber-eats', 'https://ubereats.com/store/x');
    expect(Cache::has($key))->toBeTrue();

    $this->travel(31)->seconds();
    expect(Cache::has($key))->toBeFalse();
});
