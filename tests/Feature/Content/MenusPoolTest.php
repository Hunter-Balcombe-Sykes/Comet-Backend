<?php

use App\Models\Core\Site\Site;
use App\Services\Migration\MenuBackfiller;
use App\Site\Pools\PoolRegistry;
use App\Site\Pools\PoolResolver;
use App\Site\Pools\PoolSectionProvisioner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupItemSlugsTable();
    setupIngestTables();
    setupContentTables();
    setupSectionsTables();
    Queue::fake();
});

/** Pin every landed dish so the pool resolves a non-empty selection. */
function pinEveryMenuItem(string $siteId): void
{
    $site = Site::query()->findOrFail($siteId);
    $section = app(PoolSectionProvisioner::class)->ensure($site, 'menus');

    $sortKey = 1;
    foreach (DB::connection('pgsql')->table('content.items')->where('kind', 'menu_item')->pluck('id') as $itemId) {
        DB::connection('pgsql')->table('site.section_items')->insert([
            'id' => (string) Str::uuid(),
            'section_id' => $section->id,
            'item_id' => $itemId,
            'state' => 'pinned',
            'sort_key' => $sortKey++,
            'created_at' => now()->toDateTimeString(),
        ]);
    }
}

it('owns the menu_item kind and nothing else', function () {
    expect(PoolRegistry::kinds('menus'))->toBe(['menu_item'])
        ->and(PoolRegistry::poolForKind('menu_item'))->toBe('menus');
});

it('provisions the settled priced-undated shape, not latest_per_auto_source', function () {
    // latest_per_auto_source emits ONE item per connection source, which for a
    // 156-dish menu means one dish visible and 155 hidden — the pathology
    // events hit in slice 2 and media in slice 1a.
    expect(PoolRegistry::sectionShape('menus'))->toBe([
        'rule' => [['op' => 'kind_is', 'values' => ['menu_item']]],
        'order_by' => 'recency',
    ]);
});

it('reuses the shape services and shop reconciled on, rather than inventing a third', function () {
    expect(PoolRegistry::SECTION_SHAPE['menus'])
        ->toBe(PoolRegistry::SECTION_SHAPE['services'])
        ->toBe(PoolRegistry::SECTION_SHAPE['shop']);
});

it('is a full-curation pool — a dish is the owner\'s own content', function () {
    expect(PoolRegistry::allowsPin('menus'))->toBeTrue()
        ->and(PoolRegistry::allowsManualAdd('menus'))->toBeTrue()
        ->and(PoolRegistry::carriesSourceStats('menus'))->toBeFalse()
        // A "latest dish" is meaningless — the badge would label whichever dish
        // the vendor happened to re-list most recently as new.
        ->and(PoolRegistry::carriesLatestTag('menus'))->toBeFalse();
});

it('hangs its curation off the menu page', function () {
    expect(PoolRegistry::PAGE_KEYS['menus'])->toBe('menu')
        ->and(PoolRegistry::PAGE_LABELS['menus'])->toBe('Menu')
        ->and(PoolRegistry::sectionKey('menus'))->toBe('pool:menus')
        ->and(PoolRegistry::poolForSectionKey('pool:menus'))->toBe('menus');
});

// ── The wire (Unit 3 + Unit 6) ──────────────────────────────────────────────

it('publishes menu categories in the collections map, not as dangling ids', function () {
    // itemPayloads() used to join collections THROUGH content.storefronts and
    // gate on kind='storefront'. A menu category has no sidecar, so every dish
    // would have published collectionIds pointing at collections absent from
    // the map.
    [$userId, $siteId] = seedMenuWithDishes(['Iced Latte'], categories: ['Drinks']);
    app(MenuBackfiller::class)->run();
    pinEveryMenuItem($siteId);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'menus');
    $referenced = collect($resolved['selection'])->flatMap(fn (array $i) => $i['collectionIds'])->unique()->values();

    expect($referenced)->not->toBeEmpty()
        ->and(array_keys($resolved['collections']))->toEqualCanonicalizing($referenced->all());
});

it('carries the same key set on a sidecar-less collection as on a store card', function () {
    [$userId, $siteId] = seedMenuWithDishes(['Iced Latte'], categories: ['Drinks']);
    app(MenuBackfiller::class)->run();
    pinEveryMenuItem($siteId);

    $entry = collect(app(PoolResolver::class)
        ->resolve(Site::query()->findOrFail($siteId), 'menus')['collections'])->first();

    expect(array_keys($entry))->toEqualCanonicalizing(PoolResolver::STORE_KEYS);
});

it('nulls the store-only fields on a category and fills them on an order platform', function () {
    [$userId, $siteId, $menuId] = seedMenuWithDishes(['Iced Latte'], categories: ['Drinks'], platforms: ['uber_eats']);
    DB::connection('pgsql')->table('site.menu_platform_links')->insert([
        'id' => (string) Str::uuid(), 'menu_id' => $menuId, 'platform' => 'uber_eats',
        'store_url' => 'https://ubereats.com/store/x', 'status' => 'ok',
        'created_at' => now()->toDateTimeString(), 'updated_at' => now()->toDateTimeString(),
    ]);
    app(MenuBackfiller::class)->run();
    pinEveryMenuItem($siteId);

    $collections = collect(app(PoolResolver::class)
        ->resolve(Site::query()->findOrFail($siteId), 'menus')['collections']);

    $category = $collections->firstWhere('externalRef', 'menu:drinks');
    $platform = $collections->firstWhere('externalRef', 'order:uber_eats');

    expect($category['provider'])->toBeNull()
        ->and($category['url'])->toBeNull()
        ->and($category['name'])->toBe('Drinks')
        ->and($platform['provider'])->toBe('uber_eats')
        ->and($platform['url'])->toBe('https://ubereats.com/store/x');
});

it('serves the menu dining modes on the pool envelope and null everywhere else', function () {
    [$userId, $siteId, $menuId] = seedMenuWithDishes(['Iced Latte']);
    DB::connection('pgsql')->table('site.menus')->where('id', $menuId)
        ->update(['dining_modes' => json_encode(['DELIVERY', 'PICKUP'])]);
    app(MenuBackfiller::class)->run();
    pinEveryMenuItem($siteId);

    $site = Site::query()->findOrFail($siteId);

    expect(app(PoolResolver::class)->resolve($site, 'menus')['diningModes'])->toBe(['DELIVERY', 'PICKUP'])
        ->and(app(PoolResolver::class)->resolve($site, 'services')['diningModes'])->toBeNull();
});

it('treats an empty dining_modes array as absent rather than publishing nothing', function () {
    [$userId, $siteId, $menuId] = seedMenuWithDishes(['Iced Latte']);
    DB::connection('pgsql')->table('site.menus')->where('id', $menuId)->update(['dining_modes' => json_encode([])]);
    app(MenuBackfiller::class)->run();
    pinEveryMenuItem($siteId);

    expect(app(PoolResolver::class)
        ->resolve(Site::query()->findOrFail($siteId), 'menus')['diningModes'])
        ->toBeNull();
});

it('hides a collection the owner deleted, even while its dishes stay selected', function () {
    // removed_at is one-way on a collection. A group header reappearing because
    // its members are still on the page is the failure this guards.
    [$userId, $siteId] = seedMenuWithDishes(['Iced Latte'], categories: ['Drinks']);
    app(MenuBackfiller::class)->run();
    pinEveryMenuItem($siteId);

    DB::connection('pgsql')->table('content.collections')
        ->where('kind', 'menu_category')->update(['removed_at' => now()->toDateTimeString()]);

    $resolved = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'menus');

    expect(collect($resolved['collections'])->firstWhere('externalRef', 'menu:drinks'))->toBeNull()
        ->and($resolved['selection'])->not->toBeEmpty();
});
