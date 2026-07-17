<?php

use App\Services\Platforms\MenuMerger;
use Illuminate\Support\Facades\Log;

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
    ])]);
    $dd = platformMenu([normItem([
        'externalId' => 'd1', 'name' => 'Chicken Burrito', 'pickupPrice' => 15.5, 'image' => 'https://dd/img.jpg',
        'rating' => 95.0, 'ratingCount' => 213, 'badges' => [['text' => '#1 Most liked']],
    ])]);

    $links = storeLinks(['uber-eats' => ['delivery'], 'doordash' => ['pickup']]);
    $item = (new MenuMerger)->merge(['uber-eats' => $ue, 'doordash' => $dd], 'uber-eats', $links)['categories'][0]['items'][0];

    expect($item['basePrice'])->toBe(15.5);              // min across platforms
    expect($item['pickupPrice'])->toBe(15.5);            // DoorDash offers pickup
    expect($item['pickupSource'])->toBe('doordash');
    expect($item['deliveryPrice'])->toBe(17.0);          // Uber Eats offers delivery
    expect($item['deliverySource'])->toBe('uber-eats');
    expect($item['imageUrl'])->toBe('https://ue/img.jpg'); // UE image preferred
    expect($item['rating'])->toBe(95.0);                 // DoorDash-only
    expect($item['badges'][0]['text'])->toBe('#1 Most liked');
    expect($item['ddExternalId'])->toBe('d1');
    // Both platforms carried distinct art → both captured, hero (=imageUrl) first.
    expect($item['images'])->toBe(['https://ue/img.jpg', 'https://dd/img.jpg']);
});

it('collects the cross-platform image set without duplicating identical art, null when no platform has any', function () {
    // Same URL on both platforms → one entry (not a fake "gallery" of dupes).
    $ue = platformMenu([normItem(['name' => 'Same Art Dish', 'deliveryPrice' => 10.0, 'image' => 'https://cdn/shared.jpg'])]);
    $dd = platformMenu([
        normItem(['externalId' => 'd1', 'name' => 'Same Art Dish', 'pickupPrice' => 9.0, 'image' => 'https://cdn/shared.jpg']),
        normItem(['externalId' => 'd2', 'name' => 'Artless Dish', 'pickupPrice' => 5.0]),
    ]);

    $links = storeLinks(['uber-eats' => ['delivery'], 'doordash' => ['pickup']]);
    $items = (new MenuMerger)->merge(['uber-eats' => $ue, 'doordash' => $dd], 'uber-eats', $links)['categories'][0]['items'];

    expect($items[0]['images'])->toBe(['https://cdn/shared.jpg']);
    // No image anywhere → null (mirrors imageUrl), never [].
    $artless = collect($items)->firstWhere('name', 'Artless Dish');
    expect($artless['images'])->toBeNull();
});

it('builds a platforms array of length 2 with per-mode prices and urls for a dish on both platforms', function () {
    // UE offers both modes (same price each); DoorDash offers delivery only.
    $ue = platformMenu([normItem(['name' => 'Chicken Burrito', 'pickupPrice' => 17.0, 'deliveryPrice' => 17.0])]);
    $dd = platformMenu([normItem(['name' => 'Chicken Burrito', 'deliveryPrice' => 15.5])]);

    $links = storeLinks(['uber-eats' => ['pickup', 'delivery'], 'doordash' => ['delivery']]);
    $item = (new MenuMerger)->merge(['uber-eats' => $ue, 'doordash' => $dd], 'uber-eats', $links)['categories'][0]['items'][0];

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
    $merged = (new MenuMerger)->merge(['uber-eats' => $ue, 'doordash' => $dd], 'uber-eats', $links);

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
    $item = (new MenuMerger)->merge(['uber-eats' => $ue, 'doordash' => $dd], 'uber-eats', $links)['categories'][0]['items'][0];

    expect($item['imageUrl'])->toBe('https://ue/rice.jpg');       // UE image
    expect($item['description'])->toBe('Steamed jasmine rice.');  // DoorDash fills the gap
});

it('fills a missing Uber Eats image from the matched DoorDash item', function () {
    $ue = platformMenu([normItem(['name' => 'Plain Rice', 'deliveryPrice' => 4.0, 'image' => null])]);
    $dd = platformMenu([normItem(['name' => 'Plain Rice', 'pickupPrice' => 3.5, 'image' => 'https://dd/rice.jpg'])]);

    $links = storeLinks(['uber-eats' => ['delivery'], 'doordash' => ['pickup']]);
    $item = (new MenuMerger)->merge(['uber-eats' => $ue, 'doordash' => $dd], 'uber-eats', $links)['categories'][0]['items'][0];
    expect($item['imageUrl'])->toBe('https://dd/rice.jpg');
});

it('aggregates pickupPrice and deliveryPrice as the min among capable platforms', function () {
    // Both platforms offer both modes, different prices.
    $ue = platformMenu([normItem(['name' => 'Combo', 'pickupPrice' => 22.0, 'deliveryPrice' => 22.0])]);
    $dd = platformMenu([normItem(['name' => 'Combo', 'pickupPrice' => 19.5, 'deliveryPrice' => 19.5])]);

    $links = storeLinks(['uber-eats' => ['pickup', 'delivery'], 'doordash' => ['pickup', 'delivery']]);
    $item = (new MenuMerger)->merge(['uber-eats' => $ue, 'doordash' => $dd], 'uber-eats', $links)['categories'][0]['items'][0];

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
    $item = (new MenuMerger)->merge(['uber-eats' => $ue], 'uber-eats', $links)['categories'][0]['items'][0];

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
    $merged = (new MenuMerger)->merge(['uber-eats' => $ue, 'doordash' => $dd], 'uber-eats', $links);
    $items = collect($merged['categories'])->flatMap(fn ($c) => $c['items']);
    expect($items)->toHaveCount(1);
    expect($items[0]['pickupPrice'])->toBe(18.0);
    expect($items[0]['platforms'])->toHaveCount(2);

    // "Beef Burrito" vs "Bean Burrito" share no containment → NOT matched → both
    // appear (union), each single-platform.
    $ue2 = platformMenu([normItem(['name' => 'Beef Burrito', 'deliveryPrice' => 16.0])]);
    $dd2 = platformMenu([normItem(['name' => 'Bean Burrito', 'pickupPrice' => 14.0])]);
    $merged2 = (new MenuMerger)->merge(['uber-eats' => $ue2, 'doordash' => $dd2], 'uber-eats', $links);
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
    $merged = (new MenuMerger)->merge(['doordash' => $dd], 'doordash', $links);
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
    $item = (new MenuMerger)->merge(['uber-eats' => $ue], 'uber-eats', $links)['categories'][0]['items'][0];

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
    $merged = (new MenuMerger)->merge(['doordash' => $dd], 'doordash', $links);
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

it('preserves PLATFORMS priority order regardless of map insertion order', function () {
    $ue = platformMenu([normItem(['name' => 'Burger'])]);
    $dd = platformMenu([normItem(['name' => 'Burger'])]);
    $merged = app(MenuMerger::class)->merge(
        ['doordash' => $dd, 'uber-eats' => $ue],  // reversed insertion order
        'uber-eats',
        [],
    );
    expect($merged['categories'][0]['items'][0]['platforms'][0]['platform'])->toBe('uber-eats')
        ->and($merged['categories'][0]['items'][0]['platforms'][1]['platform'])->toBe('doordash');
});

// ── B2: refresh stability (G6-1/G6-2) — deterministic order, cross-platform
// category dedupe, platform ad/upsell category filtering ──────────────────

it('produces identical category order across repeated merges of unchanged input (G6-1a)', function () {
    $ue = [
        'store' => ['name' => 'Store', 'currency' => 'AUD'],
        'categories' => [
            ['name' => 'Featured items', 'items' => [normItem(['name' => 'Hero Dish', 'deliveryPrice' => 10.0])]],
            ['name' => 'Mains', 'items' => [normItem(['name' => 'Steak', 'deliveryPrice' => 30.0])]],
            ['name' => 'Sides', 'items' => [normItem(['name' => 'Fries', 'deliveryPrice' => 6.0])]],
        ],
    ];
    $links = storeLinks(['uber-eats' => ['delivery']]);

    $first = (new MenuMerger)->merge(['uber-eats' => $ue], 'uber-eats', $links);
    $second = (new MenuMerger)->merge(['uber-eats' => $ue], 'uber-eats', $links);

    $firstNames = collect($first['categories'])->pluck('name')->all();
    expect($firstNames)->toBe(collect($second['categories'])->pluck('name')->all());
    // Source order preserved when no category collides on position.
    expect($firstNames)->toBe(['Featured items', 'Mains', 'Sides']);
});

it('sorts a leftover category into canonical order by source position, using normalized name as the tiebreak (G6-1a)', function () {
    // UE (canonical) has 2 categories; DoorDash's one exclusive category
    // ("Sides") lands at DD's own index 0 — the SAME raw position as UE's
    // "Mains". Without a tiebreak this is an arbitrary insertion-order tie;
    // normalized name gives a total, reproducible order every time, and this
    // proves the SORT (not incidental spine-then-leftover insertion order)
    // controls the final sequence: plain insertion order would have put
    // Sides last (appended in the leftover pass), not second.
    $ue = [
        'store' => ['name' => 'Store', 'currency' => 'AUD'],
        'categories' => [
            ['name' => 'Mains', 'items' => [normItem(['name' => 'Filet Mignon', 'deliveryPrice' => 30.0])]],
            ['name' => 'Desserts', 'items' => [normItem(['name' => 'Tiramisu', 'deliveryPrice' => 9.0])]],
        ],
    ];
    $dd = [
        'store' => ['name' => 'Store', 'currency' => 'AUD'],
        'categories' => [
            ['name' => 'Sides', 'items' => [normItem(['name' => 'Fries', 'pickupPrice' => 6.0])]],
        ],
    ];

    $links = storeLinks(['uber-eats' => ['delivery'], 'doordash' => ['pickup']]);
    $merged = (new MenuMerger)->merge(['uber-eats' => $ue, 'doordash' => $dd], 'uber-eats', $links);

    expect(collect($merged['categories'])->pluck('name')->all())->toBe(['Mains', 'Sides', 'Desserts']);
});

it('merges categories whose names collide once normalized, across platforms (G6-1b)', function () {
    // "DOP Pizza" scraped from Uber Eats and, with different case/punctuation,
    // from DoorDash — the real bug: these rendered as two separate sections.
    // "Margherita" is fused cross-platform by MenuMerger's existing item-level
    // matching BEFORE category-dedupe runs (it lives in the index regardless
    // of which category holds it), so only ONE Margherita reaches this point —
    // this test proves the CATEGORY shells themselves collapse into one.
    $ue = [
        'store' => ['name' => 'Store', 'currency' => 'AUD'],
        'categories' => [
            ['name' => 'DOP Pizza', 'items' => [
                normItem(['name' => 'Margherita', 'deliveryPrice' => 20.0]),
                normItem(['name' => 'Capricciosa', 'deliveryPrice' => 22.0]),
            ]],
        ],
    ];
    $dd = [
        'store' => ['name' => 'Store', 'currency' => 'AUD'],
        'categories' => [
            ['name' => 'dop  pizza!', 'items' => [
                normItem(['name' => 'Margherita', 'pickupPrice' => 20.0]),
                normItem(['name' => 'Diavola', 'pickupPrice' => 24.0]),
            ]],
        ],
    ];

    $links = storeLinks(['uber-eats' => ['delivery'], 'doordash' => ['pickup']]);
    $merged = (new MenuMerger)->merge(['uber-eats' => $ue, 'doordash' => $dd], 'uber-eats', $links);

    expect($merged['categories'])->toHaveCount(1);
    expect($merged['categories'][0]['name'])->toBe('DOP Pizza'); // canonical casing wins
    expect(collect($merged['categories'][0]['items'])->pluck('name')->all())
        ->toBe(['Margherita', 'Capricciosa', 'Diavola']);
});

// ── fix-round: dedupe scoping (critic P2 — same-platform dedupe used to
// merge ANY pair sharing a normalized name, regardless of content, silently
// fusing genuinely distinct sections) ──────────────────────────────────────

it('always merges a same-normalized-name category pair from DIFFERENT platforms, even with zero item overlap (fix-round)', function () {
    $ue = [
        'store' => ['name' => 'Store', 'currency' => 'AUD'],
        'categories' => [
            ['name' => 'Wine', 'items' => [normItem(['name' => 'Pinot Noir', 'deliveryPrice' => 11.0])]],
        ],
    ];
    $dd = [
        'store' => ['name' => 'Store', 'currency' => 'AUD'],
        'categories' => [
            // Completely different items — no cross-platform item-level match
            // either — but cross-platform category dedupe stays unconditional:
            // this is the actual "one section scraped once per platform" bug.
            ['name' => 'wine', 'items' => [normItem(['name' => 'Sauvignon Blanc', 'pickupPrice' => 12.0])]],
        ],
    ];
    $links = storeLinks(['uber-eats' => ['delivery'], 'doordash' => ['pickup']]);
    $merged = (new MenuMerger)->merge(['uber-eats' => $ue, 'doordash' => $dd], 'uber-eats', $links);

    expect($merged['categories'])->toHaveCount(1);
    expect(collect($merged['categories'][0]['items'])->pluck('name')->all())->toBe(['Pinot Noir', 'Sauvignon Blanc']);
});

it('keeps two SAME-platform categories separate when their item sets do not materially overlap — reproduces the Wine+Wine false positive (fix-round)', function () {
    $dd = [
        'store' => ['name' => 'Store', 'currency' => 'AUD'],
        'categories' => [
            ['name' => 'Wine', 'items' => [normItem(['name' => 'House Red (glass)', 'pickupPrice' => 9.0])]],
            ['name' => 'Mains', 'items' => [normItem(['name' => 'Steak', 'pickupPrice' => 32.0])]],
            // A SECOND, unrelated "Wine" section on the SAME platform — a
            // takeaway-bottles list sharing zero items with the by-the-glass
            // list above. Before the fix-round, same-platform dedupe merged
            // ANY pair sharing a normalized category name regardless of
            // content, silently fusing two genuinely different sections.
            ['name' => 'Wine', 'items' => [normItem(['name' => 'Bottle of Shiraz (takeaway)', 'pickupPrice' => 28.0])]],
        ],
    ];
    $links = storeLinks(['doordash' => ['pickup']]);
    $merged = (new MenuMerger)->merge(['doordash' => $dd], 'doordash', $links);

    // Both "Wine" categories survive, unmerged — 3 categories in, 3 out.
    expect($merged['categories'])->toHaveCount(3);
    $wines = collect($merged['categories'])->filter(fn ($c) => $c['name'] === 'Wine');
    expect($wines)->toHaveCount(2);
    $wineItemNames = $wines->flatMap(fn ($c) => collect($c['items'])->pluck('name'))->all();
    expect($wineItemNames)->toBe(['House Red (glass)', 'Bottle of Shiraz (takeaway)']);
});

it('merges two SAME-platform categories sharing a name when their item sets materially overlap (fix-round)', function () {
    $dd = [
        'store' => ['name' => 'Store', 'currency' => 'AUD'],
        'categories' => [
            // Same platform, same normalized name, and the SAME dish repeated
            // — a raw scrape artifact (e.g. a paginated section split in two)
            // rather than two distinct sections. 1/1 shared name = 100% overlap
            // of the smaller set, well over the 50% threshold.
            ['name' => 'Wine', 'items' => [normItem(['name' => 'House Red', 'pickupPrice' => 9.0])]],
            ['name' => 'wine', 'items' => [
                normItem(['name' => 'House Red', 'pickupPrice' => 9.0]),
                normItem(['name' => 'House White', 'pickupPrice' => 9.0]),
            ]],
        ],
    ];
    $links = storeLinks(['doordash' => ['pickup']]);
    $merged = (new MenuMerger)->merge(['doordash' => $dd], 'doordash', $links);

    expect($merged['categories'])->toHaveCount(1);
    expect(collect($merged['categories'][0]['items'])->pluck('name')->all())->toBe(['House Red', 'House White']);
});

it('drops an exact-duplicate item (same normalized name + price) when merging same-named categories (G6-1b)', function () {
    // Two categories on the SAME platform that normalize to one name — a raw
    // scrape artifact, not a cross-platform match (item-level matching only
    // compares ACROSS platforms) — each independently lists "Margherita" at
    // the identical price; the merge must keep exactly one.
    $ue = [
        'store' => ['name' => 'Store', 'currency' => 'AUD'],
        'categories' => [
            ['name' => 'Pizza', 'items' => [normItem(['name' => 'Margherita', 'deliveryPrice' => 20.0])]],
            ['name' => 'PIZZA', 'items' => [
                normItem(['name' => 'Margherita', 'deliveryPrice' => 20.0]),  // exact duplicate → dropped
                normItem(['name' => 'Hawaiian', 'deliveryPrice' => 21.0]),    // distinct → kept
            ]],
        ],
    ];
    $links = storeLinks(['uber-eats' => ['delivery']]);
    $merged = (new MenuMerger)->merge(['uber-eats' => $ue], 'uber-eats', $links);

    expect($merged['categories'])->toHaveCount(1);
    expect(collect($merged['categories'][0]['items'])->pluck('name')->all())->toBe(['Margherita', 'Hawaiian']);
});

it('filters out a platform ad/upsell category like "Add Drinks to Your Order from Liquorland" (G6-1c)', function () {
    $dd = [
        'store' => ['name' => 'Store', 'currency' => 'AUD'],
        'categories' => [
            ['name' => 'Antipasti', 'items' => [normItem(['name' => 'Bruschetta', 'pickupPrice' => 12.0])]],
            ['name' => 'Add Drinks to Your Order from Liquorland', 'items' => [normItem(['name' => 'Coke Can', 'pickupPrice' => 4.0])]],
        ],
    ];
    $links = storeLinks(['doordash' => ['pickup']]);
    $merged = (new MenuMerger)->merge(['doordash' => $dd], 'doordash', $links);

    $names = collect($merged['categories'])->pluck('name')->all();
    expect($names)->toBe(['Antipasti']);
    expect($names)->not->toContain('Add Drinks to Your Order from Liquorland');
});

// ── fix-round: REAL cross-run stability (critic P1 — position was the
// per-call array index, so it reproduced an upstream reorder instead of
// resisting it) ─────────────────────────────────────────────────────────

it('preserves the PERSISTED category order across a refresh even when the upstream scrape reshuffles categories (fix-round, real cross-run stability)', function () {
    $links = storeLinks(['uber-eats' => ['delivery']]);

    // Run 1 — upstream returns categories in this order.
    $run1 = (new MenuMerger)->merge(['uber-eats' => [
        'store' => ['name' => 'Store', 'currency' => 'AUD'],
        'categories' => [
            ['name' => 'Featured items', 'items' => [normItem(['name' => 'Hero Dish', 'deliveryPrice' => 10.0])]],
            ['name' => 'Mains', 'items' => [normItem(['name' => 'Steak', 'deliveryPrice' => 30.0])]],
            ['name' => 'Sides', 'items' => [normItem(['name' => 'Fries', 'deliveryPrice' => 6.0])]],
        ],
    ]], 'uber-eats', $links);
    $persistedOrder = collect($run1['categories'])->pluck('name')->all();
    expect($persistedOrder)->toBe(['Featured items', 'Mains', 'Sides']);

    // Run 2 — the SAME 3 categories, but the platform's own scrape this time
    // returned them in a completely different array order (the exact
    // real-world failure mode — nothing about the menu changed, only the
    // upstream ordering). The persisted order from run 1 is fed back in, the
    // way MenuFetchJob does from the DB.
    $run2 = (new MenuMerger)->merge(['uber-eats' => [
        'store' => ['name' => 'Store', 'currency' => 'AUD'],
        'categories' => [
            ['name' => 'Sides', 'items' => [normItem(['name' => 'Fries', 'deliveryPrice' => 6.0])]],
            ['name' => 'Featured items', 'items' => [normItem(['name' => 'Hero Dish', 'deliveryPrice' => 10.0])]],
            ['name' => 'Mains', 'items' => [normItem(['name' => 'Steak', 'deliveryPrice' => 30.0])]],
        ],
    ]], 'uber-eats', $links, $persistedOrder);

    // Output order matches run 1 EXACTLY, not the shuffled upstream order —
    // known categories now sort by their PERSISTED position, not this call's
    // own array index.
    expect(collect($run2['categories'])->pluck('name')->all())->toBe($persistedOrder);
});

it('appends a genuinely new category after every persisted category, regardless of where it lands in this run\'s scrape order (fix-round)', function () {
    $links = storeLinks(['uber-eats' => ['delivery']]);

    $run1 = (new MenuMerger)->merge(['uber-eats' => [
        'store' => ['name' => 'Store', 'currency' => 'AUD'],
        'categories' => [
            ['name' => 'Mains', 'items' => [normItem(['name' => 'Steak', 'deliveryPrice' => 30.0])]],
            ['name' => 'Sides', 'items' => [normItem(['name' => 'Fries', 'deliveryPrice' => 6.0])]],
        ],
    ]], 'uber-eats', $links);
    $persistedOrder = collect($run1['categories'])->pluck('name')->all();
    expect($persistedOrder)->toBe(['Mains', 'Sides']);

    // Run 2 — a brand-new "Desserts" category is inserted FIRST in the raw
    // scrape (source position 0). If known-category order still fell back to
    // scrape position it would jump to the front; it must append AFTER the
    // known Mains/Sides instead.
    $run2 = (new MenuMerger)->merge(['uber-eats' => [
        'store' => ['name' => 'Store', 'currency' => 'AUD'],
        'categories' => [
            ['name' => 'Desserts', 'items' => [normItem(['name' => 'Tiramisu', 'deliveryPrice' => 9.0])]],
            ['name' => 'Mains', 'items' => [normItem(['name' => 'Steak', 'deliveryPrice' => 30.0])]],
            ['name' => 'Sides', 'items' => [normItem(['name' => 'Fries', 'deliveryPrice' => 6.0])]],
        ],
    ]], 'uber-eats', $links, $persistedOrder);

    expect(collect($run2['categories'])->pluck('name')->all())->toBe(['Mains', 'Sides', 'Desserts']);
});

it('is conservative: does not filter a genuine dish category that merely resembles ad copy (G6-1c)', function () {
    $dd = [
        'store' => ['name' => 'Store', 'currency' => 'AUD'],
        'categories' => [
            ['name' => 'Recommended Pizzas', 'items' => [normItem(['name' => 'Margherita', 'pickupPrice' => 18.0])]],
            ['name' => 'Add-Ons', 'items' => [normItem(['name' => 'Extra Cheese', 'pickupPrice' => 3.0])]],
            // A real build-your-own-style section sharing the OLD pattern's
            // "add ___ to your order" shape but with no trailing source-brand
            // clause — the critic's reproduced P1 false positive: the OLD
            // pattern (no "from <retailer>" requirement) dropped this.
            ['name' => 'Add Toppings to Your Order', 'items' => [normItem(['name' => 'Jalapenos', 'pickupPrice' => 1.5])]],
        ],
    ];
    $links = storeLinks(['doordash' => ['pickup']]);
    $merged = (new MenuMerger)->merge(['doordash' => $dd], 'doordash', $links);

    // All three are real categories (none matches the narrowed ad pattern) —
    // order follows source position, unaffected by the ad filter.
    expect(collect($merged['categories'])->pluck('name')->all())
        ->toBe(['Recommended Pizzas', 'Add-Ons', 'Add Toppings to Your Order']);
});

// ── fix-round: ad-filter narrowing (critic P1 — "Add ___ to Your Order"
// with no source-brand tail is a plausible real restaurant section name,
// not platform ad copy) ───────────────────────────────────────────────────

it('narrows the ad-filter to require a trailing source-brand clause, both directions (fix-round)', function () {
    $dd = [
        'store' => ['name' => 'Store', 'currency' => 'AUD'],
        'categories' => [
            // Plausible real menu sections sharing the "Add ___ to Your
            // Order" shape but with NO trailing "from <retailer>" — must
            // survive (these are the critic's exact reproduced strings).
            ['name' => 'Add Extras to Your Order', 'items' => [normItem(['name' => 'Extra Sauce', 'pickupPrice' => 1.0])]],
            ['name' => 'Add a Side to Your Order', 'items' => [normItem(['name' => 'Garlic Bread', 'pickupPrice' => 5.0])]],
            // The genuine platform-injected cross-sell shape — a trailing
            // "from <retailer>" clause — is still dropped.
            ['name' => 'Add Drinks to Your Order from Liquorland', 'items' => [normItem(['name' => 'Coke Can', 'pickupPrice' => 4.0])]],
        ],
    ];
    $links = storeLinks(['doordash' => ['pickup']]);
    $merged = (new MenuMerger)->merge(['doordash' => $dd], 'doordash', $links);

    $names = collect($merged['categories'])->pluck('name')->all();
    expect($names)->toBe(['Add Extras to Your Order', 'Add a Side to Your Order']);
    expect($names)->not->toContain('Add Drinks to Your Order from Liquorland');
});

// ── fix-round: observability (critic P2 — every destructive MenuMerger
// decision was silent; one Log::info line per drop / per merge EVENT, never
// per-item, so a bad ad-filter or dedupe decision can actually be noticed) ──

it('logs one line (name + platform) when an ad category is dropped (fix-round observability)', function () {
    Log::shouldReceive('info')->once()->with('menu.merger.ad_category_dropped', [
        'name' => 'Add Drinks to Your Order from Liquorland',
        'platform' => 'doordash',
    ]);

    $dd = [
        'store' => ['name' => 'Store', 'currency' => 'AUD'],
        'categories' => [
            ['name' => 'Antipasti', 'items' => [normItem(['name' => 'Bruschetta', 'pickupPrice' => 12.0])]],
            ['name' => 'Add Drinks to Your Order from Liquorland', 'items' => [normItem(['name' => 'Coke Can', 'pickupPrice' => 4.0])]],
        ],
    ];
    $links = storeLinks(['doordash' => ['pickup']]);
    (new MenuMerger)->merge(['doordash' => $dd], 'doordash', $links);
});

it('logs one line (names + platforms + item counts) when categories are deduped (fix-round observability)', function () {
    Log::shouldReceive('info')->once()->with('menu.merger.categories_deduped', [
        'names' => ['DOP Pizza', 'dop  pizza!'],
        'platforms' => ['uber-eats', 'doordash'],
        // UE's canonical category carries 2 items (Margherita, already fused
        // with DD's Margherita by item-level matching, + Capricciosa); DD's
        // leftover category carries 1 (Diavola — its own Margherita was
        // excluded from leftovers() as already-matched).
        'itemCounts' => [2, 1],
    ]);

    $ue = [
        'store' => ['name' => 'Store', 'currency' => 'AUD'],
        'categories' => [
            ['name' => 'DOP Pizza', 'items' => [
                normItem(['name' => 'Margherita', 'deliveryPrice' => 20.0]),
                normItem(['name' => 'Capricciosa', 'deliveryPrice' => 22.0]),
            ]],
        ],
    ];
    $dd = [
        'store' => ['name' => 'Store', 'currency' => 'AUD'],
        'categories' => [
            ['name' => 'dop  pizza!', 'items' => [
                normItem(['name' => 'Margherita', 'pickupPrice' => 20.0]),
                normItem(['name' => 'Diavola', 'pickupPrice' => 24.0]),
            ]],
        ],
    ];
    $links = storeLinks(['uber-eats' => ['delivery'], 'doordash' => ['pickup']]);
    (new MenuMerger)->merge(['uber-eats' => $ue, 'doordash' => $dd], 'uber-eats', $links);
});

it('logs nothing when nothing is dropped or deduped (no spam on the common case)', function () {
    Log::shouldReceive('info')->never();

    $dd = [
        'store' => ['name' => 'Store', 'currency' => 'AUD'],
        'categories' => [
            ['name' => 'Mains', 'items' => [normItem(['name' => 'Steak', 'pickupPrice' => 30.0])]],
            ['name' => 'Sides', 'items' => [normItem(['name' => 'Fries', 'pickupPrice' => 6.0])]],
        ],
    ];
    $links = storeLinks(['doordash' => ['pickup']]);
    (new MenuMerger)->merge(['doordash' => $dd], 'doordash', $links);
});
