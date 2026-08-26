<?php

use App\Services\Profile\SectorActionRecipes;

function sarPlatform(string $key, ?string $page, string $connectedAt = '2026-08-01T00:00:00+00:00'): array
{
    return [
        'id' => 'platform:'.$key, 'kind' => 'platform', 'label' => ucfirst($key),
        'url' => 'https://example.com/'.$key, 'thumb' => null,
        'connectedAt' => $connectedAt, 'ref' => null,
        'meta' => ['platformKey' => $key, 'page' => $page, 'fallback' => false],
    ];
}

function sarPage(string $id): array
{
    return [
        'id' => 'page:'.$id, 'kind' => 'page', 'label' => ucfirst($id),
        'url' => '/'.$id, 'thumb' => null, 'connectedAt' => '2026-07-01T00:00:00+00:00',
        'ref' => null, 'meta' => ['pageId' => $id],
    ];
}

function sarItem(string $id, string $pool, ?string $at, bool $undated = false): array
{
    return [
        'id' => 'item:'.$id, 'kind' => 'item', 'label' => 'Item '.$id,
        'url' => '/'.$pool.'#'.$id, 'thumb' => null, 'connectedAt' => $at,
        'ref' => ['pool' => $pool, 'itemId' => $id],
        'meta' => ['pool' => $pool, 'undated' => $undated],
    ];
}

it('boosts decay geometrically from 2.0 at ratio 0.75', function () {
    expect(SectorActionRecipes::boostFor(1))->toBe(2.0)
        ->and(SectorActionRecipes::boostFor(2))->toBe(1.5)
        ->and(round(SectorActionRecipes::boostFor(3), 4))->toBe(1.125)
        ->and(round(SectorActionRecipes::boostFor(5), 4))->toBe(0.6328);
});

it('a barber with one booking platform leads with that platform link (deepest single target)', function () {
    $candidates = [
        sarPage('contact'),
        sarPlatform('fresha', 'services'),
        sarPlatform('instagram', null),
    ];

    $boosts = SectorActionRecipes::resolve('barber', $candidates);

    // beauty bucket: book, top-social, contact, top-product (unresolvable → skipped)
    expect(array_keys($boosts))->toBe(['platform:fresha', 'platform:instagram', 'page:contact'])
        ->and($boosts['platform:fresha'])->toBe(2.0)
        ->and($boosts['platform:instagram'])->toBe(1.5);
});

it('several booking platforms fall back to the services page — the page IS the choice', function () {
    $candidates = [
        sarPage('services'),
        sarPlatform('fresha', 'services'),
        sarPlatform('booksy', 'services'),
    ];

    $boosts = SectorActionRecipes::resolve('barber', $candidates);

    expect(array_key_first($boosts))->toBe('page:services');
});

it('a restaurant leads reserve → order → menu, with reserve resolving to the newest reservation platform', function () {
    $candidates = [
        sarPage('menu'),
        sarPage('contact'),
        sarPlatform('opentable', null, '2026-08-01T00:00:00+00:00'),
        sarPlatform('resdiary', null, '2026-08-20T00:00:00+00:00'),
        sarPlatform('uber-eats', 'menu', '2026-08-10T00:00:00+00:00'),
    ];

    $boosts = SectorActionRecipes::resolve('restaurant', $candidates);

    expect(array_slice(array_keys($boosts), 0, 3))
        ->toBe(['platform:resdiary', 'platform:uber-eats', 'page:menu']);
});

it('a musician leads listen (editorial pick) → latest event → latest release', function () {
    $candidates = [
        sarPlatform('soundcloud', 'listen'),
        sarPlatform('spotify', 'listen'),
        sarPlatform('instagram', null),
        sarItem('ev1', 'events', '2026-09-01T00:00:00+00:00'),
        sarItem('ev0', 'events', '2026-08-01T00:00:00+00:00'),
        sarItem('rel1', 'listen', '2026-08-15T00:00:00+00:00'),
        sarItem('relUndated', 'listen', '2026-08-20T00:00:00+00:00', undated: true),
    ];

    $boosts = SectorActionRecipes::resolve('musician', $candidates);

    expect(array_slice(array_keys($boosts), 0, 4))
        ->toBe(['platform:spotify', 'item:ev1', 'item:rel1', 'platform:instagram']);
});

it('top-product picks the highest-scored shop item, undated latest-* never wins', function () {
    $candidates = [
        sarPage('shop'),
        sarItem('p1', 'shop', '2026-08-01T00:00:00+00:00'),
        sarItem('p2', 'shop', '2026-08-02T00:00:00+00:00'),
    ];

    $boosts = SectorActionRecipes::resolve('clothing-boutique', $candidates, ['p1' => 0.9, 'p2' => 0.2]);

    // retail bucket: shop, top-product, top-social(skip), contact(skip)
    expect(array_keys($boosts))->toBe(['page:shop', 'item:p1']);
});

it('unknown or empty sector yields no boosts', function () {
    expect(SectorActionRecipes::resolve(null, [sarPage('contact')]))->toBe([])
        ->and(SectorActionRecipes::resolve('not-a-sector', [sarPage('contact')]))->toBe([]);
});

it('a role that resolves to an id already claimed is skipped, later entries move up', function () {
    // bar recipe: reserve, latest-event, menu, order, top-social. With one
    // ordering platform and no menu page, both 'menu'(→null) and 'order'
    // resolve against the same platform space — order takes the single
    // ordering platform; nothing double-claims.
    $candidates = [
        sarPlatform('opentable', null),
        sarPlatform('doordash', 'menu'),
        sarItem('gig', 'events', '2026-09-01T00:00:00+00:00'),
    ];

    $boosts = SectorActionRecipes::resolve('bar', $candidates);

    expect(array_keys($boosts))->toBe(['platform:opentable', 'item:gig', 'platform:doordash'])
        ->and($boosts['platform:doordash'])->toBe(round(2.0 * 0.75 ** 2, 10));
});
