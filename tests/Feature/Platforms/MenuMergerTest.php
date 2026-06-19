<?php

use App\Services\Platforms\MenuMerger;

// MenuMerger is pure logic (no DB / no HTTP) — it fuses two already-normalized
// platform menus (the shape MenuApifyScraper returns) into the persisted menu.

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

it('prices pickup from DoorDash and delivery from Uber Eats for a matched item', function () {
    $ue = platformMenu([normItem([
        'externalId' => 'u1', 'name' => 'Chicken Burrito', 'price' => 17.0, 'image' => 'https://ue/img.jpg',
        'modifiers' => [['name' => 'Size', 'options' => [['name' => 'Large', 'price' => 2.0]]]],
    ])]);
    $dd = platformMenu([normItem([
        'externalId' => 'd1', 'name' => 'Chicken Burrito', 'price' => 15.5, 'image' => 'https://dd/img.jpg',
        'rating' => 95.0, 'ratingCount' => 213, 'badges' => [['text' => '#1 Most liked']],
    ])]);

    $item = (new MenuMerger)->merge($ue, $dd, 'uber-eats', 'doordash', 'uber-eats')['categories'][0]['items'][0];

    expect($item['basePrice'])->toBe(17.0);
    expect($item['pickupPrice'])->toBe(15.5);            // DoorDash = pickup
    expect($item['pickupSource'])->toBe('doordash');
    expect($item['deliveryPrice'])->toBe(17.0);          // Uber Eats = delivery
    expect($item['deliverySource'])->toBe('uber-eats');
    expect($item['imageUrl'])->toBe('https://ue/img.jpg'); // UE image preferred
    expect($item['rating'])->toBe(95.0);                 // DoorDash-only
    expect($item['badges'][0]['text'])->toBe('#1 Most liked');
    expect($item['modifiers'][0]['name'])->toBe('Size'); // Uber Eats-only
    expect($item['ueExternalId'])->toBe('u1');
    expect($item['ddExternalId'])->toBe('d1');
});

it('fills a missing Uber Eats image from the matched DoorDash item', function () {
    $ue = platformMenu([normItem(['name' => 'Plain Rice', 'price' => 4.0, 'image' => null])]);
    $dd = platformMenu([normItem(['name' => 'Plain Rice', 'price' => 3.5, 'image' => 'https://dd/rice.jpg'])]);

    $item = (new MenuMerger)->merge($ue, $dd, 'uber-eats', 'doordash', 'uber-eats')['categories'][0]['items'][0];
    expect($item['imageUrl'])->toBe('https://dd/rice.jpg');
});

it('matches a trailing-qualifier variant but not a similar different dish', function () {
    // "Margherita" ⊂ "Margherita Pizza" → matched.
    $ue = platformMenu([normItem(['name' => 'Margherita', 'price' => 20.0])]);
    $dd = platformMenu([normItem(['name' => 'Margherita Pizza', 'price' => 18.0])]);
    $item = (new MenuMerger)->merge($ue, $dd, 'uber-eats', 'doordash', 'uber-eats')['categories'][0]['items'][0];
    expect($item['pickupPrice'])->toBe(18.0);

    // "Beef Burrito" vs "Bean Burrito" share no containment → not matched.
    $ue2 = platformMenu([normItem(['name' => 'Beef Burrito', 'price' => 16.0])]);
    $dd2 = platformMenu([normItem(['name' => 'Bean Burrito', 'price' => 14.0])]);
    $item2 = (new MenuMerger)->merge($ue2, $dd2, 'uber-eats', 'doordash', 'uber-eats')['categories'][0]['items'][0];
    expect($item2['pickupPrice'])->toBeNull();
});

it('leaves the other mode price empty when only one platform is connected', function () {
    $ue = platformMenu([normItem(['name' => 'Chicken Burrito', 'price' => 17.0])]);

    $item = (new MenuMerger)->merge($ue, null, 'uber-eats', 'doordash', 'uber-eats')['categories'][0]['items'][0];
    expect($item['deliveryPrice'])->toBe(17.0);
    expect($item['deliverySource'])->toBe('uber-eats');
    expect($item['pickupPrice'])->toBeNull();
    expect($item['pickupSource'])->toBeNull();
});

it('uses DoorDash as the canonical source when no Uber Eats menu exists', function () {
    $dd = platformMenu(
        [normItem(['name' => 'Plain Rice', 'price' => 4.0, 'image' => 'https://dd/rice.jpg', 'rating' => 90.0])],
        ['rating' => 3.7, 'reviewCount' => 38],
    );

    $merged = (new MenuMerger)->merge(null, $dd, 'doordash', 'doordash', null);
    $item = $merged['categories'][0]['items'][0];

    expect($merged['store']['rating'])->toBe(3.7);
    expect($item['pickupPrice'])->toBe(4.0);
    expect($item['pickupSource'])->toBe('doordash');
    expect($item['deliveryPrice'])->toBeNull();          // no delivery platform
    expect($item['rating'])->toBe(90.0);
    expect($item['imageUrl'])->toBe('https://dd/rice.jpg');
    expect($merged['categories'][0]['sourcePlatform'])->toBe('doordash');
});
