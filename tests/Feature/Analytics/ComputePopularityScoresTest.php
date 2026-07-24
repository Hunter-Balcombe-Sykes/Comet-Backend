<?php

use App\Console\Commands\ComputeContentPopularityScores;
use App\Services\Analytics\RankedActionsComputer;
use Illuminate\Console\Scheduling\Schedule;
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
    setupActionEventsTable();
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

it('seeds nothing for ancient connections (boost below the floor) — the action layer still cold-starts, no freshness gate', function () {
    $tenant = createTenant('cmd-ancient');
    $resourceId = 'link-'.Str::random(8);
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $tenant->id,
        'platform' => 'custom',
        'resource_id' => $resourceId,
        'resource_kind' => 'link',
        'payload' => json_encode(['kind' => 'link', 'url' => 'https://example.com/old', 'name' => 'Old link']),
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

    // ...but the action layer (2026-07-23 rebuild) has no freshness/floor gate
    // at all — it cold-starts every pool entry at its prior CTR regardless of
    // the underlying connection's age. The 200-day-old custom connection
    // still yields its custom:<resource_id> action, scored at the 'custom'
    // family's prior (0.05).
    $row = popularityScoreRow($tenant->site->id, 'action', 'custom:'.$resourceId);
    expect($row)->not->toBeNull()
        ->and((float) $row->score)->toEqualWithDelta(0.05, 0.0001);
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
    $resourceId = 'link-'.Str::random(8);
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $tenant->id,
        'platform' => 'custom',
        'resource_id' => $resourceId,
        'resource_kind' => 'link',
        'payload' => json_encode(['kind' => 'link', 'url' => 'https://example.com/obs3', 'name' => 'Obs3 link']),
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
    expect(popularityScoreRow($tenant->site->id, 'action', 'custom:'.$resourceId))->toBeNull();
});

// ── SCALE-3: the full sweep (no --site) scopes to sites with recent events ──

it('processes a site with a recent event but skips a published site with none, in a full sweep', function () {
    $active = createTenant('scale3-active');
    $idle = createTenant('scale3-idle');

    // Active site: one link click right now — must be picked up by the sweep.
    DB::connection('pgsql')->table('analytics.link_clicks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $active->id,
        'site_id' => $active->site->id,
        'section_key' => 'bio',
        'url' => 'https://example.com/active',
        'occurred_at' => now()->toISOString(),
        'created_at' => now()->toISOString(),
    ]);

    // Idle site: no events at all, but it DOES have a fresh platform connection —
    // pre-fix, the full sweep would still process it and seed a freshness-only
    // score. Post-fix it must be skipped entirely (zero rows, any content_type).
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $idle->id,
        'platform' => 'custom',
        'resource_id' => 'link-'.Str::random(8),
        'resource_kind' => 'link',
        'payload' => json_encode(['kind' => 'link', 'url' => 'https://example.com/idle']),
        'is_active' => 1,
        'created_at' => now()->toISOString(),
        'updated_at' => now()->toISOString(),
    ]);

    // No --site — the periodic scheduled full sweep.
    $this->artisan('analytics:compute-popularity')->assertExitCode(0);

    expect(popularityScoreRow($active->site->id, 'page', 'home'))->not->toBeNull();

    // Idle site was skipped entirely — no page/item/action rows of any kind.
    expect(DB::connection('pgsql')->table('analytics.content_popularity_scores')
        ->where('site_id', $idle->site->id)
        ->count())->toBe(0);
});

it('treats an event older than the recent-events window as no signal in a full sweep', function () {
    $stale = createTenant('scale3-stale');

    // 90 minutes old — outside the 60-minute recent-events window, even though
    // it would still contribute a (heavily decayed) score if processed.
    DB::connection('pgsql')->table('analytics.link_clicks')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $stale->id,
        'site_id' => $stale->site->id,
        'section_key' => 'bio',
        'url' => 'https://example.com/stale',
        'occurred_at' => now()->subMinutes(90)->toISOString(),
        'created_at' => now()->subMinutes(90)->toISOString(),
    ]);

    $this->artisan('analytics:compute-popularity')->assertExitCode(0);

    expect(DB::connection('pgsql')->table('analytics.content_popularity_scores')
        ->where('site_id', $stale->site->id)
        ->count())->toBe(0);
});

it('an explicit --site bypasses the recent-events scope even with zero recent events', function () {
    $idle = createTenant('scale3-explicit');
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $idle->id,
        'platform' => 'custom',
        'resource_id' => 'link-'.Str::random(8),
        'resource_kind' => 'link',
        'payload' => json_encode(['kind' => 'link', 'url' => 'https://example.com/explicit']),
        'is_active' => 1,
        'created_at' => now()->toISOString(),
        'updated_at' => now()->toISOString(),
    ]);

    $this->artisan('analytics:compute-popularity', ['--site' => $idle->site->id])->assertExitCode(0);

    // Explicit --site always processes the named site, matching the
    // pre-existing contract (every test above this block relies on it).
    expect(popularityScoreRow($idle->site->id, 'link_item', 'https://example.com/explicit'))->not->toBeNull();
});

// ── OBS-5: an explicit $timeout ceiling on this every-15-min sweep ──

it('declares an explicit, non-null $timeout ceiling', function () {
    $property = (new ReflectionClass(ComputeContentPopularityScores::class))->getProperty('timeout');
    $property->setAccessible(true);

    expect($property->getDefaultValue())->not->toBeNull()
        ->and($property->getDefaultValue())->toBeGreaterThan(0);
});

// ── SCALE-3 follow-up (2026-07-20): pin the missed-tick margin invariant so a
// future shrink of the window (or a cadence change) goes red instead of just
// re-opening the gap silently. See routes/console.php's "missed-tick gap" note. ──

it('keeps the recent-events lookback at least 2x the scheduled cadence', function () {
    $events = collect(app(Schedule::class)->events());
    $event = $events->first(fn ($e) => str_contains((string) $e->command, 'analytics:compute-popularity'));

    expect($event)->not->toBeNull('analytics:compute-popularity is not registered in the scheduler')
        ->and($event->expression)->toBe('*/15 * * * *'); // everyFifteenMinutes()

    $cadenceMinutes = 15;
    $window = (new ReflectionClass(ComputeContentPopularityScores::class))->getConstant('RECENT_EVENTS_WINDOW_MINUTES');

    // 2x cadence is the bare minimum to survive even one missed tick
    // (W >= (K+1) x cadence for K=1); the current 60min value is chosen to
    // survive K=3, comfortably clearing this floor.
    expect($window)->toBeGreaterThanOrEqual($cadenceMinutes * 2);
});
