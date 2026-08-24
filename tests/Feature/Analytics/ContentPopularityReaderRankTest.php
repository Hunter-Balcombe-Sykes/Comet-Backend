<?php

use App\Services\Analytics\ContentPopularityReader;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// RANK-1: the stored hysteresis rank (ActionScorer::rankWithHysteresis(),
// >10% anti-thrash swap threshold) is written every 15 minutes and was never
// read anywhere — every consumer re-derived order from raw score, discarding
// the swap-thrash guard entirely. These tests pin actionRanksForSite() and
// the rewritten pageRanksFromActions() that now reads it.

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupSitesTable();
    setupContentPopularityScoresTable();
});

/** @param  list<array{key: string, score: float, rank: int}>  $rows */
function seedPopularityRows(string $siteId, array $rows, string $contentType = 'action'): void
{
    foreach ($rows as $row) {
        DB::connection('pgsql')->table('analytics.content_popularity_scores')->insert([
            'id' => (string) Str::uuid(),
            'site_id' => $siteId,
            'content_type' => $contentType,
            'content_key' => $row['key'],
            'score' => $row['score'],
            'rank' => $row['rank'],
            'computed_at' => now()->toISOString(),
        ]);
    }
}

it('pageRanksFromActions() returns dense page ranks from the STORED rank, not a re-derived score sort (non-vacuity: arsort() on these scores yields the OPPOSITE order — see the pre-fix red evidence in the implementation report)', function () {
    $site = createTenant('rank1-pages')->site;
    // page:menu scores HIGHER (0.418) than page:services (0.400) — a 4.5%
    // lead, inside ActionScorer's 10% swap threshold — so the hysteresis
    // rank correctly keeps services at rank 1. arsort() on raw score would
    // put menu first instead: that inversion is exactly what this pins.
    seedPopularityRows($site->id, [
        ['key' => 'page:services', 'score' => 0.400, 'rank' => 1],
        ['key' => 'page:menu', 'score' => 0.418, 'rank' => 2],
        ['key' => 'item:x', 'score' => 0.300, 'rank' => 3],
    ]);

    $ranks = app(ContentPopularityReader::class)->pageRanksFromActions($site->id);

    expect($ranks)->toBe(['services' => 1, 'menu' => 2]);
});

it('actionRanksForSite() returns ints for every action row, including non-page kinds (catches a future != "action" copy-paste)', function () {
    $site = createTenant('rank1-ranks')->site;
    seedPopularityRows($site->id, [
        ['key' => 'page:services', 'score' => 0.400, 'rank' => 1],
        ['key' => 'page:menu', 'score' => 0.418, 'rank' => 2],
        ['key' => 'item:x', 'score' => 0.300, 'rank' => 3],
    ]);

    $ranks = app(ContentPopularityReader::class)->actionRanksForSite($site->id);

    expect($ranks)->toBe(['page:services' => 1, 'page:menu' => 2, 'item:x' => 3]);
    foreach ($ranks as $rank) {
        expect($rank)->toBeInt();
    }
});

it('actionScoresForSite() is untouched by RANK-1 — still returns array<string, float> (pins the dashboard score/scoreShare wire)', function () {
    $site = createTenant('rank1-scores')->site;
    seedPopularityRows($site->id, [
        ['key' => 'page:services', 'score' => 0.400, 'rank' => 1],
        ['key' => 'page:menu', 'score' => 0.418, 'rank' => 2],
        ['key' => 'item:x', 'score' => 0.300, 'rank' => 3],
    ]);

    $scores = app(ContentPopularityReader::class)->actionScoresForSite($site->id);
    ksort($scores); // actionScoresForSite() carries no orderBy — assert the set, not row order

    expect($scores)->toBe(['item:x' => 0.300, 'page:menu' => 0.418, 'page:services' => 0.400]);
    foreach ($scores as $score) {
        expect($score)->toBeFloat();
    }
});

it('empty cases: null siteId and a site with zero action rows both return []', function () {
    $reader = app(ContentPopularityReader::class);
    $site = createTenant('rank1-empty')->site;

    expect($reader->pageRanksFromActions(null))->toBe([]);
    expect($reader->actionRanksForSite(null))->toBe([]);
    expect($reader->pageRanksFromActions($site->id))->toBe([]);
    expect($reader->actionRanksForSite($site->id))->toBe([]);
});

it('tie-break determinism: two page: rows sharing rank=3 sort by id ascending, independent of DB row insertion order', function () {
    $reader = app(ContentPopularityReader::class);

    $siteA = createTenant('rank1-tie-a')->site;
    seedPopularityRows($siteA->id, [
        ['key' => 'page:aaa', 'score' => 0.20, 'rank' => 3],
        ['key' => 'page:bbb', 'score' => 0.21, 'rank' => 3],
    ]);
    $rankedA = $reader->pageRanksFromActions($siteA->id);

    $siteB = createTenant('rank1-tie-b')->site;
    seedPopularityRows($siteB->id, [
        ['key' => 'page:bbb', 'score' => 0.21, 'rank' => 3],
        ['key' => 'page:aaa', 'score' => 0.20, 'rank' => 3],
    ]);
    $rankedB = $reader->pageRanksFromActions($siteB->id);

    // 'aaa' sorts before 'bbb' by id — asserted with both insertion orders
    // to prove this is independent of DB row order, not a lucky default.
    expect($rankedA)->toBe(['aaa' => 1, 'bbb' => 2]);
    expect($rankedB)->toBe(['aaa' => 1, 'bbb' => 2]);
});
