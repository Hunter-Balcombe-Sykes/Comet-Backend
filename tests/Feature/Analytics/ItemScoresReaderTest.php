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
    // shop_product (0.9) inserted BEFORE menu_item (0.7): with no ORDER BY,
    // insertion order is iteration order, so a last-write-wins bug would
    // also land on 0.7 here — only a true max() survives both orderings.
    DB::connection('pgsql')->table('analytics.content_popularity_scores')->insert([
        $row('engine_item', 'item-a', 0.4),
        $row('shop_product', 'item-b', 0.9),  // collision: max wins
        $row('menu_item', 'item-b', 0.7),
        $row('action', 'instagram', 0.99),    // excluded
        $row('page', 'home', 0.99),           // excluded
    ]);

    $scores = app(ContentPopularityReader::class)->itemScoresForSite($siteId);

    expect($scores)->toBe(['item-a' => 0.4, 'item-b' => 0.9]);
});

it('fails open to an empty map for a null site id', function () {
    expect(app(ContentPopularityReader::class)->itemScoresForSite(null))->toBe([]);
});

it('fails open to an empty map when the read throws (catch block, not the null guard)', function () {
    $tenant = createTenant('item-scores-faulty');
    $siteId = $tenant->site->id;

    // Drop the table setupContentPopularityScoresTable() just created so the
    // query inside itemScoresForSite() throws a REAL QueryException ("no
    // such table") — proving the try/catch fires for a non-null site id,
    // not just the early-return guard clause the null-site test covers.
    DB::connection('pgsql')->statement('DROP TABLE analytics.content_popularity_scores');

    expect(app(ContentPopularityReader::class)->itemScoresForSite($siteId))->toBe([]);
});
