<?php

use App\Catalog\LegacyPlatformMap;
use App\Services\PublicSite\IndividualProfilePayloadBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// GET /api/site/actions — the dashboard /actions page data source (spec §7).

beforeEach(function () {
    config(['partna.throttle.enabled' => false, 'partna.actions.slots' => 10]);
    setupBlocksTable();
    setupServicesTable();
    setupSectionViewsTable();
    setupLinkClicksTable();
    setupItemViewsTable();
    setupActionEventsTable();
    setupContentPopularityScoresTable();
    setupSectionsTables();
    setupIngestTables();
    setupContentTables();
    setupSiteMediaTable();
    setupDesignKitsTable();
});

function endpointConnection(object $tenant, string $platform, array $payload = []): void
{
    DB::connection('pgsql')->table('site.platform_connections')->insert([
        'id' => (string) Str::uuid(),
        'user_id' => $tenant->id,
        'surface_key' => $surface = (LegacyPlatformMap::surfaceFor($platform) ?? $platform),
        'routing_class' => LegacyPlatformMap::routingClassFor($surface),
        'resource_id' => 'r-'.Str::random(8),
        'payload' => json_encode($payload),
        'is_active' => 1,
        'created_at' => now()->toISOString(),
        'updated_at' => now()->toISOString(),
    ]);
}

it('returns mode, slots (with unavailable), entries and scored candidates', function () {
    $pro = createTenant('sa-shape');
    endpointConnection($pro, 'instagram', ['username' => 'maha']);
    endpointConnection($pro, 'tiktok', ['url' => 'https://tiktok.com/@maha']);
    DB::connection('pgsql')->table('analytics.content_popularity_scores')->insert([
        'id' => (string) Str::uuid(), 'site_id' => $pro->site->id, 'content_type' => 'action',
        'content_key' => 'platform:tiktok', 'score' => 0.8, 'rank' => 1, 'computed_at' => now()->toISOString(),
    ]);
    DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->update(['settings' => json_encode([
        'actions' => ['mode' => 'smart', 'slots' => [['position' => 0, 'id' => 'platform:instagram'], ['position' => 3, 'id' => 'item:gone']]],
    ])]);

    $data = actingAsUser($pro->fresh(['site']))->getJson('/api/site/actions')->assertOk()->json();

    expect($data['mode'])->toBe('smart')
        ->and($data['slots'])->toBe([
            ['position' => 0, 'id' => 'platform:instagram', 'unavailable' => false],
            ['position' => 3, 'id' => 'item:gone', 'unavailable' => true],
        ])
        ->and(array_column($data['entries'], 'id'))->toBe(['platform:instagram', 'platform:tiktok'])
        ->and($data['entries'][0]['locked'])->toBeTrue()
        ->and(array_column($data['candidates'], 'id'))->toBe(['platform:tiktok', 'platform:instagram'])
        ->and($data['candidates'][0])->toMatchArray(['score' => 0.8, 'scoreShare' => 1.0, 'kind' => 'platform', 'label' => 'TikTok'])
        ->and($data['candidates'][1]['score'])->toBeNull()
        ->and(array_keys($data['candidates'][0]))->toBe(['id', 'kind', 'label', 'url', 'thumb', 'connectedAt', 'score', 'scoreShare', 'ref', 'meta']);
});

it('entries are identical to the public payload resolution for the same state (no drift)', function () {
    $pro = createTenant('sa-drift');
    endpointConnection($pro, 'instagram', ['username' => 'maha']);
    endpointConnection($pro, 'youtube', ['handle' => 'maha']);
    DB::connection('pgsql')->table('site.sites')->where('id', $pro->site->id)->update(['settings' => json_encode([
        'actions' => ['mode' => 'newest', 'slots' => [['position' => 0, 'id' => 'platform:youtube']]],
    ])]);

    $endpoint = actingAsUser($pro->fresh(['site']))->getJson('/api/site/actions')->assertOk()->json('entries');
    $fresh = $pro->fresh(['site']);
    $public = app(IndividualProfilePayloadBuilder::class)->build($fresh, $fresh->site)['actions']['entries'];

    expect($endpoint)->toBe($public)->and($endpoint)->not->toBe([]);
});

it('requires authentication', function () {
    $this->getJson('/api/site/actions')->assertStatus(401);
});
