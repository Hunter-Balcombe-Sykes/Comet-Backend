<?php

use App\Services\Analytics\RankedActionsComputer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// RankedActionsComputer — demand-rate scoring (2026-07-23 rebuild):
// rate = (taps + k·prior) / (exposures + k), blended 0.7/0.3 with the
// previous stored score, ranked with the same >10% hysteresis swap gate as
// the page/item popularity job. Independent of page/item scores — tests
// exercise computeForSite() directly against hand-built pool arrays +
// analytics.action_events rows, no artisan command / site-presence fixture
// needed for the algorithm itself.
//
// Priors used below (config/partna.php): menu=0.28, instagram=0.05, k=25.

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupActionEventsTable();
    setupContentPopularityScoresTable();
});

function poolEntry(string $id): array
{
    return ['id' => $id, 'kind' => 'page', 'label' => $id, 'pageId' => null, 'url' => null, 'platform' => null, 'sourceCreatedAt' => null];
}

function insertActionEventRow(string $siteId, string $actionId, string $event, string $sessionId, ?string $occurredAt = null): void
{
    DB::connection('pgsql')->table('analytics.action_events')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'action_id' => $actionId,
        'event' => $event,
        'occurred_at' => $occurredAt ?? now()->toISOString(),
        'session_id' => $sessionId,
        'created_at' => now()->toISOString(),
    ]);
}

function insertPreviousActionScore(string $siteId, string $actionId, float $score, int $rank): void
{
    DB::connection('pgsql')->table('analytics.content_popularity_scores')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $siteId,
        'content_type' => 'action',
        'content_key' => $actionId,
        'score' => $score,
        'rank' => $rank,
        'computed_at' => now()->toISOString(),
    ]);
}

/** @return array<string, object{score: float, rank: int}> keyed by content_key */
function actionScores(string $siteId): array
{
    return DB::connection('pgsql')->table('analytics.content_popularity_scores')
        ->where('site_id', $siteId)->where('content_type', 'action')
        ->get(['content_key', 'score', 'rank'])
        ->keyBy('content_key')
        ->all();
}

it('cold start: zero events yields scores exactly equal to each action\'s prior, ranked by prior', function () {
    $tenant = createTenant('rac-cold');
    $pool = [poolEntry('menu'), poolEntry('instagram')];

    $result = app(RankedActionsComputer::class)->computeForSite($tenant->site, $pool);

    expect($result['deletes'])->toBe([]);
    $rows = collect($result['rows'])->keyBy('content_key');

    expect((float) $rows['menu']['score'])->toEqualWithDelta(0.28, 0.0001)
        ->and((float) $rows['instagram']['score'])->toEqualWithDelta(0.05, 0.0001)
        ->and((int) $rows['menu']['rank'])->toBe(1)
        ->and((int) $rows['instagram']['rank'])->toBe(2);
});

it('a low-prior action with a high demand rate outranks a high-prior action with a low one', function () {
    $tenant = createTenant('rac-demand-beats-prior');

    // instagram (prior .05): 40 exposed sessions, 10 tap sessions today.
    for ($i = 0; $i < 40; $i++) {
        insertActionEventRow($tenant->site->id, 'instagram', 'seen', (string) Str::uuid());
    }
    $instagramTapSessions = [];
    for ($i = 0; $i < 10; $i++) {
        $sid = (string) Str::uuid();
        $instagramTapSessions[] = $sid;
        insertActionEventRow($tenant->site->id, 'instagram', 'tap', $sid);
    }
    // menu (prior .28): 200 exposed, 20 tap.
    for ($i = 0; $i < 200; $i++) {
        insertActionEventRow($tenant->site->id, 'menu', 'seen', (string) Str::uuid());
    }
    for ($i = 0; $i < 20; $i++) {
        insertActionEventRow($tenant->site->id, 'menu', 'tap', (string) Str::uuid());
    }

    $result = app(RankedActionsComputer::class)->computeForSite($tenant->site, [poolEntry('menu'), poolEntry('instagram')]);
    $rows = collect($result['rows'])->keyBy('content_key');

    // instagram: (10 + 25*.05)/(40+25) = 11.25/65 ≈ 0.17308
    // menu:      (20 + 25*.28)/(200+25) = 27/225 = 0.12
    // Delta is generous (not 0.0001) because dayWeight()'s age is measured
    // from midnight of the bucket's calendar date to the moment the test
    // runs, not from a fixed zero — "today" fixtures carry an intra-day
    // decay component that varies with wall-clock time-of-day (same reason
    // the pre-rebuild test file used 0.005-0.01 deltas for this exact class
    // of assertion).
    expect((float) $rows['instagram']['score'])->toEqualWithDelta(0.1731, 0.005)
        ->and((float) $rows['menu']['score'])->toEqualWithDelta(0.12, 0.005)
        ->and((int) $rows['instagram']['rank'])->toBe(1)
        ->and((int) $rows['menu']['rank'])->toBe(2);
});

it('session-deduplicates: 15 taps from the same session count as one', function () {
    $tenant = createTenant('rac-session-dedupe');
    $sessionId = (string) Str::uuid();

    for ($i = 0; $i < 15; $i++) {
        insertActionEventRow($tenant->site->id, 'instagram', 'tap', $sessionId);
    }

    $result = app(RankedActionsComputer::class)->computeForSite($tenant->site, [poolEntry('instagram')]);
    $row = collect($result['rows'])->keyBy('content_key')['instagram'];

    // 1 distinct tap session, 0 exposures: (1 + 25*.05)/(0+25) = 2.25/25 = 0.09
    // (generous delta — see the intra-day dayWeight() note above)
    expect((float) $row['score'])->toEqualWithDelta(0.09, 0.005);
});

it('decays a day bucket\'s events with a 90-day true half-life', function () {
    $tenant = createTenant('rac-decay');

    insertActionEventRow($tenant->site->id, 'instagram', 'tap', (string) Str::uuid(), now()->subDays(180)->toISOString());

    $result = app(RankedActionsComputer::class)->computeForSite($tenant->site, [poolEntry('instagram')]);
    $row = collect($result['rows'])->keyBy('content_key')['instagram'];

    // ~180 days old -> weight ~2^(-180/90) = 0.25. taps ~0.25, exposures = 0.
    // (0.25 + 25*.05)/(0+25) = 1.5/25 = 0.06 (generous delta — see the
    // intra-day dayWeight() note above; 180.x days vs exactly 180 shifts
    // this further than a same-day fixture would).
    expect((float) $row['score'])->toEqualWithDelta(0.06, 0.005);
});

it('blends the fresh rate with the previously stored score (0.7 new / 0.3 prev)', function () {
    $tenant = createTenant('rac-blend');
    insertPreviousActionScore($tenant->site->id, 'instagram', 0.5, 1);

    // 9 exposed sessions, 1 tap session today (all weight 1).
    for ($i = 0; $i < 9; $i++) {
        insertActionEventRow($tenant->site->id, 'instagram', 'seen', (string) Str::uuid());
    }
    insertActionEventRow($tenant->site->id, 'instagram', 'tap', (string) Str::uuid());

    $result = app(RankedActionsComputer::class)->computeForSite($tenant->site, [poolEntry('instagram')]);
    $row = collect($result['rows'])->keyBy('content_key')['instagram'];

    // rate = (1 + 25*.05)/(9+25) = 2.25/34 ≈ 0.066176
    // blended = 0.7*0.066176 + 0.3*0.5 ≈ 0.196324 (generous delta — see the
    // intra-day dayWeight() note above)
    expect((float) $row['score'])->toEqualWithDelta(0.19632, 0.005);
});

it('deletes stale action keys when the pool shrinks', function () {
    $tenant = createTenant('rac-stale');

    $first = app(RankedActionsComputer::class)->computeForSite($tenant->site, [poolEntry('menu'), poolEntry('instagram')]);
    expect($first['rows'])->toHaveCount(2);
    DB::connection('pgsql')->table('analytics.content_popularity_scores')->insert($first['rows']);

    // instagram left the pool (e.g. the connection was removed).
    $second = app(RankedActionsComputer::class)->computeForSite($tenant->site, [poolEntry('menu')]);

    expect($second['deletes'])->toBe(['instagram'])
        ->and(collect($second['rows'])->pluck('content_key')->all())->toBe(['menu']);
});

it('an empty pool deletes every previously stored action row', function () {
    $tenant = createTenant('rac-empty-pool');
    insertPreviousActionScore($tenant->site->id, 'menu', 0.28, 1);
    insertPreviousActionScore($tenant->site->id, 'instagram', 0.05, 2);

    $result = app(RankedActionsComputer::class)->computeForSite($tenant->site, []);

    expect($result['rows'])->toBe([])
        ->and(collect($result['deletes'])->sort()->values()->all())->toBe(['instagram', 'menu']);
});

it('a legacy pre-rebuild "<kind>:<ref>" stored key is swept as stale on the first post-rebuild run', function () {
    $tenant = createTenant('rac-legacy-sweep');
    insertPreviousActionScore($tenant->site->id, 'button:instagram', 0.81, 1);
    insertPreviousActionScore($tenant->site->id, 'page:services', 0.42, 2);

    $result = app(RankedActionsComputer::class)->computeForSite($tenant->site, [poolEntry('menu')]);

    expect(collect($result['deletes'])->sort()->values()->all())->toBe(['button:instagram', 'page:services'])
        ->and(collect($result['rows'])->pluck('content_key')->all())->toBe(['menu']);
});

it('keeps ranks stable under hysteresis blending across consecutive runs with unchanged signal', function () {
    $tenant = createTenant('rac-stable');
    for ($i = 0; $i < 5; $i++) {
        insertActionEventRow($tenant->site->id, 'menu', 'seen', (string) Str::uuid());
    }
    insertActionEventRow($tenant->site->id, 'menu', 'tap', (string) Str::uuid());
    for ($i = 0; $i < 30; $i++) {
        insertActionEventRow($tenant->site->id, 'instagram', 'seen', (string) Str::uuid());
    }
    $pool = [poolEntry('menu'), poolEntry('instagram')];

    $first = app(RankedActionsComputer::class)->computeForSite($tenant->site, $pool);
    DB::connection('pgsql')->table('analytics.content_popularity_scores')->insert($first['rows']);

    $second = app(RankedActionsComputer::class)->computeForSite($tenant->site, $pool);

    expect(collect($second['rows'])->pluck('rank', 'content_key')->all())
        ->toBe(collect($first['rows'])->pluck('rank', 'content_key')->all());
});
