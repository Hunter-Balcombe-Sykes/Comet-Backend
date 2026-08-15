<?php

use App\Services\Platforms\MenuProjectionMapper;

function menuDish(array $overrides = []): object
{
    return (object) array_merge([
        'id' => '33008fc0-84f6-4e74-bf75-e6b63cc8ca63',
        'name' => 'Iced Latte',
        'description' => 'Cold and strong',
        'image_url' => 'https://cdn.example/latte.jpg',
        'images' => json_encode(['https://cdn.example/latte.jpg', 'https://cdn.example/latte-2.jpg']),
        'rating' => 4.7,
        'rating_count' => 31,
        'badges' => json_encode(['Popular', 'Vegetarian']),
        'base_price' => 5.50,
        'pickup_price' => 5.00,
        'pickup_source' => 'uber_eats',
        'delivery_price' => 6.50,
        'delivery_source' => 'doordash',
        'currency' => 'AUD',
        'dd_external_id' => 'dd-9911',
        'is_manual' => false,
    ], $overrides);
}

function menuRow(?string $currency = 'AUD'): object
{
    return (object) ['id' => '019f649c-32aa-714e-9ad7-1dbb1a620cb5', 'currency' => $currency];
}

it('derives the coord from the menu and the normalised name, not the row uuid', function () {
    // MenuFetchJob deletes and re-inserts every dish, reusing the uuid via
    // takeReusedId() keyed on the NORMALISED NAME. The uuid therefore churns on
    // a vendor rename, and manual:{uuid} would mint a second item and strand
    // the first. The name is what the legacy writer itself treats as identity.
    $menu = menuRow();

    expect(MenuProjectionMapper::coordFor($menu->id, 'Iced Latte'))
        ->toBe(MenuProjectionMapper::coordFor($menu->id, '  ICED   LATTE!  '))
        ->toBe("manual:menu:{$menu->id}:".sha1('iced latte'));
});

it('scopes the coord to the menu so two vendors keep distinct dishes', function () {
    expect(MenuProjectionMapper::coordFor('menu-a', 'Garlic Bread'))
        ->not->toBe(MenuProjectionMapper::coordFor('menu-b', 'Garlic Bread'));
});

it('maps the aggregate prices to base, pickup and delivery offers', function () {
    $offers = collect((new MenuProjectionMapper)->project(menuDish(), [], [], menuRow())['offers']);

    expect($offers->firstWhere('channel', 'base'))
        ->toMatchArray(['amount_minor' => 550, 'currency' => 'AUD', 'qualifier' => 'exact'])
        ->and($offers->firstWhere('channel', 'pickup'))->toMatchArray(['amount_minor' => 500])
        ->and($offers->firstWhere('channel', 'delivery'))->toMatchArray(['amount_minor' => 650]);
});

it('falls back to the menu currency, and leaves it null when the menu has none', function () {
    // 93 of 318 live rows carry a NULL currency. A menu is one vendor in one
    // country, so the menu's own currency is the honest default — not a
    // hardcoded AUD, which would be a guess about where the vendor trades.
    $withMenu = (new MenuProjectionMapper)->project(menuDish(['currency' => null]), [], [], menuRow('AUD'));
    $without = (new MenuProjectionMapper)->project(menuDish(['currency' => null]), [], [], menuRow(null));

    expect(collect($withMenu['offers'])->firstWhere('channel', 'base')['currency'])->toBe('AUD')
        ->and(collect($without['offers'])->firstWhere('channel', 'base')['currency'])->toBeNull();
});

it('prices a zero-cost dish as free, staying inside offers_qualifier_check', function () {
    $offers = collect((new MenuProjectionMapper)->project(menuDish(['base_price' => 0]), [], [], menuRow())['offers']);

    expect($offers->firstWhere('channel', 'base')['qualifier'])->toBe('free');
});

it('emits one offer per platform per priced mode, carrying the order url', function () {
    $platforms = [
        (object) ['platform' => 'uber_eats', 'pickup_price' => 5.00, 'pickup_url' => 'https://ue/x', 'delivery_price' => 6.00, 'delivery_url' => 'https://ue/y'],
        (object) ['platform' => 'doordash', 'pickup_price' => null, 'pickup_url' => null, 'delivery_price' => 6.50, 'delivery_url' => 'https://dd/z'],
    ];

    $perPlatform = collect((new MenuProjectionMapper)->project(menuDish(), [], $platforms, menuRow())['offers'])
        ->filter(fn (array $o) => $o['url'] !== null);

    // content.offers is a SET and is never resolved to a winner: two platforms
    // selling the same dish at different prices are both true, and hiding one
    // would be a lie about where the visitor can buy.
    expect($perPlatform)->toHaveCount(3)
        ->and($perPlatform->where('channel', 'delivery')->pluck('amount_minor')->sort()->values()->all())
        ->toBe([600, 650]);
});

it('emits collections from the categories, because the identity key reads them', function () {
    // offering_name_in_category is norm(category)|norm(dish) at minLength 5 —
    // it is what makes "Fries" and "Cola" mergeable at all, and it is derived
    // from the projection's collections entries, not from its tags.
    $projection = (new MenuProjectionMapper)->project(menuDish(), [
        ['id' => 'cat-1', 'name' => 'Drinks', 'position' => 2],
        ['id' => 'cat-2', 'name' => 'Coffee', 'position' => 5],
    ], [], menuRow());

    // external_ref is derived from the LABEL, not the legacy category uuid —
    // MenuItemProjector has no uuid available (MenuRecords carries no category
    // id) and must land on the same ref, or the two lanes mint the category
    // twice. categoryRef()'s docblock carries the full reasoning.
    expect($projection['collections'])->toBe([
        ['kind' => 'menu_category', 'external_ref' => 'menu:drinks', 'label' => 'Drinks', 'position' => 2],
        ['kind' => 'menu_category', 'external_ref' => 'menu:coffee', 'label' => 'Coffee', 'position' => 5],
    ]);
});

it('drops a category with a blank label rather than keying identity off nothing', function () {
    // upsertCollections() skips an entry with an empty label anyway; dropping
    // it here keeps the projection honest about what it claims to carry.
    $projection = (new MenuProjectionMapper)->project(menuDish(), [
        ['id' => 'cat-1', 'name' => '   ', 'position' => 0],
    ], [], menuRow());

    expect($projection['collections'])->toBe([]);
});

it('maps description, badges, rating and the hero-first image set', function () {
    $projection = (new MenuProjectionMapper)->project(menuDish(), [], [], menuRow());

    expect($projection['kind'])->toBe('menu_item')
        ->and($projection['headline'])->toBe('Iced Latte')
        ->and($projection['facets']['f_text']['body'])->toBe('Cold and strong')
        ->and($projection['facets']['f_rated'])->toMatchArray(['rating' => 4.7, 'ratings_count' => 31])
        ->and($projection['tags'])->toBe([
            ['tag' => 'Popular', 'tag_type' => 'badge'],
            ['tag' => 'Vegetarian', 'tag_type' => 'badge'],
        ])
        ->and($projection['media'][0])->toMatchArray(['role' => 'cover', 'url' => 'https://cdn.example/latte.jpg']);
});

it('does not duplicate image_url when it is already the hero of images', function () {
    $projection = (new MenuProjectionMapper)->project(menuDish(), [], [], menuRow());

    expect(collect($projection['media'])->pluck('url')->all())
        ->toBe(['https://cdn.example/latte.jpg', 'https://cdn.example/latte-2.jpg']);
});

it('omits absent facets rather than writing nulls', function () {
    $bare = menuDish([
        'description' => null, 'rating' => null, 'rating_count' => null,
        'badges' => null, 'images' => null, 'image_url' => null,
    ]);

    $projection = (new MenuProjectionMapper)->project($bare, [], [], menuRow());

    expect($projection['facets'])->toBe([])
        ->and($projection['tags'])->toBe([])
        ->and($projection['media'])->toBe([]);
});

it('keeps a rating with no count, and a count with no rating', function () {
    // f_rated's columns are independently nullable, and DoorDash supplies a
    // thumbs-up count for dishes it has no star average for.
    $ratingOnly = (new MenuProjectionMapper)->project(menuDish(['rating_count' => null]), [], [], menuRow());
    $countOnly = (new MenuProjectionMapper)->project(menuDish(['rating' => null]), [], [], menuRow());

    expect($ratingOnly['facets']['f_rated'])->toBe(['rating' => 4.7])
        ->and($countOnly['facets']['f_rated'])->toBe(['ratings_count' => 31]);
});
