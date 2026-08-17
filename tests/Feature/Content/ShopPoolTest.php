<?php

use App\Site\Pools\PoolRegistry;

it('registers shop as a pool owning the product kind', function () {
    expect(PoolRegistry::POOLS['shop'])->toBe(['product'])
        ->and(PoolRegistry::PAGE_KEYS['shop'])->toBe('shop')
        ->and(PoolRegistry::PAGE_LABELS['shop'])->toBe('Shop')
        ->and(PoolRegistry::sectionKey('shop'))->toBe('pool:shop')
        ->and(PoolRegistry::poolForKind('product'))->toBe('shop');
});

// Opt-in (owner, 2026-08-17): a connected store fills the LIBRARY, and only
// pins plus each store's newest-while-auto-latest-is-on publish — the
// watch/listen default. kind_is alone put a whole catalogue on the page.
it('shapes the shop section as pins plus latest-per-source, not the whole catalogue', function () {
    expect(PoolRegistry::sectionShape('shop'))->toBe([
        'rule' => [
            ['op' => 'kind_is', 'values' => ['product']],
            ['op' => 'latest_per_auto_source', 'values' => ['product']],
        ],
        'order_by' => 'recency',
    ]);
});

// 5a §7 decided this both ways and excluded shop: hand-ordering fights a
// Latest badge, pool recency is last_seen_at (a sync artefact, not product
// newness), and 16 of 51 dev products are unavailable so the badge can land
// on sold-out stock.
it('excludes shop from the Latest tag', function () {
    expect(PoolRegistry::carriesLatestTag('shop'))->toBeFalse()
        ->and(PoolRegistry::LATEST_TAG_POOLS)->not->toContain('shop');
});
