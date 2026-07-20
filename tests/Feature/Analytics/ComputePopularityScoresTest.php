<?php

use App\Console\Commands\ComputeContentPopularityScores;
use App\Services\Analytics\RankedActionsComputer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;

// analytics:compute-popularity — first regression coverage for the scoring
// command: the freshness boost (additive, seeds zero-signal keys) and the
// per-page dwell term, end-to-end through the artisan command.

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupSitesTable();     // sites + platform_connections
    setupBlocksTable();
    setupServicesTable();
    setupSectionViewsTable();
    setupLinkClicksTable();
    setupItemViewsTable();
    setupContentPopularityScoresTable();
});

function popularityScoreRow(string $siteId, string $type, string $key): ?object
{
    return DB::connection('pgsql')->table('analytics.content_popularity_scores')
        ->where('site_id', $siteId)
        ->where('content_type', $type)
        ->where('content_key', $key)
        ->first();
}

it('seeds brand-new content with a freshness-only score (page + link item, zero events)', function () {
    $tenant = createTenant('cmd-fresh');
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $tenant->id,
        'platform' => 'custom',
        'resource_id' => 'link-'.Str::random(8),
        'resource_kind' => 'link',
        'payload' => json_encode(['kind' => 'link', 'url' => 'https://example.com/fresh']),
        'is_active' => 1,
        'created_at' => now()->toISOString(),
        'updated_at' => now()->toISOString(),
    ]);

    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])
        ->assertExitCode(0);

    // Links page: no events at all — score IS the freshness boost (blend of a
    // first-seen key is a no-op: new == computed).
    $page = popularityScoreRow($tenant->site->id, 'page', 'links');
    expect($page)->not->toBeNull()
        ->and((float) $page->score)->toBeGreaterThan(14.5)
        ->and((float) $page->score)->toBeLessThanOrEqual(15.0);

    // The link item itself: freshness-only score at the item weight.
    $item = popularityScoreRow($tenant->site->id, 'link_item', 'https://example.com/fresh');
    expect($item)->not->toBeNull()
        ->and((float) $item->score)->toBeGreaterThan(2.9)
        ->and((float) $item->score)->toBeLessThanOrEqual(3.0)
        ->and((int) $item->rank)->toBe(1);
});

it('seeds nothing for ancient connections (boost below the floor)', function () {
    $tenant = createTenant('cmd-ancient');
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $tenant->id,
        'platform' => 'custom',
        'resource_id' => 'link-'.Str::random(8),
        'resource_kind' => 'link',
        'payload' => json_encode(['kind' => 'link', 'url' => 'https://example.com/old']),
        'is_active' => 1,
        'created_at' => now()->subDays(200)->toISOString(),
        'updated_at' => now()->subDays(200)->toISOString(),
    ]);

    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])
        ->assertExitCode(0);

    // Page/item families seed nothing (boost below the floor)...
    expect(DB::connection('pgsql')->table('analytics.content_popularity_scores')
        ->where('site_id', $tenant->site->id)
        ->where('content_type', '!=', 'action')
        ->count())->toBe(0);

    // ...but the ranked-action layer still writes its cold-start row for the
    // present Links page (priors + recency carry zero-signal sites by design).
    expect(popularityScoreRow($tenant->site->id, 'action', 'page:links'))->not->toBeNull();
});

it('decays each day\'s events with a 90-day true half-life', function () {
    $tenant = createTenant('cmd-decay');
    // Two page-clicks on bio (→ home) exactly 90 days ago: 2·3 = 6.0 raw,
    // halved by one full half-life → ≈3.0 (blend of a first-seen key is a no-op).
    foreach (range(1, 2) as $i) {
        DB::connection('pgsql')->table('analytics.link_clicks')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $tenant->id,
            'site_id' => $tenant->site->id,
            'section_key' => 'bio',
            'url' => 'https://example.com/'.$i,
            'occurred_at' => now()->subDays(90)->toISOString(),
            'created_at' => now()->subDays(90)->toISOString(),
        ]);
    }

    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])
        ->assertExitCode(0);

    $home = popularityScoreRow($tenant->site->id, 'page', 'home');
    expect($home)->not->toBeNull()
        ->and((float) $home->score)->toBeGreaterThan(2.85)
        ->and((float) $home->score)->toBeLessThan(3.15);
});

it('does not let one fresh event resurrect stale history to full weight', function () {
    $tenant = createTenant('cmd-resurrect');
    // 10 clicks 180 days ago (2 half-lives → count as 2.5 clicks = 7.5) + 1
    // impression today (≈1.0). Old formula: (30 + 1) × recency(now)=1 → 31.
    foreach (range(1, 10) as $i) {
        DB::connection('pgsql')->table('analytics.link_clicks')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $tenant->id,
            'site_id' => $tenant->site->id,
            'section_key' => 'bio',
            'url' => 'https://example.com/'.$i,
            'occurred_at' => now()->subDays(180)->toISOString(),
            'created_at' => now()->subDays(180)->toISOString(),
        ]);
    }
    DB::connection('pgsql')->table('analytics.section_views')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $tenant->id,
        'site_id' => $tenant->site->id,
        'section_key' => 'bio',
        'visitor_id' => (string) Str::uuid(),
        'occurred_at' => now()->toISOString(),
        'created_at' => now()->toISOString(),
    ]);

    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])
        ->assertExitCode(0);

    // Expect ≈ 7.5 + 1 = 8.5 — decisively below the old resurrected 31.
    $home = popularityScoreRow($tenant->site->id, 'page', 'home');
    expect($home)->not->toBeNull()
        ->and((float) $home->score)->toBeGreaterThan(7.5)
        ->and((float) $home->score)->toBeLessThan(10.0);
});

it('fades stored keys with no remaining signal and deletes them below the floor', function () {
    $tenant = createTenant('cmd-fade');
    // A stale stored score with NO backing events (e.g. its raw events were
    // purged): must decay 0.3× per run, not freeze forever.
    DB::connection('pgsql')->table('analytics.content_popularity_scores')->insert([
        'id' => (string) Str::uuid(),
        'site_id' => $tenant->site->id,
        'content_type' => 'shop_product',
        'content_key' => 'ghost-product',
        'score' => 10.0,
        'rank' => 1,
        'computed_at' => now()->subDay()->toISOString(),
    ]);

    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])
        ->assertExitCode(0);

    $row = popularityScoreRow($tenant->site->id, 'shop_product', 'ghost-product');
    expect($row)->not->toBeNull()
        ->and((float) $row->score)->toBeGreaterThan(2.9)
        ->and((float) $row->score)->toBeLessThan(3.1); // 0.3 × 10

    // Keep running — 0.9 → 0.27 → 0.081 → 0.0243 < floor → deleted.
    foreach (range(1, 4) as $_run) {
        $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])
            ->assertExitCode(0);
    }

    expect(popularityScoreRow($tenant->site->id, 'shop_product', 'ghost-product'))->toBeNull();
});

it('adds the dwell term to the page score (0.05 per second)', function () {
    $tenant = createTenant('cmd-dwell');
    // One bio impression (bio → home, always present) with 60s of dwell:
    // score = (1 impression + 0.05·60s) · recency(≈1) = 4.0.
    DB::connection('pgsql')->table('analytics.section_views')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $tenant->id,
        'site_id' => $tenant->site->id,
        'section_key' => 'bio',
        'visitor_id' => (string) Str::uuid(),
        'duration_ms' => 60_000,
        'occurred_at' => now()->toISOString(),
        'created_at' => now()->toISOString(),
    ]);

    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])
        ->assertExitCode(0);

    $home = popularityScoreRow($tenant->site->id, 'page', 'home');
    expect($home)->not->toBeNull()
        ->and((float) $home->score)->toBeGreaterThan(3.9)
        ->and((float) $home->score)->toBeLessThanOrEqual(4.0);
});

// ── OBS-3: the ranked-actions catch block reports() before logging ──

it('reports (but does not throw) when the ranked-actions layer fails, and still writes page/item scores', function () {
    Exceptions::fake();

    $tenant = createTenant('cmd-obs3');
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $tenant->id,
        'platform' => 'custom',
        'resource_id' => 'link-'.Str::random(8),
        'resource_kind' => 'link',
        'payload' => json_encode(['kind' => 'link', 'url' => 'https://example.com/obs3']),
        'is_active' => 1,
        'created_at' => now()->toISOString(),
        'updated_at' => now()->toISOString(),
    ]);

    // Force the action layer (RankedActionsComputer::computeForSite) to blow up
    // AFTER page/item scores have already been computed for this run.
    $brokenRankedActions = Mockery::mock(RankedActionsComputer::class);
    $brokenRankedActions->shouldReceive('computeForSite')
        ->andThrow(new RuntimeException('ranked actions exploded'));
    app()->instance(RankedActionsComputer::class, $brokenRankedActions);

    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])
        ->assertExitCode(0);

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'ranked actions exploded');

    // Fail-open survives: page + link-item scores computed BEFORE the action
    // layer still land, even though computeActions() blew up.
    expect(popularityScoreRow($tenant->site->id, 'page', 'links'))->not->toBeNull();
    expect(popularityScoreRow($tenant->site->id, 'link_item', 'https://example.com/obs3'))->not->toBeNull();

    // The action layer itself produced nothing — it exploded before writing.
    expect(popularityScoreRow($tenant->site->id, 'action', 'page:links'))->toBeNull();
});

// ── OBS-5: an explicit $timeout ceiling on this every-15-min sweep ──

it('declares an explicit, non-null $timeout ceiling', function () {
    $property = (new ReflectionClass(ComputeContentPopularityScores::class))->getProperty('timeout');
    $property->setAccessible(true);

    expect($property->getDefaultValue())->not->toBeNull()
        ->and($property->getDefaultValue())->toBeGreaterThan(0);
});
