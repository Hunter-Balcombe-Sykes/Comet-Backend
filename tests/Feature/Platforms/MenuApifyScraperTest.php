<?php

use App\Services\Platforms\MenuApifyScraper;
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
    expect($item['image'])->toBe('https://tb-static.uber.com/x.jpeg');
    expect($item['rating'])->toBeNull();                                  // UE has no per-item rating
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

// ── fetchStore (per-mode price fusion) ────────────────────────────────

it('fuses the pickup and delivery scrapes into per-mode prices keyed by external id', function () {
    // Same store, same dish (uuid i1), different price per mode — delivery is
    // marked up. fetchStore scrapes pickup then delivery and fuses by uuid.
    Http::fake(['api.apify.com/*' => Http::sequence()
        ->push([['currencyCode' => 'AUD', 'menuItems' => [['name' => 'Burrito', 'section' => 'Cat', 'price' => 15.0]]]], 201)
        ->push([['currencyCode' => 'AUD', 'menuItems' => [['name' => 'Burrito', 'section' => 'Cat', 'price' => 17.0]]]], 201),
    ]);

    $link = [
        'pickupUrl' => 'https://www.ubereats.com/au/store/x?diningMode=PICKUP',
        'deliveryUrl' => 'https://www.ubereats.com/au/store/x?diningMode=DELIVERY',
        'storeUrl' => 'https://www.ubereats.com/au/store/x',
        'modes' => ['pickup', 'delivery'],
    ];
    $menu = app(MenuApifyScraper::class)->fetchStore($link, 'uber-eats', 'u1');

    $item = $menu['categories'][0]['items'][0];
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

    $link = ['pickupUrl' => null, 'deliveryUrl' => null, 'storeUrl' => 'https://www.ubereats.com/au/store/x', 'modes' => ['pickup', 'delivery']];
    $menu = app(MenuApifyScraper::class)->fetchStore($link, 'uber-eats', 'u1');

    $item = $menu['categories'][0]['items'][0];
    expect($item['pickupPrice'])->toBe(12.0);
    expect($item['deliveryPrice'])->toBe(12.0);
    Http::assertSentCount(1);   // one scrape, reused for both modes
});

it('returns only the scraped mode price when the other mode scrape is blocked', function () {
    // pickup scrape keeps coming back empty (all 4 attempts), delivery succeeds.
    Http::fake(['api.apify.com/*' => Http::sequence()
        ->push([], 201)->push([], 201)->push([], 201)->push([], 201)   // pickup: 4 empty
        ->push([['currencyCode' => 'AUD', 'menuItems' => [['name' => 'Combo', 'section' => 'Cat', 'price' => 20.0]]]], 201),
    ]);

    $link = [
        'pickupUrl' => 'https://www.ubereats.com/au/store/x?diningMode=PICKUP',
        'deliveryUrl' => 'https://www.ubereats.com/au/store/x?diningMode=DELIVERY',
        'storeUrl' => 'https://www.ubereats.com/au/store/x',
        'modes' => ['pickup', 'delivery'],
    ];
    $menu = app(MenuApifyScraper::class)->fetchStore($link, 'uber-eats', 'u1');

    $item = $menu['categories'][0]['items'][0];
    expect($item['pickupPrice'])->toBeNull();     // pickup scrape blocked
    expect($item['deliveryPrice'])->toBe(20.0);
});
