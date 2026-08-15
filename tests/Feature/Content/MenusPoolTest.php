<?php

use App\Site\Pools\PoolRegistry;

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
