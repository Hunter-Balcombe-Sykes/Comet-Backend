<?php

use App\Catalog\LegacyPlatformMap;
use App\Services\Analytics\ContentFreshness;
use App\Services\Content\LinkPoolWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class)->in(__FILE__);

// ContentFreshness — the additive, 14d-half-life boost per content grain,
// aged from the owning row's created_at. See the service docblock for which
// grains are eligible and why.

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

it('boosts each pool link per URL (f_link.url = the item content_key) at W_ITEM', function () {
    // The legacy custom-connection lane was retired 2026-08-19 — links live
    // in the custom_links POOL, and freshness reads content.items directly.
    $tenant = createTenant('fresh-links');
    $writer = app(LinkPoolWriter::class);
    $newId = $writer->add($tenant->refresh(), 'https://example.com/new', enrich: false);
    $oldId = $writer->add($tenant->refresh(), 'https://example.com/older', enrich: false);
    DB::connection('pgsql')->table('content.items')
        ->where('id', $oldId)->update(['created_at' => now()->subDays(14)->toISOString()]);

    $boosts = app(ContentFreshness::class)->boostsForSite($tenant->site);

    // Pool links also freshen the Links PAGE.
    expect($boosts['link_item']['https://example.com/new'])->toBeGreaterThan(ContentFreshness::W_ITEM * 0.99);
    // 14 days = one half-life → half weight.
    expect($boosts['link_item']['https://example.com/older'])->toBeGreaterThan(ContentFreshness::W_ITEM * 0.48)
        ->and($boosts['link_item']['https://example.com/older'])->toBeLessThan(ContentFreshness::W_ITEM * 0.52);
});
