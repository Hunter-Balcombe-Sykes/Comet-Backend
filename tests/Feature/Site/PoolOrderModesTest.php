<?php

use App\Models\Core\Site\Site;
use App\Services\Content\ServiceCollections;
use App\Site\Pools\PoolResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

// settings.pool_order per pool (spec §5.4): newest reorders pins and auto
// together, smart follows popularity ranks, manual keeps pins-then-rule,
// events ignore the mode.

beforeEach(function () {
    setupUsersTable();
    setupSitesTable();
    setupContentTables();
    setupSectionsTables();
    setupMediaTables();
    setupContentPopularityScoresTable();
    Queue::fake();
});

function orderModeSite(string $siteId, array $poolOrder): void
{
    DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->update(['settings' => json_encode(['pool_order' => $poolOrder])]);
}

function orderModePinKeys(string $siteId, array $itemIdsInOrder): void
{
    foreach ($itemIdsInOrder as $i => $itemId) {
        DB::connection('pgsql')->table('site.section_items')->where('item_id', $itemId)->update(['sort_key' => $i + 1]);
    }
}

function orderModeSelection(string $siteId, string $pool): array
{
    $site = Site::query()->findOrFail($siteId);

    return array_column(app(PoolResolver::class)->resolve($site, $pool)['selection'], 'headline');
}

/** Three videos on one source; the OLDEST is pinned so manual shows it first. */
function orderModeWatch(): array
{
    [$pro, $siteId] = poolTenant();
    $conn = poolConnection($pro->id);
    $source = poolSource($pro->id, $conn);
    $old = poolItem($pro->id, $source, 'video', 'Old', now()->subDays(20)->toISOString());
    $mid = poolItem($pro->id, $source, 'video', 'Mid', now()->subDays(10)->toISOString());
    $new = poolItem($pro->id, $source, 'video', 'New', now()->subDay()->toISOString());
    poolPin($siteId, 'watch', $old);
    poolPin($siteId, 'watch', $mid);
    poolPin($siteId, 'watch', $new);
    orderModePinKeys($siteId, [$old, $mid, $new]);

    return [$pro, $siteId, compact('old', 'mid', 'new')];
}

it('manual (default pins order) keeps the pinned order', function () {
    [, $siteId] = orderModeWatch();
    orderModeSite($siteId, ['watch' => 'manual']);
    expect(orderModeSelection($siteId, 'watch'))->toBe(['Old', 'Mid', 'New']);
});

it('newest (the default) reorders pins by publishedAt desc', function () {
    [, $siteId] = orderModeWatch();
    expect(orderModeSelection($siteId, 'watch'))->toBe(['New', 'Mid', 'Old']);
});

it('smart follows popularity rank, unranked trail by recency', function () {
    [, $siteId, $ids] = orderModeWatch();
    orderModeSite($siteId, ['watch' => 'smart']);
    foreach ([[$ids['old'], 1], [$ids['mid'], 2]] as [$itemId, $rank]) {
        DB::connection('pgsql')->table('analytics.content_popularity_scores')->insert([
            'id' => (string) Str::uuid(), 'site_id' => $siteId, 'content_type' => 'watch_item',
            'content_key' => $itemId, 'score' => 10.0 / $rank, 'rank' => $rank, 'computed_at' => now()->toISOString(),
        ]);
    }

    $site = Site::query()->findOrFail($siteId);
    $selection = app(PoolResolver::class)->resolve($site, 'watch')['selection'];

    expect(array_column($selection, 'headline'))->toBe(['Old', 'Mid', 'New'])
        ->and(array_column($selection, 'popularityRank'))->toBe([1, 2, null]);
});

it('events ignore the mode and stay soonest-first', function () {
    [$pro, $siteId] = poolTenant();
    $conn = poolConnection($pro->id, 'eventbrite.organiser');
    $source = poolSource($pro->id, $conn);
    $soon = poolItem($pro->id, $source, 'event', 'Soon', now()->subDays(1)->toISOString());
    $later = poolItem($pro->id, $source, 'event', 'Later', now()->toISOString());
    foreach ([[$soon, 2], [$later, 9]] as [$itemId, $days]) {
        DB::connection('pgsql')->table('content.f_occurrence')->insert([
            'item_id' => $itemId, 'source_id' => $source,
            'starts_at_local' => now()->addDays($days)->toDateTimeString(),
            'starts_at_utc' => now()->addDays($days)->toDateTimeString(),
            'zone_confidence' => 'offset_only', 'is_all_day' => 0, 'updated_at' => now(),
        ]);
    }
    // Pin "Soon" first. Under 'newest' "Later" (published more recently)
    // would jump ahead — the events exemption keeps the pinned order.
    poolPin($siteId, 'events', $soon);
    poolPin($siteId, 'events', $later);
    orderModePinKeys($siteId, [$soon, $later]);
    orderModeSite($siteId, ['events' => 'newest', 'watch' => 'newest']);

    expect(orderModeSelection($siteId, 'events'))->toBe(['Soon', 'Later']);
});

it('locks hold an item in place under newest; manual ignores locks', function () {
    [, $siteId, $ids] = orderModeWatch();
    DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->update(['settings' => json_encode([
        'pool_order' => ['watch' => 'newest'],
        'pool_locks' => ['watch' => [['position' => 0, 'id' => $ids['old']]]],
    ])]);
    expect(orderModeSelection($siteId, 'watch'))->toBe(['Old', 'New', 'Mid']);

    DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->update(['settings' => json_encode([
        'pool_order' => ['watch' => 'manual'],
        'pool_locks' => ['watch' => [['position' => 2, 'id' => $ids['old']]]],
    ])]);
    expect(orderModeSelection($siteId, 'watch'))->toBe(['Old', 'Mid', 'New']);
});

// ── Smart ordering v2 (A3): category pools — collection ranks, smart category
// order, per-category locks ──

/** Two service categories (Hair: Cut, Colour · Nails: Manicure) + one loose service. */
function orderModeServices(): array
{
    [$pro, $siteId] = poolTenant();
    $collections = app(ServiceCollections::class);
    $hair = $collections->create($pro->id, 'Hair');
    $nails = $collections->create($pro->id, 'Nails');
    $cut = ownerServiceItem($pro->id, ['title' => 'Cut', 'sort_order' => 0]);
    $colour = ownerServiceItem($pro->id, ['title' => 'Colour', 'sort_order' => 1]);
    $mani = ownerServiceItem($pro->id, ['title' => 'Manicure', 'sort_order' => 2]);
    $loose = ownerServiceItem($pro->id, ['title' => 'Consult', 'sort_order' => 3]);
    $collections->assign($pro->id, $cut, $hair, null);
    $collections->assign($pro->id, $colour, $hair, null);
    $collections->assign($pro->id, $mani, $nails, null);

    return [$pro, $siteId, compact('hair', 'nails', 'cut', 'colour', 'mani', 'loose')];
}

function orderModeRank(string $siteId, string $family, string $key, int $rank): void
{
    DB::connection('pgsql')->table('analytics.content_popularity_scores')->insert([
        'id' => (string) Str::uuid(), 'site_id' => $siteId, 'content_type' => $family,
        'content_key' => $key, 'score' => 10.0 / $rank, 'rank' => $rank, 'computed_at' => now()->toISOString(),
    ]);
}

it('carries a service category\'s own popularityRank on the collections map, null when unranked', function () {
    [, $siteId, $ids] = orderModeServices();
    orderModeRank($siteId, 'service_category', $ids['nails'], 1);

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'services');

    expect($out['collections'][$ids['nails']]['popularityRank'])->toBe(1)
        ->and($out['collections'][$ids['hair']]['popularityRank'])->toBeNull();
});

it('smart orders categories by their own rank (unranked after, by position) and the wire in category order', function () {
    [, $siteId, $ids] = orderModeServices();
    orderModeSite($siteId, ['services' => 'smart']);
    orderModeRank($siteId, 'service_category', $ids['nails'], 1);
    orderModeRank($siteId, 'service', $ids['colour'], 1);
    orderModeRank($siteId, 'service', $ids['cut'], 2);

    $out = app(PoolResolver::class)->resolve(Site::query()->findOrFail($siteId), 'services');

    expect(array_column($out['collections'], 'position', 'name'))->toBe(['Nails' => 0, 'Hair' => 1])
        ->and(array_column($out['selection'], 'headline'))->toBe(['Manicure', 'Colour', 'Cut', 'Consult']);
});

it('a lock on a category pool holds the item at a position WITHIN its category', function () {
    [, $siteId, $ids] = orderModeServices();
    orderModeRank($siteId, 'service', $ids['colour'], 1);
    orderModeRank($siteId, 'service', $ids['cut'], 2);
    DB::connection('pgsql')->table('site.sites')->where('id', $siteId)->update(['settings' => json_encode([
        'pool_order' => ['services' => 'smart'],
        // Cut locked at #0 of Hair — not #0 of the whole pool.
        'pool_locks' => ['services' => [['position' => 0, 'id' => $ids['cut']]]],
    ])]);

    expect(orderModeSelection($siteId, 'services'))->toBe(['Cut', 'Colour', 'Manicure', 'Consult']);
});
