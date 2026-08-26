<?php

use App\Catalog\LegacyPlatformMap;
use App\Services\Analytics\ContentFreshness;
use App\Services\Content\LinkPoolWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// ContentFreshness — the additive boost per item family (config
// partna.pools.smart weights + half-lives), aged from publishedAt ??
// firstSeenAt. See the service docblock.

beforeEach(function () {
    tenantHelpersEnsureTables();
    setupSitesTable();     // includes site.platform_connections
    setupServicesTable();
    // Services cutover: the services-page freshness signal reads content.items
    // (both source kinds) instead of the legacy site.services row.
    setupIngestTables();
    setupContentTables();
});

function freshnessSeedConnection(object $tenant, string $platform, string $createdAt, array $extra = []): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('site.platform_connections')->insert(array_merge([
        'id' => $id,
        'user_id' => $tenant->id,
        // Retired legacy slugs map to null now — fall back to the slug, and
        // to the 'link' class the retired custom surface historically carried.
        'surface_key' => $surface = (LegacyPlatformMap::surfaceFor($platform) ?? $platform),
        'routing_class' => LegacyPlatformMap::routingClassFor($surface) ?? 'link',
        'resource_id' => $platform.'-'.Str::random(6),
        'is_active' => 1,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ], $extra));

    return $id;
}

it('boosts each pool link per item id (the item content_key) at W_ITEM', function () {
    // The legacy custom-connection lane was retired 2026-08-19 — links live
    // in the custom_links POOL, and freshness reads content.items directly.
    $tenant = createTenant('fresh-links');
    $writer = app(LinkPoolWriter::class);
    $newId = $writer->add($tenant->refresh(), 'https://example.com/new', enrich: false);
    $oldId = $writer->add($tenant->refresh(), 'https://example.com/older', enrich: false);
    // Aged from publishedAt ?? firstSeenAt (a link has no publishedAt).
    DB::connection('pgsql')->table('content.items')
        ->where('id', $oldId)->update(['first_seen_at' => now()->subDays(14)->toISOString()]);

    $boosts = app(ContentFreshness::class)->boostsForSite($tenant->site);

    // Pool links also freshen the Links PAGE.
    expect($boosts['link_item'][$newId])->toBeGreaterThan(ContentFreshness::W_ITEM * 0.99);
    // 14 days = one half-life → half weight.
    expect($boosts['link_item'][$oldId])->toBeGreaterThan(ContentFreshness::W_ITEM * 0.48)
        ->and($boosts['link_item'][$oldId])->toBeLessThan(ContentFreshness::W_ITEM * 0.52);
});

it('boosts every scored family from publishedAt ?? firstSeenAt at its own weight, and never an event', function () {
    $tenant = createTenant('fresh-families');
    $source = poolSource($tenant->id, null);
    $video = poolItem($tenant->id, $source, 'video', 'Clip', now()->toISOString());          // published today
    $track = poolItem($tenant->id, $source, 'track', 'Song', now()->subDays(21)->toISOString()); // one 21d half-life
    $media = poolItem($tenant->id, $source, 'media', 'Shot', now()->toISOString());
    $event = poolItem($tenant->id, $source, 'event', 'Gig', now()->toISOString());
    // A service with no publishedAt ages from first_seen_at (30d half-life).
    $service = poolItem($tenant->id, $source, 'service', 'Cut', now()->toISOString());
    DB::connection('pgsql')->table('content.f_published')->where('item_id', $service)->delete();
    DB::connection('pgsql')->table('content.items')->where('id', $service)->update(['first_seen_at' => now()->subDays(30)->toISOString()]);

    $boosts = app(ContentFreshness::class)->boostsForSite($tenant->site);

    expect($boosts['watch_item'][$video])->toEqualWithDelta(3.0, 0.01)
        ->and($boosts['listen_item'][$track])->toEqualWithDelta(2.0, 0.01)
        ->and($boosts['gallery_item'][$media])->toEqualWithDelta(5.0, 0.01)
        ->and($boosts['service'][$service])->toEqualWithDelta(0.5, 0.01)
        ->and($boosts)->not->toHaveKey('engine_item');
    foreach ($boosts as $family) {
        expect($family)->not->toHaveKey($event);
    }
});

it('ages a multi-source item from the EARLIEST published_from, not the last one registered', function () {
    $tenant = createTenant('fresh-dedup');
    $sourceA = poolSource($tenant->id, null);
    $itemId = poolItem($tenant->id, $sourceA, 'video', 'Clip', now()->subDays(5)->toISOString());
    // A second source reports the SAME item published much earlier — e.g. a
    // connector backfilling a video the manual source only just picked up.
    $sourceB = poolSource($tenant->id, poolConnection($tenant->id, 'youtube.channel'));
    DB::connection('pgsql')->table('content.f_published')->insert([
        'item_id' => $itemId, 'source_id' => $sourceB,
        'published_from' => now()->subDays(20)->toISOString(), 'updated_at' => now(),
    ]);

    $boosts = app(ContentFreshness::class)->boostsForSite($tenant->site);

    // WHY: SQL MIN(published_from) means "earliest any source knows" — no
    // other fixture in this suite attaches two sources to one item, so a
    // regression from MIN to MAX (last-registered date wins) would go
    // undetected without this pinning the 20-day boost over the 5-day one.
    expect($boosts['watch_item'][$itemId])->toEqualWithDelta(1.1145, 0.05)
        ->and($boosts['watch_item'][$itemId])->not->toEqualWithDelta(2.3421, 0.05);
});

it('falls back to first_seen_at when every source leaves published_from null', function () {
    $tenant = createTenant('fresh-null-fallback');
    $sourceA = poolSource($tenant->id, null);
    $itemId = poolItem($tenant->id, $sourceA, 'video', 'Clip', now()->toISOString());
    DB::connection('pgsql')->table('content.f_published')->where('item_id', $itemId)->update(['published_from' => null]);
    DB::connection('pgsql')->table('content.items')->where('id', $itemId)->update(['first_seen_at' => now()->subDays(20)->toISOString()]);
    $sourceB = poolSource($tenant->id, poolConnection($tenant->id, 'youtube.channel'));
    DB::connection('pgsql')->table('content.f_published')->insert([
        'item_id' => $itemId, 'source_id' => $sourceB, 'published_from' => null, 'updated_at' => now(),
    ]);

    $boosts = app(ContentFreshness::class)->boostsForSite($tenant->site);

    // MIN(NULL, NULL) is NULL either way, so this pins the GROUP BY not
    // collapsing two all-null rows into something other than the fallback.
    expect($boosts['watch_item'][$itemId])->toEqualWithDelta(1.1145, 0.05);
});
