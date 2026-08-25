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

it('locks hold their position while the rest fill around them; a lock on an unknown id is reported unavailable, not silently skipped', function () {
    $items = [orderingItem('a', '2026-08-09T00:00:00+00:00'), orderingItem('b', '2026-08-08T00:00:00+00:00'), orderingItem('c', '2026-08-07T00:00:00+00:00'), orderingItem('d', '2026-08-06T00:00:00+00:00')];
    $result = PoolOrdering::applyLocks($items, [['position' => 0, 'id' => 'd'], ['position' => 2, 'id' => 'gone'], ['position' => 3, 'id' => 'a']]);
    expect(array_column($result['items'], 'id'))->toBe(['d', 'b', 'c', 'a']);
    expect($result['unavailable'])->toBe(['gone']);
});

it('a lock past the end lands last; no locks is a no-op', function () {
    $items = [orderingItem('a', null), orderingItem('b', null)];
    $result = PoolOrdering::applyLocks($items, [['position' => 9, 'id' => 'a']]);
    expect(array_column($result['items'], 'id'))->toBe(['b', 'a']);
    expect($result['unavailable'])->toBe([]);

    $noLocks = PoolOrdering::applyLocks($items, []);
    expect($noLocks['items'])->toBe($items);
    expect($noLocks['unavailable'])->toBe([]);
});

it('#RANK-2: two locks at the same position — the first wins, the second is reported unavailable rather than silently dropped', function () {
    $items = [orderingItem('a', '2026-08-09T00:00:00+00:00'), orderingItem('b', '2026-08-08T00:00:00+00:00'), orderingItem('c', '2026-08-07T00:00:00+00:00')];
    $result = PoolOrdering::applyLocks($items, [['position' => 0, 'id' => 'b'], ['position' => 0, 'id' => 'c']]);
    // 'b' survives at position 0; 'c' loses the collision but still renders — it just falls into the fill, unpinned.
    expect(array_column($result['items'], 'id'))->toBe(['b', 'a', 'c']);
    expect($result['unavailable'])->toBe(['c']);
});

it('#RANK-2: applyLocksPerCollection aggregates unavailable across buckets — same-category collisions and ids outside the selection both surface', function () {
    $collections = ['mains' => ['name' => 'Mains', 'position' => 0], 'starters' => ['name' => 'Starters', 'position' => 1]];
    $items = [
        orderingItem('steak', '2026-08-09T00:00:00+00:00', collections: ['mains']),
        orderingItem('fish', '2026-08-08T00:00:00+00:00', collections: ['mains']),
        orderingItem('soup', '2026-08-07T00:00:00+00:00', collections: ['starters']),
    ];
    $locks = [
        ['position' => 0, 'id' => 'fish'],   // mains #0 — placed
        ['position' => 0, 'id' => 'steak'],  // mains #0 — collides with the lock above, same category
        ['position' => 5, 'id' => 'ghost'],  // not in the selection at all, in ANY category
    ];
    $result = PoolOrdering::applyLocksPerCollection($items, $locks, $collections);
    expect(array_column($result['items'], 'id'))->toBe(['fish', 'steak', 'soup']);
    // 'ghost' is caught in the id-matching pass (never homed to a bucket); 'steak' surfaces
    // later when its bucket's applyLocks() call resolves the position collision.
    expect($result['unavailable'])->toBe(['ghost', 'steak']);
});
