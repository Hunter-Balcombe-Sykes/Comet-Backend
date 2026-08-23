<?php

use App\Site\Pools\PoolOrdering;

function orderingItem(string $id, ?string $published, ?string $seen = '2026-08-01T00:00:00+00:00', ?int $rank = null, array $collections = []): array
{
    return ['id' => $id, 'publishedAt' => $published, 'firstSeenAt' => $seen, 'popularityRank' => $rank, 'collectionIds' => $collections];
}

it('manual leaves the order untouched', function () {
    $items = [orderingItem('b', '2026-08-02T00:00:00+00:00'), orderingItem('a', '2026-08-09T00:00:00+00:00')];
    expect(array_column(PoolOrdering::order('manual', $items), 'id'))->toBe(['b', 'a']);
});

it('newest: dated first by publishedAt desc, then undated by firstSeenAt desc, id desc on ties', function () {
    $items = [
        orderingItem('old', '2026-08-02T00:00:00+00:00'),
        orderingItem('undated', null, null),
        orderingItem('seen-only', null, '2026-08-05T00:00:00+00:00'),
        orderingItem('new', '2026-08-09T00:00:00+00:00'),
        orderingItem('tie-a', '2026-08-09T00:00:00+00:00'),
    ];
    // 'seen-only' was first seen AFTER 'old' was published, but it is undated (X5) — it trails every dated item.
    expect(array_column(PoolOrdering::order('newest', $items), 'id'))->toBe(['tie-a', 'new', 'old', 'seen-only', 'undated']);
});

it('smart orders by popularityRank asc, unranked trail in newest order', function () {
    $items = [
        orderingItem('r3', '2026-08-09T00:00:00+00:00', rank: 3),
        orderingItem('unranked-new', '2026-08-10T00:00:00+00:00'),
        orderingItem('r1', '2026-08-01T00:00:00+00:00', rank: 1),
        orderingItem('unranked-old', '2026-08-02T00:00:00+00:00'),
    ];
    expect(array_column(PoolOrdering::order('smart', $items), 'id'))->toBe(['r1', 'r3', 'unranked-new', 'unranked-old']);
});

it('collections follow their best member and are renumbered; manual untouched', function () {
    $collections = ['mains' => ['name' => 'Mains', 'position' => 0], 'starters' => ['name' => 'Starters', 'position' => 1], 'empty' => ['name' => 'Empty', 'position' => 2]];
    $ordered = [orderingItem('soup', '2026-08-09T00:00:00+00:00', collections: ['starters']), orderingItem('steak', '2026-08-02T00:00:00+00:00', collections: ['mains'])];

    $out = PoolOrdering::orderCollections('newest', $collections, $ordered);
    expect(array_keys($out))->toBe(['starters', 'mains', 'empty'])
        ->and(array_column($out, 'position'))->toBe([0, 1, 2])
        ->and($out['starters']['name'])->toBe('Starters');

    expect(PoolOrdering::orderCollections('manual', $collections, $ordered))->toBe($collections);
});
