<?php

use Illuminate\Support\Facades\DB;
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

    expect(DB::connection('pgsql')->table('analytics.content_popularity_scores')
        ->where('site_id', $tenant->site->id)->count())->toBe(0);
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
