<?php

use App\Models\Core\Site\Site;
use App\Services\Analytics\ActionScorer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Identity boosts riding ActionScorer (smart-scoring plan, 2026-08-27):
// worked examples for the boost floor, the organic-component hysteresis for
// boosted pairs, the boost wall against organic candidates, and boost
// migration between latest-* targets.

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupSitesTable();
    setupActionEventsTable();
    setupContentPopularityScoresTable();
    config(['partna.actions.prior_k' => 25, 'partna.actions.weights' => ['demand' => 0.45, 'reach' => 0.30, 'fresh' => 0.25]]);
});

function boostCandidate(string $id, string $kind, ?string $connectedAt = null): array
{
    return [
        'id' => $id, 'kind' => $kind, 'label' => $id, 'url' => 'https://x/'.$id, 'thumb' => null,
        'connectedAt' => $connectedAt ?? now()->subDays(120)->toIso8601String(),
        'ref' => $kind === 'item' ? ['pool' => 'listen', 'itemId' => substr($id, 5)] : null, 'meta' => [],
    ];
}

/** Persist a run's rows the way the command does, so the next run blends against them. */
function persistBoostRows(Site $site, array $result): void
{
    foreach ($result['rows'] as $row) {
        DB::connection('pgsql')->table('analytics.content_popularity_scores')->updateOrInsert(
            ['site_id' => $site->id, 'content_type' => 'action', 'content_key' => $row['content_key']],
            ['id' => (string) Str::uuid(), 'score' => $row['score'], 'rank' => $row['rank'], 'computed_at' => now()->toISOString()],
        );
    }
    foreach ($result['deletes'] as $key) {
        DB::connection('pgsql')->table('analytics.content_popularity_scores')
            ->where('site_id', $site->id)->where('content_type', 'action')->where('content_key', $key)->delete();
    }
}

function boostTapBurst(Site $site, string $actionId, int $taps, int $exposures): void
{
    for ($i = 0; $i < $exposures; $i++) {
        $session = (string) Str::uuid();
        DB::connection('pgsql')->table('analytics.action_events')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $site->user_id, 'site_id' => $site->id,
            'action_id' => $actionId, 'event' => 'seen', 'occurred_at' => now()->toISOString(),
            'session_id' => $session, 'created_at' => now()->toISOString(),
        ]);
        if ($i < $taps) {
            DB::connection('pgsql')->table('analytics.action_events')->insert([
                'id' => (string) Str::uuid(), 'user_id' => $site->user_id, 'site_id' => $site->id,
                'action_id' => $actionId, 'event' => 'tap', 'occurred_at' => now()->toISOString(),
                'session_id' => $session, 'created_at' => now()->toISOString(),
            ]);
        }
    }
}

it('cold start with boosts: exact blended scores — the recipe order IS the lander order (worked example)', function () {
    $site = createTenant('sb-cold')->site;
    $candidates = [
        boostCandidate('platform:fresha', 'platform'),
        boostCandidate('platform:instagram', 'platform'),
        boostCandidate('page:contact', 'page'),
    ];
    $boosts = ['platform:fresha' => 2.0, 'platform:instagram' => 1.5, 'page:contact' => 1.125];

    $rows = [];
    foreach (app(ActionScorer::class)->computeForSite($site, $candidates, [], $boosts)['rows'] as $row) {
        $rows[$row['content_key']] = $row;
    }

    // No events, 120-day-old connections: demand = smoothing prior exactly,
    // reach 0, freshness ≈ 0 → score = 0.45·prior + boost (boost REPLACES the
    // additive prior); no previous rows → blended = score.
    expect((float) $rows['platform:fresha']['score'])->toEqualWithDelta(0.45 * 0.05 + 2.0, 0.01)
        ->and((float) $rows['platform:instagram']['score'])->toEqualWithDelta(0.45 * 0.05 + 1.5, 0.01)
        ->and((float) $rows['page:contact']['score'])->toEqualWithDelta(0.45 * 0.12 + 1.125, 0.01)
        ->and($rows['platform:fresha']['rank'])->toBe(1)
        ->and($rows['platform:instagram']['rank'])->toBe(2)
        ->and($rows['page:contact']['rank'])->toBe(3);
});

it('a 2× tap-rate advantage flips recipe #2 over #1 within 6 runs — boosted pairs compare organics', function () {
    $site = createTenant('sb-flip')->site;
    $candidates = [boostCandidate('platform:a', 'platform'), boostCandidate('platform:b', 'platform')];
    $boosts = ['platform:a' => 2.0, 'platform:b' => 1.5];

    boostTapBurst($site, 'platform:a', taps: 10, exposures: 100);
    boostTapBurst($site, 'platform:b', taps: 20, exposures: 100);

    $rankB = null;
    for ($run = 1; $run <= 6; $run++) {
        $result = app(ActionScorer::class)->computeForSite($site, $candidates, [], $boosts);
        persistBoostRows($site, $result);
        foreach ($result['rows'] as $row) {
            if ($row['content_key'] === 'platform:b') {
                $rankB = (int) $row['rank'];
            }
        }
        if ($rankB === 1) {
            break;
        }
    }

    expect($rankB)->toBe(1);
});

it('equal tap rates never flip a boosted pair across 20 runs (noise holds)', function () {
    $site = createTenant('sb-noise')->site;
    $candidates = [boostCandidate('platform:a', 'platform'), boostCandidate('platform:b', 'platform')];
    $boosts = ['platform:a' => 2.0, 'platform:b' => 1.5];

    boostTapBurst($site, 'platform:a', taps: 12, exposures: 100);
    boostTapBurst($site, 'platform:b', taps: 12, exposures: 100);

    for ($run = 1; $run <= 20; $run++) {
        $result = app(ActionScorer::class)->computeForSite($site, $candidates, [], $boosts);
        persistBoostRows($site, $result);
        $ranks = [];
        foreach ($result['rows'] as $row) {
            $ranks[$row['content_key']] = (int) $row['rank'];
        }
        expect($ranks['platform:a'])->toBe(1)->and($ranks['platform:b'])->toBe(2);
    }
});

it('a genuinely popular organic candidate overtakes recipe #4 but never #1 (the boost wall)', function () {
    $site = createTenant('sb-wall')->site;
    $candidates = [
        boostCandidate('platform:top', 'platform'),
        boostCandidate('platform:fourth', 'platform'),
        boostCandidate('item:hit', 'item', now()->toIso8601String()), // fresh → full freshness term
    ];
    $boosts = ['platform:top' => 2.0, 'platform:fourth' => 0.84];

    // The organic hit: heavy, high-rate engagement + freshness + site-max reach.
    boostTapBurst($site, 'item:hit', taps: 200, exposures: 200);

    $result = app(ActionScorer::class)->computeForSite($site, $candidates, [], $boosts);
    $ranks = [];
    foreach ($result['rows'] as $row) {
        $ranks[$row['content_key']] = (int) $row['rank'];
    }

    expect($ranks['platform:top'])->toBe(1)      // uncatchable
        ->and($ranks['item:hit'])->toBe(2)       // beat the #4 recipe rung
        ->and($ranks['platform:fourth'])->toBe(3);
});

it('a latest-* boost migrates to the newer target and the old one decays, then deletes when it leaves the pool', function () {
    $site = createTenant('sb-migrate')->site;
    $rel1 = boostCandidate('item:rel1', 'item', now()->subDays(40)->toIso8601String());
    $rel2 = boostCandidate('item:rel2', 'item', now()->toIso8601String());

    // Run 1: rel1 is the latest release.
    $r1 = app(ActionScorer::class)->computeForSite($site, [$rel1, $rel2], [], ['item:rel1' => 1.125]);
    persistBoostRows($site, $r1);

    // Run 2: a newer release lands — the boost moves to rel2.
    $r2 = app(ActionScorer::class)->computeForSite($site, [$rel1, $rel2], [], ['item:rel2' => 1.125]);
    persistBoostRows($site, $r2);
    $ranks = [];
    foreach ($r2['rows'] as $row) {
        $ranks[$row['content_key']] = (int) $row['rank'];
    }
    expect($ranks['item:rel2'])->toBe(1)->and($ranks['item:rel1'])->toBe(2);

    // Run 3: rel1 leaves the candidate set entirely → stale-key delete.
    $r3 = app(ActionScorer::class)->computeForSite($site, [$rel2], [], ['item:rel2' => 1.125]);
    expect($r3['deletes'])->toContain('item:rel1');
});
