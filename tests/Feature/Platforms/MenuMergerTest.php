<?php

use App\Services\Platforms\MenuMerger;

// MenuMerger is pure logic (no DB / no HTTP) — it UNIONs two already-normalized
// platform menus (the shape MenuApifyScraper returns) into the persisted menu:
// every dish from every platform appears, matched dishes merge + gap-fill, and
// each dish records the platforms it's on (price + modes + url).

function normItem(array $overrides): array
{
    return array_merge([
        'externalId' => null,
        'name' => 'Item',
        'description' => null,
        'price' => null,
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
 * modes it offers, so the merger's mode aggregation has urls to slot.
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
    $ue = platformMenu([normItem([
        'externalId' => 'u1', 'name' => 'Chicken Burrito', 'price' => 17.0, 'image' => 'https://ue/img.jpg',
        'modifiers' => [['name' => 'Size', 'options' => [['name' => 'Large', 'price' => 2.0]]]],
    ])]);
    $dd = platformMenu([normItem([
        'externalId' => 'd1', 'name' => 'Chicken Burrito', 'price' => 15.5, 'image' => 'https://dd/img.jpg',
        'rating' => 95.0, 'ratingCount' => 213, 'badges' => [['text' => '#1 Most liked']],
    ])]);

    // DoorDash store offers pickup, Uber Eats store offers delivery.
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

it('builds a platforms array of length 2 with correct prices and modes for a dish on both platforms', function () {
    $ue = platformMenu([normItem(['name' => 'Chicken Burrito', 'price' => 17.0])]);
    $dd = platformMenu([normItem(['name' => 'Chicken Burrito', 'price' => 15.5])]);

    $links = storeLinks(['uber-eats' => ['pickup', 'delivery'], 'doordash' => ['delivery']]);
    $item = (new MenuMerger)->merge($ue, $dd, 'uber-eats', $links)['categories'][0]['items'][0];

    expect($item['platforms'])->toHaveCount(2);
    // Content-priority order: Uber Eats first.
    expect($item['platforms'][0]['platform'])->toBe('uber-eats');
    expect($item['platforms'][0]['price'])->toBe(17.0);
    expect($item['platforms'][0]['modes'])->toBe(['pickup', 'delivery']);
    expect($item['platforms'][0]['url'])->toBe('https://uber-eats/store?diningMode=PICKUP');
    expect($item['platforms'][1]['platform'])->toBe('doordash');
    expect($item['platforms'][1]['price'])->toBe(15.5);
    expect($item['platforms'][1]['modes'])->toBe(['delivery']);
    expect($item['platforms'][1]['url'])->toBe('https://doordash/store?diningMode=DELIVERY');
});

it('includes a DoorDash-only item in the union (not dropped)', function () {
    $ue = platformMenu([normItem(['name' => 'Margherita', 'price' => 20.0])]);
    $dd = [
        'store' => ['name' => 'Store', 'currency' => 'AUD'],
        'categories' => [
            ['name' => 'Pizzas', 'items' => [normItem(['name' => 'Margherita', 'price' => 18.0])]],
            // A category + dish that exists ONLY on DoorDash.
            ['name' => 'Desserts', 'items' => [normItem(['name' => 'Tiramisu', 'price' => 9.0, 'rating' => 88.0])]],
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
    expect($tiramisu['platforms'][0]['price'])->toBe(9.0);
});

it('gap-fills the image from Uber Eats and the description from DoorDash on a matched item', function () {
    // UE has the image but no description; DoorDash has the description but no image.
    $ue = platformMenu([normItem(['name' => 'Plain Rice', 'price' => 4.0, 'image' => 'https://ue/rice.jpg', 'description' => null])]);
    $dd = platformMenu([normItem(['name' => 'Plain Rice', 'price' => 3.5, 'image' => null, 'description' => 'Steamed jasmine rice.'])]);

    $links = storeLinks(['uber-eats' => ['delivery'], 'doordash' => ['pickup']]);
    $item = (new MenuMerger)->merge($ue, $dd, 'uber-eats', $links)['categories'][0]['items'][0];

    expect($item['imageUrl'])->toBe('https://ue/rice.jpg');       // UE image
    expect($item['description'])->toBe('Steamed jasmine rice.');  // DoorDash fills the gap
});

it('fills a missing Uber Eats image from the matched DoorDash item', function () {
    $ue = platformMenu([normItem(['name' => 'Plain Rice', 'price' => 4.0, 'image' => null])]);
    $dd = platformMenu([normItem(['name' => 'Plain Rice', 'price' => 3.5, 'image' => 'https://dd/rice.jpg'])]);

    $links = storeLinks(['uber-eats' => ['delivery'], 'doordash' => ['pickup']]);
    $item = (new MenuMerger)->merge($ue, $dd, 'uber-eats', $links)['categories'][0]['items'][0];
    expect($item['imageUrl'])->toBe('https://dd/rice.jpg');
});

it('aggregates pickupPrice and deliveryPrice as the min among capable platforms', function () {
    // Both platforms offer both modes, different prices.
    $ue = platformMenu([normItem(['name' => 'Combo', 'price' => 22.0])]);
    $dd = platformMenu([normItem(['name' => 'Combo', 'price' => 19.5])]);

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
    $ue = platformMenu([normItem(['name' => 'Chicken Burrito', 'price' => 17.0])]);

    $links = storeLinks(['uber-eats' => ['delivery']]);
    $item = (new MenuMerger)->merge($ue, null, 'uber-eats', $links)['categories'][0]['items'][0];

    expect($item['deliveryPrice'])->toBe(17.0);
    expect($item['deliverySource'])->toBe('uber-eats');
    expect($item['pickupPrice'])->toBeNull();
    expect($item['pickupSource'])->toBeNull();
    expect($item['platforms'])->toHaveCount(1);
    expect($item['platforms'][0]['modes'])->toBe(['delivery']);
});

it('matches a trailing-qualifier variant but not a similar different dish', function () {
    $links = storeLinks(['uber-eats' => ['delivery'], 'doordash' => ['pickup']]);

    // "Margherita" ⊂ "Margherita Pizza" → matched (one merged item, 2 platforms).
    $ue = platformMenu([normItem(['name' => 'Margherita', 'price' => 20.0])]);
    $dd = platformMenu([normItem(['name' => 'Margherita Pizza', 'price' => 18.0])]);
    $merged = (new MenuMerger)->merge($ue, $dd, 'uber-eats', $links);
    $items = collect($merged['categories'])->flatMap(fn ($c) => $c['items']);
    expect($items)->toHaveCount(1);
    expect($items[0]['pickupPrice'])->toBe(18.0);
    expect($items[0]['platforms'])->toHaveCount(2);

    // "Beef Burrito" vs "Bean Burrito" share no containment → NOT matched → both
    // appear (union), each single-platform.
    $ue2 = platformMenu([normItem(['name' => 'Beef Burrito', 'price' => 16.0])]);
    $dd2 = platformMenu([normItem(['name' => 'Bean Burrito', 'price' => 14.0])]);
    $merged2 = (new MenuMerger)->merge($ue2, $dd2, 'uber-eats', $links);
    $items2 = collect($merged2['categories'])->flatMap(fn ($c) => $c['items']);
    expect($items2)->toHaveCount(2);
    expect($items2->pluck('name')->all())->toContain('Beef Burrito');
    expect($items2->pluck('name')->all())->toContain('Bean Burrito');
});

it('uses DoorDash as the canonical source when no Uber Eats menu exists', function () {
    $dd = platformMenu(
        [normItem(['name' => 'Plain Rice', 'price' => 4.0, 'image' => 'https://dd/rice.jpg', 'rating' => 90.0])],
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

it('offers both modes when a platform store link has no type info', function () {
    $ue = platformMenu([normItem(['name' => 'Combo', 'price' => 12.0])]);

    // storeLinks with an empty/untyped store → modes default to both.
    $links = ['uber-eats' => ['pickupUrl' => null, 'deliveryUrl' => null, 'storeUrl' => 'https://ue/store', 'modes' => ['pickup', 'delivery']]];
    $item = (new MenuMerger)->merge($ue, null, 'uber-eats', $links)['categories'][0]['items'][0];

    expect($item['platforms'][0]['modes'])->toBe(['pickup', 'delivery']);
    expect($item['platforms'][0]['url'])->toBe('https://ue/store');
    expect($item['pickupPrice'])->toBe(12.0);
    expect($item['deliveryPrice'])->toBe(12.0);
});
