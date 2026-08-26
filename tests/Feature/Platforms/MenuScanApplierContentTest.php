<?php

use App\Models\Core\Site\Menu;
use App\Models\Core\User\User;
use App\Services\Content\ManualMenuItems;
use App\Services\Content\ManualMenuWriter;
use App\Services\Platforms\MenuProjectionMapper;
use App\Services\Platforms\MenuScanApplier;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// Slice 7 Task 8: the AI-scan lane writes content.* through ManualMenuWriter.
// Every behaviour the legacy applier's docblock promised is asserted here
// against content.items / content.collections — never against site.menu_items,
// which this lane no longer writes at all.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    Queue::fake();
});

function msacUser(string $suffix = ''): User
{
    return createTenant('msac'.($suffix !== '' ? $suffix : Str::lower(Str::random(6))), [
        'account_type' => 'business',
        'sector' => 'restaurant',
    ]);
}

/** The user's menu — the coord's namespace, and the row this lane still writes. */
function msacMenu(User $user, string $source = 'uber-eats'): Menu
{
    return Menu::query()->where('user_id', $user->id)->first()
        ?? Menu::create([
            'user_id' => $user->id,
            'content_source' => $source,
            'currency' => 'AUD',
            'fetch_status' => 'ok',
            'last_fetched_at' => now(),
        ]);
}

/**
 * Land a SCRAPED-shaped dish exactly as MenuFetchJob's persist will: through
 * the mapper, so its categories carry the plain `menu:<slug>` refs that make
 * them scraper-owned.
 *
 * @param  array<string, mixed>  $dish
 * @param  list<string>  $categories
 * @param  list<array<string, mixed>>  $platformRows
 */
function msacSeedScraped(User $user, Menu $menu, array $dish, array $categories = [], array $platformRows = []): string
{
    $writer = app(ManualMenuWriter::class);

    return $writer->write(
        (string) $user->id,
        $writer->coordFor((string) $menu->id, (string) $dish['name']),
        $writer->projectionFor(
            (object) $dish,
            array_map(fn (string $name, int $i) => ['id' => (string) Str::uuid(), 'name' => $name, 'position' => $i],
                $categories, array_keys($categories)),
            array_map(fn (array $row) => (object) $row, $platformRows),
            $menu,
        ),
    );
}

/** @return Collection<int, stdClass> */
function msacCategories(User $user)
{
    return app(ManualMenuItems::class)->categories((string) $user->id);
}

function msacRows(User $user)
{
    return app(ManualMenuItems::class)->rows((string) $user->id);
}

// ── One normalised name = one dish, menu-wide (now the coord's own rule) ─────

it('addresses a dish by its coord, so two spellings of one name stay one item', function () {
    $user = msacUser();
    $menu = msacMenu($user);

    // The two spellings differ in case AND punctuation — they hash to the same
    // coord, which IS the match rule now that the lookup is gone.
    expect(MenuProjectionMapper::coordFor((string) $menu->id, 'Pizza Margherita'))
        ->toBe(MenuProjectionMapper::coordFor((string) $menu->id, 'pizza  margherita!'));

    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Pizza Margherita', 'description' => 'Classic', 'price' => 18.0, 'category' => 'Pizza'],
        ['name' => 'pizza  margherita!', 'description' => 'Classic', 'price' => 18.0, 'category' => 'Pizza'],
    ]);

    expect(msacRows($user))->toHaveCount(1);
});

it('matches a SCRAPED dish menu-wide and updates it in place rather than adding a second', function () {
    $user = msacUser();
    $menu = msacMenu($user);
    $scrapedId = msacSeedScraped($user, $menu,
        ['name' => 'Garlic Bread', 'description' => 'Bread.', 'base_price' => 12.0],
        ['Sides'],
    );

    $result = app(MenuScanApplier::class)->apply($user, [
        ['name' => 'garlic bread', 'description' => 'House-made sourdough with roasted garlic butter.', 'price' => null, 'category' => null],
    ]);

    $rows = msacRows($user);

    expect($result)->toBe(['updated' => 1, 'added' => 0])
        ->and($rows)->toHaveCount(1)
        ->and((string) $rows[0]->id)->toBe($scrapedId)
        // The stored headline wins — a scan never renames a dish it matched.
        ->and($rows[0]->headline)->toBe('Garlic Bread')
        ->and($rows[0]->description)->toContain('roasted garlic butter');
});

// ── A field the scan omitted never nulls out existing content ────────────────

it('leaves every column the scan did not supply exactly as it was', function () {
    // The failure this pins is structural, not cosmetic: ProjectionWriter
    // REPLACES media / offers / tags / collection_items per (item, source), so
    // a partial projection deletes the dish's images, its per-platform prices
    // and its categories. Only a full re-projection survives it.
    $user = msacUser();
    $menu = msacMenu($user);
    msacSeedScraped($user, $menu, [
        'name' => 'Iced Latte',
        'description' => 'Cold and strong',
        'base_price' => 5.5,
        'pickup_price' => 5.0,
        'delivery_price' => 6.5,
        'image_url' => 'https://cdn.test/hero.jpg',
        'images' => ['https://cdn.test/hero.jpg', 'https://cdn.test/second.jpg'],
        'rating' => 96.0,
        'rating_count' => 41,
        'badges' => ['Popular'],
    ], ['Drinks'], [[
        'platform' => 'uber_eats', 'delivery_price' => 6.5,
        'item_url' => 'https://ubereats.com/store/x/sec/sub/item-uuid', 'external_ref' => 'item-uuid', 'sold_out' => false,
    ]]);

    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Iced Latte', 'description' => 'Single origin, cold brewed for eighteen hours.', 'price' => null, 'category' => null],
    ]);

    $row = msacRows($user)[0];

    expect($row->description)->toContain('eighteen hours')
        ->and($row->base_price)->toBe(5.5)
        ->and($row->pickup_price)->toBe(5.0)
        ->and($row->delivery_price)->toBe(6.5)
        ->and($row->currency)->toBe('AUD')
        ->and($row->image_url)->toBe('https://cdn.test/hero.jpg')
        ->and($row->images)->toBe(['https://cdn.test/hero.jpg', 'https://cdn.test/second.jpg'])
        ->and($row->rating)->toBe(96.0)
        ->and($row->rating_count)->toBe(41)
        ->and($row->badges)->toBe([['text' => 'Popular']])
        ->and($row->category_ids)->toHaveCount(1)
        ->and(collect($row->platforms)->pluck('platform')->all())->toBe(['uber_eats'])
        ->and($row->platforms[0]->item_url)->toBe('https://ubereats.com/store/x/sec/sub/item-uuid')
        ->and($row->platforms[0]->external_ref)->toBe('item-uuid')
        ->and($row->platforms[0]->sold_out)->toBeFalse();
});

// ── One dish listed under several categories stays ONE dish ─────────────────

it('grows a dish\'s collection memberships instead of minting a second item', function () {
    $user = msacUser();

    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Garlic Bread', 'description' => null, 'price' => 9.0, 'category' => 'Lunch'],
        ['name' => 'Garlic Bread', 'description' => null, 'price' => 9.0, 'category' => 'Dinner'],
    ]);

    $rows = msacRows($user);
    $labels = msacCategories($user)->keyBy('id');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->category_ids)->toHaveCount(2)
        ->and(collect($rows[0]->category_ids)->map(fn ($id) => $labels[$id]->label)->sort()->values()->all())
        ->toBe(['Dinner', 'Lunch']);
});

it('grows the memberships of a dish scanned again in a LATER apply', function () {
    $user = msacUser();

    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Garlic Bread', 'description' => null, 'price' => 9.0, 'category' => 'Lunch'],
    ]);
    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Garlic Bread', 'description' => null, 'price' => 9.0, 'category' => 'Dinner'],
    ]);

    expect(msacRows($user))->toHaveCount(1)
        ->and(msacRows($user)[0]->category_ids)->toHaveCount(2);
});

it('does not re-attach a category the dish is already listed under, whatever the casing', function () {
    $user = msacUser();
    $menu = msacMenu($user);
    msacSeedScraped($user, $menu, ['name' => 'Garlic Bread', 'base_price' => 9.0], ['Sides']);

    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Garlic Bread', 'description' => null, 'price' => null, 'category' => '  sides '],
    ]);

    // A scraped "Sides" already covers the scan's "sides" — shadowing it with a
    // scan-owned duplicate is what this rule exists to prevent.
    expect(msacRows($user)[0]->category_ids)->toHaveCount(1)
        ->and(msacCategories($user))->toHaveCount(1);
});

// ── A no-match lands under a SCAN-OWNED category, never a scraped one ────────

it('creates a new dish under a scan-owned category rather than reusing the scraped one', function () {
    $user = msacUser();
    $menu = msacMenu($user);
    msacSeedScraped($user, $menu, ['name' => 'Margherita', 'base_price' => 18.0], ['Pizza']);

    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Capricciosa', 'description' => null, 'price' => 22.0, 'category' => 'Pizza'],
    ]);

    $categories = msacCategories($user)->keyBy('external_ref');

    expect($categories->keys()->sort()->values()->all())
        ->toBe([MenuProjectionMapper::categoryRef('Pizza'), MenuScanApplier::categoryRefFor('scan', 'Pizza')]);

    $rows = msacRows($user)->keyBy('headline');
    expect($rows['Capricciosa']->category_ids)
        ->toBe([(string) $categories[MenuScanApplier::categoryRefFor('scan', 'Pizza')]->id])
        ->and($rows['Margherita']->category_ids)
        ->toBe([(string) $categories[MenuProjectionMapper::categoryRef('Pizza')]->id]);
});

it('reuses its OWN scan category across applies, matched case-insensitively on the trimmed name', function () {
    $user = msacUser();

    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Capricciosa', 'description' => null, 'price' => 22.0, 'category' => 'Pizza'],
    ]);
    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Quattro Formaggi', 'description' => null, 'price' => 24.0, 'category' => '  PIZZA '],
    ]);

    expect(msacCategories($user))->toHaveCount(1)
        ->and((string) msacCategories($user)[0]->external_ref)
        ->toBe(MenuScanApplier::categoryRefFor('scan', 'Pizza'));
});

it('files an uncategorised scan item under the default category', function () {
    $user = msacUser();

    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Mystery Special', 'description' => null, 'price' => 12.0, 'category' => null],
    ]);

    expect(msacCategories($user)->pluck('label')->all())->toBe(['Menu']);
});

it('keeps the "scan" and "website-scan" namespaces apart for the same category name', function () {
    $user = msacUser();

    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Margherita', 'description' => null, 'price' => 18.0, 'category' => 'Pizza'],
    ]);
    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Carbonara', 'description' => null, 'price' => 20.0, 'category' => 'Pizza'],
    ], enrichOnly: true, source: 'website-scan');

    expect(msacCategories($user)->pluck('external_ref')->sort()->values()->all())->toBe([
        MenuScanApplier::categoryRefFor('scan', 'Pizza'),
        MenuScanApplier::categoryRefFor('website-scan', 'Pizza'),
    ]);
});

it('namespaces owner categories away from every reachable scraped ref', function () {
    // Str::slug emits [a-z0-9-] only, so `menu:scan:…` is structurally
    // unreachable from a vendor's category label — which is what makes the ref
    // namespace a safe substitute for menu_categories.source_platform.
    expect(MenuScanApplier::categoryRefFor('scan', 'Pizza'))->toBe('menu:scan:pizza')
        ->and(MenuProjectionMapper::categoryRef('scan: Pizza'))->toBe('menu:scan-pizza')
        ->and(MenuScanApplier::isOwnerCategoryRef('menu:scan:pizza'))->toBeTrue()
        ->and(MenuScanApplier::isOwnerCategoryRef('menu:website-scan:pizza'))->toBeTrue()
        ->and(MenuScanApplier::isOwnerCategoryRef('menu:manual:pizza'))->toBeTrue()
        ->and(MenuScanApplier::isOwnerCategoryRef('menu:scan-pizza'))->toBeFalse()
        ->and(MenuScanApplier::isOwnerCategoryRef(null))->toBeFalse();
});

// ── Enrich-only conservatism ────────────────────────────────────────────────

it('never restructures a SCRAPED dish\'s categories on an automatic scan', function () {
    $user = msacUser();
    $menu = msacMenu($user);
    msacSeedScraped($user, $menu, ['name' => 'Garlic Bread', 'base_price' => 9.0], ['Sides']);

    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Garlic Bread', 'description' => null, 'price' => null, 'category' => 'Starters'],
    ], enrichOnly: true);

    expect(msacRows($user)[0]->category_ids)->toHaveCount(1)
        ->and(msacCategories($user)->pluck('label')->all())->toBe(['Sides']);
});

it('completes a SCAN-created dish\'s memberships on an automatic scan', function () {
    $user = msacUser();

    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Garlic Bread', 'description' => null, 'price' => 9.0, 'category' => 'Lunch'],
    ]);
    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Garlic Bread', 'description' => null, 'price' => null, 'category' => 'Dinner'],
    ], enrichOnly: true);

    expect(msacRows($user)[0]->category_ids)->toHaveCount(2);
});

it('fills a missing price but never overwrites a scraped one', function () {
    $user = msacUser();
    $menu = msacMenu($user);
    msacSeedScraped($user, $menu, ['name' => 'Garlic Bread', 'base_price' => 12.0], ['Sides']);

    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Garlic Bread', 'description' => null, 'price' => 9.0, 'category' => null],
    ], enrichOnly: true);
    expect(msacRows($user)[0]->base_price)->toBe(12.0);

    $unpriced = msacUser();
    $unpricedMenu = msacMenu($unpriced);
    msacSeedScraped($unpriced, $unpricedMenu, ['name' => 'Garlic Bread'], ['Sides']);

    app(MenuScanApplier::class)->apply($unpriced, [
        ['name' => 'Garlic Bread', 'description' => null, 'price' => 9.0, 'category' => null],
    ], enrichOnly: true);
    expect(msacRows($unpriced)[0]->base_price)->toBe(9.0);
});

// ── Dietary badges merge in BOTH modes ──────────────────────────────────────

it('merges scanned dietary markers into existing badges without duplicates, in both modes', function () {
    foreach ([false, true] as $enrichOnly) {
        $user = msacUser();
        $menu = msacMenu($user);
        msacSeedScraped($user, $menu,
            ['name' => 'Green Curry', 'base_price' => 24.0, 'badges' => [['text' => 'Popular'], ['text' => 'Vegan', 'type' => 'dietary']]],
            ['Mains'],
        );

        app(MenuScanApplier::class)->apply($user, [
            ['name' => 'Green Curry', 'description' => null, 'price' => null, 'category' => null, 'dietary' => ['vegan', 'Gluten free']],
        ], enrichOnly: $enrichOnly);

        // Deduped case-insensitively against what the platforms already carry;
        // alphabetical because content.item_tags has no ordinal column.
        expect(collect(msacRows($user)[0]->badges)->pluck('text')->all())
            ->toBe(['Gluten free', 'Popular', 'Vegan']);
    }
});

it('badges a brand-new scan item with its dietary markers', function () {
    $user = msacUser();

    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Buckwheat Pancakes', 'description' => 'House made', 'price' => 18.0, 'category' => 'Breakfast', 'dietary' => ['Gluten free']],
    ]);

    // `type` does not survive the projection — item_tags spends its one
    // classification column on tag_type='badge' (MenuProjectionMapper::badges).
    expect(msacRows($user)[0]->badges)->toBe([['text' => 'Gluten free']]);
});

// ── The is_manual successor ─────────────────────────────────────────────────

it('leaves a dish the owner has hand-edited untouched, and adds no duplicate', function () {
    $user = msacUser();
    $menu = msacMenu($user);
    $itemId = msacSeedScraped($user, $menu, ['name' => 'Garlic Bread', 'description' => 'Bread.', 'base_price' => 12.0], ['Sides']);

    DB::connection('pgsql')->table('content.manual_overrides')->insert([
        'id' => (string) Str::uuid(),
        'item_id' => $itemId,
        'facet' => 'f_text',
        'column_name' => 'body',
        'value' => json_encode('Owner wrote this.'),
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    $result = app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Garlic Bread', 'description' => 'Scan wrote this instead.', 'price' => 9.0, 'category' => 'Starters'],
    ]);

    expect($result)->toBe(['updated' => 0, 'added' => 0])
        ->and(msacRows($user))->toHaveCount(1)
        ->and(msacRows($user)[0]->description)->toBe('Bread.')
        ->and(msacRows($user)[0]->base_price)->toBe(12.0);
});

// ── It no longer writes the legacy tables at all ────────────────────────────

it('writes content.* and leaves site.menu_items empty', function () {
    $user = msacUser();

    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Margherita', 'description' => 'Classic', 'price' => 18.0, 'category' => 'Pizza'],
    ]);

    expect(DB::connection('pgsql')->table('site.menu_items')->count())->toBe(0)
        ->and(DB::connection('pgsql')->table('site.menu_categories')->count())->toBe(0)
        ->and(msacRows($user))->toHaveCount(1);
});

it('still stamps the menu row it is scoped to', function () {
    $user = msacUser();

    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Margherita', 'description' => null, 'price' => 18.0, 'category' => 'Pizza'],
    ], enrichOnly: true, source: 'website-scan');

    $menu = Menu::query()->where('user_id', $user->id)->firstOrFail();
    expect($menu->content_source)->toBe('website-scan')
        ->and($menu->last_fetched_at)->not->toBeNull();
});

it('does not resurrect an owner-deleted dish, and does not clobber it either', function () {
    $user = msacUser();
    $menu = msacMenu($user);
    $itemId = msacSeedScraped($user, $menu, ['name' => 'Garlic Bread', 'description' => 'Bread.', 'base_price' => 12.0], ['Sides']);
    DB::connection('pgsql')->table('content.items')->where('id', $itemId)
        ->update(['removed_at' => now()->toDateTimeString()]);

    app(MenuScanApplier::class)->apply($user, [
        ['name' => 'Garlic Bread', 'description' => null, 'price' => null, 'category' => 'Starters'],
    ]);

    expect(msacRows($user))->toHaveCount(0)
        ->and(app(ManualMenuItems::class)->rows((string) $user->id, includeRemoved: true))->toHaveCount(1);
});
