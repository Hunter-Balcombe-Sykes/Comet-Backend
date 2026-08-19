<?php

use App\Models\Core\Site\Site;
use App\Services\PublicSite\ItemFeedService;

// Pure-array fixtures — the resolver never touches the DB.
function feedPools(): array
{
    return [
        'watch' => ['items' => [
            ['id' => 'v-old', 'slug' => null, 'publishedAt' => '2026-08-01T00:00:00Z', 'firstSeenAt' => '2026-08-01T00:00:00Z', 'collectionIds' => []],
            ['id' => 'v-new', 'slug' => null, 'publishedAt' => '2026-08-15T00:00:00Z', 'firstSeenAt' => '2026-08-15T00:00:00Z', 'collectionIds' => []],
        ], 'latestItemId' => 'v-new'],
        'listen' => ['items' => [
            ['id' => 't-mid', 'slug' => null, 'publishedAt' => '2026-08-10T00:00:00Z', 'firstSeenAt' => '2026-08-10T00:00:00Z', 'collectionIds' => []],
        ], 'latestItemId' => 't-mid'],
        'menus' => ['items' => [
            ['id' => 'd-1', 'slug' => null, 'publishedAt' => null, 'firstSeenAt' => '2026-08-05T00:00:00Z', 'collectionIds' => ['cat-starters']],
            ['id' => 'd-2', 'slug' => null, 'publishedAt' => null, 'firstSeenAt' => '2026-08-12T00:00:00Z', 'collectionIds' => ['cat-starters']],
            ['id' => 'd-loose', 'slug' => null, 'publishedAt' => null, 'firstSeenAt' => '2026-08-03T00:00:00Z', 'collectionIds' => []],
        ], 'collections' => ['cat-starters' => ['name' => 'Starters']]],
        // Must be IGNORED even when present in the input map (spec §3).
        'reviews' => ['items' => [
            ['id' => 'r-1', 'slug' => null, 'publishedAt' => null, 'firstSeenAt' => '2026-08-14T00:00:00Z', 'collectionIds' => []],
        ]],
    ];
}

it('newest orders items by publishedAt falling back to firstSeenAt, category block by its newest item', function () {
    $out = (new ItemFeedService)->resolve(feedPools(), 'newest', [], []);

    expect($out['mode'])->toBe('newest');
    // v-new (08-15) > cat-starters block (newest inside: d-2, 08-12) > t-mid (08-10) > v-old (08-01) > d-loose (08-03)?
    // No — d-loose (08-03) is OLDER than v-old (08-01)? 08-03 > 08-01, so: ... t-mid, d-loose, v-old.
    expect(array_map(fn ($e) => $e['kind'] === 'category' ? $e['collectionId'] : $e['itemId'], $out['entries']))
        ->toBe(['v-new', 'cat-starters', 't-mid', 'd-loose', 'v-old']);

    // Inside the block: newest-first.
    $block = collect($out['entries'])->firstWhere('kind', 'category');
    expect($block['itemIds'])->toBe(['d-2', 'd-1'])
        ->and($block['pool'])->toBe('menus');
});

it('never emits reviews entries', function () {
    $out = (new ItemFeedService)->resolve(feedPools(), 'newest', [], []);
    expect(collect($out['entries'])->pluck('pool')->all())->not->toContain('reviews');
});

it('uncategorised menu items float as plain item entries', function () {
    $out = (new ItemFeedService)->resolve(feedPools(), 'newest', [], []);
    $loose = collect($out['entries'])->first(fn ($e) => ($e['itemId'] ?? null) === 'd-loose');
    expect($loose)->not->toBeNull()->and($loose['kind'])->toBe('item');
});

it('score mode orders by score, category takes best item, unscored sort after scored by recency', function () {
    $scores = ['t-mid' => 0.9, 'd-1' => 0.5, 'v-old' => 0.2];
    $out = (new ItemFeedService)->resolve(feedPools(), 'score', [], $scores);

    expect(array_map(fn ($e) => $e['kind'] === 'category' ? $e['collectionId'] : $e['itemId'], $out['entries']))
        // t-mid 0.9 > block (best d-1 = 0.5) > v-old 0.2 > unscored by recency: v-new (08-15) > d-loose (08-03)
        ->toBe(['t-mid', 'cat-starters', 'v-old', 'v-new', 'd-loose']);

    // Scores on the wire in score mode; null for unscored.
    expect($out['entries'][0]['score'])->toBe(0.9)
        ->and($out['entries'][3]['score'])->toBeNull();

    // Inside the block: score order, unscored last (d-1 scored, d-2 not).
    $block = collect($out['entries'])->firstWhere('kind', 'category');
    expect($block['itemIds'])->toBe(['d-1', 'd-2'])->and($block['score'])->toBe(0.5);
});

it('newest and manual modes carry null scores', function () {
    $out = (new ItemFeedService)->resolve(feedPools(), 'newest', [], ['v-new' => 0.9]);
    expect(collect($out['entries'])->pluck('score')->unique()->all())->toBe([null]);
});

it('manual mode is strict: stored order applied, stale refs dropped, nothing auto-appended', function () {
    $manual = [
        ['kind' => 'item', 'pool' => 'listen', 'ref' => 't-mid'],
        ['kind' => 'category', 'pool' => 'menus', 'ref' => 'cat-starters', 'items' => ['d-2', 'd-gone', 'd-1']],
        ['kind' => 'item', 'pool' => 'watch', 'ref' => 'v-gone'],   // stale → dropped
        ['kind' => 'item', 'pool' => 'reviews', 'ref' => 'r-1'],    // reviews → dropped
    ];
    $out = (new ItemFeedService)->resolve(feedPools(), 'manual', $manual, []);

    expect(count($out['entries']))->toBe(2);
    expect($out['entries'][0])->toMatchArray(['kind' => 'item', 'pool' => 'listen', 'itemId' => 't-mid']);
    // Stored within-category order kept; missing item dropped, live-but-unlisted d-loose NOT appended.
    expect($out['entries'][1]['itemIds'])->toBe(['d-2', 'd-1']);
});

it('manual category block whose every listed item is stale is dropped entirely, not emitted empty', function () {
    $manual = [
        ['kind' => 'category', 'pool' => 'menus', 'ref' => 'cat-starters', 'items' => ['d-gone']],
    ];
    $out = (new ItemFeedService)->resolve(feedPools(), 'manual', $manual, []);

    expect($out['entries'])->toBe([]);
});

it('empty pools resolve to an empty entries list, never an error', function () {
    $out = (new ItemFeedService)->resolve([], 'score', [], []);
    expect($out)->toBe(['mode' => 'score', 'entries' => []]);
});

it('reads mode and manual list from site settings with newest default', function () {
    $svc = new ItemFeedService;
    expect($svc->mode(null))->toBe('newest');
    expect($svc->mode(new Site(['settings' => []])))->toBe('newest');
    expect($svc->mode(new Site(['settings' => ['feed_mode' => 'score']])))->toBe('score');
    expect($svc->mode(new Site(['settings' => ['feed_mode' => 'bogus']])))->toBe('newest');
    expect($svc->manualFeed(new Site(['settings' => ['manual_feed' => [['kind' => 'item', 'pool' => 'watch', 'ref' => 'x']]]])))
        ->toHaveCount(1);
    expect($svc->manualFeed(new Site(['settings' => ['manual_feed' => 'junk']])))->toBe([]);
});
