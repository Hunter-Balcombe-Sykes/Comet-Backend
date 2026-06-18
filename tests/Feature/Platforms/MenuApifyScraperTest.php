<?php

use App\Services\Platforms\MenuApifyScraper;
use Illuminate\Support\Facades\Http;

// Fixtures below mirror the REAL actor output captured live on 2026-06-18:
//   Uber Eats  → natanielsantos~uber-eats-scraper  (sections→subsections→items)
//   DoorDash   → crawlerbros~doordash-restaurant-scraper (flat menuItems[])

beforeEach(fn () => config(['services.apify.token' => 'apify-test-token']));

it('maps a natanielsantos Uber Eats restaurant into our menu shape', function () {
    Http::fake(['api.apify.com/*' => Http::response([[
        'name' => 'Guzman y Gomez',
        'rating' => 4.6,
        'reviewCount' => 1200,
        'currencyCode' => 'AUD',
        'categories' => ['Mexican'],            // cuisine tags — NOT menu sections
        'sections' => [[
            'uuid' => 's1',
            'title' => 'Main-Menu',
            'subsections' => [[
                'uuid' => 'ss1',
                'title' => 'Featured items',
                'items' => [[
                    'title' => 'Burritos',
                    'description' => 'Rice, jack cheese, beans, salsa.',
                    'price' => 17.8,
                    'priceString' => '$17.80',
                    'imageUrl' => 'https://tb-static.uber.com/x.jpeg',
                    'isSoldOut' => false,
                    'uuid' => 'i1',
                ]],
            ]],
        ]],
    ]], 201)]);

    $menu = app(MenuApifyScraper::class)->fetch('https://www.ubereats.com/au/store/x/abc', 'uber-eats', 'u1');

    expect($menu)->not->toBeNull();
    expect($menu['rating'])->toBe(4.6);
    expect($menu['reviewCount'])->toBe(1200);
    expect($menu['currency'])->toBe('AUD');
    expect($menu['categories'])->toHaveCount(1);
    expect($menu['categories'][0]['name'])->toBe('Featured items');           // subsection = category
    $item = $menu['categories'][0]['items'][0];
    expect($item['name'])->toBe('Burritos');
    expect($item['price'])->toBe(17.8);                                        // already dollars, no /100
    expect($item['image'])->toBe('https://tb-static.uber.com/x.jpeg');
    expect($item['description'])->toContain('Rice');
});

it('maps a crawlerbros DoorDash store into our menu shape', function () {
    Http::fake(['api.apify.com/*' => Http::response([[
        'storeName' => 'Guzman y Gomez',
        'menuSections' => ['Mains', 'Sides'],
        'menuItemCount' => 2,
        'menuItems' => [
            ['section' => 'Mains', 'name' => 'Big Brekkie Burritos', 'description' => 'Big Brekkie Burritos', 'price' => '$15.30'],
            ['section' => 'Sides', 'name' => 'Chips', 'description' => 'Crispy', 'price' => '$5.00'],
        ],
    ]], 201)]);

    $menu = app(MenuApifyScraper::class)->fetch('https://www.doordash.com/en-AU/store/x', 'doordash', 'u1');

    expect($menu)->not->toBeNull();
    expect($menu['rating'])->toBeNull();
    expect($menu['categories'])->toHaveCount(2);
    expect($menu['categories'][0]['name'])->toBe('Mains');
    expect($menu['categories'][0]['items'][0]['name'])->toBe('Big Brekkie Burritos');
    expect($menu['categories'][0]['items'][0]['price'])->toBe(15.3);          // "$15.30" → 15.3
    expect($menu['categories'][1]['name'])->toBe('Sides');
    expect($menu['categories'][1]['items'][0]['price'])->toBe(5.0);
});

it('retries an empty Apify result, then succeeds', function () {
    Http::fake(['api.apify.com/*' => Http::sequence()
        ->push([], 201)                            // empty → retryable
        ->push([[
            'currencyCode' => 'AUD',
            'sections' => [['title' => 'S', 'subsections' => [['title' => 'Cat', 'items' => [['title' => 'Item', 'price' => 5.0]]]]]],
        ]], 201),
    ]);

    $menu = app(MenuApifyScraper::class)->fetch('https://www.ubereats.com/au/store/x', 'uber-eats', 'u1');

    expect($menu)->not->toBeNull();
    expect($menu['categories'][0]['items'][0]['name'])->toBe('Item');
    Http::assertSentCount(2);                       // one empty + one success
});

it('does not retry a 4xx hard error', function () {
    Http::fake(['api.apify.com/*' => Http::response('Actor not rented', 403)]);

    $menu = app(MenuApifyScraper::class)->fetch('https://www.ubereats.com/au/store/x', 'uber-eats', 'u1');

    expect($menu)->toBeNull();
    Http::assertSentCount(1);                        // 403 is not retried
});
