<?php

use App\Site\Actions\ActionCandidates;
use App\Site\Actions\ActionId;

function candidatePools(): array
{
    return [
        'watch' => ['items' => [
            ['id' => 'v-old', 'kind' => 'video', 'headline' => 'Old clip', 'url' => 'https://youtube.com/watch?v=1', 'thumbnail' => 'https://img/1.jpg', 'publishedAt' => '2026-08-01T00:00:00+00:00', 'firstSeenAt' => '2026-08-20T00:00:00+00:00', 'collectionIds' => []],
            ['id' => 'v-new', 'kind' => 'video', 'headline' => 'New clip', 'url' => 'https://youtube.com/watch?v=2', 'thumbnail' => null, 'publishedAt' => '2026-08-15T00:00:00+00:00', 'firstSeenAt' => '2026-08-20T00:00:00+00:00', 'collectionIds' => []],
        ], 'latestItemId' => 'v-new'],
        'menus' => [
            'items' => [
                ['id' => 'd-1', 'kind' => 'menu_item', 'headline' => 'Soup', 'url' => null, 'thumbnail' => null, 'publishedAt' => null, 'firstSeenAt' => '2026-08-10T00:00:00+00:00', 'collectionIds' => ['cat-starters', 'ubereats']],
                ['id' => 'd-2', 'kind' => 'menu_item', 'headline' => 'Bread', 'url' => null, 'thumbnail' => 'https://img/b.jpg', 'publishedAt' => null, 'firstSeenAt' => '2026-08-12T00:00:00+00:00', 'collectionIds' => ['ubereats', 'cat-starters']],
                ['id' => 'd-loose', 'kind' => 'menu_item', 'headline' => 'Special', 'url' => null, 'thumbnail' => null, 'publishedAt' => null, 'firstSeenAt' => '2026-08-03T00:00:00+00:00', 'collectionIds' => ['ubereats']],
            ],
            'collections' => [
                'cat-starters' => ['name' => 'Starters', 'provider' => null, 'position' => 0],
                'ubereats' => ['name' => 'Uber Eats', 'provider' => 'ubereats', 'position' => 0],
            ],
        ],
        'services' => ['items' => [
            ['id' => 's-1', 'kind' => 'service', 'headline' => 'Haircut', 'url' => null, 'thumbnail' => null, 'publishedAt' => null, 'firstSeenAt' => '2026-08-05T00:00:00+00:00', 'collectionIds' => []],
        ]],
        'media' => ['items' => [
            ['id' => 'm-1', 'kind' => 'media', 'headline' => 'Shot', 'url' => null, 'thumbnail' => 'https://img/m.jpg', 'publishedAt' => '2026-08-20T00:00:00+00:00', 'firstSeenAt' => '2026-08-20T00:00:00+00:00', 'collectionIds' => []],
        ]],
        'reviews' => ['items' => [
            ['id' => 'r-1', 'kind' => 'review', 'headline' => 'Great', 'url' => null, 'thumbnail' => null, 'publishedAt' => null, 'firstSeenAt' => '2026-08-05T00:00:00+00:00', 'collectionIds' => []],
        ]],
    ];
}

it('derives item and category candidates from the pool wire, never from reviews or media (D1)', function () {
    $out = collect(ActionCandidates::fromPools(candidatePools()))->keyBy('id');

    // d-loose's only served collection ('ubereats') is provider-bearing, so
    // #SEM-16 homes it there as a fallback rather than floating as an item —
    // it groups into category:ubereats, not item:d-loose.
    expect($out->keys()->all())->toEqualCanonicalizing(['item:v-old', 'item:v-new', 'category:cat-starters', 'category:ubereats', 'item:s-1']);
    foreach ($out as $c) {
        expect(ActionId::isValid($c['id']))->toBeTrue();
    }
});

it('shapes an item candidate: label, outbound url or page anchor, thumb, connectedAt = publishedAt ?? firstSeenAt, ref', function () {
    $out = collect(ActionCandidates::fromPools(candidatePools()))->keyBy('id');

    expect($out['item:v-old'])->toMatchArray([
        'kind' => 'item', 'label' => 'Old clip', 'url' => 'https://youtube.com/watch?v=1', 'thumb' => 'https://img/1.jpg',
        'connectedAt' => '2026-08-01T00:00:00+00:00', 'ref' => ['pool' => 'watch', 'itemId' => 'v-old'],
    ])->and($out['item:v-old']['meta'])->toMatchArray(['pool' => 'watch'])
        ->and($out['item:s-1'])->toMatchArray(['url' => '/services#s-1', 'connectedAt' => '2026-08-05T00:00:00+00:00']);
});

it('homes dishes in the first provider-null category; the block takes its newest member', function () {
    $out = collect(ActionCandidates::fromPools(candidatePools()))->keyBy('id');

    expect($out['category:cat-starters'])->toMatchArray([
        'kind' => 'category', 'label' => 'Starters', 'url' => '/menu#cat-starters', 'thumb' => 'https://img/b.jpg',
        'connectedAt' => '2026-08-12T00:00:00+00:00', 'ref' => null,
    ])->and($out['category:cat-starters']['meta'])->toMatchArray(['pool' => 'menus', 'collectionId' => 'cat-starters', 'itemIds' => ['d-1', 'd-2']]);
});

// #SEM-16: fromPools() hand-copied PoolOrdering::homeCollection()'s loop but
// dropped the `$fallback ??= $cid` result — a dish whose only served
// collection was provider-bearing (an order-platform sidecar) floated as a
// loose item: entry here, while PoolOrdering's identical loop homed the same
// dish under the sidecar. The two homing paths now share one implementation.

it('SEM-16: a sidecar-only item (its only served collection has a provider) groups under that collection instead of floating', function () {
    $pools = ['menus' => [
        'items' => [
            ['id' => 'sidecar-only', 'kind' => 'menu_item', 'headline' => 'Combo', 'url' => null, 'thumbnail' => null, 'publishedAt' => null, 'firstSeenAt' => '2026-08-01T00:00:00+00:00', 'collectionIds' => ['ubereats']],
        ],
        'collections' => [
            'ubereats' => ['name' => 'Uber Eats', 'provider' => 'ubereats', 'position' => 0],
        ],
    ]];

    $out = collect(ActionCandidates::fromPools($pools))->keyBy('id');

    expect($out->has('item:sidecar-only'))->toBeFalse();
    expect($out->has('category:ubereats'))->toBeTrue();
    expect($out['category:ubereats']['meta']['itemIds'])->toBe(['sidecar-only']);
});

it('SEM-16: a real (null-provider) category wins even when a provider-bearing collection is listed first', function () {
    $pools = ['menus' => [
        'items' => [
            ['id' => 'ordered-first', 'kind' => 'menu_item', 'headline' => 'Wrap', 'url' => null, 'thumbnail' => null, 'publishedAt' => null, 'firstSeenAt' => '2026-08-01T00:00:00+00:00', 'collectionIds' => ['ubereats', 'mains']],
        ],
        'collections' => [
            'ubereats' => ['name' => 'Uber Eats', 'provider' => 'ubereats', 'position' => 0],
            'mains' => ['name' => 'Mains', 'provider' => null, 'position' => 1],
        ],
    ]];

    $out = collect(ActionCandidates::fromPools($pools))->keyBy('id');

    expect($out->has('category:mains'))->toBeTrue();
    expect($out->has('category:ubereats'))->toBeFalse();
    expect($out['category:mains']['meta']['itemIds'])->toBe(['ordered-first']);
});

it('never produces an action from the media pool — no item and no gallery category (D1)', function () {
    $pools = ['media' => [
        'items' => [
            ['id' => 'm-1', 'kind' => 'media', 'headline' => 'Shot', 'url' => null, 'thumbnail' => 'https://img/m.jpg', 'publishedAt' => '2026-08-20T00:00:00+00:00', 'firstSeenAt' => '2026-08-20T00:00:00+00:00', 'collectionIds' => ['album']],
        ],
        'collections' => ['album' => ['name' => 'Album', 'provider' => null, 'position' => 0]],
    ]];
    expect(ActionCandidates::fromPools($pools))->toBe([]);
});

it('drops an item whose outbound url is not http(s)', function () {
    $pools = ['watch' => ['items' => [
        ['id' => 'bad', 'kind' => 'video', 'headline' => 'x', 'url' => 'javascript:alert(1)', 'thumbnail' => null, 'publishedAt' => null, 'firstSeenAt' => null, 'collectionIds' => []],
    ]]];
    expect(ActionCandidates::fromPools($pools))->toBe([]);
});

it('flags synced items without a publishedAt as undated, but never a hand-added link', function () {
    $pools = [
        'watch' => ['items' => [['id' => 'v', 'kind' => 'video', 'headline' => 'x', 'url' => 'https://y/v', 'thumbnail' => null, 'publishedAt' => null, 'firstSeenAt' => '2026-08-20T00:00:00+00:00', 'collectionIds' => []]]],
        'custom_links' => ['items' => [['id' => 'l', 'kind' => 'link', 'headline' => 'x', 'url' => 'https://y/l', 'thumbnail' => null, 'publishedAt' => null, 'firstSeenAt' => '2026-08-20T00:00:00+00:00', 'collectionIds' => []]]],
    ];
    $out = collect(ActionCandidates::fromPools($pools))->keyBy('id');
    expect($out['item:v']['meta']['undated'])->toBeTrue()->and($out['item:l']['meta']['undated'])->toBeFalse();
});

it('an event is dated by its occurrence: startsAt rides meta and stands in for the missing publishedAt', function () {
    // Real ingested events carry f_occurrence and never f_published (the
    // projector writes no publish row), so publishedAt-only dating left
    // every real event undated and the next-event recipe role dead
    // (critic find, 2026-08-27).
    $pools = ['events' => ['items' => [[
        'id' => 'ev', 'kind' => 'event', 'headline' => 'Gig', 'url' => 'https://tix/ev',
        'thumbnail' => null, 'publishedAt' => null, 'firstSeenAt' => '2026-08-20T00:00:00+00:00',
        'startsAt' => '2026-09-05T19:00:00+00:00', 'collectionIds' => [],
    ]]]];
    $out = collect(ActionCandidates::fromPools($pools))->keyBy('id');
    expect($out['item:ev']['meta']['undated'])->toBeFalse()
        ->and($out['item:ev']['meta']['startsAt'])->toBe('2026-09-05T19:00:00+00:00')
        ->and($out['item:ev']['connectedAt'])->toBe('2026-09-05T19:00:00+00:00');
});
