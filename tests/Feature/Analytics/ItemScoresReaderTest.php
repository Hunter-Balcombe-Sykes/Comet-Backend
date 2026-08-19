<?php

use App\Services\Analytics\ContentPopularityReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ContentPopularityReader::itemScoresForSite() — the flat content_key => score
// map that feeds ItemFeedService's score mode (spec §4). Mirrors forSite()'s
// fail-open posture but flattens across content_type (excluding the derived
// 'action' rows and 'page' rows) since the feed looks a candidate up by id
// then slug, not by family.

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupContentPopularityScoresTable();
});

it('returns a flat content_key => score map, excluding action and page rows, max on collision', function () {
    $tenant = createTenant('item-scores-flat');
    $siteId = $tenant->site->id;

    $row = fn (string $type, string $key, float $score) => [
        'id' => (string) Str::orderedUuid(), 'site_id' => $siteId,
        'content_type' => $type, 'content_key' => $key,
        'score' => $score, 'rank' => 1, 'computed_at' => now(),
    ];
    DB::connection('pgsql')->table('analytics.content_popularity_scores')->insert([
        $row('engine_item', 'item-a', 0.4),
        $row('menu_item', 'item-b', 0.7),
        $row('shop_product', 'item-b', 0.9),  // collision: max wins
        $row('action', 'instagram', 0.99),    // excluded
        $row('page', 'home', 0.99),           // excluded
    ]);

    $scores = app(ContentPopularityReader::class)->itemScoresForSite($siteId);

    expect($scores)->toBe(['item-a' => 0.4, 'item-b' => 0.9]);
});

it('fails open to an empty map for a null site id', function () {
    expect(app(ContentPopularityReader::class)->itemScoresForSite(null))->toBe([]);
});
