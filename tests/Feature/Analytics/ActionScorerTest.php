<?php

use App\Models\Core\Site\Site;
use App\Services\Analytics\ActionScorer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ActionScorer — the composite smart score (spec §6). Candidates are plain
// arrays (ActionCandidates output); events and previous rows are seeded.

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupSitesTable();
    setupActionEventsTable();
    setupContentPopularityScoresTable();
    config(['partna.actions.prior_k' => 25, 'partna.actions.weights' => ['demand' => 0.45, 'reach' => 0.30, 'fresh' => 0.25]]);
});

function scorerCandidate(string $id, string $kind, ?string $connectedAt, array $extra = []): array
{
    return array_merge([
        'id' => $id, 'kind' => $kind, 'label' => $id, 'url' => 'https://x/'.$id, 'thumb' => null,
        'connectedAt' => $connectedAt, 'ref' => $kind === 'item' ? ['pool' => 'watch', 'itemId' => substr($id, 5)] : null, 'meta' => [],
    ], $extra);
}

function seedActionEvent(Site $site, string $actionId, string $event, ?string $session = null, ?string $at = null): void
{
    DB::connection('pgsql')->table('analytics.action_events')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $site->user_id,
        'site_id' => $site->id,
        'action_id' => $actionId,
        'event' => $event,
        'occurred_at' => $at ?? now()->toISOString(),
        'session_id' => $session ?? (string) Str::uuid(),
        'created_at' => $at ?? now()->toISOString(),
    ]);
}

function scoreRows(array $result): array
{
    $out = [];
    foreach ($result['rows'] as $row) {
        $out[$row['content_key']] = ['score' => (float) $row['score'], 'rank' => (int) $row['rank']];
    }

    return $out;
}

it('cold start: no events → prior + freshness decide; a page outranks a platform outranks an old item', function () {
    $site = createTenant('sc-cold')->site;
    $old = now()->subDays(120)->toIso8601String();
    $result = app(ActionScorer::class)->computeForSite($site, [
        scorerCandidate('item:old', 'item', $old),
        scorerCandidate('platform:instagram', 'platform', $old),
        scorerCandidate('page:services', 'page', $old),
    ]);
    $rows = scoreRows($result);

    // With no history the blend is 0.7·s + 0.3·s = s; freshness ≈ 0 at 120 days;
    // demandRate = prior exactly; reach 0. score = 0.45·prior + prior.
    expect($rows['page:services']['score'])->toEqualWithDelta(1.45 * 0.28, 0.01)
        ->and($rows['platform:instagram']['score'])->toEqualWithDelta(1.45 * 0.05, 0.01)
        ->and($rows['item:old']['score'])->toEqualWithDelta(1.45 * 0.03, 0.01)
        ->and($rows['page:services']['rank'])->toBe(1)
        ->and($rows['platform:instagram']['rank'])->toBe(2)
        ->and($rows['item:old']['rank'])->toBe(3)
        ->and($result['deletes'])->toBe([]);
});

it('freshness lifts a brand-new item above a long-connected platform', function () {
    $site = createTenant('sc-fresh')->site;
    $rows = scoreRows(app(ActionScorer::class)->computeForSite($site, [
        scorerCandidate('item:new', 'item', now()->toIso8601String()),
        scorerCandidate('platform:instagram', 'platform', now()->subDays(120)->toIso8601String()),
    ]));

    expect($rows['item:new']['score'])->toBeGreaterThan($rows['platform:instagram']['score'])
        ->and($rows['item:new']['rank'])->toBe(1);
});

it('taps raise demand and reach: a tapped item overtakes an untouched page', function () {
    $site = createTenant('sc-taps')->site;
    foreach (range(1, 40) as $_) {
        $session = (string) Str::uuid();
        seedActionEvent($site, 'item:hot', 'seen', $session);
        seedActionEvent($site, 'item:hot', 'tap', $session);
    }
    foreach (range(1, 40) as $_) {
        seedActionEvent($site, 'page:services', 'seen');
    }
    $old = now()->subDays(120)->toIso8601String();
    $rows = scoreRows(app(ActionScorer::class)->computeForSite($site, [
        scorerCandidate('item:hot', 'item', $old),
        scorerCandidate('page:services', 'page', $old),
    ]));

    expect($rows['item:hot']['rank'])->toBe(1)->and($rows['page:services']['rank'])->toBe(2);
});

it('session-deduplicates taps: 15 taps from one session count once', function () {
    $site = createTenant('sc-dedupe')->site;
    $session = (string) Str::uuid();
    foreach (range(1, 15) as $_) {
        seedActionEvent($site, 'item:a', 'tap', $session);
        seedActionEvent($site, 'item:a', 'seen', $session);
    }
    foreach (range(1, 3) as $_) {
        $s = (string) Str::uuid();
        seedActionEvent($site, 'item:b', 'tap', $s);
        seedActionEvent($site, 'item:b', 'seen', $s);
    }
    $old = now()->subDays(120)->toIso8601String();
    $rows = scoreRows(app(ActionScorer::class)->computeForSite($site, [
        scorerCandidate('item:a', 'item', $old),
        scorerCandidate('item:b', 'item', $old),
    ]));

    expect($rows['item:b']['score'])->toBeGreaterThan($rows['item:a']['score']);
});

it('folds an item\'s pool engagement score into reach', function () {
    $site = createTenant('sc-reach')->site;
    $old = now()->subDays(120)->toIso8601String();
    $rows = scoreRows(app(ActionScorer::class)->computeForSite($site, [
        scorerCandidate('item:a', 'item', $old),
        scorerCandidate('item:b', 'item', $old),
    ], ['b' => 12.0]));

    expect($rows['item:b']['score'])->toBeGreaterThan($rows['item:a']['score'])
        ->and($rows['item:b']['score'] - $rows['item:a']['score'])->toEqualWithDelta(0.30, 0.001);
});

it('folds a product\'s shop_product score into reach by its item id (no handle detour)', function () {
    $site = createTenant('sc-reach-product')->site;
    $old = now()->subDays(120)->toIso8601String();
    $productId = (string) Str::uuid();
    $rows = scoreRows(app(ActionScorer::class)->computeForSite($site, [
        scorerCandidate('item:'.$productId, 'item', $old, ['ref' => ['pool' => 'shop', 'itemId' => $productId]]),
        scorerCandidate('item:other', 'item', $old),
    ], [$productId => 9.0, 'bulwark-jacket' => 99.0]));

    expect($rows['item:'.$productId]['score'] - $rows['item:other']['score'])->toEqualWithDelta(0.30, 0.001);
});

it('deletes stale keys and keeps hysteresis: a 5% better newcomer does not overtake the incumbent', function () {
    $site = createTenant('sc-stale')->site;
    $old = now()->subDays(120)->toIso8601String();
    foreach ([['platform:gone', 0.5, 1], ['page:services', 0.40, 2], ['page:menu', 0.40, 3]] as [$key, $score, $rank]) {
        DB::connection('pgsql')->table('analytics.content_popularity_scores')->insert([
            'id' => (string) Str::uuid(), 'site_id' => $site->id, 'content_type' => 'action',
            'content_key' => $key, 'score' => $score, 'rank' => $rank, 'computed_at' => now()->subDay()->toISOString(),
        ]);
    }
    config(['partna.actions.priors' => ['page:services' => 0.28, 'page:menu' => 0.29]]);
    $result = app(ActionScorer::class)->computeForSite($site, [
        scorerCandidate('page:services', 'page', $old),
        scorerCandidate('page:menu', 'page', $old),
    ]);
    $rows = scoreRows($result);

    expect($result['deletes'])->toBe(['platform:gone'])
        ->and($rows['page:menu']['score'])->toBeGreaterThan($rows['page:services']['score'])
        ->and($rows['page:services']['rank'])->toBe(1)
        ->and($rows['page:menu']['rank'])->toBe(2);
});

it('an empty candidate set clears every stored action row', function () {
    $site = createTenant('sc-empty')->site;
    DB::connection('pgsql')->table('analytics.content_popularity_scores')->insert([
        'id' => (string) Str::uuid(), 'site_id' => $site->id, 'content_type' => 'action',
        'content_key' => 'page:services', 'score' => 0.4, 'rank' => 1, 'computed_at' => now()->toISOString(),
    ]);
    $result = app(ActionScorer::class)->computeForSite($site, []);
    expect($result)->toBe(['rows' => [], 'deletes' => ['page:services']]);
});
