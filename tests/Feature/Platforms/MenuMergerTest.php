<?php

use App\Services\Platforms\MenuMerger;

// MenuMerger is pure logic (no DB / no HTTP) — it UNIONs two already-normalized
// platform menus (the shape MenuApifyScraper::fetchStore returns, where each item
// already carries a pickupPrice + deliveryPrice) into the persisted menu: every
// dish from every platform appears, matched dishes merge + gap-fill, and each
// dish records the platforms it's on (per-mode price + per-mode url). A connected
// platform whose scrape returned nothing (null menu, but a store link present) is
// a "ghost" — attached to every dish with null prices so it never disappears.

function normItem(array $overrides): array
{
    return array_merge([
        'externalId' => null,
        'name' => 'Item',
        'description' => null,
        'pickupPrice' => null,
        'deliveryPrice' => null,
        'image' => null,
        'isSoldOut' => false,
        'modifiers' => null,
        'rating' => null,
        'ratingCount' => null,
        'badges' => null,
    ], $overrides);
}

function platformMenu(array $items, array $store = []): array
{
    return [
        'store' => array_merge(['name' => 'Store', 'rating' => null, 'reviewCount' => null, 'currency' => 'AUD', 'logo' => null], $store),
        'categories' => [['name' => 'Mains', 'items' => $items]],
    ];
}

/**
 * Build the storeLinks input (MenuSource::storeLinks shape) for a set of
 * platform → modes. Each platform gets a pickup and/or delivery url matching the
 * modes it offers, so the merger has per-mode urls to slot.
 */
function storeLinks(array $modesByPlatform): array
{
    $out = [];
    foreach ($modesByPlatform as $platform => $modes) {
        $out[$platform] = [
            'pickupUrl' => in_array('pickup', $modes, true) ? "https://{$platform}/store?diningMode=PICKUP" : null,
            'deliveryUrl' => in_array('delivery', $modes, true) ? "https://{$platform}/store?diningMode=DELIVERY" : null,
            'storeUrl' => "https://{$platform}/store",
            'modes' => $modes,
        ];
    }

    return $out;
}

it('prices pickup from DoorDash and delivery from Uber Eats for a matched item', function () {
    // UE store offers delivery only (its scrape priced delivery), DoorDash offers
    // pickup only — the user's mixed scenario.
    $ue = platformMenu([normItem([
        'externalId' => 'u1', 'name' => 'Chicken Burrito', 'deliveryPrice' => 17.0, 'image' => 'https://ue/img.jpg',
        'modifiers' => [['name' => 'Size', 'options' => [['name' => 'Large', 'price' => 2.0]]]],
    ])]);
    $dd = platformMenu([normItem([
        'externalId' => 'd1', 'name' => 'Chicken Burrito', 'pickupPrice' => 15.5, 'image' => 'https://dd/img.jpg',
        'rating' => 95.0, 'ratingCount' => 213, 'badges' => [['text' => '#1 Most liked']],
    ])]);

    $links = storeLinks(['uber-eats' => ['delivery'], 'doordash' => ['pickup']]);
    $item = (new MenuMerger)->merge($ue, $dd, 'uber-eats', $links)['categories'][0]['items'][0];

    expect($item['basePrice'])->toBe(15.5);              // min across platforms
    expect($item['pickupPrice'])->toBe(15.5);            // DoorDash offers pickup
    expect($item['pickupSource'])->toBe('doordash');
    expect($item['deliveryPrice'])->toBe(17.0);          // Uber Eats offers delivery
    expect($item['deliverySource'])->toBe('uber-eats');
    expect($item['imageUrl'])->toBe('https://ue/img.jpg'); // UE image preferred
    expect($item['rating'])->toBe(95.0);                 // DoorDash-only
    expect($item['badges'][0]['text'])->toBe('#1 Most liked');
    expect($item['modifiers'][0]['name'])->toBe('Size'); // Uber Eats-only
    expect($item['ueExternalId'])->toBe('u1');
    expect($item['ddExternalId'])->toBe('d1');
});

it('builds a platforms array of length 2 with per-mode prices and urls for a dish on both platforms', function () {
    // UE offers both modes (same price each); DoorDash offers delivery only.
    $ue = platformMenu([normItem(['name' => 'Chicken Burrito', 'pickupPrice' => 17.0, 'deliveryPrice' => 17.0])]);
    $dd = platformMenu([normItem(['name' => 'Chicken Burrito', 'deliveryPrice' => 15.5])]);

    $links = storeLinks(['uber-eats' => ['pickup', 'delivery'], 'doordash' => ['delivery']]);
    $item = (new MenuMerger)->merge($ue, $dd, 'uber-eats', $links)['categories'][0]['items'][0];

    expect($item['platforms'])->toHaveCount(2);
    // Content-priority order: Uber Eats first.
    expect($item['platforms'][0]['platform'])->toBe('uber-eats');
    expect($item['platforms'][0]['pickupPrice'])->toBe(17.0);
    expect($item['platforms'][0]['pickupUrl'])->toBe('https://uber-eats/store?diningMode=PICKUP');
    expect($item['platforms'][0]['deliveryPrice'])->toBe(17.0);
    expect($item['platforms'][0]['deliveryUrl'])->toBe('https://uber-eats/store?diningMode=DELIVERY');
    expect($item['platforms'][1]['platform'])->toBe('doordash');
    expect($item['platforms'][1]['pickupPrice'])->toBeNull();      // DoorDash doesn't offer pickup
    expect($item['platforms'][1]['pickupUrl'])->toBeNull();
    expect($item['platforms'][1]['deliveryPrice'])->toBe(15.5);
    expect($item['platforms'][1]['deliveryUrl'])->toBe('https://doordash/store?diningMode=DELIVERY');
});

it('includes a DoorDash-only item in the union (not dropped)', function () {
    $ue = platformMenu([normItem(['name' => 'Margherita', 'deliveryPrice' => 20.0])]);
    $dd = [
        'store' => ['name' => 'Store', 'currency' => 'AUD'],
        'categories' => [
            ['name' => 'Pizzas', 'items' => [normItem(['name' => 'Margherita', 'pickupPrice' => 18.0])]],
            // A category + dish that exists ONLY on DoorDash.
            ['name' => 'Desserts', 'items' => [normItem(['name' => 'Tiramisu', 'pickupPrice' => 9.0, 'rating' => 88.0])]],
        ],
    ];

    $links = storeLinks(['uber-eats' => ['delivery'], 'doordash' => ['pickup']]);
    $merged = (new MenuMerger)->merge($ue, $dd, 'uber-eats', $links);

    // The UE spine category, plus the DoorDash-only Desserts category appended.
    $names = collect($merged['categories'])->flatMap(fn ($c) => collect($c['items'])->pluck('name'))->all();
    expect($names)->toContain('Margherita');
    expect($names)->toContain('Tiramisu');

    // The DoorDash-only dish is sourced from DoorDash and carries its platform.
    $dessert = collect($merged['categories'])->firstWhere('name', 'Desserts');
    expect($dessert['sourcePlatform'])->toBe('doordash');
    $tiramisu = $dessert['items'][0];
    expect($tiramisu['name'])->toBe('Tiramisu');
    expect($tiramisu['rating'])->toBe(88.0);
    expect($tiramisu['platforms'])->toHaveCount(1);
    expect($tiramisu['platforms'][0]['platform'])->toBe('doordash');
    expect($tiramisu['platforms'][0]['pickupPrice'])->toBe(9.0);
});

it('gap-fills the image from Uber Eats and the description from DoorDash on a matched item', function () {
    // UE has the image but no description; DoorDash has the description but no image.
    $ue = platformMenu([normItem(['name' => 'Plain Rice', 'deliveryPrice' => 4.0, 'image' => 'https://ue/rice.jpg', 'description' => null])]);
    $dd = platformMenu([normItem(['name' => 'Plain Rice', 'pickupPrice' => 3.5, 'image' => null, 'description' => 'Steamed jasmine rice.'])]);

    $links = storeLinks(['uber-eats' => ['delivery'], 'doordash' => ['pickup']]);
    $item = (new MenuMerger)->merge($ue, $dd, 'uber-eats', $links)['categories'][0]['items'][0];

    expect($item['imageUrl'])->toBe('https://ue/rice.jpg');       // UE image
    expect($item['description'])->toBe('Steamed jasmine rice.');  // DoorDash fills the gap
});

it('fills a missing Uber Eats image from the matched DoorDash item', function () {
    $ue = platformMenu([normItem(['name' => 'Plain Rice', 'deliveryPrice' => 4.0, 'image' => null])]);
    $dd = platformMenu([normItem(['name' => 'Plain Rice', 'pickupPrice' => 3.5, 'image' => 'https://dd/rice.jpg'])]);

    $links = storeLinks(['uber-eats' => ['delivery'], 'doordash' => ['pickup']]);
    $item = (new MenuMerger)->merge($ue, $dd, 'uber-eats', $links)['categories'][0]['items'][0];
    expect($item['imageUrl'])->toBe('https://dd/rice.jpg');
});

it('aggregates pickupPrice and deliveryPrice as the min among capable platforms', function () {
    // Both platforms offer both modes, different prices.
    $ue = platformMenu([normItem(['name' => 'Combo', 'pickupPrice' => 22.0, 'deliveryPrice' => 22.0])]);
    $dd = platformMenu([normItem(['name' => 'Combo', 'pickupPrice' => 19.5, 'deliveryPrice' => 19.5])]);

    $links = storeLinks(['uber-eats' => ['pickup', 'delivery'], 'doordash' => ['pickup', 'delivery']]);
    $item = (new MenuMerger)->merge($ue, $dd, 'uber-eats', $links)['categories'][0]['items'][0];

    expect($item['basePrice'])->toBe(19.5);     // min across
    expect($item['pickupPrice'])->toBe(19.5);   // DoorDash cheaper, offers pickup
    expect($item['pickupSource'])->toBe('doordash');
    expect($item['deliveryPrice'])->toBe(19.5); // DoorDash cheaper, offers delivery
    expect($item['deliverySource'])->toBe('doordash');
});

it('leaves a mode price null when no platform offers that mode', function () {
    // Only Uber Eats is connected, and its store offers delivery only.
    $ue = platformMenu([normItem(['name' => 'Chicken Burrito', 'deliveryPrice' => 17.0])]);

    $links = storeLinks(['uber-eats' => ['delivery']]);
    $item = (new MenuMerger)->merge($ue, null, 'uber-eats', $links)['categories'][0]['items'][0];

    expect($item['deliveryPrice'])->toBe(17.0);
    expect($item['deliverySource'])->toBe('uber-eats');
    expect($item['pickupPrice'])->toBeNull();
    expect($item['pickupSource'])->toBeNull();
    expect($item['platforms'])->toHaveCount(1);
    expect($item['platforms'][0]['pickupPrice'])->toBeNull();
    expect($item['platforms'][0]['pickupUrl'])->toBeNull();
    expect($item['platforms'][0]['deliveryPrice'])->toBe(17.0);
    expect($item['platforms'][0]['deliveryUrl'])->toBe('https://uber-eats/store?diningMode=DELIVERY');
});

it('matches a trailing-qualifier variant but not a similar different dish', function () {
    $links = storeLinks(['uber-eats' => ['delivery'], 'doordash' => ['pickup']]);

    // "Margherita" ⊂ "Margherita Pizza" → matched (one merged item, 2 platforms).
    $ue = platformMenu([normItem(['name' => 'Margherita', 'deliveryPrice' => 20.0])]);
    $dd = platformMenu([normItem(['name' => 'Margherita Pizza', 'pickupPrice' => 18.0])]);
    $merged = (new MenuMerger)->merge($ue, $dd, 'uber-eats', $links);
    $items = collect($merged['categories'])->flatMap(fn ($c) => $c['items']);
    expect($items)->toHaveCount(1);
    expect($items[0]['pickupPrice'])->toBe(18.0);
    expect($items[0]['platforms'])->toHaveCount(2);

    // "Beef Burrito" vs "Bean Burrito" share no containment → NOT matched → both
    // appear (union), each single-platform.
    $ue2 = platformMenu([normItem(['name' => 'Beef Burrito', 'deliveryPrice' => 16.0])]);
    $dd2 = platformMenu([normItem(['name' => 'Bean Burrito', 'pickupPrice' => 14.0])]);
    $merged2 = (new MenuMerger)->merge($ue2, $dd2, 'uber-eats', $links);
    $items2 = collect($merged2['categories'])->flatMap(fn ($c) => $c['items']);
    expect($items2)->toHaveCount(2);
    expect($items2->pluck('name')->all())->toContain('Beef Burrito');
    expect($items2->pluck('name')->all())->toContain('Bean Burrito');
});

it('uses DoorDash as the canonical source when no Uber Eats menu exists', function () {
    $dd = platformMenu(
        [normItem(['name' => 'Plain Rice', 'pickupPrice' => 4.0, 'image' => 'https://dd/rice.jpg', 'rating' => 90.0])],
        ['rating' => 3.7, 'reviewCount' => 38],
    );

    $links = storeLinks(['doordash' => ['pickup']]);
    $merged = (new MenuMerger)->merge(null, $dd, 'doordash', $links);
    $item = $merged['categories'][0]['items'][0];

    expect($merged['store']['rating'])->toBe(3.7);
    expect($item['pickupPrice'])->toBe(4.0);
    expect($item['pickupSource'])->toBe('doordash');
    expect($item['deliveryPrice'])->toBeNull();          // no delivery platform
    expect($item['rating'])->toBe(90.0);
    expect($item['imageUrl'])->toBe('https://dd/rice.jpg');
    expect($merged['categories'][0]['sourcePlatform'])->toBe('doordash');
});

it('offers both modes at one price when a platform store link is untyped', function () {
    // Untyped store → fetchStore applied its single price to both modes.
    $ue = platformMenu([normItem(['name' => 'Combo', 'pickupPrice' => 12.0, 'deliveryPrice' => 12.0])]);

    $links = ['uber-eats' => ['pickupUrl' => null, 'deliveryUrl' => null, 'storeUrl' => 'https://ue/store', 'modes' => ['pickup', 'delivery']]];
    $item = (new MenuMerger)->merge($ue, null, 'uber-eats', $links)['categories'][0]['items'][0];

    expect($item['platforms'][0]['pickupPrice'])->toBe(12.0);
    expect($item['platforms'][0]['pickupUrl'])->toBe('https://ue/store');
    expect($item['platforms'][0]['deliveryPrice'])->toBe(12.0);
    expect($item['platforms'][0]['deliveryUrl'])->toBe('https://ue/store');
    expect($item['pickupPrice'])->toBe(12.0);
    expect($item['deliveryPrice'])->toBe(12.0);
});

it('attaches a connected-but-unscraped platform to every dish as a priceless ghost', function () {
    // DoorDash scraped fine; Uber Eats is connected (store link present) but its
    // scrape returned nothing this run. UE must still appear on every dish —
    // linking to its store, prices null — so a flaky scrape never hides it.
    $dd = platformMenu([
        normItem(['name' => 'Margherita', 'pickupPrice' => 18.0, 'deliveryPrice' => 20.0]),
        normItem(['name' => 'Tiramisu', 'pickupPrice' => 9.0]),
    ]);

    $links = storeLinks(['uber-eats' => ['pickup', 'delivery'], 'doordash' => ['pickup', 'delivery']]);
    $merged = (new MenuMerger)->merge(null, $dd, 'doordash', $links);
    $items = collect($merged['categories'])->flatMap(fn ($c) => $c['items']);

    foreach ($items as $item) {
        expect(collect($item['platforms'])->pluck('platform')->all())->toBe(['uber-eats', 'doordash']);
        $ue = collect($item['platforms'])->firstWhere('platform', 'uber-eats');
        // Ghost: no prices, but the order urls still route to the UE store.
        expect($ue['pickupPrice'])->toBeNull();
        expect($ue['deliveryPrice'])->toBeNull();
        expect($ue['pickupUrl'])->toBe('https://uber-eats/store?diningMode=PICKUP');
        expect($ue['deliveryUrl'])->toBe('https://uber-eats/store?diningMode=DELIVERY');
    }

    // Aggregates come only from the platform that actually priced (DoorDash).
    $margherita = $items->firstWhere('name', 'Margherita');
    expect($margherita['pickupPrice'])->toBe(18.0);
    expect($margherita['pickupSource'])->toBe('doordash');
    expect($margherita['basePrice'])->toBe(18.0);
});
