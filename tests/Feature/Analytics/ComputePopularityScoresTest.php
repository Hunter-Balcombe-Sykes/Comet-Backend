<?php

use App\Console\Commands\ComputeContentPopularityScores;
use App\Services\Analytics\ActionScorer;
use App\Services\Content\LinkPoolWriter;
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
    // SiteActionsService reads the `custom_links` pool for the `custom:`
    // action family (convergence Phase 6), so site.sections must exist.
    setupSectionsTables();
    // Link items live in the custom_links POOL now (2026-08-19) — the
    // freshness reader joins content.items + f_link.
    setupIngestTables();
    setupContentTables();
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

it('seeds a brand-new link with a freshness-only item score and an action row for it (zero events)', function () {
    $tenant = createTenant('cmd-fresh');
    $poolItemId = app(LinkPoolWriter::class)->add($tenant->refresh(), 'https://example.com/fresh', enrich: false);

    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])
        ->assertExitCode(0);

    // No page family any more (2026-08-23): pages are actions.
    expect(DB::connection('pgsql')->table('analytics.content_popularity_scores')
        ->where('site_id', $tenant->site->id)->where('content_type', 'page')->count())->toBe(0);

    $item = popularityScoreRow($tenant->site->id, 'link_item', 'https://example.com/fresh');
    expect($item)->not->toBeNull()
        ->and((float) $item->score)->toBeGreaterThan(2.9)
        ->and((float) $item->score)->toBeLessThanOrEqual(3.0)
        ->and((int) $item->rank)->toBe(1);

    $action = popularityScoreRow($tenant->site->id, 'action', 'item:'.$poolItemId);
    expect($action)->not->toBeNull()->and((float) $action->score)->toBeGreaterThan(0.0);
});

it('seeds nothing for an ancient link (boost below the floor) — the action layer still cold-starts, no freshness gate', function () {
    $tenant = createTenant('cmd-ancient');
    $poolItemId = app(LinkPoolWriter::class)->add($tenant->refresh(), 'https://example.com/old', 'Old link', enrich: false);
    DB::connection('pgsql')->table('content.items')->where('id', $poolItemId)->update(['created_at' => now()->subDays(200)->toISOString()]);

    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])
        ->assertExitCode(0);

    expect(DB::connection('pgsql')->table('analytics.content_popularity_scores')
        ->where('site_id', $tenant->site->id)
        ->where('content_type', '!=', 'action')
        ->count())->toBe(0);

    // Action cold start: demand = prior, reach 0, freshness from the item's
    // first_seen (today — the pool write stamps it) → prior + 0.45·prior + 0.25·fresh.
    $row = popularityScoreRow($tenant->site->id, 'action', 'item:'.$poolItemId);
    expect($row)->not->toBeNull()
        ->and((float) $row->score)->toBeGreaterThan(1.45 * 0.03 - 0.001);
});

it('decays each day\'s events with a 90-day true half-life', function () {
    $tenant = createTenant('cmd-decay');
    // Two item clicks exactly 90 days old on a watch item: 2 × W_CLICK(3.0) × 0.5 = 3.0.
    foreach (range(1, 2) as $i) {
        DB::connection('pgsql')->table('analytics.link_clicks')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $tenant->id,
            'site_id' => $tenant->site->id,
            'section_key' => 'watch',
            'product_id' => 'vid-1',
            'url' => 'https://example.com/'.$i,
            'occurred_at' => now()->subDays(90)->toISOString(),
            'created_at' => now()->subDays(90)->toISOString(),
        ]);
    }

    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])
        ->assertExitCode(0);

    $row = popularityScoreRow($tenant->site->id, 'watch_item', 'vid-1');
    expect($row)->not->toBeNull()
        ->and((float) $row->score)->toBeGreaterThan(2.85)
        ->and((float) $row->score)->toBeLessThan(3.15);
});

it('does not let one fresh event resurrect stale history to full weight', function () {
    $tenant = createTenant('cmd-resurrect');
    // 10 clicks 180 days ago (weight 0.25 each → 7.5) + one fresh impression (1.0).
    foreach (range(1, 10) as $i) {
        DB::connection('pgsql')->table('analytics.link_clicks')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $tenant->id,
            'site_id' => $tenant->site->id,
            'section_key' => 'watch',
            'product_id' => 'vid-1',
            'url' => 'https://example.com/'.$i,
            'occurred_at' => now()->subDays(180)->toISOString(),
            'created_at' => now()->subDays(180)->toISOString(),
        ]);
    }
    DB::connection('pgsql')->table('analytics.item_views')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $tenant->id,
        'site_id' => $tenant->site->id,
        'item_type' => 'watch_item',
        'item_id' => 'vid-1',
        'visitor_id' => (string) Str::uuid(),
        'occurred_at' => now()->toISOString(),
        'created_at' => now()->toISOString(),
    ]);

    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])
        ->assertExitCode(0);

    // Old model: 31 × 1.0 (decay-since-latest) = 31. New: 7.5 + 1.0 = 8.5.
    $row = popularityScoreRow($tenant->site->id, 'watch_item', 'vid-1');
    expect($row)->not->toBeNull()
        ->and((float) $row->score)->toBeGreaterThan(7.5)
        ->and((float) $row->score)->toBeLessThan(10.0);
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

// ── OBS-3: the ranked-actions catch block reports() before logging ──

it('reports (but does not throw) when the action layer fails, and still writes item scores', function () {
    Exceptions::fake();
    $tenant = createTenant('cmd-obs3');
    $poolItemId = app(LinkPoolWriter::class)->add($tenant->refresh(), 'https://example.com/obs3', 'Obs3 link', enrich: false);

    $broken = Mockery::mock(ActionScorer::class);
    $broken->shouldReceive('computeForSite')->andThrow(new RuntimeException('action scoring exploded'));
    app()->instance(ActionScorer::class, $broken);

    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])
        ->assertExitCode(0);

    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'action scoring exploded');
    expect(popularityScoreRow($tenant->site->id, 'link_item', 'https://example.com/obs3'))->not->toBeNull();
    expect(popularityScoreRow($tenant->site->id, 'action', 'item:'.$poolItemId))->toBeNull();
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
        'section_key' => 'watch',
        'product_id' => 'vid-active',
        'url' => 'https://example.com/active',
        'occurred_at' => now()->toISOString(),
        'created_at' => now()->toISOString(),
    ]);

    // Idle site: no events at all, but it DOES have a fresh platform connection —
    // pre-fix, the full sweep would still process it and seed a freshness-only
    // score. Post-fix it must be skipped entirely (zero rows, any content_type).
    $poolItemId = app(LinkPoolWriter::class)->add($idle->refresh(), 'https://example.com/idle', enrich: false);

    // No --site — the periodic scheduled full sweep.
    $this->artisan('analytics:compute-popularity')->assertExitCode(0);

    expect(popularityScoreRow($active->site->id, 'watch_item', 'vid-active'))->not->toBeNull();

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
    $poolItemId = app(LinkPoolWriter::class)->add($idle->refresh(), 'https://example.com/explicit', enrich: false);

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
