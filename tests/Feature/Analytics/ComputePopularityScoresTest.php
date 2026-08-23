<?php

use App\Console\Commands\ComputeContentPopularityScores;
use App\Services\Analytics\ActionScorer;
use App\Services\Content\LinkPoolWriter;
use App\Services\Content\ServiceCollections;
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
    // ActionCandidates reads the pools for item actions (was: the `custom:`
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

    $item = popularityScoreRow($tenant->site->id, 'link_item', $poolItemId);
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
    DB::connection('pgsql')->table('content.items')->where('id', $poolItemId)
        ->update(['created_at' => now()->subDays(200)->toISOString(), 'first_seen_at' => now()->subDays(200)->toISOString()]);

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
    // Two item clicks exactly 90 days old on a watch item: 2 × w_click(watch 2.0) × 0.5 = 2.0.
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
        ->and((float) $row->score)->toBeGreaterThan(1.9)
        ->and((float) $row->score)->toBeLessThan(2.1);
});

it('keys a legacy handle-keyed shop click and a url-keyed link click by the item id', function () {
    $tenant = createTenant('cmd-idkey');
    $store = shopStore($tenant->id);
    $productId = shopProduct($tenant->id, $store, 'Bulwark Jacket'); // handle bulwark-jacket
    $linkId = app(LinkPoolWriter::class)->add($tenant->refresh(), 'https://example.com/keyed', enrich: false);
    // Age both items out of their freshness window so the score is clicks only.
    DB::connection('pgsql')->table('content.items')->whereIn('id', [$linkId, $productId])
        ->update(['first_seen_at' => now()->subDays(200)->toISOString()]);
    DB::connection('pgsql')->table('content.f_published')->where('item_id', $productId)
        ->update(['published_from' => now()->subDays(200)->toISOString()]);

    foreach ([['shop', 'bulwark-jacket'], ['shop', $productId], ['custom', 'https://example.com/keyed']] as [$section, $key]) {
        DB::connection('pgsql')->table('analytics.link_clicks')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $tenant->id,
            'site_id' => $tenant->site->id,
            'section_key' => $section,
            'product_id' => $key,
            'url' => 'https://example.com/'.Str::random(4),
            'occurred_at' => now()->toISOString(),
            'created_at' => now()->toISOString(),
        ]);
    }

    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])->assertExitCode(0);

    // Both shop clicks (handle + id) fold onto ONE id-keyed row: 2 × w_click(shop 3.0).
    $product = popularityScoreRow($tenant->site->id, 'shop_product', $productId);
    expect($product)->not->toBeNull()->and((float) $product->score)->toEqualWithDelta(6.0, 0.05);
    expect(popularityScoreRow($tenant->site->id, 'shop_product', 'bulwark-jacket'))->toBeNull();

    $link = popularityScoreRow($tenant->site->id, 'link_item', $linkId);
    expect($link)->not->toBeNull()->and((float) $link->score)->toEqualWithDelta(3.0, 0.05);
    expect(popularityScoreRow($tenant->site->id, 'link_item', 'https://example.com/keyed'))->toBeNull();
});

it('does not let one fresh event resurrect stale history to full weight', function () {
    $tenant = createTenant('cmd-resurrect');
    // 10 watch clicks 180 days ago (weight 0.25 × w_click 2.0 each → 5.0) + one fresh impression (1.0).
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

    // Old model: 21 × 1.0 (decay-since-latest) = 21. New: 5.0 + 1.0 = 6.0.
    $row = popularityScoreRow($tenant->site->id, 'watch_item', 'vid-1');
    expect($row)->not->toBeNull()
        ->and((float) $row->score)->toBeGreaterThan(5.5)
        ->and((float) $row->score)->toBeLessThan(7.0);
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

// ── Smart ordering v2 (A2): per-family weights, generalised freshness, media dwell ──

function popularityTenantSource(object $tenant): string
{
    return DB::table('content.sources')->where('user_id', $tenant->id)->where('kind', 'manual')->value('id')
        ?? poolSource($tenant->id, null);
}

it('applies each family\'s own click weight: the same clicks score a product 3.0 and a video 2.0', function () {
    $tenant = createTenant('cmd-weights');
    $source = popularityTenantSource($tenant);
    $ago = now()->subDays(300)->toISOString();
    $product = poolItem($tenant->id, $source, 'product', 'Tee', $ago);
    $video = poolItem($tenant->id, $source, 'video', 'Clip', $ago);
    DB::table('content.items')->whereIn('id', [$product, $video])->update(['first_seen_at' => $ago]);
    foreach ([['shop', $product], ['watch', $video]] as [$section, $id]) {
        DB::connection('pgsql')->table('analytics.link_clicks')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $tenant->id, 'site_id' => $tenant->site->id,
            'section_key' => $section, 'product_id' => $id, 'url' => 'https://example.com/x',
            'occurred_at' => now()->toISOString(), 'created_at' => now()->toISOString(),
        ]);
    }

    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])->assertExitCode(0);

    expect((float) popularityScoreRow($tenant->site->id, 'shop_product', $product)->score)->toEqualWithDelta(3.0, 0.05)
        ->and((float) popularityScoreRow($tenant->site->id, 'watch_item', $video)->score)->toEqualWithDelta(2.0, 0.05);
});

it('seeds a zero-signal media item on freshness alone (5.0 at day 0) and lets it fade below a clicked one by day 21', function () {
    $tenant = createTenant('cmd-media-fresh');
    $source = popularityTenantSource($tenant);
    $fresh = poolItem($tenant->id, $source, 'media', 'New shot', now()->toISOString());
    DB::table('content.items')->where('id', $fresh)->update(['first_seen_at' => now()->toISOString()]);
    $old = poolItem($tenant->id, $source, 'media', 'Old shot', now()->subDays(300)->toISOString());
    DB::table('content.items')->where('id', $old)->update(['first_seen_at' => now()->subDays(300)->toISOString()]);
    // The old shot has one impression (w_view 1.0) — a real, small signal.
    DB::connection('pgsql')->table('analytics.item_views')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $tenant->id, 'site_id' => $tenant->site->id,
        'item_type' => 'gallery_item', 'item_id' => $old, 'visitor_id' => (string) Str::uuid(),
        'occurred_at' => now()->toISOString(), 'created_at' => now()->toISOString(),
    ]);

    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])->assertExitCode(0);
    $freshRow = popularityScoreRow($tenant->site->id, 'gallery_item', $fresh);
    expect($freshRow)->not->toBeNull()
        ->and((float) $freshRow->score)->toEqualWithDelta(5.0, 0.05)
        ->and((int) $freshRow->rank)->toBe(1);

    // 21 days on (three 7-day half-lives): 5 × 0.125 = 0.625 < the old shot's 1.0.
    $this->travel(21)->days();
    DB::connection('pgsql')->table('analytics.content_popularity_scores')->where('site_id', $tenant->site->id)->delete();
    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])->assertExitCode(0);
    $freshRow = popularityScoreRow($tenant->site->id, 'gallery_item', $fresh);
    expect((float) $freshRow->score)->toEqualWithDelta(0.625, 0.05)
        ->and((int) $freshRow->rank)->toBe(2);
});

it('splits the gallery page dwell equally across the served media items at 0.05/s', function () {
    $tenant = createTenant('cmd-media-dwell');
    $source = popularityTenantSource($tenant);
    $ago = now()->subDays(300)->toISOString();
    $a = poolItem($tenant->id, $source, 'media', 'Shot A', $ago);
    $b = poolItem($tenant->id, $source, 'media', 'Shot B', $ago);
    DB::table('content.items')->whereIn('id', [$a, $b])->update(['first_seen_at' => $ago]);
    foreach ([$a, $b] as $id) {
        poolPin($tenant->site->id, 'media', $id);
    }
    // 40 s of gallery dwell today → 20 s each → 1.0 each.
    DB::connection('pgsql')->table('analytics.section_views')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $tenant->id, 'site_id' => $tenant->site->id,
        'section_key' => 'gallery', 'duration_ms' => 40000,
        'visitor_id' => (string) Str::uuid(), 'occurred_at' => now()->toISOString(), 'created_at' => now()->toISOString(),
    ]);

    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])->assertExitCode(0);

    expect((float) popularityScoreRow($tenant->site->id, 'gallery_item', $a)->score)->toEqualWithDelta(1.0, 0.05)
        ->and((float) popularityScoreRow($tenant->site->id, 'gallery_item', $b)->score)->toEqualWithDelta(1.0, 0.05);
});

it('scores a service category as the SUM of its served members and ranks categories by it (D2)', function () {
    $tenant = createTenant('cmd-category-sum');
    $ago = now()->subDays(300)->toISOString();
    $hair = app(ServiceCollections::class)->create($tenant->id, 'Hair');
    $nails = app(ServiceCollections::class)->create($tenant->id, 'Nails');
    $cut = ownerServiceItem($tenant->id, ['title' => 'Cut']);
    $colour = ownerServiceItem($tenant->id, ['title' => 'Colour']);
    $mani = ownerServiceItem($tenant->id, ['title' => 'Manicure']);
    app(ServiceCollections::class)->assign($tenant->id, $cut, $hair, null);
    app(ServiceCollections::class)->assign($tenant->id, $colour, $hair, null);
    app(ServiceCollections::class)->assign($tenant->id, $mani, $nails, null);
    DB::table('content.items')->whereIn('id', [$cut, $colour, $mani])->update(['first_seen_at' => $ago]);
    // Hair: 1 + 1 clicks (3.0 each) = 6.0; Nails: one dish with 1 click = 3.0 — breadth wins.
    foreach ([$cut, $colour, $mani] as $id) {
        DB::connection('pgsql')->table('analytics.link_clicks')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $tenant->id, 'site_id' => $tenant->site->id,
            'section_key' => 'book', 'product_id' => $id, 'url' => 'https://example.com/x',
            'occurred_at' => now()->toISOString(), 'created_at' => now()->toISOString(),
        ]);
    }

    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])->assertExitCode(0);

    $hairRow = popularityScoreRow($tenant->site->id, 'service_category', $hair);
    $nailsRow = popularityScoreRow($tenant->site->id, 'service_category', $nails);
    expect($hairRow)->not->toBeNull()->and((float) $hairRow->score)->toEqualWithDelta(6.0, 0.05)->and((int) $hairRow->rank)->toBe(1)
        ->and($nailsRow)->not->toBeNull()->and((float) $nailsRow->score)->toEqualWithDelta(3.0, 0.05)->and((int) $nailsRow->rank)->toBe(2);

    // A category that stops being served fades out like any other stored row.
    app(ServiceCollections::class)->remove($tenant->id, $nails);
    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])->assertExitCode(0);
    expect((float) popularityScoreRow($tenant->site->id, 'service_category', $nails)->score)->toEqualWithDelta(0.9, 0.05);
});

it('counts a lander tap on item:<id> as a click in that item\'s family (D7)', function () {
    $tenant = createTenant('cmd-lander-tap');
    $source = popularityTenantSource($tenant);
    $ago = now()->subDays(300)->toISOString();
    $video = poolItem($tenant->id, $source, 'video', 'Clip', $ago);
    DB::table('content.items')->where('id', $video)->update(['first_seen_at' => $ago]);
    // Three taps from ONE session count once; one more session → 2 clicks × w_click(watch 2.0) = 4.0.
    $session = (string) Str::uuid();
    foreach ([$session, $session, $session, (string) Str::uuid()] as $sid) {
        DB::connection('pgsql')->table('analytics.action_events')->insert([
            'id' => (string) Str::uuid(), 'user_id' => $tenant->id, 'site_id' => $tenant->site->id,
            'action_id' => 'item:'.$video, 'event' => 'tap', 'session_id' => $sid,
            'occurred_at' => now()->toISOString(), 'created_at' => now()->toISOString(),
        ]);
    }

    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])->assertExitCode(0);

    expect((float) popularityScoreRow($tenant->site->id, 'watch_item', $video)->score)->toEqualWithDelta(4.0, 0.05);
});

it('never writes a row for an event item, even a brand-new one', function () {
    $tenant = createTenant('cmd-no-events');
    $source = popularityTenantSource($tenant);
    $event = poolItem($tenant->id, $source, 'event', 'Gig', now()->addDays(3)->toISOString());
    DB::connection('pgsql')->table('analytics.link_clicks')->insert([
        'id' => (string) Str::uuid(), 'user_id' => $tenant->id, 'site_id' => $tenant->site->id,
        'section_key' => 'events', 'product_id' => $event, 'url' => 'https://example.com/gig',
        'occurred_at' => now()->toISOString(), 'created_at' => now()->toISOString(),
    ]);

    $this->artisan('analytics:compute-popularity', ['--site' => $tenant->site->id])->assertExitCode(0);

    expect(DB::connection('pgsql')->table('analytics.content_popularity_scores')
        ->where('site_id', $tenant->site->id)->where('content_key', $event)
        ->where('content_type', '!=', 'action')->count())->toBe(0);
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
    expect(popularityScoreRow($tenant->site->id, 'link_item', $poolItemId))->not->toBeNull();
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

    // Idle site: no events at all, and its only item was added TWO HOURS ago
    // (outside the window — a content change inside it would scope the site
    // in, D6). The item is still fresh enough to seed a freshness-only score
    // if the site were processed, so zero rows proves the skip.
    $poolItemId = app(LinkPoolWriter::class)->add($idle->refresh(), 'https://example.com/idle', enrich: false);
    DB::connection('pgsql')->table('content.items')->where('id', $poolItemId)
        ->update(['created_at' => now()->subHours(2)->toDateTimeString(), 'updated_at' => now()->subHours(2)->toDateTimeString()]);

    // No --site — the periodic scheduled full sweep.
    $this->artisan('analytics:compute-popularity')->assertExitCode(0);

    expect(popularityScoreRow($active->site->id, 'watch_item', 'vid-active'))->not->toBeNull();

    // Idle site was skipped entirely — no page/item/action rows of any kind.
    expect(DB::connection('pgsql')->table('analytics.content_popularity_scores')
        ->where('site_id', $idle->site->id)
        ->count())->toBe(0);
});

it('scopes a site with a NEW item and no traffic into the full sweep (D6)', function () {
    $quiet = createTenant('scale3-new-item');
    $source = popularityTenantSource($quiet);
    // Created now, no events at all — the content change alone scopes it in.
    $video = poolItem($quiet->id, $source, 'video', 'Fresh clip', now()->toISOString());
    DB::table('content.items')->where('id', $video)->update(['first_seen_at' => now()->toISOString()]);

    $this->artisan('analytics:compute-popularity')->assertExitCode(0);

    // Cold start: watch_item freshness 3.0 at day 0.
    $row = popularityScoreRow($quiet->site->id, 'watch_item', $video);
    expect($row)->not->toBeNull()->and((float) $row->score)->toEqualWithDelta(3.0, 0.05);
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
    expect(popularityScoreRow($idle->site->id, 'link_item', $poolItemId))->not->toBeNull();
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
