<?php

use App\Catalog\LegacyPlatformMap;
use App\Services\Analytics\ContentFreshness;
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
        'surface_key' => LegacyPlatformMap::surfaceFor($platform),
        'routing_class' => LegacyPlatformMap::routingClassFor(LegacyPlatformMap::surfaceFor($platform)),
        'resource_id' => $platform.'-'.Str::random(6),
        'is_active' => 1,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ], $extra));

    return $id;
}

it('boosts a page by its newest active connection, near W_PAGE when brand-new', function () {
    $tenant = createTenant('fresh-page');
    freshnessSeedConnection($tenant, 'spotify', now()->toISOString());

    $boosts = app(ContentFreshness::class)->boostsForSite($tenant->site);

    expect($boosts['page'])->toHaveKey('listen')
        ->and($boosts['page']['listen'])->toBeGreaterThan(ContentFreshness::W_PAGE * 0.99)
        ->and($boosts['page']['listen'])->toBeLessThanOrEqual(ContentFreshness::W_PAGE);
});

it('decays with a 14-day half-life (28 days ≈ quarter weight) and the newest connection wins per page', function () {
    $tenant = createTenant('fresh-decay');
    freshnessSeedConnection($tenant, 'spotify', now()->subDays(90)->toISOString());
    // Newer connection to the SAME page — its age drives the boost.
    freshnessSeedConnection($tenant, 'apple-music', now()->subDays(28)->toISOString());

    $boosts = app(ContentFreshness::class)->boostsForSite($tenant->site);

    $expected = ContentFreshness::W_PAGE / 4; // 2^(-28/14)
    expect($boosts['page']['listen'])->toBeGreaterThan($expected * 0.95)
        ->and($boosts['page']['listen'])->toBeLessThan($expected * 1.05);
});

it('drops boosts that have faded below the floor (ancient content seeds nothing)', function () {
    $tenant = createTenant('fresh-ancient');
    freshnessSeedConnection($tenant, 'youtube', now()->subDays(200)->toISOString());

    $boosts = app(ContentFreshness::class)->boostsForSite($tenant->site);

    expect($boosts['page'])->not->toHaveKey('watch');
});

it('ignores inactive and soft-deleted connections', function () {
    $tenant = createTenant('fresh-dead');
    freshnessSeedConnection($tenant, 'spotify', now()->toISOString(), ['is_active' => 0]);
    freshnessSeedConnection($tenant, 'youtube', now()->toISOString(), ['deleted_at' => now()->toISOString()]);

    $boosts = app(ContentFreshness::class)->boostsForSite($tenant->site);

    expect($boosts['page'])->not->toHaveKey('listen')
        ->and($boosts['page'])->not->toHaveKey('watch');
});

it('boosts each custom link per URL (payload.url = the item content_key) at W_ITEM', function () {
    $tenant = createTenant('fresh-links');
    freshnessSeedConnection($tenant, 'custom', now()->toISOString(), [
        'resource_kind' => 'link',
        'payload' => json_encode(['kind' => 'link', 'url' => 'https://example.com/new']),
    ]);
    freshnessSeedConnection($tenant, 'custom', now()->subDays(14)->toISOString(), [
        'resource_kind' => 'link',
        'payload' => json_encode(['kind' => 'link', 'url' => 'https://example.com/older']),
    ]);

    $boosts = app(ContentFreshness::class)->boostsForSite($tenant->site);

    // Custom connections also freshen the Links PAGE.
    expect($boosts['page'])->toHaveKey('links');
    expect($boosts['link_item']['https://example.com/new'])->toBeGreaterThan(ContentFreshness::W_ITEM * 0.99);
    // 14 days = one half-life → half weight.
    expect($boosts['link_item']['https://example.com/older'])->toBeGreaterThan(ContentFreshness::W_ITEM * 0.48)
        ->and($boosts['link_item']['https://example.com/older'])->toBeLessThan(ContentFreshness::W_ITEM * 0.52);
});

it('freshens the Services page from service content items (newest wins over an older connection)', function () {
    $tenant = createTenant('fresh-book');
    freshnessSeedConnection($tenant, 'fresha', now()->subDays(60)->toISOString());
    DB::connection('pgsql')->table('content.items')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $tenant->id,
        'kind' => 'service',
        'headline_cache' => 'New Cut',
        'facets_cache' => '{}',
        'eligible_cache' => '{}',
        'first_seen_at' => now()->toISOString(),
        'last_seen_at' => now()->toISOString(),
        'created_at' => now()->toISOString(),
        'updated_at' => now()->toISOString(),
    ]);

    $boosts = app(ContentFreshness::class)->boostsForSite($tenant->site);

    // The brand-new service item drives the Services page's age, not the 60d-old connection.
    expect($boosts['page']['services'])->toBeGreaterThan(ContentFreshness::W_PAGE * 0.99);
});
